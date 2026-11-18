<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryMergePass;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunMerger;
use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunCatalog;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunMerger;
use PHPUnit\Framework\TestCase;

final class AudioInventoryMergePassTest extends TestCase
{
    private string $root;
    private AudioInventoryTemporaryWorkspace $workspace;
    private AudioInventoryPathRunCodec $paths;
    private AudioInventoryRunCatalogCodec $catalogCodec;
    private array $others = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-pass-'.bin2hex(random_bytes(12)); mkdir($this->root, 0700);
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 6, 256);
        $this->paths = new AudioInventoryPathRunCodec(1024); $this->catalogCodec = new AudioInventoryRunCatalogCodec(128);
    }

    protected function tearDown(): void
    {
        try { $this->workspace->cleanup(); foreach ($this->others as $other) { $other->cleanup(); } }
        finally { rmdir($this->root); parent::tearDown(); }
    }

    private function runs(): array { return (new \ReflectionProperty($this->workspace, 'runs'))->getValue($this->workspace); }
    private function catalog(): AudioInventoryWorkspaceRunCatalog { return new AudioInventoryWorkspaceRunCatalog($this->workspace, $this->catalogCodec); }
    private function merger(int $k = 2, int $readers = 3): AudioInventoryWorkspaceRunMerger
    {
        return new AudioInventoryWorkspaceRunMerger($this->workspace, new AudioInventoryPathRunMerger($this->paths, $readers), $k);
    }
    private function service(int $k = 2, ?AudioInventoryWorkspaceRunCatalog $reader = null, ?AudioInventoryWorkspaceRunCatalog $writer = null, ?AudioInventoryWorkspaceRunMerger $merger = null): AudioInventoryMergePass
    {
        return new AudioInventoryMergePass($this->workspace, $reader ?? $this->catalog(), $writer ?? $this->catalog(), $merger ?? $this->merger($k), $this->paths, $k);
    }
    private function input(array $paths): string
    {
        $id = $this->workspace->createRun(); $this->paths->write($this->workspace->openWrite($id), $paths);
        $this->workspace->close($id); $this->workspace->finalize($id); return $id;
    }
    private function catalogIds(string $id): array
    {
        return $this->catalog()->consume($id, static fn (iterable $ids) => iterator_to_array($ids, false));
    }
    private function pathsIn(string $id): array
    {
        try { return iterator_to_array($this->paths->read($this->workspace->openRead($id)), false); }
        finally { $this->workspace->close($id); }
    }
    private function assertClosedAndPreserved(array $before): void
    {
        foreach ($this->runs() as $run) { self::assertNull($run['stream']); }
        foreach ($before as $id => $run) {
            self::assertArrayHasKey($id, $this->runs()); self::assertFileExists($run['path']);
            self::assertSame($run['state'], $this->runs()[$id]['state']);
        }
    }
    private function corrupt(string $id): void
    {
        $path = $this->runs()[$id]['path']; $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1); file_put_contents($path, $bytes);
    }

    /** @dataProvider validPasses */
    public function testRealPassCountersGroupsAndPublication(array $blocks, int $k, array $expectedGroups): void
    {
        $ids = array_map(fn ($paths) => $this->input($paths), $blocks); $input = $this->catalog()->create($ids);
        $before = $this->runs(); $service = $this->service($k); $result = $service->run($input);
        $carried = count($ids) % $k === 1 ? $ids[count($ids) - 1] : null;
        self::assertSame(['output_catalog_id', 'input_runs', 'output_runs', 'merged_groups', 'carried_run_id'], array_keys($result));
        self::assertSame(count($ids), $result['input_runs']); self::assertSame(count($expectedGroups), $result['output_runs']);
        self::assertSame(count($expectedGroups) - ($carried !== null ? 1 : 0), $result['merged_groups']);
        self::assertSame($carried, $result['carried_run_id']);
        $outputIds = $this->catalogIds($result['output_catalog_id']);
        self::assertSame($expectedGroups, array_map(fn ($id) => $this->pathsIn($id), $outputIds));
        if ($carried !== null) { self::assertSame($carried, $outputIds[count($outputIds) - 1]); }
        if (count($ids) > 1) { self::assertLessThan($result['input_runs'], $result['output_runs']); }
        self::assertCount(count($before) + 1 + $result['merged_groups'], $this->runs());
        self::assertSame($ids, $this->catalogIds($input)); self::assertSame([], $service->getCloseFailures());
        $this->assertClosedAndPreserved($before);
    }
    public static function validPasses(): array
    {
        return [
            [[], 2, []], [[[]], 2, [[]]], [[['z', 'é']], 2, [['z', 'é']]],
            [[['z'], ['a']], 2, [['a', 'z']]],
            [[['z'], ['a'], ['d'], ['b']], 2, [['a', 'z'], ['b', 'd']]],
            [[['z'], ['a'], ['d'], ['b'], ['solo']], 2, [['a', 'z'], ['b', 'd'], ['solo']]],
            [[['z'], ['a'], ['d'], ['b'], ['c']], 3, [['a', 'd', 'z'], ['b', 'c']]],
            [[['z'], ['a'], ['d'], ['solo']], 3, [['a', 'd', 'z'], ['solo']]],
        ];
    }

    /** @dataProvider invalidLimits */
    public function testInvalidGroupLimitIsRejected(int $k): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->service($k, null, null, $this->merger());
    }
    public static function invalidLimits(): array { return [[0], [1], [-1]]; }
    public function testReaderAndWriterMustBeDistinct(): void
    {
        $catalog = $this->catalog(); $this->expectException(\InvalidArgumentException::class); $this->service(2, $catalog, $catalog);
    }

    public function testCorruptCatalogIsRejectedBeforeAnyOutputCreation(): void
    {
        $ids = [$this->input(['a']), $this->input(['b'])]; $input = $this->catalog()->create($ids); $this->corrupt($input); $before = $this->runs();
        try { $this->service()->run($input); self::fail('Corrupt catalog accepted.'); }
        catch (\UnexpectedValueException) { self::assertSame(array_keys($before), array_keys($this->runs())); }
        $this->assertClosedAndPreserved($before);
    }

    /** @dataProvider badReferences */
    public function testBadBlockReferencePreventsPublication(string $kind, bool $singleton): void
    {
        $bad = 'unknown';
        if ($kind === 'incomplete') { $bad = $this->workspace->createRun(); }
        elseif ($kind === 'removed') { $bad = $this->input(['b']); $this->workspace->discard($bad); }
        elseif ($kind === 'foreign') {
            $other = new AudioInventoryTemporaryWorkspace($this->root, 1, 1); $this->others[] = $other; $bad = $other->createRun();
        }
        $ids = $singleton ? [$bad] : [$this->input(['a']), $bad]; $input = $this->catalog()->create($ids); $before = $this->runs();
        try { $this->service()->run($input); self::fail('Bad block accepted.'); }
        catch (\LogicException) { $this->assertClosedAndPreserved($before); }
    }
    public static function badReferences(): array
    {
        return [['unknown', true], ['unknown', false], ['removed', true], ['removed', false], ['incomplete', true], ['incomplete', false], ['foreign', true]];
    }

    /** @dataProvider invalidSingletons */
    public function testSingletonIsFullyValidated(array $paths, bool $corrupt): void
    {
        $id = $this->input($paths); if ($corrupt) { $this->corrupt($id); }
        $input = $this->catalog()->create([$id]); $before = $this->runs();
        try { $this->service()->run($input); self::fail('Invalid singleton accepted.'); }
        catch (\UnexpectedValueException) { $this->assertClosedAndPreserved($before); }
    }
    public static function invalidSingletons(): array { return [[['a'], true], [[ 'b', 'a'], false], [['a', 'a'], false], [[], true]]; }

    public function testSingletonCannotBeACatalogFile(): void
    {
        $id = $this->catalog()->create([]); $input = $this->catalog()->create([$id]);
        $this->expectException(\UnexpectedValueException::class); $this->service()->run($input);
    }

    public function testDuplicatePathsAcrossMergedBlocksAreRejected(): void
    {
        $ids = [$this->input(['a', 'z']), $this->input(['b', 'z'])]; $input = $this->catalog()->create($ids); $before = $this->runs();
        try { $this->service()->run($input); self::fail('Duplicate accepted.'); }
        catch (\UnexpectedValueException) { $this->assertClosedAndPreserved($before); }
    }

    public function testIntegratedMergerErrorIsPropagated(): void
    {
        $input = $this->catalog()->create([$this->input(['a']), $this->input(['b'])]);
        $merger = new AudioInventoryWorkspaceRunMerger($this->workspace, new AudioInventoryPathRunMerger($this->paths, 1), 2);
        $before = $this->runs();
        try { $this->service(2, null, null, $merger)->run($input); self::fail('Reader limit ignored.'); }
        catch (\LengthException) { $this->assertClosedAndPreserved($before); }
    }

    public function testOutputCatalogWriteFailureDoesNotPublishFinalizedMergeOutput(): void
    {
        $input = $this->catalog()->create([$this->input(['a']), $this->input(['b'])]); $before = $this->runs();
        $writer = new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(1));
        try { $this->service(2, null, $writer)->run($input); self::fail('Catalog write failure concealed.'); }
        catch (\UnexpectedValueException) {
            $new = array_diff_key($this->runs(), $before);
            self::assertCount(2, $new); self::assertSame(['writing', 'finalized'], array_column(array_values($new), 'state'));
        }
        $this->assertClosedAndPreserved($before);
    }

    private function closingCatalog(int $failOn, \Throwable $failure): AudioInventoryWorkspaceRunCatalog
    {
        return new class($this->workspace, $this->catalogCodec, $failOn, $failure) extends AudioInventoryWorkspaceRunCatalog {
            private int $closures = 0;
            public function __construct($workspace, $codec, private int $failOn, private \Throwable $failure) { parent::__construct($workspace, $codec); }
            protected function closeRun(string $id): void { parent::closeRun($id); if (++$this->closures === $this->failOn) { throw $this->failure; } }
        };
    }

    /** @dataProvider closureStages */
    public function testClosureFailurePreventsPublication(string $stage): void
    {
        $input = $this->catalog()->create([$this->input(['a']), $this->input(['b'])]); $before = $this->runs();
        $failure = new \RuntimeException('close '.$stage);
        $reader = $stage === 'writer' ? $this->catalog() : $this->closingCatalog($stage === 'validation' ? 1 : 2, $failure);
        $writer = $stage === 'writer' ? $this->closingCatalog(1, $failure) : $this->catalog();
        try { $this->service(2, $reader, $writer)->run($input); self::fail('Closure failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        if ($stage === 'validation') { self::assertCount(count($before), $this->runs()); }
        if ($stage === 'outer_reader') {
            foreach (array_diff_key($this->runs(), $before) as $run) { self::assertSame('finalized', $run['state']); }
        }
        $this->assertClosedAndPreserved($before);
    }
    public static function closureStages(): array { return [['validation'], ['writer'], ['outer_reader']]; }

    public function testSingletonCloseFailureIsPreservedWithoutPublication(): void
    {
        $id = $this->input(['a']); $input = $this->catalog()->create([$id]); $failure = new \RuntimeException('singleton close');
        $pass = new class($this->workspace, $this->catalog(), $this->catalog(), $this->merger(), $this->paths, 2, $failure) extends AudioInventoryMergePass {
            public function __construct($workspace, $reader, $writer, $merger, $codec, $k, private \Throwable $failure) { parent::__construct($workspace, $reader, $writer, $merger, $codec, $k); }
            protected function closeRun(string $id): void { parent::closeRun($id); throw $this->failure; }
        };
        try { $pass->run($input); self::fail('Singleton closure failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        foreach ($this->runs() as $run) { self::assertNull($run['stream']); }
    }

    public function testNestedSecondaryClosuresRemainObservable(): void
    {
        $input = $this->catalog()->create([$this->input(['a']), $this->input(['a'])]);
        $writerFailure = new \RuntimeException('writer secondary'); $readerFailure = new \RuntimeException('reader secondary'); $mergeFailure = new \RuntimeException('merge secondary');
        $merger = new class($this->workspace, new AudioInventoryPathRunMerger($this->paths, 2), 2, $mergeFailure) extends AudioInventoryWorkspaceRunMerger {
            private bool $failed = false;
            public function __construct($workspace, $merger, $k, private \Throwable $failure) { parent::__construct($workspace, $merger, $k); }
            protected function closeRun(string $id): void { parent::closeRun($id); if (!$this->failed) { $this->failed = true; throw $this->failure; } }
        };
        $service = $this->service(2, $this->closingCatalog(2, $readerFailure), $this->closingCatalog(1, $writerFailure), $merger);
        try { $service->run($input); self::fail('Duplicate concealed.'); }
        catch (\UnexpectedValueException $initial) { self::assertStringContainsString('Duplicate', $initial->getMessage()); }
        self::assertSame([$mergeFailure, $writerFailure, $readerFailure], $service->getCloseFailures());
        foreach ($this->runs() as $run) { self::assertNull($run['stream']); }
    }

    /** @dataProvider budgets */
    public function testEffectiveStreamAndCreationBudgets(int $streams, int $runs, bool $success): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, $streams, $runs);
        $ids = [$this->input(['a']), $this->input(['b'])]; $input = $this->catalog()->create($ids); $before = $this->runs();
        try {
            $result = $this->service()->run($input);
            self::assertTrue($success); self::assertSame(1, $result['output_runs']);
        } catch (\LengthException) { self::assertFalse($success); }
        $this->assertClosedAndPreserved($before);
    }
    public static function budgets(): array { return [[5, 5, true], [4, 5, false], [5, 4, false]]; }

    public function testManyGroupsAreBoundedAndUseAtMostKPlusThreeStreams(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 5, 200);
        $source = (function (): \Generator { for ($i = 0; $i < 120; ++$i) { yield $this->input([sprintf('SYN/%04d', $i)]); } })();
        $input = $this->catalog()->create($source);
        $merger = new class($this->workspace, new AudioInventoryPathRunMerger($this->paths, 2), 2) extends AudioInventoryWorkspaceRunMerger {
            public int $maxGroup = 0; public int $calls = 0; public int $peakOpen = 0;
            public function __construct(private AudioInventoryTemporaryWorkspace $owner, $merger, $k) { parent::__construct($owner, $merger, $k); }
            public function merge(array $ids): string { ++$this->calls; $this->maxGroup = max($this->maxGroup, count($ids)); return parent::merge($ids); }
            protected function closeRun(string $id): void {
                $runs = (new \ReflectionProperty($this->owner, 'runs'))->getValue($this->owner); $open = 0;
                foreach ($runs as $run) { if (is_resource($run['stream'])) { ++$open; } }
                $this->peakOpen = max($this->peakOpen, $open); parent::closeRun($id);
            }
        };
        $result = $this->service(2, null, null, $merger)->run($input);
        self::assertSame(120, $result['input_runs']); self::assertSame(60, $result['output_runs']);
        self::assertSame(60, $merger->calls); self::assertSame(2, $merger->maxGroup); self::assertSame(5, $merger->peakOpen);
        $count = $this->catalog()->consume($result['output_catalog_id'], static function (iterable $ids): int { $count = 0; foreach ($ids as $id) { ++$count; } return $count; });
        self::assertSame(60, $count);
        $this->workspace->cleanup(); $this->workspace->cleanup(); self::assertSame([], $this->runs());
    }
}
