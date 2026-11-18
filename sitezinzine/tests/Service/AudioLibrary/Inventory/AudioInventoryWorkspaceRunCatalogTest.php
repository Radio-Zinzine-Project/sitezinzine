<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunCatalog;
use PHPUnit\Framework\TestCase;

final class AudioInventoryWorkspaceRunCatalogTest extends TestCase
{
    private string $root;
    private AudioInventoryTemporaryWorkspace $workspace;
    private AudioInventoryRunCatalogCodec $codec;
    private array $others = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-catalog-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 2, 12);
        $this->codec = new AudioInventoryRunCatalogCodec(128);
    }

    protected function tearDown(): void
    {
        try {
            $this->workspace->cleanup();
            foreach ($this->others as $other) { $other->cleanup(); }
        } finally { rmdir($this->root); parent::tearDown(); }
    }

    private function service(): AudioInventoryWorkspaceRunCatalog
    {
        return new AudioInventoryWorkspaceRunCatalog($this->workspace, $this->codec);
    }

    private function runs(): array
    {
        return (new \ReflectionProperty($this->workspace, 'runs'))->getValue($this->workspace);
    }

    private function assertClosed(): void
    {
        foreach ($this->runs() as $run) { self::assertNull($run['stream']); }
    }

    private function readAll(AudioInventoryWorkspaceRunCatalog $service, string $id): array
    {
        return $service->consume($id, static fn (iterable $ids): array => iterator_to_array($ids, false));
    }

    /** @dataProvider sequences */
    public function testCreateAndConsumeRealCatalogPreservesOrderAndBytes(array $ids): void
    {
        $service = $this->service(); $id = $service->create($ids);
        self::assertSame('finalized', $this->runs()[$id]['state']); $this->assertClosed();
        self::assertSame($ids, $this->readAll($service, $id));
        self::assertSame($ids, $this->readAll($service, $id), 'Reopening starts at the beginning.');
        self::assertFileExists($this->runs()[$id]['path']);
        self::assertSame([], $service->getCloseFailures()); $this->assertClosed();
    }
    public static function sequences(): array
    {
        return [[[]], [['one']], [['z', 'a', 'a']], [["\0opaque/../id", "é\n\\", "\xff"]]];
    }

    public function testCallbackSeesOpenStreamAndLazyReading(): void
    {
        $service = $this->service(); $id = $service->create(['a', 'b']);
        $result = $service->consume($id, function (iterable $ids) use ($id): string {
            $stream = $this->runs()[$id]['stream'];
            self::assertTrue(is_resource($stream)); self::assertSame(0, ftell($stream));
            self::assertSame('a', $ids->current());
            self::assertLessThan(fstat($stream)['size'], ftell($stream));
            self::assertSame(['a', 'b'], iterator_to_array($ids, false));
            self::assertTrue(feof($stream));
            return 'consumer result';
        });
        self::assertSame('consumer result', $result); $this->assertClosed();
    }

    /** @dataProvider partialModes */
    public function testPartialConsumptionIsNeverReportedAsValidated(string $mode): void
    {
        $service = $this->service(); $id = $service->create(['a', 'b']);
        try {
            $service->consume($id, static function (iterable $ids) use ($mode): mixed {
                if ($mode === 'first') { $ids->current(); }
                elseif ($mode === 'break') { foreach ($ids as $value) { break; } }
                elseif ($mode === 'last_without_footer') { $ids->current(); $ids->next(); }
                elseif ($mode === 'return_generator') { return $ids; }
                return 'not validated';
            });
            self::fail('Partial consumption accepted.');
        } catch (\LogicException) { $this->assertClosed(); }
        self::assertSame(['a', 'b'], $this->readAll($service, $id));
    }
    public static function partialModes(): array { return [['none'], ['first'], ['break'], ['last_without_footer'], ['return_generator']]; }

    public function testEmptyCatalogStillRequiresIteration(): void
    {
        $service = $this->service(); $id = $service->create([]);
        $this->expectException(\LogicException::class);
        $service->consume($id, static fn (iterable $ids) => null);
    }

    public function testRetainedPartialIteratorCannotReadAfterCallbackScope(): void
    {
        $service = $this->service(); $id = $service->create(['a', 'b']); $retained = null;
        try { $service->consume($id, static function (iterable $ids) use (&$retained): void { $retained = $ids; $ids->current(); }); }
        catch (\LogicException) { $this->assertClosed(); }
        $this->expectException(\LogicException::class); $retained->next();
    }

    private function corrupt(string $id): void
    {
        $path = $this->runs()[$id]['path']; $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        file_put_contents($path, $bytes);
    }

    public function testCorruptionRaisesCodecErrorAndClosesStream(): void
    {
        $service = $this->service(); $id = $service->create(['a']); $this->corrupt($id);
        try { $this->readAll($service, $id); self::fail('Corruption accepted.'); }
        catch (\UnexpectedValueException) { $this->assertClosed(); self::assertFileExists($this->runs()[$id]['path']); }
    }

    public function testCallbackCannotSwallowCodecFailureAndAnnounceSuccess(): void
    {
        $service = $this->service(); $id = $service->create(['a']); $this->corrupt($id);
        $this->expectException(\LogicException::class);
        $service->consume($id, static function (iterable $ids): string {
            try { iterator_to_array($ids, false); } catch (\UnexpectedValueException) {}
            return 'pretend success';
        });
    }

    /** @dataProvider invalidIds */
    public function testCodecWriteFailureLeavesUnfinalizedOwnedFile(mixed $value): void
    {
        $service = $this->service();
        try { $service->create([$value]); self::fail('Invalid ID accepted.'); }
        catch (\UnexpectedValueException) {
            self::assertCount(1, $this->runs());
            foreach ($this->runs() as $run) { self::assertSame('writing', $run['state']); self::assertFileExists($run['path']); }
            $this->assertClosed();
        }
    }
    public static function invalidIds(): array { return [[''], [null], [str_repeat('x', 129)]]; }

    public function testSourceFailureIsPreservedAndOtherBlocksRemainUntouched(): void
    {
        $other = $this->workspace->createRun(); $failure = new \RuntimeException('source');
        $source = (static function () use ($failure): \Generator { yield 'a'; throw $failure; })();
        try { $this->service()->create($source); self::fail('Source failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertCount(2, $this->runs()); self::assertArrayHasKey($other, $this->runs()); $this->assertClosed();
        foreach ($this->runs() as $run) { self::assertSame('writing', $run['state']); }
    }

    public function testCallbackFailureIsPreserved(): void
    {
        $service = $this->service(); $id = $service->create(['a']); $failure = new \DomainException('consumer');
        try { $service->consume($id, static function (iterable $ids) use ($failure): void { $ids->current(); throw $failure; }); self::fail('Consumer failure concealed.'); }
        catch (\DomainException $actual) { self::assertSame($failure, $actual); }
        $this->assertClosed();
    }

    public function testUnknownAndRemovedIdsAreRejectedWithoutCallingConsumer(): void
    {
        $service = $this->service(); $id = $service->create([]); $this->workspace->discard($id);
        foreach ([$id, 'unknown', '../outside'] as $invalid) {
            try { $service->consume($invalid, static function (): void { self::fail('Consumer called.'); }); self::fail('Invalid catalog accepted.'); }
            catch (\InvalidArgumentException) { self::assertSame([], $this->runs()); }
        }
    }

    public function testForeignIdIsRejected(): void
    {
        $other = new AudioInventoryTemporaryWorkspace($this->root, 1, 1); $this->others[] = $other;
        $id = (new AudioInventoryWorkspaceRunCatalog($other, $this->codec))->create([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->consume($id, static fn (iterable $ids) => iterator_to_array($ids));
    }

    public function testUnfinalizedFileIsRejected(): void
    {
        $id = $this->workspace->createRun();
        $this->expectException(\LogicException::class); $this->readAll($this->service(), $id);
    }

    public function testRealPathRunIsNotAcceptedAsCatalog(): void
    {
        $id = $this->workspace->createRun();
        (new AudioInventoryPathRunCodec(128))->write($this->workspace->openWrite($id), ['a.mp3']);
        $this->workspace->close($id); $this->workspace->finalize($id);
        try { $this->readAll($this->service(), $id); self::fail('Wrong format accepted.'); }
        catch (\UnexpectedValueException) { $this->assertClosed(); }
    }

    public function testBusyCatalogReaderIsNotClosedByFailedOperation(): void
    {
        $service = $this->service(); $id = $service->create([]); $stream = $this->workspace->openRead($id);
        try { $this->readAll($service, $id); self::fail('Busy catalog accepted.'); }
        catch (\LogicException) { self::assertTrue(is_resource($stream)); }
        finally { $this->workspace->close($id); }
    }

    public function testStreamBudgetRefusalPreservesOtherStream(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 1, 3);
        $service = $this->service(); $id = $service->create([]); $other = $this->workspace->createRun();
        $stream = $this->workspace->openWrite($other);
        try { $this->readAll($service, $id); self::fail('Stream limit ignored.'); }
        catch (\LengthException) { self::assertTrue(is_resource($stream)); self::assertNull($this->runs()[$id]['stream']); }
        finally { $this->workspace->close($other); }
    }

    public function testCreationBudgetRemainsCumulative(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 1, 1);
        $service = $this->service(); $id = $service->create([]); $this->workspace->discard($id);
        $this->expectException(\LengthException::class); $service->create([]);
    }

    private function faultService(\Throwable $failure): AudioInventoryWorkspaceRunCatalog
    {
        return new class($this->workspace, $this->codec, $failure) extends AudioInventoryWorkspaceRunCatalog {
            public function __construct($workspace, $codec, private \Throwable $failure) { parent::__construct($workspace, $codec); }
            protected function closeRun(string $id): void { parent::closeRun($id); throw $this->failure; }
        };
    }

    public function testWriterClosureFailurePreventsFinalization(): void
    {
        $failure = new \RuntimeException('close'); $service = $this->faultService($failure);
        try { $service->create(['a']); self::fail('Closure failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        $this->assertClosed(); foreach ($this->runs() as $run) { self::assertSame('writing', $run['state']); }
    }

    public function testReaderClosureFailurePreventsReturningConsumerResult(): void
    {
        $id = $this->service()->create(['a']); $failure = new \RuntimeException('close');
        try { $this->readAll($this->faultService($failure), $id); self::fail('Closure failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        $this->assertClosed();
    }

    /** @dataProvider failureStages */
    public function testSecondaryClosureFailureDoesNotReplaceOriginal(string $stage): void
    {
        $secondary = new \RuntimeException('secondary close'); $initial = new \DomainException('initial');
        $service = $this->faultService($secondary);
        try {
            if ($stage === 'write') {
                $source = (static function () use ($initial): \Generator { yield 'a'; throw $initial; })(); $service->create($source);
            } else {
                $id = $this->service()->create(['a']);
                $service->consume($id, static function () use ($initial): void { throw $initial; });
            }
            self::fail('Initial failure concealed.');
        } catch (\DomainException $actual) { self::assertSame($initial, $actual); }
        self::assertSame([$secondary], $service->getCloseFailures()); $this->assertClosed();
    }
    public static function failureStages(): array { return [['write'], ['consume']]; }

    public function testLargeCatalogUsesProgressiveBoundedConsumptionAndWorkspaceCleanup(): void
    {
        $service = $this->service(); $produced = 0; $writeGrowth = 0; $baseline = memory_get_usage();
        $source = (static function () use (&$produced, &$writeGrowth, $baseline): \Generator {
            for ($i = 0; $i < 100000; ++$i) { ++$produced; $writeGrowth = max($writeGrowth, memory_get_usage() - $baseline); yield 'opaque-'.$i; }
        })();
        $id = $service->create($source); self::assertSame(100000, $produced);
        $count = $service->consume($id, static function (iterable $ids) use ($baseline): int {
            $count = 0; $growth = 0;
            foreach ($ids as $value) {
                if ($value !== 'opaque-'.$count) { self::fail('Order changed.'); }
                ++$count; $growth = max($growth, memory_get_usage() - $baseline);
            }
            self::assertLessThan(2 * 1024 * 1024, $growth); return $count;
        });
        self::assertSame(100000, $count); self::assertLessThan(2 * 1024 * 1024, $writeGrowth);
        $path = $this->runs()[$id]['path']; $this->assertClosed();
        $this->workspace->cleanup(); $this->workspace->cleanup(); self::assertFileDoesNotExist($path); self::assertSame([], $this->runs());
    }
}
