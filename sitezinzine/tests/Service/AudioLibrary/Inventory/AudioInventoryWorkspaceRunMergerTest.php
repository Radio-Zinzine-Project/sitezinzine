<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunMerger;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunMerger;
use PHPUnit\Framework\TestCase;

final class AudioInventoryWorkspaceRunMergerTest extends TestCase
{
    private string $root;
    private AudioInventoryTemporaryWorkspace $workspace;
    private AudioInventoryPathRunCodec $codec;
    private array $otherWorkspaces = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-group-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 5, 12);
        $this->codec = new AudioInventoryPathRunCodec(1024);
    }

    protected function tearDown(): void
    {
        try {
            $this->workspace->cleanup();
            foreach ($this->otherWorkspaces as $workspace) { $workspace->cleanup(); }
        } finally {
            // Only this test's isolated directory; no recursive arbitrary cleanup.
            rmdir($this->root);
            parent::tearDown();
        }
    }

    private function input(array $paths, ?AudioInventoryTemporaryWorkspace $workspace = null): string
    {
        $workspace ??= $this->workspace;
        $id = $workspace->createRun();
        $this->codec->write($workspace->openWrite($id), $paths);
        $workspace->close($id); $workspace->finalize($id);
        return $id;
    }

    private function service(int $limit = 4, int $readers = 4): AudioInventoryWorkspaceRunMerger
    {
        return new AudioInventoryWorkspaceRunMerger($this->workspace, new AudioInventoryPathRunMerger($this->codec, $readers), $limit);
    }

    private function runs(): array
    {
        // Read-only observation of states/resources; never substitutes SQL or file operations.
        return (new \ReflectionProperty($this->workspace, 'runs'))->getValue($this->workspace);
    }

    private function assertFailureState(array $inputs): void
    {
        foreach ($this->runs() as $id => $run) {
            self::assertFalse(is_resource($run['stream']), 'Operation must close its streams.');
            self::assertSame(in_array($id, $inputs, true) ? 'finalized' : 'writing', $run['state']);
            self::assertFileExists($run['path']);
        }
    }

    private function read(string $id): array
    {
        try { return iterator_to_array($this->codec->read($this->workspace->openRead($id)), false); }
        finally { $this->workspace->close($id); }
    }

    /** @dataProvider groups */
    public function testRealMergePreservesInputsAndProducesFullyReadableOutput(array $groups, array $expected): void
    {
        $ids = array_map(fn ($paths) => $this->input($paths), $groups);
        $before = $this->runs(); $service = $this->service();
        $output = $service->merge($ids);
        self::assertNotContains($output, $ids);
        self::assertSame($expected, $this->read($output));
        self::assertSame([], $service->getCloseFailures());
        foreach ($ids as $index => $id) {
            self::assertSame($groups[$index], $this->read($id));
            self::assertSame(file_get_contents($before[$id]['path']), file_get_contents($this->runs()[$id]['path']));
        }
        foreach ($this->runs() as $run) { self::assertNull($run['stream']); self::assertSame('finalized', $run['state']); }
    }

    public static function groups(): array
    {
        return [
            [[['a', 'c'], ['b', 'd']], ['a', 'b', 'c', 'd']],
            [[['A', 'z'], ['Z', 'é'], ['a'], []], ['A', 'Z', 'a', 'z', 'é']],
            [[["a\n.mp3", 'dir\\name'], ['a b.mp3', "x/\xff"]], ["a\n.mp3", 'a b.mp3', 'dir\\name', "x/\xff"]],
            [[[], []], []],
        ];
    }

    /** @dataProvider invalidGroups */
    public function testInvalidGroupIsRejectedBeforeCreatingOutput(array $ids): void
    {
        $service = $this->service(2);
        try { $service->merge($ids); self::fail('Invalid group accepted.'); }
        catch (\InvalidArgumentException) { self::assertSame([], $this->runs()); }
    }

    public static function invalidGroups(): array
    {
        return [[[]], [['one']], [['one', 'two', 'three']], [['same', 'same']], [['one', 2]], [['one', '']], [['one', '../path']]];
    }

    /** @dataProvider invalidLimits */
    public function testInvalidLimit(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->service($limit);
    }
    public static function invalidLimits(): array { return [[0], [1], [-1]]; }

    public function testUnknownInputClosesAlreadyOpenedInput(): void
    {
        $a = $this->input(['a']); $service = $this->service();
        try { $service->merge([$a, 'unknown']); self::fail('Unknown input accepted.'); }
        catch (\InvalidArgumentException) { $this->assertFailureState([$a]); }
    }

    public function testForeignInputIsRejected(): void
    {
        $other = new AudioInventoryTemporaryWorkspace($this->root, 3, 3); $this->otherWorkspaces[] = $other;
        $a = $this->input(['a']); $b = $this->input(['b'], $other);
        $this->expectException(\InvalidArgumentException::class); $this->service()->merge([$a, $b]);
    }

    public function testIncompleteInputIsRejected(): void
    {
        $a = $this->input(['a']); $b = $this->workspace->createRun();
        try { $this->service()->merge([$a, $b]); self::fail('Incomplete input accepted.'); }
        catch (\LogicException) { $this->assertFailureState([$a]); }
    }

    public function testDiscardedInputIsRejected(): void
    {
        $a = $this->input(['a']); $b = $this->input(['b']); $this->workspace->discard($b);
        $this->expectException(\LogicException::class); $this->service()->merge([$a, $b]);
    }

    public function testInsufficientStreamBudgetClosesOnlyOperationStreams(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 2, 6);
        $a = $this->input(['a']); $b = $this->input(['b']);
        try { $this->service()->merge([$a, $b]); self::fail('Stream budget ignored.'); }
        catch (\LengthException) { $this->assertFailureState([$a, $b]); }
        self::assertSame(['a'], $this->read($a));
    }

    public function testExhaustedCreationBudgetPreservesInputs(): void
    {
        $this->workspace->cleanup(); $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 3, 2);
        $a = $this->input(['a']); $b = $this->input(['b']);
        try { $this->service()->merge([$a, $b]); self::fail('Creation budget ignored.'); }
        catch (\LengthException) { self::assertCount(2, $this->runs()); $this->assertFailureState([$a, $b]); }
    }

    /** @dataProvider invalidContents */
    public function testMergeFailureNeverFinalizesOutputAndPreservesInputs(array $left, array $right): void
    {
        $a = $this->input($left); $b = $this->input($right);
        try { $this->service()->merge([$a, $b]); self::fail('Invalid merge succeeded.'); }
        catch (\UnexpectedValueException) { $this->assertFailureState([$a, $b]); }
        self::assertSame($left, $this->read($a)); self::assertSame($right, $this->read($b));
    }
    public static function invalidContents(): array { return [[[ 'a', 'z'], ['b', 'z']], [['b', 'a'], ['c']]]; }

    public function testCorruptFooterPreventsFinalization(): void
    {
        $a = $this->input(['a']); $b = $this->input(['z']); $path = $this->runs()[$b]['path'];
        $bytes = file_get_contents($path); $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1); file_put_contents($path, $bytes);
        try { $this->service()->merge([$a, $b]); self::fail('Corruption accepted.'); }
        catch (\UnexpectedValueException) { $this->assertFailureState([$a, $b]); }
    }

    public function testMergerReaderLimitFailureClosesEverything(): void
    {
        $a = $this->input(['a']); $b = $this->input(['b']);
        try { $this->service(4, 1)->merge([$a, $b]); self::fail('Reader limit ignored.'); }
        catch (\LengthException) { $this->assertFailureState([$a, $b]); }
    }

    public function testPreexistingReaderIsNotClosedByOperation(): void
    {
        $a = $this->input(['a']); $b = $this->input(['b']); $stream = $this->workspace->openRead($b);
        try { $this->service()->merge([$a, $b]); self::fail('Busy input accepted.'); }
        catch (\LogicException) { self::assertTrue(is_resource($stream)); self::assertNull($this->runs()[$a]['stream']); }
        finally { $this->workspace->close($b); }
    }

    private function faultService(array $failures): AudioInventoryWorkspaceRunMerger
    {
        // The real workspace closes the real streams before deterministic fault injection.
        return new class($this->workspace, new AudioInventoryPathRunMerger($this->codec, 4), 4, $failures) extends AudioInventoryWorkspaceRunMerger {
            public array $closedIds = [];
            public function __construct($workspace, $merger, $limit, private array $failures) { parent::__construct($workspace, $merger, $limit); }
            protected function closeRun(string $runId): void
            {
                parent::closeRun($runId); $this->closedIds[] = $runId;
                $failure = array_shift($this->failures);
                if ($failure instanceof \Throwable) { throw $failure; }
            }
        };
    }

    public function testClosureFailurePreventsFinalizationAndOtherClosuresContinue(): void
    {
        $a = $this->input(['a']); $b = $this->input(['b']); $failure = new \RuntimeException('close');
        $service = $this->faultService([$failure]);
        try { $service->merge([$a, $b]); self::fail('Closure failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertCount(3, $service->closedIds); $this->assertFailureState([$a, $b]);
    }

    public function testMergeExceptionIsPreservedAndSecondaryCloseFailureRemainsObservable(): void
    {
        $a = $this->input(['a']); $b = $this->input(['a']); $secondary = new \RuntimeException('secondary close');
        $service = $this->faultService([$secondary]);
        try { $service->merge([$a, $b]); self::fail('Duplicate accepted.'); }
        catch (\UnexpectedValueException $initial) { self::assertStringContainsString('Duplicate', $initial->getMessage()); }
        self::assertSame([$secondary], $service->getCloseFailures()); self::assertCount(3, $service->closedIds);
        $this->assertFailureState([$a, $b]);
        $service->merge([$a, $this->input(['b'])]);
        self::assertSame([], $service->getCloseFailures(), 'Diagnostics belong to the most recent call only.');
    }

    public function testSecondaryClosureFailuresDoNotReplaceFirstClosureFailure(): void
    {
        $a = $this->input(['a']); $b = $this->input(['b']);
        $first = new \RuntimeException('first'); $second = new \RuntimeException('second');
        $service = $this->faultService([$first, $second]);
        try { $service->merge([$a, $b]); self::fail('Closure failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($first, $actual); }
        self::assertSame([$second], $service->getCloseFailures()); $this->assertFailureState([$a, $b]);
    }
}
