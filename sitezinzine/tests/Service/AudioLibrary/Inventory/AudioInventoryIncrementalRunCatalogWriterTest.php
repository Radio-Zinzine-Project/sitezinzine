<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryIncrementalRunCatalogWriter;
use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunCatalog;
use PHPUnit\Framework\TestCase;

final class AudioInventoryIncrementalRunCatalogWriterTest extends TestCase
{
    private string $root;
    private AudioInventoryTemporaryWorkspace $workspace;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-incremental-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 1, 3);
    }

    protected function tearDown(): void
    {
        try { $this->workspace->cleanup(); }
        finally { rmdir($this->root); parent::tearDown(); }
    }

    private function runs(): array { return (new \ReflectionProperty($this->workspace, 'runs'))->getValue($this->workspace); }
    private function ownedRun(): array { return array_values($this->runs())[0]; }
    private function assertClosed(): void { foreach ($this->runs() as $run) { self::assertNull($run['stream']); } }

    /** @dataProvider identifiers */
    public function testByteCompatibleCatalogWithExactOrderCountAndRepetitions(array $ids): void
    {
        $writer = new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 128);
        self::assertSame('open', $writer->getState());
        foreach ($ids as $id) { $writer->append($id); }
        self::assertSame('writing', $this->ownedRun()['state']);
        $report = $writer->finish();
        self::assertSame(['catalog_id' => array_key_first($this->runs()), 'identifier_count' => count($ids)], $report);
        self::assertSame('finalized', $writer->getState()); self::assertSame('finalized', $this->ownedRun()['state']);
        $this->assertClosed();
        $stream = $this->workspace->openRead($report['catalog_id']);
        try { self::assertSame($ids, iterator_to_array((new AudioInventoryRunCatalogCodec(128))->read($stream), false)); }
        finally { $this->workspace->close($report['catalog_id']); }
        $expected = fopen('php://memory', 'w+b');
        try {
            (new AudioInventoryRunCatalogCodec(128))->write($expected, $ids); rewind($expected);
            self::assertSame(stream_get_contents($expected), file_get_contents($this->ownedRun()['path']));
        } finally { fclose($expected); }
        $catalog = new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(128));
        self::assertSame($ids, $catalog->consume($report['catalog_id'], static fn (iterable $values): array => iterator_to_array($values, false)));
        self::assertSame([], $writer->getCloseFailures()); self::assertFileExists($this->ownedRun()['path']);
    }
    public static function identifiers(): array
    {
        return [[[]], [['a']], [['z', 'a', 'a', 'b']], [["\0../id", 'Été', "line\nname", "\xff", '0']]];
    }

    public function testAppendWritesImmediatelyWithoutPublishing(): void
    {
        $writer = new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 16);
        $stream = $this->ownedRun()['stream'];
        self::assertSame(8, ftell($stream)); $writer->append('abc'); self::assertSame(15, ftell($stream));
        $writer->append('def'); self::assertSame(22, ftell($stream));
        $this->expectException(\LogicException::class);
        try { $this->workspace->openRead(array_key_first($this->runs())); }
        finally { $writer->abort(); }
    }

    public function testInvalidIdentifierAndOverlongBytesAreRejectedWithoutChangingRecords(): void
    {
        $writer = new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 2);
        foreach (['', 'abc', 'éa'] as $id) {
            try { $writer->append($id); self::fail('Invalid ID accepted.'); }
            catch (\UnexpectedValueException) { self::assertSame(8, ftell($this->ownedRun()['stream'])); }
        }
        $writer->append('é'); self::assertSame(1, $writer->finish()['identifier_count']);
    }

    /** @dataProvider invalidLimits */
    public function testInvalidLimitDoesNotCreateFile(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try { new AudioInventoryIncrementalRunCatalogWriter($this->workspace, $limit); }
        finally { self::assertSame([], $this->runs()); }
    }
    public static function invalidLimits(): array { return [[0], [-1], [0x100000000]]; }

    /** @dataProvider terminalCalls */
    public function testNoOperationCanRepublishAfterFinish(string $method): void
    {
        $writer = new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 16); $writer->finish();
        $this->expectException(\LogicException::class);
        $method === 'append' ? $writer->append('x') : $writer->$method();
    }
    public static function terminalCalls(): array { return [['append'], ['finish'], ['abort']]; }

    public function testAbortClosesWithoutFinalizingOrRemovingAndIsIdempotent(): void
    {
        $writer = new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 16); $writer->append('a');
        $path = $this->ownedRun()['path']; $writer->abort(); $writer->abort();
        self::assertSame('aborted', $writer->getState()); self::assertSame('writing', $this->ownedRun()['state']);
        self::assertFileExists($path); $this->assertClosed();
        foreach (['append', 'finish'] as $method) {
            try { $method === 'append' ? $writer->append('x') : $writer->finish(); self::fail('Aborted writer reused.'); }
            catch (\LogicException) { self::assertSame('aborted', $writer->getState()); }
        }
    }

    public function testStreamBudgetRefusalNeverClosesAnotherOwnedStream(): void
    {
        $id = $this->workspace->createRun(); $stream = $this->workspace->openWrite($id);
        try { new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 16); self::fail('Stream limit ignored.'); }
        catch (\LengthException) { self::assertTrue(is_resource($stream)); self::assertCount(1, $this->runs()); }
        finally { $this->workspace->close($id); }
    }

    public function testOneFileCreationAndOneDescriptorSufficeIncludingValidation(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 1, 1);
        $writer = new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 16);
        $writer->append('a'); self::assertSame(1, $writer->finish()['identifier_count']);
        self::assertCount(1, $this->runs()); $this->assertClosed();
    }

    private function faultWriter(): AudioInventoryIncrementalRunCatalogWriter
    {
        return new class($this->workspace, 128) extends AudioInventoryIncrementalRunCatalogWriter {

    public int $chunkLimit = 8192;
    public int|false|null $writeFault = null;
    public ?\Throwable $writingError = null;
    public bool $flushFailure = false;
    public ?\Throwable $closingError = null;
    public int $failCloseAt = 1;
    public ?\Throwable $finalizingError = null;
    public ?\Throwable $readingError = null;
    public ?\Closure $afterFinalize = null;
    public int $writeCalls = 0;
    private int $closeCalls = 0;

    protected function writeChunk(mixed $stream, string $bytes): int|false
    {
        ++$this->writeCalls;
        if ($this->writingError !== null) { throw $this->writingError; }
        if ($this->writeFault !== null) { return $this->writeFault; }
        return parent::writeChunk($stream, substr($bytes, 0, $this->chunkLimit));
    }
    protected function flushStream(mixed $stream): bool { return !$this->flushFailure && parent::flushStream($stream); }
    protected function closeRun(string $id): void
    {
        parent::closeRun($id);
        if (++$this->closeCalls === $this->failCloseAt && $this->closingError !== null) { throw $this->closingError; }
    }
    protected function finalizeRun(string $id): void
    {
        if ($this->finalizingError !== null) { throw $this->finalizingError; }
        parent::finalizeRun($id); if ($this->afterFinalize !== null) { ($this->afterFinalize)(); }
    }
    protected function openReadRun(string $id): mixed
    {
        if ($this->readingError !== null) { throw $this->readingError; }
        return parent::openReadRun($id);
    }

        };
    }

    public function testPartialWritesProduceCompatibleCompleteFrame(): void
    {
        $writer = $this->faultWriter(); $writer->chunkLimit = 2; $writer->append('abcdef');
        self::assertSame(1, $writer->finish()['identifier_count']); self::assertGreaterThan(20, $writer->writeCalls);
        $this->assertClosed();
    }

    /** @dataProvider failedWrites */
    public function testFailedOrZeroProgressWritesPermanentlyDisablePublication(bool $zero, bool $finish): void
    {
        $writer = $this->faultWriter(); $writer->writeFault = $zero ? 0 : false;
        try { $finish ? $writer->finish() : $writer->append('a'); self::fail('Write failure concealed.'); }
        catch (\RuntimeException) { self::assertSame('failed', $writer->getState()); }
        self::assertSame('writing', $this->ownedRun()['state']); self::assertFileExists($this->ownedRun()['path']); $this->assertClosed();
        foreach (['append', 'finish'] as $method) {
            try { $method === 'append' ? $writer->append('b') : $writer->finish(); self::fail('Failed writer reused.'); }
            catch (\LogicException) { self::assertSame('failed', $writer->getState()); }
        }
        $writer->abort(); self::assertSame('failed', $writer->getState());
    }
    public static function failedWrites(): array { return [[false, false], [true, false], [false, true], [true, true]]; }

    public function testFlushFailurePreventsWorkspaceFinalization(): void
    {
        $writer = $this->faultWriter(); $writer->flushFailure = true;
        try { $writer->finish(); self::fail('Flush failure concealed.'); }
        catch (\RuntimeException) { self::assertSame('failed', $writer->getState()); }
        self::assertSame('writing', $this->ownedRun()['state']); $this->assertClosed();
    }

    /** @dataProvider closingStages */
    public function testClosureFailurePreventsPublication(bool $reader): void
    {
        $writer = $this->faultWriter(); $error = new \RuntimeException('close');
        $writer->closingError = $error; $writer->failCloseAt = $reader ? 2 : 1;
        $result = null;
        try { $result = $writer->finish(); self::fail('Close failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($error, $actual); }
        self::assertNull($result); self::assertSame('failed', $writer->getState()); $this->assertClosed();
        self::assertSame($reader ? 'finalized' : 'writing', $this->ownedRun()['state']); self::assertFileExists($this->ownedRun()['path']);
    }
    public static function closingStages(): array { return [[false], [true]]; }

    public function testFinalizeFailurePropagatesAndLeavesFileOwned(): void
    {
        $writer = $this->faultWriter(); $failure = new \DomainException('finalize'); $writer->finalizingError = $failure;
        try { $writer->finish(); self::fail('Finalization failure concealed.'); }
        catch (\DomainException $actual) { self::assertSame($failure, $actual); }
        self::assertSame('failed', $writer->getState()); self::assertSame('writing', $this->ownedRun()['state']); $this->assertClosed();
    }

    public function testReadOpeningFailureCannotPublishPhysicallyFinalizedFile(): void
    {
        $writer = $this->faultWriter(); $failure = new \RuntimeException('read'); $writer->readingError = $failure;
        try { $writer->finish(); self::fail('Read failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertSame('failed', $writer->getState()); self::assertSame('finalized', $this->ownedRun()['state']); $this->assertClosed();
    }

    public function testCorruptedChecksumIsRejectedByRealCodecBeforePublication(): void
    {
        $writer = $this->faultWriter(); $writer->append('a');
        $writer->afterFinalize = function (): void {
            $path = $this->ownedRun()['path']; $bytes = file_get_contents($path); $bytes[strlen($bytes) - 1] = ~$bytes[strlen($bytes) - 1]; file_put_contents($path, $bytes);
        };
        $this->expectException(\UnexpectedValueException::class);
        try { $writer->finish(); }
        finally { self::assertSame('failed', $writer->getState()); $this->assertClosed(); }
    }

    public function testValidReplacementFrameWithDifferentCountIsRefused(): void
    {
        $writer = $this->faultWriter(); $writer->append('a'); $writer->append('b');
        $writer->afterFinalize = function (): void {
            // Same byte size, independently valid frame, different count.
            $stream = fopen($this->ownedRun()['path'], 'wb');
            try { (new AudioInventoryRunCatalogCodec(128))->write($stream, ['abcdef']); }
            finally { fclose($stream); }
        };
        $this->expectException(\UnexpectedValueException::class);
        try { $writer->finish(); }
        finally { self::assertSame('failed', $writer->getState()); $this->assertClosed(); }
    }

    public function testPrimaryWriteErrorSurvivesSecondaryCloseError(): void
    {
        $writer = $this->faultWriter(); $primary = new \DomainException('write'); $secondary = new \RuntimeException('close');
        $writer->writingError = $primary; $writer->closingError = $secondary;
        try { $writer->append('a'); self::fail('Write error hidden.'); }
        catch (\DomainException $actual) { self::assertSame($primary, $actual); }
        self::assertSame([$secondary], $writer->getCloseFailures()); self::assertSame('failed', $writer->getState());
        $writer->abort(); self::assertSame([$secondary], $writer->getCloseFailures()); $this->assertClosed();
    }

    public function testValidationFailureSurvivesSecondaryReaderClosureError(): void
    {
        $writer = $this->faultWriter(); $writer->append('a'); $secondary = new \RuntimeException('read close');
        $writer->closingError = $secondary; $writer->failCloseAt = 2;
        $writer->afterFinalize = function (): void { $path = $this->ownedRun()['path']; $bytes = file_get_contents($path); $bytes[0] = 'X'; file_put_contents($path, $bytes); };
        try { $writer->finish(); self::fail('Validation error hidden.'); }
        catch (\UnexpectedValueException) { self::assertSame([$secondary], $writer->getCloseFailures()); }
        self::assertSame('failed', $writer->getState()); $this->assertClosed();
    }

    public function testAbortClosureFailureRemainsVisibleAndCannotPublish(): void
    {
        $writer = $this->faultWriter(); $error = new \RuntimeException('abort close'); $writer->closingError = $error;
        try { $writer->abort(); self::fail('Abort close error hidden.'); }
        catch (\RuntimeException $actual) { self::assertSame($error, $actual); }
        self::assertSame('failed', $writer->getState()); self::assertFileExists($this->ownedRun()['path']); $this->assertClosed();
    }

    public function testOneHundredThousandAppendsAndValidationHaveBoundedMemory(): void
    {
        $writer = new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 64);
        $baseline = memory_get_usage(); $growth = 0;
        for ($i = 0; $i < 100000; ++$i) {
            $writer->append('opaque-'.$i); $growth = max($growth, memory_get_usage() - $baseline);
        }
        $report = $writer->finish(); $growth = max($growth, memory_get_usage() - $baseline);
        self::assertSame(100000, $report['identifier_count']); self::assertLessThan(2 * 1024 * 1024, $growth);
        $stream = $this->workspace->openRead($report['catalog_id']); $count = 0;
        try {
            foreach ((new AudioInventoryRunCatalogCodec(64))->read($stream) as $value) {
                if ($value !== 'opaque-'.$count) { self::fail('Order or bytes changed.'); }
                ++$count;
            }
        } finally { $this->workspace->close($report['catalog_id']); }
        self::assertSame(100000, $count); self::assertCount(1, $this->runs()); $this->assertClosed();
    }
}
