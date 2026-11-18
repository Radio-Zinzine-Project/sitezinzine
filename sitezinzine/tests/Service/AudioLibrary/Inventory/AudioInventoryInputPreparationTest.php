<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationExporter;
use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryIncrementalRunCatalogWriter;
use App\Service\AudioLibrary\Inventory\AudioInventoryInputPreparation;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryReferenceCollector;
use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryScanner;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunCatalog;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryReconciler;
use App\Service\AudioLibrary\Inventory\AudioInventoryClassifier;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

final class AudioInventoryInputPreparationTest extends TestCase
{
    private string $root;
    private string $library;
    private AudioInventoryTemporaryWorkspace $workspace;
    private int $level = 0;
    private bool $connected = true;
    private array $steps = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-inputs-'.bin2hex(random_bytes(12)); mkdir($this->root, 0700);
        $this->library = $this->root.'/library'; mkdir($this->library);
        mkdir($this->root.'/work');
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root.'/work', 5, 2500);
    }
    protected function tearDown(): void
    {
        try { $this->workspace->cleanup(); }
        finally { $this->removeOwned($this->root); parent::tearDown(); }
    }
    private function removeOwned(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
        foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { $this->removeOwned($path.'/'.$entry); } }
        rmdir($path);
    }
    private function fixture(string $path): void
    {
        $absolute = $this->library.'/'.$path;
        if (!is_dir(dirname($absolute))) { mkdir(dirname($absolute), 0700, true); }
        file_put_contents($absolute, 'synthetic audio fixture; do not read or modify');
    }
    private function context(string $scope = 'test_subset'): array
    {
        return ['library_id' => 'fixture-library', 'storage_kind' => 'synthetic', 'generation' => 'g1',
            'scope' => $scope, 'source' => 'test-observer', 'evidence_id' => 'fixture-1'];
    }
    private static function row(int $id, ?string $path, ?string $url = null): array
    {
        return ['id' => $id, 'thumbnail_mp3' => $path, 'url' => $url];
    }
    private function exporter(array|\Closure $rows): AudioInventoryAssociationExporter
    {
        $db = $this->createMock(Connection::class);
        $db->method('getTransactionNestingLevel')->willReturnCallback(fn () => $this->level);
        $db->method('isAutoCommit')->willReturn(true); $db->method('isConnected')->willReturnCallback(fn () => $this->connected);
        $db->method('getDatabasePlatform')->willReturn(new MySQLPlatform()); $db->method('fetchOne')->willReturn('InnoDB');
        $db->expects(self::once())->method('executeStatement')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(0);
        $db->method('beginTransaction')->willReturnCallback(function (): void { $this->level = 1; $this->steps[] = 'begin'; });
        $db->method('commit')->willReturnCallback(function (): void { $this->level = 0; $this->steps[] = 'commit'; });
        $db->method('rollBack')->willReturnCallback(function (): void { $this->level = 0; $this->steps[] = 'rollback'; });
        $db->expects(self::once())->method('close')->willReturnCallback(function (): void { $this->connected = false; $this->steps[] = 'close'; });
        $db->method('fetchAllAssociative')->willReturnCallback(function ($sql, array $params) use ($rows): array {
            if ($this->level !== 1) { self::fail('No owned SQL snapshot.'); }
            if ($rows instanceof \Closure) { return $rows($params); }
            return array_slice($rows, $params['afterId'], $params['limit']);
        });
        foreach (['insert', 'update', 'delete', 'setTransactionIsolation'] as $method) { $db->expects(self::never())->method($method); }
        return new AudioInventoryAssociationExporter(new AudioInventoryReferenceCollector($db, new AudioLocalUrlResolver(['http://local.test/emissions/'])),
            $this->workspace, new AudioInventoryAssociationRunCodec(4096), new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 128),
            new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(128)), 20, 2);
    }
    private function owner(array|\Closure $rows = [], int $records = 2, int $pathBytes = 1024, int $diagnostics = 10, int $passes = 20): AudioInventoryInputPreparation
    {
        return new class($this->workspace, $this->exporter($rows), new AudioInventoryScanner($this->library),
            new AudioInventoryPathRunCodec(4096), new AudioInventoryAssociationRunCodec(4096), 2, $records, $pathBytes, 8192, $diagnostics, $passes, $this) extends AudioInventoryInputPreparation {
            public ?\Closure $replaceObservations = null;
            public ?\Closure $beforeConsume = null;
            public ?\Throwable $closingError = null;
            public bool $injectClose = false;
            public function __construct($workspace, $exporter, $scanner, $pathCodec, $associationCodec, $k, $records, $pathBytes, $associationBytes, $errors, $passes, private AudioInventoryInputPreparationTest $test) {
                parent::__construct($workspace, $exporter, $scanner, $pathCodec, $associationCodec, $k, $records, $pathBytes, $associationBytes, $errors, $passes);
            }
            protected function scanObservations(string $root): iterable {
                $source = parent::scanObservations($root); return $this->replaceObservations ? ($this->replaceObservations)($source) : $source;
            }
            protected function beginSorting(): void { $this->test->assertSqlClosed(); }
            protected function beforeConsumption(?string $pathRun, ?string $associationRun): void {
                if ($this->beforeConsume) { ($this->beforeConsume)($pathRun, $associationRun); }
                $this->injectClose = true;
            }
            protected function closeRun(string $id): void {
                parent::closeRun($id); if ($this->injectClose && $this->closingError) { throw $this->closingError; }
            }
        };
    }
    public function assertSqlClosed(): void { self::assertSame(0, $this->level); self::assertFalse($this->connected); self::assertContains('commit', $this->steps); }
    private function consumeAll(iterable $paths, iterable $associations, array $context): array
    {
        return [iterator_to_array($paths, false), iterator_to_array($associations, false), $context];
    }
    private function runs(): array { return (new \ReflectionProperty($this->workspace, 'runs'))->getValue($this->workspace); }
    private function assertClosed(): void { foreach ($this->runs() as $run) { self::assertNull($run['stream']); } }

    public function testSyntheticPipelineReallyUsesMultiplePassesAndFeedsReconciler(): void
    {
        $paths = ['associated.mp3', 'multiple.mp3', 'orphan.mp3', 'Case.mp3', 'case.mp3', 'old/nested.mp3', 'z.mp3', 'a.mp3', 'b.mp3'];
        foreach ($paths as $path) { $this->fixture($path); }
        // Windows may map these two names to one physical directory entry.
        // SQL identities must nevertheless remain distinct. Linux validation
        // additionally exercises both actual files when the volume permits it.
        $physicalCount = in_array('case.mp3', scandir($this->library), true) ? 9 : 8;
        $rows = [self::row(1, 'associated.mp3'), self::row(2, 'multiple.mp3', 'http://local.test/emissions/multiple.mp3'),
            self::row(3, 'multiple.mp3'), self::row(4, 'missing.mp3'), self::row(5, 'Case.mp3'), self::row(6, 'case.mp3'),
            self::row(7, 'old/nested.mp3'), self::row(8, 'z.mp3'), self::row(9, 'a.mp3')];
        $owner = $this->owner($rows); $results = [];
        $report = $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1',
            static function ($physical, $associations, $context) use (&$results): void {
                $result = (new AudioInventoryReconciler(new AudioInventoryClassifier(), 10))->reconcile($physical, $associations, $context,
                    static function ($row) use (&$results): void { $results[$row['relative_path']] = $row; });
                self::assertSame('completed', $result['status']);
            });
        self::assertSame('completed', $report['status']); self::assertSame($physicalCount, $report['consumed_paths']); self::assertSame(10, $report['consumed_associations']);
        self::assertGreaterThan(1, count($report['sql_sort']['passes'])); self::assertGreaterThan(1, count($report['physical_sort']['passes']));
        foreach (['sql_sort' => 10, 'physical_sort' => $physicalCount] as $key => $count) {
            self::assertSame(1, $report[$key]['final_runs']); self::assertSame($count, $report[$key]['records']);
            foreach ($report[$key]['passes'] as $pass) { self::assertLessThan($pass['input_runs'], $pass['output_runs']); self::assertSame($count, $pass['records']); }
        }
        self::assertSame('associated', $results['associated.mp3']['classification']);
        self::assertSame('multiple_associations', $results['multiple.mp3']['classification']); self::assertCount(3, $results['multiple.mp3']['associations']);
        self::assertSame('orphan_candidate', $results['orphan.mp3']['classification']); self::assertNull($results['missing.mp3']['present']);
        self::assertArrayHasKey('Case.mp3', $results); self::assertArrayHasKey('case.mp3', $results); self::assertFalse($report['absence_conclusions_allowed']);
        foreach ($paths as $path) { self::assertSame('synthetic audio fixture; do not read or modify', file_get_contents($this->library.'/'.$path)); }
        $this->assertClosed(); foreach ($this->runs() as $run) { self::assertFileExists($run['path']); }
    }

    public function testEmptyInputsAndSingleRunsAreFullyConsumable(): void
    {
        $owner = $this->owner(); $values = null;
        $report = $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', function ($p, $a, $c) use (&$values): void { $values = $this->consumeAll($p, $a, $c); });
        self::assertSame([[], []], array_slice($values, 0, 2)); self::assertSame(0, $report['sql_sort']['final_runs']); self::assertSame(0, $report['physical_sort']['final_runs']); $this->assertClosed();
    }

    public function testExactAssociationDuplicatesAndBinaryPathsRemainUnchanged(): void
    {
        $this->fixture('one.mp3');
        $rows = [self::row(1, 'Été/Name.mp3'), self::row(2, 'Été/Name.mp3')];
        $owner = $this->owner($rows, 1); $received = [];
        $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static function ($p, $a) use (&$received): void { foreach ($p as $unused) {} foreach ($a as $row) { $received[] = $row; } });
        self::assertSame(['Été/Name.mp3', 'Été/Name.mp3'], array_column($received, 'relative_path')); self::assertSame([1, 2], array_column($received, 'emission_id'));
    }

    public function testCaseDistinctPhysicalObservationsAndIdenticalAssociationsSurviveAllStages(): void
    {
        $owner = $this->owner(static fn ($params): array => $params['afterId'] === 0 ? [self::row(1, 'Case.mp3'), self::row(1, 'Case.mp3')] : [], 1);
        $owner->replaceObservations = static function ($source): \Generator {
            yield from $source;
            yield ['type' => 'file', 'relative_path' => 'case.mp3'];
            yield ['type' => 'file', 'relative_path' => 'Case.mp3'];
        };
        $values = null;
        $report = $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', function ($p, $a, $c) use (&$values): void { $values = $this->consumeAll($p, $a, $c); });
        self::assertSame(['Case.mp3', 'case.mp3'], $values[0]);
        self::assertCount(2, $values[1]); self::assertSame($values[1][0], $values[1][1]);
        self::assertSame(2, $report['consumed_paths']); self::assertSame(2, $report['consumed_associations']); $this->assertClosed();
    }

    public function testPhysicalSourceFailureIsPreservedAndClosesOperationStreams(): void
    {
        $owner = $this->owner([], 1); $error = new \DomainException('physical source');
        $owner->replaceObservations = static function ($source) use ($error): \Generator {
            yield from $source; yield ['type' => 'file', 'relative_path' => 'one.mp3']; throw $error;
        };
        $this->expectExceptionObject($error);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => null); }
        finally { $this->assertClosed(); }
    }

    public function testUncertainUrlsAndPhysicalErrorsRemainVisibleAndNeverCertifyStorage(): void
    {
        $this->fixture('one.mp3'); $owner = $this->owner([self::row(1, null, 'http://local.test/emissions/a.mp3?secret=hidden')]);
        $owner->replaceObservations = static function ($source): \Generator { yield from $source; yield ['type' => 'error', 'relative_path' => 'blocked', 'reason' => 'unreadable_directory']; };
        $report = $owner->withSortedInputs($this->library, $this->context('web_full'), static fn () => 'g1', static function ($p, $a, $c): void {
            foreach ($p as $unused) {} foreach ($a as $unused) {} self::assertFalse($c['references']['references_complete']); self::assertArrayNotHasKey('storage_decision', $c);
        });
        self::assertSame(1, $report['physical_collection']['error_count']); self::assertCount(1, $report['physical_errors']);
        self::assertCount(1, $report['sql_export']['uncertainties']); self::assertFalse($report['absence_conclusions_allowed']);
        self::assertStringNotContainsString('hidden', json_encode($report, JSON_THROW_ON_ERROR));
    }

    /** @dataProvider interruptionStages */
    public function testInterruptionNeverDeliversOrPublishesIncompleteInputs(string $stage): void
    {
        foreach (['a.mp3', 'b.mp3', 'c.mp3', 'd.mp3', 'e.mp3'] as $path) { $this->fixture($path); }
        $owner = $this->owner([self::row(1, 'a.mp3'), self::row(2, 'b.mp3'), self::row(3, 'c.mp3'), self::row(4, 'd.mp3'), self::row(5, 'e.mp3')], 1);
        $called = false;
        $report = $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static function () use (&$called): void { $called = true; },
            static fn (string $current, array $progress): bool => $current !== $stage);
        self::assertSame('interrupted', $report['status']); self::assertFalse($called); $this->assertClosed();
    }
    public static function interruptionStages(): array { return [['sql'], ['physical'], ['sql_merge'], ['physical_merge']]; }

    public function testPhysicalDiagnosticOverflowIsExplicit(): void
    {
        $owner = $this->owner([], 2, 1024, 0);
        $owner->replaceObservations = static function ($source): \Generator { yield from $source; yield ['type' => 'error', 'relative_path' => '', 'reason' => 'unavailable']; };
        $this->expectException(\LengthException::class);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => null); } finally { $this->assertClosed(); }
    }

    public function testPhysicalBufferRejectsOversizedPath(): void
    {
        $this->fixture('long-name.mp3'); $owner = $this->owner([], 2, 2);
        $this->expectException(\UnexpectedValueException::class);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => null); } finally { $this->assertClosed(); }
    }

    public function testDuplicatePhysicalObservationIsNotSilentlyDeduplicated(): void
    {
        $owner = $this->owner([], 1);
        $owner->replaceObservations = static function ($source): \Generator { yield from $source; yield ['type' => 'file', 'relative_path' => 'same.mp3']; yield ['type' => 'file', 'relative_path' => 'same.mp3']; };
        $this->expectException(\UnexpectedValueException::class);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => null); } finally { $this->assertClosed(); }
    }

    public function testSqlExceptionRemainsOriginal(): void
    {
        $error = new \DomainException('SQL'); $owner = $this->owner(static function () use ($error): array { throw $error; });
        $this->expectExceptionObject($error);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => null); } finally { $this->assertClosed(); }
    }

    /** @dataProvider corruptKinds */
    public function testLastTrailerCorruptionCannotProduceSuccess(string $kind): void
    {
        $this->fixture('one.mp3'); $owner = $this->owner([self::row(1, 'one.mp3')]);
        $owner->beforeConsume = function ($p, $a) use ($kind): void {
            $id = $kind === 'paths' ? $p : $a; $run = $this->runs()[$id]; $bytes = file_get_contents($run['path']);
            $bytes[strlen($bytes) - 1] = ~$bytes[strlen($bytes) - 1]; file_put_contents($run['path'], $bytes);
        };
        $this->expectException(\UnexpectedValueException::class);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', function ($p, $a): void { $this->consumeAll($p, $a, []); }); }
        finally { $this->assertClosed(); }
    }
    public static function corruptKinds(): array { return [['paths'], ['associations']]; }

    public function testPartialConsumptionIsNeverSuccessAndEscapedIteratorsAreRevoked(): void
    {
        $this->fixture('one.mp3'); $owner = $this->owner([self::row(1, 'one.mp3')]); $escaped = null;
        try {
            $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static function ($p, $a) use (&$escaped): void { $p->rewind(); self::assertSame('one.mp3', $p->current()); $escaped = $p; });
            self::fail('Partial consumption accepted.');
        } catch (\LogicException) { $this->assertClosed(); }
        foreach (['current', 'valid', 'next', 'rewind', 'key'] as $method) {
            try { $escaped->$method(); self::fail('Escaped iterator usable after scope.'); } catch (\LogicException) { self::assertTrue(true); }
        }
    }

    public function testConsumerFalseInterruptsWithoutDraining(): void
    {
        $this->fixture('one.mp3'); $owner = $this->owner([self::row(1, 'one.mp3')]);
        $report = $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => false);
        self::assertSame('interrupted', $report['status']); self::assertSame(0, $report['consumed_paths']); $this->assertClosed();
    }

    public function testConsumerErrorSurvivesTwoSecondaryClosingErrors(): void
    {
        $this->fixture('one.mp3'); $owner = $this->owner([self::row(1, 'one.mp3')]);
        $primary = new \DomainException('consumer'); $secondary = new \RuntimeException('close'); $owner->closingError = $secondary;
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static function () use ($primary): void { throw $primary; }); self::fail('Primary error hidden.'); }
        catch (\DomainException $actual) { self::assertSame($primary, $actual); }
        self::assertContains($secondary, $owner->getSecondaryErrors()); $this->assertClosed();
    }

    public function testClosingFailureAfterFullConsumptionStillPreventsSuccess(): void
    {
        $this->fixture('one.mp3'); $owner = $this->owner([self::row(1, 'one.mp3')]); $error = new \RuntimeException('close'); $owner->closingError = $error;
        $this->expectExceptionObject($error);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', function ($p, $a): void { $this->consumeAll($p, $a, []); }); }
        finally { $this->assertClosed(); }
    }

    public function testPassBudgetIsEnforced(): void
    {
        $owner = $this->owner([self::row(1, 'a'), self::row(2, 'b'), self::row(3, 'c'), self::row(4, 'd'), self::row(5, 'e')], 1, 1024, 10, 1);
        $this->expectException(\LengthException::class);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => null); } finally { $this->assertClosed(); }
    }

    public function testWorkspaceDescriptorBudgetIsNotBypassed(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root.'/work', 2, 100);
        $owner = $this->owner([self::row(1, 'a'), self::row(2, 'b')], 1);
        $this->expectException(\LengthException::class);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => null); } finally { $this->assertClosed(); }
    }

    public function testWorkspaceCumulativeFileBudgetIsNotBypassed(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root.'/work', 5, 3);
        $owner = $this->owner([self::row(1, 'a'), self::row(2, 'b')], 1);
        $this->expectException(\LengthException::class);
        try { $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static fn () => null); } finally { $this->assertClosed(); }
    }

    public function testObservationOnlyPropagatesNullsAndModeToScopedConsumer(): void
    {
        $this->fixture('one.mp3'); $owner = $this->owner([self::row(1, 'missing.mp3')]);
        $context = ['scope' => 'web_full', 'source' => 'scanner', 'evidence_id' => 'actual-collection'];
        $report = $owner->withSortedInputs($this->library, $context, null, static function ($p, $a, $c): void {
            foreach ($p as $unused) {} foreach ($a as $unused) {}
            self::assertSame('observation_only', $c['collection_mode']); self::assertArrayNotHasKey('storage_decision', $c);
            self::assertNull($c['physical_collection']['library_id']); self::assertNull($c['physical_collection']['generation_before']);
        }, null, 'observation_only');
        self::assertSame('completed', $report['status']); self::assertSame('observation_only', $report['collection_mode']);
        self::assertFalse($report['absence_conclusions_allowed']); self::assertNull($report['physical_collection']['storage_kind']); $this->assertClosed();
    }

    public function testUnknownPreparationModeDoesNotStartSql(): void
    {
        $exporter = $this->createMock(AudioInventoryAssociationExporter::class); $exporter->expects(self::never())->method('export');
        $owner = new AudioInventoryInputPreparation($this->workspace, $exporter, new AudioInventoryScanner($this->library),
            new AudioInventoryPathRunCodec(1024), new AudioInventoryAssociationRunCodec(1024), 2, 2, 1024, 8192, 10, 10);
        $this->expectException(\InvalidArgumentException::class);
        $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static function (): void { self::fail('Invalid mode delivered streams.'); }, null, 'typo');
    }

    public function testObservationOnlyInterruptionPreservesModeAndNullMetadata(): void
    {
        $this->fixture('one.mp3'); $owner = $this->owner();
        $report = $owner->withSortedInputs($this->library, ['scope' => 'test_subset', 'source' => 'scanner', 'evidence_id' => 'collection-2'], null,
            static function (): void { self::fail('Interrupted collection delivered streams.'); }, static fn ($stage) => $stage !== 'physical', 'observation_only');
        self::assertSame('interrupted', $report['status']); self::assertSame('observation_only', $report['collection_mode']);
        self::assertNull($report['physical_collection']['generation_after']); self::assertFalse($report['absence_conclusions_allowed']); $this->assertClosed();
    }

    public function testManyPagesAndBlocksRemainProgressive(): void
    {
        $owner = $this->owner(static function ($params): array {
            $result = []; for ($id = $params['afterId'] + 1; $id <= min(400, $params['afterId'] + $params['limit']); ++$id) { $result[] = self::row($id, sprintf('SYN/%04d.mp3', 401 - $id)); } return $result;
        }, 8);
        $owner->replaceObservations = static function ($source): \Generator {
            yield from $source;
            for ($i = 400; $i > 0; --$i) { yield ['type' => 'file', 'relative_path' => sprintf('SYN/%04d.mp3', $i)]; }
        };
        $baseline = memory_get_usage(); $growth = 0; $consumed = 0;
        $report = $owner->withSortedInputs($this->library, $this->context(), static fn () => 'g1', static function ($p, $a) use (&$consumed): void {
            foreach ($p as $unused) {} $previous = null;
            foreach ($a as $row) { if ($previous !== null && strcmp($previous, $row['relative_path']) > 0) { self::fail('Unsorted associations.'); } $previous = $row['relative_path']; ++$consumed; }
        }, static function ($stage, $progress) use (&$growth, $baseline): bool { $growth = max($growth, memory_get_usage() - $baseline); return true; });
        self::assertSame(400, $consumed); self::assertSame(400, $report['sql_sort']['records']); self::assertSame(400, $report['consumed_paths']);
        self::assertGreaterThan(1, count($report['sql_sort']['passes'])); self::assertLessThan(12 * 1024 * 1024, $growth); $this->assertClosed();
    }
}
