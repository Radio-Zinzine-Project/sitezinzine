<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory\Report;

use App\Service\AudioLibrary\Inventory\AudioInventoryReportGenerator;
use App\Service\AudioLibrary\Inventory\AudioInventoryReportStore;
use App\Service\AudioLibrary\Inventory\AudioInventoryInputPreparation;
use App\Service\AudioLibrary\Inventory\AudioInventoryReconciler;
use App\Service\AudioLibrary\Inventory\AudioInventoryClassifier;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryScanner;
use App\Service\AudioLibrary\Inventory\AudioInventoryReferenceCollector;
use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationExporter;
use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryIncrementalRunCatalogWriter;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunCatalog;
use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

final class AudioInventoryReportGeneratorTest extends TestCase
{
    private string $root;
    private AudioInventoryTemporaryWorkspace $workspace;
    private AudioInventoryReportStore $store;
    private int $level = 0;
    private bool $connected = true;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-report-generator-'.bin2hex(random_bytes(12)); mkdir($this->root, 0700);
        foreach (['public', 'private', 'work', 'library'] as $name) { mkdir($this->root.'/'.$name, 0700); }
        foreach (['associated.mp3', 'multiple.mp3', 'orphan.mp3', 'Case.mp3', 'case.mp3', 'a.mp3', 'b.mp3', 'c.mp3', 'd.mp3'] as $path) { file_put_contents($this->root.'/library/'.$path, 'synthetic; unchanged'); }
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root.'/work', 5, 300);
        $this->store = new AudioInventoryReportStore($this->root.'/private', $this->root.'/public', 2, 32768, 131072, 100);
    }
    protected function tearDown(): void { try { $this->workspace->cleanup(); } finally { $this->remove($this->root); parent::tearDown(); } }
    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
        foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { $this->remove($path.'/'.$entry); } } rmdir($path);
    }
    private function context(string $scope = 'test_subset'): array
    {
        return ['library_id' => 'fixture', 'storage_kind' => 'synthetic', 'generation' => 'g1', 'scope' => $scope, 'source' => 'test', 'evidence_id' => 'fixture'];
    }
    private function preparation(bool $uncertain = false): AudioInventoryInputPreparation
    {
        $paths = ['associated.mp3', 'multiple.mp3', 'multiple.mp3', 'missing.mp3', 'Case.mp3', 'case.mp3', 'a.mp3', 'b.mp3', 'c.mp3'];
        $rows = []; foreach ($paths as $i => $path) { $rows[] = ['id' => $i + 1, 'thumbnail_mp3' => $path, 'url' => $i === 1 ? 'http://local.test/emissions/multiple.mp3' : null]; }
        if ($uncertain) { $rows[0]['url'] = 'http://local.test/emissions/other.mp3?secret=hidden'; }
        $db = $this->createMock(Connection::class);
        $db->method('getTransactionNestingLevel')->willReturnCallback(fn () => $this->level); $db->method('isConnected')->willReturnCallback(fn () => $this->connected);
        $db->method('isAutoCommit')->willReturn(true); $db->method('getDatabasePlatform')->willReturn(new MySQLPlatform()); $db->method('fetchOne')->willReturn('InnoDB');
        $db->method('executeStatement')->willReturn(0); $db->method('beginTransaction')->willReturnCallback(function (): void { $this->level = 1; });
        $db->method('commit')->willReturnCallback(function (): void { $this->level = 0; }); $db->method('rollBack')->willReturnCallback(function (): void { $this->level = 0; });
        $db->method('close')->willReturnCallback(function (): void { $this->connected = false; });
        $db->method('fetchAllAssociative')->willReturnCallback(static fn ($sql, $params): array => array_slice($rows, $params['afterId'], $params['limit']));
        foreach (['insert', 'update', 'delete'] as $method) { $db->expects(self::never())->method($method); }
        $exporter = new AudioInventoryAssociationExporter(new AudioInventoryReferenceCollector($db, new AudioLocalUrlResolver(['http://local.test/emissions/'])), $this->workspace,
            new AudioInventoryAssociationRunCodec(4096), new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 128),
            new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(128)), 10, 2);
        return new class($this->workspace, $exporter, new AudioInventoryScanner($this->root.'/library'), new AudioInventoryPathRunCodec(4096), new AudioInventoryAssociationRunCodec(4096), 2, 1, 1024, 8192, 10, 20) extends AudioInventoryInputPreparation {
            public bool $physicalError = false; public bool $corruptLate = false;
            protected function scanObservations(string $root): iterable {
                foreach (parent::scanObservations($root) as $row) { yield $row; }
                if ($this->physicalError) { yield ['type' => 'error', 'relative_path' => 'blocked', 'reason' => 'unreadable_directory']; }
            }
            protected function beforeConsumption(?string $p, ?string $a): void {
                if ($this->corruptLate) { throw new \DomainException('late preparation failure'); }
            }
        };
    }
    private function generator(AudioInventoryInputPreparation $preparation, int $associationLimit = 10): AudioInventoryReportGenerator
    {
        return new AudioInventoryReportGenerator($preparation, new AudioInventoryReconciler(new AudioInventoryClassifier(), $associationLimit), $this->store);
    }

    public function testRealMultipassReconciliationPublishesExactlyClassifiedPagedRows(): void
    {
        $manifest = $this->generator($this->preparation())->run($this->root.'/library', $this->context(), static fn () => 'g1');
        self::assertSame('published', $manifest['publication']); self::assertGreaterThan(2, $manifest['page_count']);
        self::assertGreaterThan(1, count($manifest['report']['preparation']['sql_sort']['passes']));
        self::assertGreaterThan(1, count($manifest['report']['preparation']['physical_sort']['passes']));
        $results = [];
        for ($page = 1; $page <= $manifest['page_count']; ++$page) { foreach ($this->store->readPage($manifest['inventory_id'], $page)['results'] as $row) { $results[$row['relative_path']] = $row; } }
        self::assertSame('associated', $results['associated.mp3']['classification']); self::assertSame('multiple_associations', $results['multiple.mp3']['classification']);
        self::assertCount(3, $results['multiple.mp3']['associations']); self::assertSame('orphan_candidate', $results['orphan.mp3']['classification']);
        self::assertSame('indeterminate', $results['missing.mp3']['classification']); self::assertNull($results['missing.mp3']['present']);
        self::assertArrayHasKey('Case.mp3', $results); self::assertArrayHasKey('case.mp3', $results);
        self::assertSame(count($results), $manifest['result_count']); self::assertFalse($manifest['report']['restrictions']['storage_certified']);
        self::assertFalse($manifest['report']['restrictions']['absence_conclusions_allowed']); self::assertFalse($this->connected); self::assertSame(0, $this->level);
        self::assertSame('synthetic; unchanged', file_get_contents($this->root.'/library/associated.mp3'));
        $this->workspace->cleanup(); self::assertSame($manifest, $this->store->readManifest($manifest['inventory_id']));
    }

    public function testUncertainUrlKeepsReferenceCompletenessFalseInManifest(): void
    {
        $manifest = $this->generator($this->preparation(true))->run($this->root.'/library', $this->context(), static fn () => 'g1');
        self::assertFalse($manifest['report']['restrictions']['references_complete']); self::assertSame(0, $manifest['classification_counts']['orphan_candidate']);
        self::assertCount(1, $manifest['report']['preparation']['sql_export']['uncertainties']);
        self::assertStringNotContainsString('hidden', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    public function testPhysicalErrorsAndWebFullNeverFabricateCertification(): void
    {
        $preparation = $this->preparation(); $preparation->physicalError = true;
        $manifest = $this->generator($preparation)->run($this->root.'/library', $this->context('web_full'), static fn () => 'g1');
        self::assertSame('completed', $manifest['status']); self::assertSame('web_full', $manifest['report']['scope']);
        self::assertFalse($manifest['report']['restrictions']['physical_collection_complete']); self::assertFalse($manifest['report']['restrictions']['storage_certified']);
        self::assertFalse($manifest['report']['restrictions']['absence_conclusions_allowed']); self::assertCount(1, $manifest['report']['preparation']['physical_errors']);
    }

    /** @dataProvider cancelStages */
    public function testCancellationAtPreparationOrReconciliationNeverPublishes(string $stage): void
    {
        $report = $this->generator($this->preparation())->run($this->root.'/library', $this->context(), static fn () => 'g1', static fn ($current, $progress): bool => $current !== $stage);
        self::assertSame('interrupted', $report['status']); self::assertSame('unpublished', $report['publication']);
        self::assertFileDoesNotExist($this->root.'/private/inventory-'.$report['inventory_id'].'/manifest.json');
        $this->expectException(\RuntimeException::class); $this->store->readManifest($report['inventory_id']);
    }
    public static function cancelStages(): array { return [['sql'], ['physical'], ['reconciliation']]; }

    public function testReconcilerExceptionCannotPublish(): void
    {
        $this->expectException(\LengthException::class);
        try { $this->generator($this->preparation(), 1)->run($this->root.'/library', $this->context(), static fn () => 'g1'); }
        finally { self::assertSame([], glob($this->root.'/private/inventory-*/manifest.json')); }
    }

    /** @dataProvider observationScopes */
    public function testObservationOnlyPublishesAndReloadsUnknownMetadataWithoutCertifying(string $scope): void
    {
        $context = ['library_id' => null, 'storage_kind' => null, 'generation' => null, 'scope' => $scope, 'source' => 'scanner', 'evidence_id' => 'actual-inventory'];
        $manifest = $this->generator($this->preparation())->run($this->root.'/library', $context, null, null, 'observation_only');
        $reader = new AudioInventoryReportStore($this->root.'/private', $this->root.'/public', 2, 32768, 131072, 100);
        self::assertSame($manifest, $reader->readManifest($manifest['inventory_id']));
        self::assertSame('observation_only', $manifest['report']['collection_mode']);
        $physical = $manifest['report']['preparation']['physical_collection'];
        self::assertSame('observation_only', $physical['collection_mode']);
        foreach (['library_id', 'storage_kind', 'generation', 'generation_before', 'generation_after'] as $key) { self::assertNull($physical[$key]); }
        self::assertSame('actual-inventory', $physical['evidence_id']); self::assertSame($scope, $physical['scope']);
        foreach (['storage_certified', 'absence_conclusions_allowed', 'orphan_candidate_is_deletion_permission'] as $key) { self::assertFalse($manifest['report']['restrictions'][$key]); }
        $found = false;
        for ($page = 1; $page <= $manifest['page_count']; ++$page) {
            foreach ($reader->readPage($manifest['inventory_id'], $page)['results'] as $row) {
                if ($row['relative_path'] === 'missing.mp3') { $found = true; self::assertSame('indeterminate', $row['classification']); self::assertNull($row['present']); }
            }
        }
        self::assertTrue($found); self::assertSame('synthetic; unchanged', file_get_contents($this->root.'/library/associated.mp3'));
    }
    public static function observationScopes(): array { return [['test_subset'], ['web_full']]; }

    public function testObservationOnlyKnownValuesAndObservedGenerationsRemainDistinct(): void
    {
        $calls = 0;
        $manifest = $this->generator($this->preparation())->run($this->root.'/library', $this->context(),
            static function () use (&$calls): string { return ++$calls === 1 ? 'actual-before' : 'actual-after'; }, null, 'observation_only');
        $physical = $manifest['report']['preparation']['physical_collection'];
        self::assertSame('fixture', $physical['library_id']); self::assertSame('g1', $physical['generation']);
        self::assertSame('actual-before', $physical['generation_before']); self::assertSame('actual-after', $physical['generation_after']);
        self::assertFalse($manifest['report']['restrictions']['storage_certified']);
    }

    public function testObservationOnlyUncertaintyAndScannerErrorsRemainRestricted(): void
    {
        $preparation = $this->preparation(true); $preparation->physicalError = true;
        $manifest = $this->generator($preparation)->run($this->root.'/library', ['scope' => 'web_full', 'source' => 'scanner', 'evidence_id' => 'actual-3'], null, null, 'observation_only');
        self::assertFalse($manifest['report']['restrictions']['references_complete']); self::assertFalse($manifest['report']['restrictions']['physical_collection_complete']);
        self::assertSame(0, $manifest['classification_counts']['broken_reference']); self::assertSame(0, $manifest['classification_counts']['orphan_candidate']);
        self::assertCount(1, $manifest['report']['preparation']['physical_errors']);
    }

    public function testObservationOnlyInterruptionCannotPublish(): void
    {
        $report = $this->generator($this->preparation())->run($this->root.'/library', ['scope' => 'test_subset', 'source' => 'scanner', 'evidence_id' => 'actual-4'], null,
            static fn ($stage) => $stage !== 'reconciliation', 'observation_only');
        self::assertSame('interrupted', $report['status']); self::assertSame('unpublished', $report['publication']);
        self::assertSame([], glob($this->root.'/private/inventory-*/manifest.json'));
    }

    public function testUnknownGeneratorModeIsRefusedBeforeStartingPreparationOrStorage(): void
    {
        $preparation = $this->createMock(AudioInventoryInputPreparation::class); $preparation->expects(self::never())->method('withSortedInputs');
        $this->expectException(\InvalidArgumentException::class);
        try { $this->generator($preparation)->run($this->root.'/library', $this->context(), static fn () => 'g1', null, 'typo'); }
        finally { self::assertSame([], glob($this->root.'/private/inventory-*')); }
    }

    public function testLateExceptionAfterSeveralPagesPreservesOriginalAndProvisionalPages(): void
    {
        $failure = new \DomainException('late consumer'); $seen = 0;
        $this->expectExceptionObject($failure);
        try {
            $this->generator($this->preparation())->run($this->root.'/library', $this->context(), static fn () => 'g1',
                static function ($stage, $progress) use (&$seen, $failure): bool { if ($stage === 'reconciliation' && ++$seen === 7) { throw $failure; } return true; });
        } finally { self::assertSame([], glob($this->root.'/private/inventory-*/manifest.json')); self::assertGreaterThanOrEqual(3, count(glob($this->root.'/private/inventory-*/page-*.json'))); }
    }
}
