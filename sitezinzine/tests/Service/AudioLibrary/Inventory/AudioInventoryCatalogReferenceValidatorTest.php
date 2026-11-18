<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryCatalogReferenceValidator;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunCatalog;
use PHPUnit\Framework\TestCase;

final class AudioInventoryCatalogReferenceValidatorTest extends TestCase
{
    private string $root;
    private AudioInventoryTemporaryWorkspace $workspace;
    private AudioInventoryPathRunCodec $paths;
    private array $others = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-reference-validator-'.bin2hex(random_bytes(12)); mkdir($this->root, 0700);
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 1, 256);
        $this->paths = new AudioInventoryPathRunCodec(1024);
    }
    protected function tearDown(): void
    {
        try { $this->workspace->cleanup(); foreach ($this->others as $other) { $other->cleanup(); } }
        finally { rmdir($this->root); parent::tearDown(); }
    }
    private function runs(): array { return (new \ReflectionProperty($this->workspace, 'runs'))->getValue($this->workspace); }
    private function catalog(): AudioInventoryWorkspaceRunCatalog { return new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(128)); }
    private function validator(int $budget = 100, ?AudioInventoryWorkspaceRunCatalog $catalog = null): AudioInventoryCatalogReferenceValidator
    {
        return new AudioInventoryCatalogReferenceValidator($this->workspace, $catalog ?? $this->catalog(), $this->paths, $budget);
    }
    private function input(array $paths): string
    {
        $id = $this->workspace->createRun(); $this->paths->write($this->workspace->openWrite($id), $paths);
        $this->workspace->close($id); $this->workspace->finalize($id); return $id;
    }
    private function assertUntouched(array $before): void
    {
        self::assertSame(array_keys($before), array_keys($this->runs()));
        foreach ($before as $id => $snapshot) {
            $run = $this->runs()[$id]; self::assertSame($snapshot['state'], $run['state']);
            self::assertFileExists($run['path']); self::assertNull($run['stream']);
        }
    }
    private function corrupt(string $id, bool $truncate = false): void
    {
        $path = $this->runs()[$id]['path']; $bytes = file_get_contents($path);
        if ($truncate) { $bytes = substr($bytes, 0, -1); }
        else { $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1); }
        file_put_contents($path, $bytes);
    }

    /** @dataProvider validCatalogs */
    public function testCompleteValidationUsesExactComparisonBudget(array $blocks, int $budget, int $comparisons): void
    {
        $ids = array_map(fn ($paths) => $this->input($paths), $blocks); $catalog = $this->catalog()->create($ids);
        $before = $this->runs(); $hashes = array_map(static fn ($run) => hash_file('sha256', $run['path']), $before);
        $validator = $this->validator($budget); $report = $validator->validate($catalog);
        self::assertSame(['catalog_id' => $catalog, 'reference_count' => count($ids), 'validated_runs' => count($ids), 'comparison_count' => $comparisons, 'valid' => true], $report);
        self::assertSame([], $validator->getCloseFailures()); $this->assertUntouched($before);
        self::assertSame($hashes, array_map(static fn ($run) => hash_file('sha256', $run['path']), $this->runs()));
    }
    public static function validCatalogs(): array
    {
        return [[[], 1, 0], [[[]], 1, 0], [[['A', 'a', 'é']], 1, 0], [[['a'], ['b']], 1, 1], [[['a'], ['b'], ['c'], ['d']], 6, 6]];
    }

    /** @dataProvider duplicatePositions */
    public function testDuplicateIdentifiersAreRejectedEvenFarApart(array $positions): void
    {
        $ids = [$this->input(['a']), $this->input(['b']), $this->input(['c'])];
        $catalog = $this->catalog()->create(array_map(static fn ($index) => $ids[$index], $positions)); $before = $this->runs();
        try { $this->validator()->validate($catalog); self::fail('Duplicate reference accepted.'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('Duplicate', $error->getMessage()); }
        $this->assertUntouched($before);
    }
    public static function duplicatePositions(): array { return [[[0, 0]], [[0, 1, 2, 0]], [[0, 1, 2, 1]]]; }

    /** @dataProvider invalidReferences */
    public function testInvalidReferenceCannotProduceValidReport(string $kind): void
    {
        $id = 'unknown';
        if ($kind === 'removed') { $id = $this->input(['a']); $this->workspace->discard($id); }
        elseif ($kind === 'incomplete') { $id = $this->workspace->createRun(); }
        elseif ($kind === 'catalog') { $id = $this->catalog()->create([]); }
        elseif ($kind === 'foreign') {
            $other = new AudioInventoryTemporaryWorkspace($this->root, 1, 1); $this->others[] = $other; $id = $other->createRun();
        }
        $catalog = $this->catalog()->create([$id]); $before = $this->runs();
        try { $this->validator()->validate($catalog); self::fail('Invalid reference accepted.'); }
        catch (\LogicException|\UnexpectedValueException) { $this->assertUntouched($before); }
    }
    public static function invalidReferences(): array { return [['unknown'], ['removed'], ['incomplete'], ['catalog'], ['foreign']]; }

    /** @dataProvider badBlocks */
    public function testBlockIntegrityAndStrictOrderAreFullyChecked(array $paths, bool $corrupt): void
    {
        $id = $this->input($paths); if ($corrupt) { $this->corrupt($id); }
        $catalog = $this->catalog()->create([$id]); $before = $this->runs();
        try { $this->validator()->validate($catalog); self::fail('Bad block accepted.'); }
        catch (\UnexpectedValueException) { $this->assertUntouched($before); }
    }
    public static function badBlocks(): array { return [[['a'], true], [[], true], [['z', 'a'], false], [['a', 'a'], false]]; }

    public function testLateBlockCorruptionIsNotHiddenByEarlierValidRuns(): void
    {
        $ids = [$this->input(['a']), $this->input(['b']), $this->input(['c'])]; $this->corrupt($ids[2]);
        $catalog = $this->catalog()->create($ids); $before = $this->runs();
        try { $this->validator()->validate($catalog); self::fail('Late corruption accepted.'); }
        catch (\UnexpectedValueException) { $this->assertUntouched($before); }
    }

    /** @dataProvider catalogDamage */
    public function testCatalogMustValidateBeforeReferencesAreProcessed(bool $truncate): void
    {
        // An invalid reference would fail differently if processed before frame validation.
        $catalog = $this->catalog()->create(['unknown']); $this->corrupt($catalog, $truncate); $before = $this->runs();
        try { $this->validator()->validate($catalog); self::fail('Bad catalog accepted.'); }
        catch (\RuntimeException) { $this->assertUntouched($before); }
    }
    public static function catalogDamage(): array { return [[false], [true]]; }

    public function testBudgetExhaustionIsDistinctFromInvalidContent(): void
    {
        $catalog = $this->catalog()->create([$this->input(['a']), $this->input(['b']), $this->input(['c'])]); $before = $this->runs();
        try { $this->validator(2)->validate($catalog); self::fail('Budget exceeded.'); }
        catch (\LengthException $error) { self::assertStringContainsString('Comparison budget', $error->getMessage()); }
        $this->assertUntouched($before);
        self::assertSame(3, $this->validator(3)->validate($catalog)['comparison_count']);
    }

    /** @dataProvider badBudgets */
    public function testComparisonBudgetMustBePositive(int $budget): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->validator($budget);
    }
    public static function badBudgets(): array { return [[0], [-1]]; }

    private function closingCatalog(int $failOn, \Throwable $failure): AudioInventoryWorkspaceRunCatalog
    {
        return new class($this->workspace, new AudioInventoryRunCatalogCodec(128), $failOn, $failure) extends AudioInventoryWorkspaceRunCatalog {
            private int $closures = 0;
            public function __construct($workspace, $codec, private int $failOn, private \Throwable $failure) { parent::__construct($workspace, $codec); }
            protected function closeRun(string $id): void { parent::closeRun($id); if (++$this->closures === $this->failOn) { throw $this->failure; } }
        };
    }

    /** @dataProvider catalogClosureStages */
    public function testCatalogClosureFailurePreventsValidReport(int $failOn): void
    {
        $catalog = $this->catalog()->create([$this->input(['a'])]); $failure = new \RuntimeException('catalog close');
        $before = $this->runs();
        try { $this->validator(1, $this->closingCatalog($failOn, $failure))->validate($catalog); self::fail('Closure failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        $this->assertUntouched($before);
    }
    public static function catalogClosureStages(): array { return [[1], [2]]; }

    private function closingValidator(\Throwable $failure): AudioInventoryCatalogReferenceValidator
    {
        return new class($this->workspace, $this->catalog(), $this->paths, 100, $failure) extends AudioInventoryCatalogReferenceValidator {
            public function __construct($workspace, $catalog, $codec, $budget, private \Throwable $failure) { parent::__construct($workspace, $catalog, $codec, $budget); }
            protected function closeRun(string $id): void { parent::closeRun($id); throw $this->failure; }
        };
    }

    public function testBlockClosureFailurePreventsValidReport(): void
    {
        $catalog = $this->catalog()->create([$this->input(['a'])]); $failure = new \RuntimeException('block close'); $before = $this->runs();
        try { $this->closingValidator($failure)->validate($catalog); self::fail('Closure failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        $this->assertUntouched($before);
    }

    public function testBlockErrorIsPreservedWhenClosingAlsoFails(): void
    {
        $id = $this->input(['a']); $this->corrupt($id); $catalog = $this->catalog()->create([$id]);
        $secondary = new \RuntimeException('secondary close'); $validator = $this->closingValidator($secondary);
        try { $validator->validate($catalog); self::fail('Corruption concealed.'); }
        catch (\UnexpectedValueException $initial) { self::assertStringContainsString('checksum', $initial->getMessage()); }
        self::assertSame([$secondary], $validator->getCloseFailures()); foreach ($this->runs() as $run) { self::assertNull($run['stream']); }
    }

    public function testCatalogErrorIsPreservedWhenClosingAlsoFails(): void
    {
        $catalog = $this->catalog()->create([]); $this->corrupt($catalog);
        $secondary = new \RuntimeException('secondary catalog close'); $validator = $this->validator(1, $this->closingCatalog(1, $secondary));
        try { $validator->validate($catalog); self::fail('Corruption concealed.'); }
        catch (\UnexpectedValueException $initial) { self::assertStringContainsString('checksum', $initial->getMessage()); }
        self::assertSame([$secondary], $validator->getCloseFailures());
        // A later operation must not retain diagnostics from the failed one.
        $empty = $this->catalog()->create([]); self::assertTrue($validator->validate($empty)['valid']); self::assertSame([], $validator->getCloseFailures());
    }

    public function testPreexistingReaderIsNotClosedByValidator(): void
    {
        $catalog = $this->catalog()->create([$this->input(['a'])]); $stream = $this->workspace->openRead($catalog);
        try { $this->validator()->validate($catalog); self::fail('Busy catalog accepted.'); }
        catch (\LogicException|\LengthException) { self::assertTrue(is_resource($stream)); }
        finally { $this->workspace->close($catalog); }
    }

    public function testBoundedSyntheticValidationUsesOneStreamAndNoGlobalIdArray(): void
    {
        // The small fixture list is released before measurement. Validation itself
        // runs with a one-stream workspace and never receives this array.
        $fixtureIds = [];
        for ($i = 0; $i < 160; ++$i) { $fixtureIds[] = $this->input([sprintf('SYN/%04d', $i)]); }
        $catalog = $this->catalog()->create($fixtureIds); unset($fixtureIds);
        $before = $this->runs(); $baseline = memory_get_usage();
        $reader = new class($this->workspace, new AudioInventoryRunCatalogCodec(128), $baseline) extends AudioInventoryWorkspaceRunCatalog {
            public int $reads = 0; public int $peakOpen = 0; public int $growth = 0;
            public function __construct(private AudioInventoryTemporaryWorkspace $owner, $codec, private int $baseline) { parent::__construct($owner, $codec); }
            public function consume(string $id, \Closure $consumer): mixed { ++$this->reads; return parent::consume($id, $consumer); }
            protected function closeRun(string $id): void {
                $runs = (new \ReflectionProperty($this->owner, 'runs'))->getValue($this->owner); $open = 0;
                foreach ($runs as $run) { if (is_resource($run['stream'])) { ++$open; } }
                $this->peakOpen = max($this->peakOpen, $open); $this->growth = max($this->growth, memory_get_usage() - $this->baseline); parent::closeRun($id);
            }
        };
        $result = $this->validator(12720, $reader)->validate($catalog);
        self::assertSame(160, $result['validated_runs']); self::assertSame(12720, $result['comparison_count']);
        self::assertSame(161, $reader->reads); self::assertSame(1, $reader->peakOpen);
        self::assertLessThan(2 * 1024 * 1024, $reader->growth); $this->assertUntouched($before);
    }
}
