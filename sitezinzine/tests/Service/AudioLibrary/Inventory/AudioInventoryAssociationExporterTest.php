<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationExporter;
use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryIncrementalRunCatalogWriter;
use App\Service\AudioLibrary\Inventory\AudioInventoryReferenceCollector;
use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunCatalog;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

final class AudioInventoryAssociationExporterTest extends TestCase
{
    private string $root;
    private AudioInventoryTemporaryWorkspace $workspace;
    private int $level = 0;
    private bool $connected = true;
    private array $steps = [];
    private ?\Closure $onCommit = null;
    private ?AudioInventoryIncrementalRunCatalogWriter $writer = null;
    private bool $partialWrapper = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-exporter-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 2, 600);
    }

    protected function tearDown(): void
    {
        try { $this->workspace->cleanup(); }
        finally {
            if ($this->partialWrapper) { stream_wrapper_unregister('zinzineexportpartial'); }
            rmdir($this->root); parent::tearDown();
        }
    }

    private function runs(): array { return (new \ReflectionProperty($this->workspace, 'runs'))->getValue($this->workspace); }
    private function assertClosed(): void { foreach ($this->runs() as $run) { self::assertNull($run['stream']); } }
    private static function row(int $id, ?string $path = 'same.mp3', ?string $url = null): array
    {
        return ['id' => $id, 'thumbnail_mp3' => $path, 'url' => $url];
    }

    private function collector(array|\Closure $pages): AudioInventoryReferenceCollector
    {
        $db = $this->createMock(Connection::class); $index = 0;
        $db->method('getTransactionNestingLevel')->willReturnCallback(fn () => $this->level);
        $db->method('isConnected')->willReturnCallback(fn () => $this->connected);
        $db->method('isAutoCommit')->willReturn(true); $db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $db->expects(self::once())->method('fetchOne')->with(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName',
            ['tableName' => 'emission'], ['tableName' => ParameterType::STRING],
        )->willReturn('InnoDB');
        $db->expects(self::once())->method('executeStatement')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(0);
        $db->method('beginTransaction')->willReturnCallback(function (): void { $this->steps[] = 'begin'; $this->level = 1; });
        $db->method('fetchAllAssociative')->willReturnCallback(function ($sql, $params, $types) use ($pages, &$index): array {
            if ($this->level !== 1) { self::fail('SQL read outside owned transaction.'); }
            if ($sql !== 'SELECT id, thumbnail_mp3, url FROM emission WHERE id > :afterId ORDER BY id ASC LIMIT :limit'
                || $types !== ['afterId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER]) { self::fail('Reader contract changed.'); }
            $value = $pages instanceof \Closure ? $pages($params, $index++) : ($pages[$index++] ?? []);
            if ($value instanceof \Throwable) { throw $value; }
            return $value;
        });
        $db->method('commit')->willReturnCallback(function (): void {
            $this->steps[] = 'commit'; if ($this->onCommit !== null) { ($this->onCommit)(); } $this->level = 0;
        });
        $db->method('rollBack')->willReturnCallback(function (): void { $this->steps[] = 'rollback'; $this->level = 0; });
        $db->expects(self::once())->method('close')->willReturnCallback(function (): void { $this->steps[] = 'close'; $this->connected = false; $this->level = 0; });
        foreach (['insert', 'update', 'delete', 'setAutoCommit', 'setTransactionIsolation'] as $method) { $db->expects(self::never())->method($method); }
        return new AudioInventoryReferenceCollector($db, new AudioLocalUrlResolver(['http://local.test/emissions/']));
    }

    private function exporter(array|\Closure $pages, int $pageSize = 2, int $diagnosticBudget = 10, ?\Closure $configure = null): AudioInventoryAssociationExporter
    {
        $collector = $this->collector($pages);
        $this->writer ??= new class($this->workspace, 128, $this) extends AudioInventoryIncrementalRunCatalogWriter {
            public ?\Throwable $appendError = null;
            public ?\Throwable $finishError = null;
            public ?\Throwable $abortError = null;
            public int $reportedCountOffset = 0;
            public bool $badId = false;
            public bool $badState = false;
            public function __construct($workspace, $limit, private AudioInventoryAssociationExporterTest $test) { parent::__construct($workspace, $limit); }
            public function append(string $id): void { if ($this->appendError) { throw $this->appendError; } parent::append($id); }
            public function finish(): array {
                $this->test->assertSqlEnded();
                if ($this->finishError) { throw $this->finishError; }
                $report = parent::finish(); $report['identifier_count'] += $this->reportedCountOffset;
                if ($this->badId) { $report['catalog_id'] = ''; }
                return $report;
            }
            public function getState(): string { return $this->badState && parent::getState() === 'finalized' ? 'open' : parent::getState(); }
            public function abort(): void { parent::abort(); if ($this->abortError) { throw $this->abortError; } }
        };
        $exporter = new class($collector, $this->workspace, new AudioInventoryAssociationRunCodec(1024), $this->writer,
            new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(128)), $diagnosticBudget, $pageSize) extends AudioInventoryAssociationExporter {
            public ?\Closure $changePage = null;
            public ?\Closure $changeSummary = null;
            public ?\Throwable $writeError = null;
            public ?\Throwable $closeError = null;
            public ?\Throwable $finalizeError = null;
            public bool $partial = false;
            public bool $wrongWrittenCount = false;
            public ?\Closure $afterFinalize = null;
            protected function collectPages(\Closure $consume): array {
                $report = parent::collectPages(function (array $page) use ($consume): bool {
                    return $consume($this->changePage ? ($this->changePage)($page) : $page);
                });
                return $this->changeSummary ? ($this->changeSummary)($report) : $report;
            }
            protected function writeAssociations(mixed $stream, array $rows): int {
                if ($this->writeError) { throw $this->writeError; }
                if ($this->partial) {
                    ExporterPartialWriteStream::$target = $stream;
                    $wrapper = fopen('zinzineexportpartial://isolated', 'wb');
                    try { return parent::writeAssociations($wrapper, $rows); }
                    finally { fclose($wrapper); }
                }
                return parent::writeAssociations($stream, $rows) + (int) $this->wrongWrittenCount;
            }
            protected function closeRun(string $id): void { parent::closeRun($id); if ($this->closeError) { throw $this->closeError; } }
            protected function finalizeRun(string $id): void {
                if ($this->finalizeError) { throw $this->finalizeError; }
                parent::finalizeRun($id); if ($this->afterFinalize) { ($this->afterFinalize)($id); }
            }
        };
        if ($configure) { $configure($exporter, $this->writer); }
        return $exporter;
    }

    public function assertSqlEnded(): void
    {
        self::assertSame(0, $this->level); self::assertFalse($this->connected); self::assertContains('commit', $this->steps);
    }

    private function exportedRows(array $report): array
    {
        $catalog = new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(128));
        return $catalog->consume($report['catalog_id'], function (iterable $ids): array {
            $rows = [];
            foreach ($ids as $id) {
                $stream = $this->workspace->openRead($id);
                try { foreach ((new AudioInventoryAssociationRunCodec(1024))->read($stream) as $row) { $rows[] = $row; } }
                finally { $this->workspace->close($id); }
            }
            return $rows;
        });
    }

    public function testEmptyExportHasValidatedEmptyCatalogAndNoAssociationFile(): void
    {
        $report = $this->exporter([[]])->export();
        self::assertSame('completed', $report['status']); self::assertTrue($report['references_complete']);
        self::assertSame(1, $report['pages_received']); self::assertSame(0, $report['files_exported']);
        self::assertSame(0, $report['associations_written']); self::assertSame(0, $report['catalog_identifier_count']);
        self::assertTrue($report['content_validated']); self::assertSame([], $report['uncertainties']);
        self::assertSame([], $report['secondary_errors']); self::assertCount(1, $this->runs());
        self::assertSame([], $this->exportedRows($report)); $this->assertClosed();
    }

    public function testRealCollectorExportsMultiplePagesBothOriginsRepeatedPathsAndExactBytes(): void
    {
        $path = "Été/Historique\n\xff.MP3";
        $report = $this->exporter([[self::row(17, $path, 'http://local.test/emissions/a.mp3'), self::row(18, $path)], [self::row(19, null)]])->export();
        self::assertSame(2, $report['pages_received']); self::assertSame(1, $report['files_exported']);
        self::assertSame(3, $report['associations_written']); self::assertSame(1, $report['catalog_identifier_count']);
        self::assertSame($report['associations_written'], $report['references']['association_count']);
        self::assertSame(19, $report['references']['next_after_id']);
        self::assertSame([
            ['storage' => 'emissionsMp3', 'relative_path' => $path, 'emission_id' => 17, 'origin' => 'thumbnailMp3', 'thumbnail_missing' => false],
            ['storage' => 'emissionsMp3', 'relative_path' => 'a.mp3', 'emission_id' => 17, 'origin' => 'local_audio_url', 'thumbnail_missing' => false],
            ['storage' => 'emissionsMp3', 'relative_path' => $path, 'emission_id' => 18, 'origin' => 'thumbnailMp3', 'thumbnail_missing' => false],
        ], $this->exportedRows($report));
        self::assertSame(['begin', 'commit', 'close'], $this->steps); $this->assertClosed();
    }

    public function testSeveralNonemptyPagesAndAnEmptyTerminalPage(): void
    {
        $report = $this->exporter([[self::row(1)], [self::row(2)], []], 1)->export();
        self::assertSame(3, $report['pages_received']); self::assertSame(2, $report['files_exported']);
        self::assertSame(2, $report['catalog_identifier_count']); self::assertSame(2, $report['associations_written']);
        self::assertSame([1, 2], array_column($this->exportedRows($report), 'emission_id'));
    }

    public function testExactRepeatedAssociationsAreNotDeduplicated(): void
    {
        // Synthetic DBAL page deliberately contains the same scalar row twice.
        $report = $this->exporter([[self::row(17), self::row(17)]])->export();
        $rows = $this->exportedRows($report); self::assertCount(2, $rows); self::assertSame($rows[0], $rows[1]);
    }

    public function testUncertaintyIsPreservedWithoutClaimingReferenceCompleteness(): void
    {
        $report = $this->exporter([[self::row(17, null, 'http://local.test/emissions/a.mp3?secret=hidden')]])->export();
        self::assertSame('completed', $report['status']); self::assertFalse($report['references_complete']);
        self::assertFalse($report['references']['references_complete']); self::assertTrue($report['content_validated']);
        self::assertSame([['emission_id' => 17, 'field' => 'url', 'reason' => 'uncertain_local_url']], $report['uncertainties']);
        self::assertStringNotContainsString('hidden', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testDiagnosticOverflowFailsInsteadOfDroppingUncertainties(): void
    {
        $exporter = $this->exporter([[self::row(17, null, 'http://local.test/emissions/a?x')]], 2, 0);
        $this->expectException(\LengthException::class);
        try { $exporter->export(); }
        finally { self::assertSame('aborted', $this->writer->getState()); self::assertContains('rollback', $this->steps); $this->assertClosed(); }
    }

    public function testInterruptionAbortsCatalogEvenOnTerminalPage(): void
    {
        $exporter = $this->exporter([[self::row(17)]]);
        $report = $exporter->export(static fn (array $progress): bool => false);
        self::assertSame('interrupted', $report['status']); self::assertNull($report['catalog_id']);
        self::assertFalse($report['references_complete']); self::assertFalse($report['content_validated']);
        self::assertSame(1, $report['files_exported']); self::assertSame(0, $report['catalog_identifier_count']);
        self::assertSame('aborted', $this->writer->getState()); self::assertNotContains('commit', $this->steps);
        self::assertCount(2, $this->runs()); $this->assertClosed();
    }

    public function testSqlFailurePreservesOriginalAndAlreadyWrittenFiles(): void
    {
        $failure = new \RuntimeException('SQL'); $exporter = $this->exporter([[self::row(17)], $failure], 1);
        $report = null;
        try { $report = $exporter->export(); self::fail('SQL failure hidden.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertNull($report); self::assertCount(2, $this->runs()); self::assertSame('aborted', $this->writer->getState()); $this->assertClosed();
    }

    /** @dataProvider malformedPages */
    public function testInvalidPageCannotPublish(\Closure $change): void
    {
        $exporter = $this->exporter([[self::row(17)]], 2, 10, static function ($exporter) use ($change): void { $exporter->changePage = $change; });
        $this->expectException(\UnexpectedValueException::class);
        try { $exporter->export(); } finally { self::assertSame('aborted', $this->writer->getState()); $this->assertClosed(); }
    }
    public static function malformedPages(): array
    {
        return [[static fn ($p) => array_diff_key($p, ['associations' => true])],
            [static fn ($p) => array_replace($p, ['associations' => 'bad'])],
            [static fn ($p) => array_replace($p, ['exhausted' => 1])],
            [static fn ($p) => array_replace($p, ['next_after_id' => '17'])],
            [static fn ($p) => array_replace($p, ['uncertainties' => [['emission_id' => 17, 'field' => 'url', 'reason' => 'bad']]])]];
    }

    /** @dataProvider wrongSummaryFields */
    public function testSummaryCountersAndCompletenessMustMatchObservedPages(string $key, mixed $value): void
    {
        $exporter = $this->exporter([[self::row(17)]], 2, 10, static function ($exporter) use ($key, $value): void {
            $exporter->changeSummary = static fn ($r) => array_replace($r, [$key => $value]);
        });
        $this->expectException(\UnexpectedValueException::class);
        try { $exporter->export(); } finally { self::assertSame('aborted', $this->writer->getState()); }
    }
    public static function wrongSummaryFields(): array
    {
        return [['pages_read', 2], ['association_count', 2], ['uncertainty_count', 1], ['next_after_id', 18],
            ['references_complete', false], ['status', 'failed'], ['temporal_consistency', 'unknown']];
    }

    /** @dataProvider faultStages */
    public function testOperationFailuresNeverReturnAnExport(string $stage): void
    {
        $failure = new \DomainException($stage);
        $exporter = $this->exporter([[self::row(17)]], 2, 10, static function ($exporter, $writer) use ($stage, $failure): void {
            match ($stage) {
                'write' => $exporter->writeError = $failure,
                'close' => $exporter->closeError = $failure,
                'finalize' => $exporter->finalizeError = $failure,
                'append' => $writer->appendError = $failure,
                'finish' => $writer->finishError = $failure,
            };
        });
        $result = null;
        try { $result = $exporter->export(); self::fail('Failure concealed.'); }
        catch (\DomainException $actual) { self::assertSame($failure, $actual); }
        self::assertNull($result); self::assertCount(2, $this->runs()); $this->assertClosed();
        if (in_array($stage, ['write', 'close', 'finalize'], true)) {
            $runs = array_values($this->runs()); self::assertSame('writing', $runs[1]['state']);
        }
    }
    public static function faultStages(): array { return [['write'], ['close'], ['finalize'], ['append'], ['finish']]; }

    public function testRealCodecRejectsInvalidAssociation(): void
    {
        $exporter = $this->exporter([[self::row(17, '../outside')]]);
        $this->expectException(\UnexpectedValueException::class);
        try { $exporter->export(); } finally { self::assertSame('aborted', $this->writer->getState()); $this->assertClosed(); }
    }

    public function testRealCodecHandlesPartialWritesThroughDelegatingTestStream(): void
    {
        self::assertTrue(stream_wrapper_register('zinzineexportpartial', ExporterPartialWriteStream::class)); $this->partialWrapper = true;
        $exporter = $this->exporter([[self::row(17)]], 2, 10, static function ($e): void { $e->partial = true; });
        $report = $exporter->export(); self::assertSame(1, $report['associations_written']);
        self::assertSame(17, $this->exportedRows($report)[0]['emission_id']);
    }

    public function testWrongCodecWriteCountCannotPublish(): void
    {
        $exporter = $this->exporter([[self::row(17)]], 2, 10, static function ($e): void { $e->wrongWrittenCount = true; });
        $this->expectException(\UnexpectedValueException::class); $exporter->export();
    }

    /** @dataProvider incorrectFinish */
    public function testCatalogFinishReportMustBeCoherent(string $property): void
    {
        $exporter = $this->exporter([[self::row(17)]], 2, 10, static function ($e, $w) use ($property): void { $w->$property = $property === 'reportedCountOffset' ? 1 : true; });
        $this->expectException(\UnexpectedValueException::class); $exporter->export();
    }
    public static function incorrectFinish(): array { return [['reportedCountOffset'], ['badId'], ['badState']]; }

    public function testContentCorruptionAfterSqlCommitIsDetectedBeforeExportPublication(): void
    {
        $exporter = $this->exporter([[self::row(17)]]);
        $this->onCommit = function (): void {
            $runs = array_values($this->runs()); $path = $runs[1]['path']; $bytes = file_get_contents($path);
            $bytes[strlen($bytes) - 1] = ~$bytes[strlen($bytes) - 1]; file_put_contents($path, $bytes);
        };
        $this->expectException(\UnexpectedValueException::class);
        try { $exporter->export(); } finally { self::assertSame('finalized', $this->writer->getState()); $this->assertClosed(); }
    }

    public function testInitialErrorAndSecondaryClosureAndAbortErrorsRemainObservable(): void
    {
        $primary = new \DomainException('write'); $close = new \RuntimeException('close'); $abort = new \RuntimeException('abort');
        $exporter = $this->exporter([[self::row(17)]], 2, 10, static function ($e, $w) use ($primary, $close, $abort): void {
            $e->writeError = $primary; $e->closeError = $close; $w->abortError = $abort;
        });
        try { $exporter->export(); self::fail('Primary failure concealed.'); }
        catch (\DomainException $actual) { self::assertSame($primary, $actual); }
        self::assertSame([$close, $abort], $exporter->getSecondaryErrors()); $this->assertClosed();
    }

    public function testWorkspaceStreamBudgetFailsWithoutClosingUnrelatedStream(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 1, 3);
        $exporter = $this->exporter([[self::row(17)]]);
        $this->expectException(\LengthException::class);
        try { $exporter->export(); } finally { self::assertSame('aborted', $this->writer->getState()); $this->assertClosed(); }
    }

    public function testCumulativeCreationBudgetIsRespected(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 2, 1);
        $exporter = $this->exporter([[self::row(17)]]);
        $this->expectException(\LengthException::class); $exporter->export();
    }

    public function testExporterCannotBeReused(): void
    {
        $exporter = $this->exporter([[]]); $exporter->export();
        $this->expectException(\LogicException::class); $exporter->export();
    }

    public function testManyPagesAreExportedWithoutGlobalAssociationOrIdentifierArray(): void
    {
        $baseline = memory_get_usage(); $growth = 0;
        $source = function (array $params) use ($baseline, &$growth): array {
            $growth = max($growth, memory_get_usage() - $baseline); $rows = [];
            for ($id = $params['afterId'] + 1; $id <= min(10000, $params['afterId'] + 20); ++$id) { $rows[] = self::row($id); }
            return $rows;
        };
        $report = $this->exporter($source, 20)->export();
        self::assertSame(501, $report['pages_received']); self::assertSame(500, $report['files_exported']);
        self::assertSame(10000, $report['associations_written']); self::assertSame(500, $report['catalog_identifier_count']);
        // Workspace registry and PHPUnit invocation tracking remain proportional
        // to file/page counts; this is a bounded fixture, not a global O(1) claim.
        self::assertLessThan(12 * 1024 * 1024, $growth); self::assertCount(501, $this->runs()); $this->assertClosed();
    }
}

/** Test-only short writes forwarded to the actual workspace-owned descriptor. */
final class ExporterPartialWriteStream
{
    public mixed $context;
    public static mixed $target;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $bytes): int|false { return fwrite(self::$target, substr($bytes, 0, 2)); }
    public function stream_eof(): bool { return feof(self::$target); }
    public function stream_tell(): int { return ftell(self::$target); }
    public function stream_stat(): array|false { return fstat(self::$target); }
    public function stream_flush(): bool { return fflush(self::$target); }
    public function stream_close(): void { /* The workspace alone closes the underlying descriptor. */ }
}
