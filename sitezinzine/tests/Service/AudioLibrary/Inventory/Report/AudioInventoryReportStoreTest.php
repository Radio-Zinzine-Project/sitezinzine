<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory\Report;

use App\Service\AudioLibrary\Inventory\AudioInventoryReportStore;
use App\Service\AudioLibrary\Inventory\AudioInventoryClassifier;
use PHPUnit\Framework\TestCase;

final class AudioInventoryReportStoreTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-reports-'.bin2hex(random_bytes(12)); mkdir($this->root, 0700);
        mkdir($this->root.'/private'); mkdir($this->root.'/public');
    }
    protected function tearDown(): void { $this->remove($this->root); parent::tearDown(); }
    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
        foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { $this->remove($path.'/'.$entry); } } rmdir($path);
    }
    private function store(int $size = 2, int $bytes = 16384, int $manifest = 65536, int $pages = 100): AudioInventoryReportStore
    {
        return new AudioInventoryReportStore($this->root.'/private', $this->root.'/public', $size, $bytes, $manifest, $pages);
    }
    public static function row(string $path = 'a.mp3', ?bool $present = true, array $associations = [], bool $complete = true): array
    {
        return (new AudioInventoryClassifier())->classify('emissionsMp3', $path, $present, $complete, $associations) + [
            'physical_observed' => $present === true, 'present' => $present, 'associations' => $associations,
            'references_complete' => $complete, 'absence_conclusions_allowed' => false, 'scope' => 'test_subset'];
    }
    public static function report(array $rows): array
    {
        $physical = $references = $associations = 0;
        foreach ($rows as $row) { $physical += (int)$row['physical_observed']; $references += (int)($row['associations'] !== []); $associations += count($row['associations']); }
        return ['preparation' => ['status' => 'completed', 'consumed_paths' => $physical, 'consumed_associations' => $associations],
            'reconciliation' => ['status' => 'completed', 'processed_paths' => count($rows), 'physical_paths' => $physical, 'referenced_paths' => $references, 'results_emitted' => count($rows)],
            'associations_seen' => $associations, 'scope' => 'test_subset', 'restrictions' => ['references_complete' => true,
                'physical_collection_complete' => true, 'storage_certified' => false, 'absence_conclusions_allowed' => false,
                'orphan_candidate_is_deletion_permission' => false]];
    }
    private function directory(string $id): string { return $this->root.'/private/inventory-'.$id; }

    public function testPagedBinarySafeRoundTripAndManifestFromNewStoreInstance(): void
    {
        $store = $this->store(); $id = $store->begin();
        $rows = [self::row("Été/\xff\n.MP3"), self::row('A'), self::row('a'), self::row('d'), self::row('z')];
        foreach ($rows as $row) { $store->append($row); }
        try { $store->readManifest($id); self::fail('Unpublished inventory exposed.'); } catch (\RuntimeException) { self::assertTrue(true); }
        $manifest = $store->publish(self::report($rows));
        self::assertSame('published', $manifest['publication']); self::assertSame('completed', $manifest['status']);
        self::assertSame($id, $manifest['inventory_id']); self::assertSame(5, $manifest['result_count']); self::assertSame(3, $manifest['page_count']);
        self::assertSame(5, $manifest['classification_counts']['orphan_candidate']);
        $reader = $this->store(); self::assertSame($manifest, $reader->readManifest($id));
        self::assertSame(array_slice($rows, 0, 2), $reader->readPage($id, 1)['results']);
        self::assertSame(array_slice($rows, 2, 2), $reader->readPage($id, 2)['results']);
        self::assertSame([$rows[4]], $reader->readPage($id, 3)['results']);
        self::assertNull($reader->readPage($id, 3)['next_page']); self::assertSame(2, $reader->readPage($id, 1)['next_page']);
        self::assertStringNotContainsString("\xff", file_get_contents($this->directory($id).'/page-00000001.json'));
    }

    public function testAllClassifierOutputsAreStoredWithoutReclassificationOrDeduplication(): void
    {
        $a = ['storage' => 'emissionsMp3', 'relative_path' => 'a', 'emission_id' => 17, 'origin' => 'thumbnailMp3', 'thumbnail_missing' => false];
        $rows = [self::row('a', true, [$a]), self::row('a', true, [$a, array_replace($a, ['emission_id' => 18])]),
            self::row('a', false, [$a]), self::row('a'), self::row('a', null, [], false)];
        $store = $this->store(10); $id = $store->begin(); foreach ($rows as $row) { $store->append($row); }
        $manifest = $store->publish(self::report($rows)); self::assertSame($rows, $store->readPage($id, 1)['results']);
        foreach (['associated', 'multiple_associations', 'broken_reference', 'orphan_candidate', 'indeterminate'] as $key) { self::assertSame(1, $manifest['classification_counts'][$key]); }
    }

    public function testEmptyReportHasNoPage(): void
    {
        $store = $this->store(); $id = $store->begin(); $manifest = $store->publish(self::report([]));
        self::assertSame(0, $manifest['page_count']); self::assertSame(0, $manifest['result_count']);
        $this->expectException(\OutOfBoundsException::class); $store->readPage($id, 1);
    }

    public function testAbortKeepsProvisionalFilesButNeverPublishes(): void
    {
        $store = $this->store(1); $id = $store->begin(); $store->append(self::row()); $store->abort(); $store->abort();
        self::assertFileExists($this->directory($id).'/page-00000001.json'); self::assertFileDoesNotExist($this->directory($id).'/manifest.json');
        $this->expectException(\LogicException::class); $store->publish(self::report([self::row()]));
    }

    /** @dataProvider unsafeIdentifiers */
    public function testArbitraryIdentifiersAreRefusedBeforePathAccess(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->store()->readManifest($id);
    }
    public static function unsafeIdentifiers(): array { return [[''], ['../outside'], ['/absolute'], [str_repeat('g', 32)], [str_repeat('a', 32).'/x']]; }

    public function testPublicOrMissingStorageRootIsRefused(): void
    {
        foreach ([$this->root.'/public', $this->root.'/missing', $this->root] as $root) {
            try { new AudioInventoryReportStore($root, $this->root.'/public', 2, 10000, 10000, 10); self::fail('Unsafe storage root accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testPageAndManifestBudgetsAreExplicit(): void
    {
        $store = $this->store(100, 200); $id = $store->begin();
        try { $store->append(self::row(str_repeat('x', 1000))); self::fail('Oversized row accepted.'); }
        catch (\LengthException) { self::assertFileDoesNotExist($this->directory($id).'/manifest.json'); }
        $store = $this->store(1, 10000, 10000, 1); $id = $store->begin(); $store->append(self::row());
        try { $store->append(self::row('b')); self::fail('Page budget exceeded.'); } catch (\LengthException) { self::assertFileDoesNotExist($this->directory($id).'/manifest.json'); }
        $store = $this->store(1, 10000, 100); $id = $store->begin(); $store->append(self::row());
        $this->expectException(\LengthException::class); $store->publish(self::report([self::row()]));
    }

    public function testCounterMismatchPreventsPublication(): void
    {
        $store = $this->store(); $id = $store->begin(); $store->append(self::row()); $report = self::report([self::row()]); $report['reconciliation']['results_emitted'] = 2;
        $this->expectException(\UnexpectedValueException::class);
        try { $store->publish($report); } finally { self::assertFileDoesNotExist($this->directory($id).'/manifest.json'); }
    }

    public function testCorruptProvisionalPageIsRejectedBeforePublication(): void
    {
        $store = $this->store(1); $id = $store->begin(); $store->append(self::row());
        $path = $this->directory($id).'/page-00000001.json'; $bytes = file_get_contents($path); $bytes[10] = '~'; file_put_contents($path, $bytes);
        $this->expectException(\UnexpectedValueException::class);
        try { $store->publish(self::report([self::row()])); } finally { self::assertFileDoesNotExist($this->directory($id).'/manifest.json'); }
    }

    public function testPublishedCorruptManifestAndPageAreRefusedIndependently(): void
    {
        $store = $this->store(1); $id = $store->begin(); $store->append(self::row()); $store->publish(self::report([self::row()]));
        $path = $this->directory($id).'/page-00000001.json'; $bytes = file_get_contents($path); file_put_contents($path, $bytes.'x');
        try { $store->readPage($id, 1); self::fail('Corrupt page accepted.'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
        file_put_contents($this->directory($id).'/manifest.json', '{broken');
        $this->expectException(\UnexpectedValueException::class); $store->readManifest($id);
    }

    private function faultStore(): AudioInventoryReportStore
    {
        return new class($this->root.'/private', $this->root.'/public', 1, 16384, 65536, 100) extends AudioInventoryReportStore {
            public int $maxChunk = 8192; public ?\Throwable $writeError = null; public ?\Throwable $closeError = null;
            public bool $zeroWrite = false; public bool $failFlush = false; public bool $failPublish = false;
            protected function writeChunk(mixed $stream, string $bytes): int|false { if ($this->writeError) { throw $this->writeError; } return $this->zeroWrite ? 0 : parent::writeChunk($stream, substr($bytes, 0, $this->maxChunk)); }
            protected function flushStream(mixed $stream): bool { return !$this->failFlush && parent::flushStream($stream); }
            protected function closeStream(mixed $stream): void { parent::closeStream($stream); if ($this->closeError) { $error = $this->closeError; $this->closeError = null; throw $error; } }
            protected function publishManifest(string $pending, string $final): bool { return !$this->failPublish && parent::publishManifest($pending, $final); }
        };
    }

    public function testPartialWritesRemainFullyReadable(): void
    {
        $store = $this->faultStore(); $store->maxChunk = 3; $id = $store->begin(); $store->append(self::row()); $store->publish(self::report([self::row()]));
        self::assertSame([self::row()], $store->readPage($id, 1)['results']);
    }

    /** @dataProvider ioFailures */
    public function testIoFailureNeverPublishes(string $fault): void
    {
        $store = $this->faultStore(); $id = $store->begin();
        if ($fault === 'close') { $store->closeError = new \RuntimeException('close'); }
        elseif ($fault === 'write') { $store->writeError = new \RuntimeException('write'); }
        else { $store->{$fault === 'zero' ? 'zeroWrite' : 'failFlush'} = true; }
        $this->expectException(\RuntimeException::class);
        try { $store->append(self::row()); } finally { self::assertFileDoesNotExist($this->directory($id).'/manifest.json'); }
    }
    public static function ioFailures(): array { return [['write'], ['zero'], ['flush'], ['close']]; }

    public function testInitialWriteFailureIsNotMaskedByClosureFailure(): void
    {
        $store = $this->faultStore(); $store->begin(); $primary = new \DomainException('write'); $secondary = new \RuntimeException('close'); $store->writeError = $primary; $store->closeError = $secondary;
        try { $store->append(self::row()); self::fail('Primary failure hidden.'); } catch (\DomainException $actual) { self::assertSame($primary, $actual); }
        self::assertSame([$secondary], $store->getSecondaryErrors());
    }

    public function testPublicationFailureKeepsPendingManifestWithoutCompletedReport(): void
    {
        $store = $this->faultStore(); $id = $store->begin(); $store->append(self::row()); $store->failPublish = true;
        $this->expectException(\RuntimeException::class);
        try { $store->publish(self::report([self::row()])); }
        finally { self::assertFileExists($this->directory($id).'/manifest.pending'); self::assertFileDoesNotExist($this->directory($id).'/manifest.json'); }
    }

    public function testManyResultsAndPagedReadsDoNotAccumulateInventory(): void
    {
        $store = $this->store(64, 65536, 65536, 1000); $id = $store->begin(); $baseline = memory_get_usage(); $growth = 0;
        for ($i = 0; $i < 5000; ++$i) { $store->append(self::row('SYN/'.$i)); $growth = max($growth, memory_get_usage() - $baseline); }
        $report = self::report([]); $report['preparation']['consumed_paths'] = 5000;
        $report['reconciliation'] = ['status' => 'completed', 'processed_paths' => 5000, 'physical_paths' => 5000, 'referenced_paths' => 0, 'results_emitted' => 5000];
        $manifest = $store->publish($report); $count = 0;
        for ($page = 1; $page <= $manifest['page_count']; ++$page) { $value = $store->readPage($id, $page); $count += count($value['results']); $growth = max($growth, memory_get_usage() - $baseline); }
        self::assertSame(5000, $count); self::assertLessThan(4 * 1024 * 1024, $growth);
    }
}
