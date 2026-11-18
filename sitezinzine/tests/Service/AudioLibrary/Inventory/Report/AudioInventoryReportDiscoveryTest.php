<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory\Report;

use App\Service\AudioLibrary\Inventory\AudioInventoryReportDiscovery;
use App\Service\AudioLibrary\Inventory\AudioInventoryReportStore;
use App\Service\AudioLibrary\Inventory\AudioInventoryClassifier;
use PHPUnit\Framework\TestCase;

final class AudioInventoryReportDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-report-discovery-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        foreach (['private', 'public', 'other'] as $dir) { mkdir($this->root.'/'.$dir, 0700); }
    }

    protected function tearDown(): void
    {
        try { $this->remove($this->root); } finally { parent::tearDown(); }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
        foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { $this->remove($path.'/'.$entry); } }
        rmdir($path);
    }

    private function profile(?string $root = null): array
    {
        // No MP3 root, SQL connection, URL configuration or collector is needed.
        return ['report_root' => $root ?? $this->root.'/private', 'budgets' => [
            'report_page_size' => 2, 'max_report_page_bytes' => 16384, 'max_manifest_bytes' => 65536, 'max_report_pages' => 100]];
    }

    private function service(int $limit = 30, ?array $profiles = null): AudioInventoryReportDiscovery
    {
        return new AudioInventoryReportDiscovery($profiles ?? ['fixture' => $this->profile()], $this->root.'/public', $limit);
    }

    private function store(): AudioInventoryReportStore
    {
        return new AudioInventoryReportStore($this->root.'/private', $this->root.'/public', 2, 16384, 65536, 100);
    }

    private function publish(bool $empty = false): array
    {
        $store = $this->store(); $store->begin();
        if (!$empty) {
            $row = (new AudioInventoryClassifier())->classify('emissionsMp3', 'fixture.mp3', true, true, []) + [
                'physical_observed' => true, 'present' => true, 'associations' => [],
                'references_complete' => true, 'absence_conclusions_allowed' => false, 'scope' => 'test_subset'];
            $store->append($row);
        }
        $n = $empty ? 0 : 1;
        return $store->publish(['preparation' => ['status' => 'completed', 'consumed_paths' => $n, 'consumed_associations' => 0],
            'reconciliation' => ['status' => 'completed', 'processed_paths' => $n, 'physical_paths' => $n,
                'referenced_paths' => 0, 'results_emitted' => $n], 'associations_seen' => 0, 'scope' => 'test_subset',
            'restrictions' => ['references_complete' => true, 'physical_collection_complete' => true,
                'storage_certified' => false, 'absence_conclusions_allowed' => false, 'orphan_candidate_is_deletion_permission' => false]]);
    }

    private function directory(string $id): string { return $this->root.'/private/inventory-'.$id; }

    public function testPublishedReportUsesOnlyManifestAndNeverReadsResultPages(): void
    {
        $m = $this->publish(); $id = $m['inventory_id'];
        // Discovery must still succeed when result pages are corrupt or absent.
        unlink($this->directory($id).'/page-00000001.json');
        $before = hash_file('sha256', $this->directory($id).'/manifest.json');
        $result = $this->service()->discover('fixture');
        self::assertSame('completed', $result['status']); self::assertFalse($result['partial']);
        self::assertSame(1, $result['entries_examined']); self::assertCount(1, $result['reports']);
        self::assertSame([], $result['anomalies']); self::assertSame(0, $result['unpublished_count']);
        $summary = $result['reports'][0];
        self::assertSame($id, $summary['inventory_id']); self::assertNull($summary['inventory_date']);
        self::assertSame('test_subset', $summary['scope']); self::assertSame(1, $summary['result_count']);
        self::assertSame($m['classification_counts'], $summary['classification_counts']);
        self::assertFalse($summary['restrictions']['absence_conclusions_allowed']);
        self::assertArrayNotHasKey('preparation', $summary);
        $read = $this->service()->readManifest('fixture', $id);
        self::assertSame('published', $read['status']); self::assertSame($m, $read['manifest']);
        self::assertNull($read['inventory_date']);
        self::assertSame($before, hash_file('sha256', $this->directory($id).'/manifest.json'));
        self::assertStringNotContainsString($this->root, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testEmptyPublishedReportIsNotConfusedWithProvisionalReport(): void
    {
        $m = $this->publish(true);
        $summary = $this->service()->discover('fixture')['reports'][0];
        self::assertSame(0, $summary['page_count']); self::assertSame(0, $summary['result_count']);
        self::assertSame('published', $this->service()->readManifest('fixture', $m['inventory_id'])['status']);
    }

    public function testProvisionalPendingManifestIsIgnoredAndReportedSeparately(): void
    {
        $store = $this->store(); $id = $store->begin(); $store->abort();
        file_put_contents($this->directory($id).'/manifest.pending', 'not published');
        $result = $this->service()->discover('fixture');
        self::assertSame([], $result['reports']); self::assertSame([], $result['anomalies']);
        self::assertSame(1, $result['unpublished_count']);
        self::assertSame('unpublished', $this->service()->readManifest('fixture', $id)['status']);
    }

    public function testCorruptManifestIsAnAnomalyRatherThanAnAbsentReport(): void
    {
        $m = $this->publish(); file_put_contents($this->directory($m['inventory_id']).'/manifest.json', '{secret broken');
        $r = $this->service()->discover('fixture');
        self::assertSame([], $r['reports']); self::assertCount(1, $r['anomalies']);
        self::assertSame('corrupt_or_unreadable', $r['anomalies'][0]['status']);
        self::assertSame('invalid_manifest', $r['anomalies'][0]['reason']);
        self::assertStringNotContainsString('secret', json_encode($r, JSON_THROW_ON_ERROR));
        self::assertSame('corrupt_or_unreadable', $this->service()->readManifest('fixture', $m['inventory_id'])['status']);
    }

    public function testUnreadableManifestFailureIsSanitizedDeterministically(): void
    {
        $m = $this->publish();
        // Root inside Docker can bypass chmod; inject only the actual read failure.
        $service = new class(['fixture' => $this->profile()], $this->root.'/public', 30) extends AudioInventoryReportDiscovery {
            protected function readStoredManifest(AudioInventoryReportStore $store, string $id): array {
                throw new \RuntimeException('/secret/path connection password');
            }
        };
        $r = $service->discover('fixture');
        self::assertSame('corrupt_or_unreadable', $r['anomalies'][0]['status']);
        self::assertSame('manifest_read_failed', $r['anomalies'][0]['reason']);
        self::assertStringNotContainsString('password', json_encode($r, JSON_THROW_ON_ERROR));
        self::assertSame('corrupt_or_unreadable', $service->readManifest('fixture', $m['inventory_id'])['status']);
    }

    public function testMissingReportAndMissingRootAreExplicitlyUnavailable(): void
    {
        $id = str_repeat('a', 32);
        self::assertSame('unavailable', $this->service()->readManifest('fixture', $id)['status']);
        $service = $this->service(30, ['fixture' => $this->profile($this->root.'/missing')]);
        $r = $service->discover('fixture');
        self::assertSame('unavailable', $r['status']); self::assertTrue($r['partial']);
        self::assertSame('unavailable', $service->readManifest('fixture', $id)['status']);
        self::assertDirectoryDoesNotExist($this->root.'/missing');
    }

    /** @dataProvider invalidIds */
    public function testInvalidIdentifierIsRejectedBeforeInspection(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->readManifest('fixture', $id);
    }
    public static function invalidIds(): array { return [[''], ['../outside'], ['/absolute'], [str_repeat('A', 32)], [str_repeat('a', 32).'/x']]; }

    public function testUnknownProfileAndEmptyConfiguredProfilesNeverFallback(): void
    {
        foreach ([$this->service(), $this->service(30, [])] as $service) {
            try { $service->discover($this->root.'/other'); self::fail('Profile path was accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    /** @dataProvider unsafeRoots */
    public function testUnsafeConfiguredRootIsRefused(string $suffix): void
    {
        $root = $suffix === 'relative' ? 'private' : $this->root.'/'.$suffix;
        $this->expectException(\InvalidArgumentException::class);
        $this->service(30, ['fixture' => $this->profile($root)])->discover('fixture');
    }
    public static function unsafeRoots(): array { return [['public'], ['public/../private'], ['relative']]; }

    public function testIncompleteProfileAndInvalidDiscoveryBudgetAreRefused(): void
    {
        try { $this->service(0); self::fail('Zero discovery budget accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        $p = $this->profile(); unset($p['budgets']['max_manifest_bytes']);
        $this->expectException(\InvalidArgumentException::class);
        $this->service(30, ['fixture' => $p])->discover('fixture');
    }

    public function testEveryRootEntryCountsAgainstBudgetIncludingInvalidNames(): void
    {
        for ($i = 0; $i < 6; ++$i) { mkdir($this->root.'/private/not-an-inventory-'.$i, 0700); }
        $r = $this->service(2)->discover('fixture');
        self::assertSame('partial', $r['status']); self::assertTrue($r['partial']);
        self::assertSame('entry_budget_reached', $r['reason']); self::assertSame(2, $r['entries_examined']);
        self::assertSame([], $r['reports']);
    }

    public function testBudgetIsConservativeEvenIfItExactlyMatchesDirectorySize(): void
    {
        $this->publish(true);
        $r = $this->service(1)->discover('fixture');
        self::assertTrue($r['partial']); self::assertSame(1, $r['entries_examined']); self::assertCount(1, $r['reports']);
    }

    public function testEmptyRootIsCompletedWithoutInventingInventoryDate(): void
    {
        $r = $this->service()->discover('fixture');
        self::assertSame('completed', $r['status']); self::assertFalse($r['partial']);
        self::assertSame(0, $r['entries_examined']); self::assertSame([], $r['reports']);
    }

    public function testInvalidNamesAndNestedInventoriesAreNotTraversed(): void
    {
        mkdir($this->root.'/private/foreign', 0700);
        mkdir($this->root.'/private/foreign/inventory-'.str_repeat('a', 32), 0700);
        mkdir($this->root.'/private/inventory-'.str_repeat('B', 32), 0700);
        $r = $this->service()->discover('fixture');
        self::assertSame(2, $r['entries_examined']); self::assertSame([], $r['reports']);
        self::assertSame([], $r['anomalies']); self::assertSame(0, $r['unpublished_count']);
    }

    public function testManifestDisappearingDuringReadIsUnavailableRatherThanCorrupt(): void
    {
        $m = $this->publish(true);
        $service = new class(['fixture' => $this->profile()], $this->root.'/public', 30) extends AudioInventoryReportDiscovery {
            public string $manifest;
            protected function readStoredManifest(AudioInventoryReportStore $store, string $id): array {
                unlink($this->manifest); return parent::readStoredManifest($store, $id);
            }
        };
        $service->manifest = $this->directory($m['inventory_id']).'/manifest.json';
        self::assertSame('unavailable', $service->discover('fixture')['anomalies'][0]['status']);
    }

    public function testSymbolicReportAndManifestAreNeverFollowed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') { self::markTestSkipped('Requires POSIX symbolic links.'); }
        $m = $this->publish(true); $id = str_repeat('b', 32);
        symlink($this->directory($m['inventory_id']), $this->directory($id));
        $r = $this->service()->discover('fixture');
        self::assertCount(1, $r['reports']); self::assertCount(1, $r['anomalies']);
        self::assertSame('unsafe_entry', $r['anomalies'][0]['reason']);
        $path = $this->directory($m['inventory_id']).'/manifest.json';
        rename($path, $this->root.'/other/manifest.json'); symlink($this->root.'/other/manifest.json', $path);
        self::assertSame('unsafe_entry', $this->service()->readManifest('fixture', $m['inventory_id'])['reason']);
    }

    public function testSymbolicRootOrAncestorIsRefused(): void
    {
        if (PHP_OS_FAMILY === 'Windows') { self::markTestSkipped('Requires POSIX symbolic links.'); }
        symlink($this->root, $this->root.'/alias');
        $this->expectException(\InvalidArgumentException::class);
        $this->service(30, ['fixture' => $this->profile($this->root.'/alias/private')])->discover('fixture');
    }

    public function testDirectoryOpenFailureCannotProduceAnExhaustiveEmptyList(): void
    {
        $service = new class(['fixture' => $this->profile()], $this->root.'/public', 30) extends AudioInventoryReportDiscovery {
            protected function openDirectory(string $root): mixed { return false; }
        };
        $r = $service->discover('fixture');
        self::assertSame('unavailable', $r['status']); self::assertTrue($r['partial']);
    }

    public function testProfileNamesNeedNeitherFilesystemAccessNorCollectionConfiguration(): void
    {
        $reader = new AudioInventoryReportDiscovery(['fixture' => ['report_root' => '/not-read']], '/not-read-public', 2);
        self::assertSame(['fixture'], $reader->getProfiles());
        self::assertSame([], (new AudioInventoryReportDiscovery([], '/not-read', 2))->getProfiles());
    }

    public function testNativePageBridgePreservesResultsAndStoreIntegrityChecks(): void
    {
        $manifest = $this->publish(); $id = $manifest['inventory_id'];
        $reader = $this->service();
        self::assertSame($this->store()->readPage($id, 1), $reader->readPage('fixture', $id, 1));
        self::assertNull($reader->discover('fixture')['reports'][0]['collection_mode']);
        file_put_contents($this->directory($id).'/page-00000001.json', 'corrupt');
        $this->expectException(\UnexpectedValueException::class);
        $reader->readPage('fixture', $id, 1);
    }

    public function testNativePageBridgeRefusesInvalidParametersAndEmptyReportPage(): void
    {
        $id = $this->publish(true)['inventory_id'];
        foreach ([['../outside', 1], [$id, 0]] as [$candidate, $page]) {
            try { $this->service()->readPage('fixture', $candidate, $page); self::fail('Unsafe page read accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $this->expectException(\OutOfBoundsException::class);
        $this->service()->readPage('fixture', $id, 1);
    }
}
