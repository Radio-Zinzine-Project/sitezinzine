<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunBuilder;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use PHPUnit\Framework\TestCase;

final class AudioInventoryPathRunBuilderTest extends TestCase
{
    private array $streams = [];
    private bool $wrapperRegistered = false;

    protected function tearDown(): void
    {
        try {
            foreach ($this->streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
            if ($this->wrapperRegistered) { stream_wrapper_unregister('zinzinebuilder'); }
        } finally { $this->streams = []; parent::tearDown(); }
    }

    public function testEmptySourceCreatesNoRun(): void
    {
        $builder = $this->builder();
        self::assertSame(self::report(), $builder->build([],
            static function (): mixed { self::fail('Empty source needs no stream.'); },
            static function (): void { self::fail('Empty source finalizes no run.'); },
        ));
    }

    /** @dataProvider runCases */
    public function testSortedRunsRespectBothLimitsAndAreCompletelyReadable(
        array $paths, int $maxPaths, int $maxBytes, array $expectedRuns,
    ): void {
        $codec = new AudioInventoryPathRunCodec(1024);
        $builder = new AudioInventoryPathRunBuilder($codec, $maxPaths, $maxBytes);
        $before = $paths;
        $received = [];
        $finalized = 0;
        $result = $builder->build($paths,
            fn (int $index): mixed => $this->memory(),
            function (mixed $stream, array $run) use ($codec, $maxPaths, $maxBytes, &$received, &$finalized): void {
                self::assertTrue(is_resource($stream));
                self::assertGreaterThan(0, ftell($stream), 'Builder leaves the stream at the end; caller controls reads.');
                rewind($stream);
                $decoded = iterator_to_array($codec->read($stream), false);
                ++$finalized;
                self::assertSame([
                    'run_index' => $finalized, 'status' => 'finalized', 'path_count' => count($decoded),
                    'path_bytes' => array_sum(array_map('strlen', $decoded)),
                ], $run);
                self::assertLessThanOrEqual($maxPaths, count($decoded));
                self::assertLessThanOrEqual($maxBytes, $run['path_bytes']);
                $received[] = $decoded;
                self::assertTrue(is_resource($stream));
            },
        );
        self::assertSame($expectedRuns, $received);
        self::assertSame(self::report(count($paths), count($expectedRuns), count($paths)), $result);
        self::assertSame($before, $paths);
    }

    public static function runCases(): array
    {
        return [
            'one' => [['a.mp3'], 3, 100, [['a.mp3']]],
            'unsorted' => [['z.mp3', 'a.mp3', 'b.mp3'], 3, 100, [['a.mp3', 'b.mp3', 'z.mp3']]],
            'strcmp rather than numeric sort' => [['2', '10', '1'], 3, 100, [['1', '10', '2']]],
            'case and accents' => [['é.mp3', 'a.mp3', 'A.mp3'], 3, 100, [['A.mp3', 'a.mp3', 'é.mp3']]],
            'raw bytes and historical characters' => [["x/\xff.mp3", "x/a\n.mp3", 'x/a\\.mp3', 'x/a b.mp3'], 4, 100, [["x/a\n.mp3", 'x/a b.mp3', 'x/a\\.mp3', "x/\xff.mp3"]]],
            'path count limit' => [['d', 'c', 'b', 'a', 'e'], 2, 100, [['c', 'd'], ['a', 'b'], ['e']]],
            'byte limit' => [['ccc', 'aa', 'b', 'dddd'], 10, 5, [['aa', 'ccc'], ['b', 'dddd']]],
            'flush before exceeding byte limit' => [['ccc', 'bb', 'aaaa'], 10, 6, [['bb', 'ccc'], ['aaaa']]],
            'both limits' => [['ccc', 'aa', 'b', 'dddd'], 2, 5, [['aa', 'ccc'], ['b', 'dddd']]],
            'individual path exactly at limit' => [['12345', 'a'], 4, 5, [['12345'], ['a']]],
            'byte count is not Unicode character count' => [['é', 'a', 'b'], 4, 3, [['a', 'é'], ['b']]],
            'no global ordering' => [['z', 'y', 'b', 'a'], 2, 10, [['y', 'z'], ['a', 'b']]],
            'duplicate across runs is left for merge' => [['a', 'a'], 1, 10, [['a'], ['a']]],
        ];
    }

    public function testFullRunIsFinalizedBeforeSourceAdvances(): void
    {
        $steps = [];
        $source = (static function () use (&$steps): \Generator {
            $steps[] = 'produce:b'; yield 'b';
            $steps[] = 'produce:a'; yield 'a';
            $steps[] = 'produce:c'; yield 'c';
            $steps[] = 'exhausted';
        })();
        $result = $this->builder(2)->build($source,
            function (int $index) use (&$steps): mixed { $steps[] = 'open:' . $index; return $this->memory(); },
            static function (mixed $stream, array $run) use (&$steps): void { $steps[] = 'finalize:' . $run['run_index']; },
        );
        self::assertSame(['produce:b', 'produce:a', 'open:1', 'finalize:1', 'produce:c', 'exhausted', 'open:2', 'finalize:2'], $steps);
        self::assertSame(self::report(3, 2, 3), $result);
    }

    public function testDuplicateInsideRunIsRejectedBeforeAllocatingAStream(): void
    {
        $builder = $this->builder(3);
        $this->expectException(\UnexpectedValueException::class);
        $builder->build(['b', 'a', 'b'],
            static function (): mixed { self::fail('A duplicate block is rejected before opening its stream.'); },
            static function (): void { self::fail('A duplicate block cannot be finalized.'); },
        );
    }

    public function testOverlongSinglePathIsRejectedBeforeAllocatingAStream(): void
    {
        $builder = $this->builder(3, 4);
        $this->expectException(\UnexpectedValueException::class);
        $builder->build(['12345'],
            static function (): mixed { self::fail('An oversized path needs no stream.'); }, static fn (): bool => true,
        );
    }

    /** @dataProvider invalidPaths */
    public function testCodecPathValidationCannotBeBypassed(mixed $path): void
    {
        $builder = $this->builder();
        $this->expectException(\UnexpectedValueException::class);
        $builder->build([$path], fn (): mixed => $this->memory(),
            static function (): void { self::fail('An invalid block must never be finalized.'); },
        );
    }

    public static function invalidPaths(): array
    {
        return [[null], [17], [''], ['/absolute.mp3'], ['dir/../file.mp3'], ['dir//file.mp3'], ['C:drive.mp3'], ["file\0.mp3"]];
    }

    public function testCodecRecordLimitRemainsEnforcedEvenWhenBlockBudgetIsLarger(): void
    {
        $builder = new AudioInventoryPathRunBuilder(new AudioInventoryPathRunCodec(3), 5, 100);
        $this->expectException(\UnexpectedValueException::class);
        $builder->build(['a.mp3'], fn (): mixed => $this->memory(),
            static function (): void { self::fail('Codec rejection cannot be reported as a valid block.'); },
        );
    }

    /** @dataProvider invalidLimits */
    public function testBothBlockLimitsMustBePositive(int $maxPaths, int $maxBytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder($maxPaths, $maxBytes);
    }

    public static function invalidLimits(): array { return [[0, 100], [-1, 100], [2, 0], [2, -1]]; }

    /** @dataProvider sourceFailurePositions */
    public function testSourceExceptionNeverReturnsASuccessReport(int $maxPaths, int $expectedFinalized): void
    {
        $failure = new \RuntimeException('Original source failure');
        $source = (static function () use ($failure): \Generator { yield 'b'; yield 'a'; throw $failure; })();
        $finalized = 0;
        $result = null;
        try {
            $result = $this->builder($maxPaths)->build($source, fn (): mixed => $this->memory(),
                static function () use (&$finalized): void { ++$finalized; },
            );
            self::fail('Source exception must propagate.');
        } catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertNull($result);
        self::assertSame($expectedFinalized, $finalized);
    }

    public static function sourceFailurePositions(): array { return [[3, 0], [2, 1]]; }

    public function testFactoryExceptionIsPropagatedWithoutFinalization(): void
    {
        $failure = new \RuntimeException('Cannot allocate stream');
        $builder = $this->builder();
        try {
            $builder->build(['a'], static function () use ($failure): mixed { throw $failure; },
                static function (): void { self::fail('No run exists to finalize.'); },
            );
            self::fail('Factory error must propagate.');
        } catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
    }

    public function testInvalidFactoryResultNeverBecomesAFinalizedRun(): void
    {
        $builder = $this->builder();
        $this->expectException(\InvalidArgumentException::class);
        $builder->build(['a'], static fn (): mixed => false,
            static function (): void { self::fail('Invalid stream cannot be finalized.'); },
        );
    }

    public function testFactoryCannotAppendToOrOverwriteAnExistingCallerStream(): void
    {
        $stream = $this->memory(); fwrite($stream, 'existing'); rewind($stream);
        $builder = $this->builder();
        try {
            $builder->build(['a'], static fn (): mixed => $stream, static function (): void { self::fail('Stream is not empty.'); });
            self::fail('Nonempty stream must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertTrue(is_resource($stream));
            rewind($stream); self::assertSame('existing', stream_get_contents($stream));
        }
    }

    public function testSerializationFailureLeavesAnOwnedIncompleteRunAndDoesNotFinalizeIt(): void
    {
        $builder = $this->builder(1);
        $created = [];
        $finalized = [];
        $result = null;
        try {
            $result = $builder->build(['a', 'b'], function (int $index) use (&$created): mixed {
                $stream = $index === 1 ? $this->memory() : $this->failingStream();
                $created[$index] = $stream; // Ownership recorded before serialization.
                return $stream;
            }, static function (mixed $stream, array $run) use (&$finalized): void { $finalized[] = $run['run_index']; });
            self::fail('The second codec write must fail.');
        } catch (\RuntimeException $error) { self::assertStringContainsString('write', $error->getMessage()); }
        self::assertNull($result);
        self::assertSame([1], $finalized);
        self::assertCount(2, $created);
        foreach ($created as $stream) { self::assertTrue(is_resource($stream)); }
        self::assertSame(0, AudioInventoryRunBuilderFailingStream::$closeCalls);
        self::assertSame("ZMP3RUN\x01", AudioInventoryRunBuilderFailingStream::$bytes);
        rewind($created[1]);
        self::assertSame(['a'], iterator_to_array((new AudioInventoryPathRunCodec(1024))->read($created[1]), false));
    }

    public function testFinalizationCallbackFailureIsNotMaskedByResumingTheSource(): void
    {
        $failure = new \RuntimeException('Owner notification failed');
        $source = (static function (): \Generator { yield 'a'; throw new \LogicException('Source must not resume.'); })();
        try {
            $this->builder(1)->build($source, fn (): mixed => $this->memory(), static function () use ($failure): void { throw $failure; });
            self::fail('Expected owner failure.');
        } catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertTrue(is_resource($this->streams[0]));
        unset($source);
    }

    public function testExplicitInterruptionStopsAfterAFinalizedRunWithoutAdvancingSource(): void
    {
        $produced = 0;
        $source = (static function () use (&$produced): \Generator {
            ++$produced; yield 'b'; ++$produced; yield 'a';
            self::fail('No lookahead is needed after reaching the count limit.'); yield 'c';
        })();
        $result = $this->builder(2)->build($source, fn (): mixed => $this->memory(), static fn (): bool => false);
        self::assertSame(2, $produced);
        self::assertSame(self::report(2, 1, 2, 'interrupted'), $result);
        unset($source);
    }

    public function testByteBoundaryLookaheadIsReceivedButNotSerializedAfterInterruption(): void
    {
        $result = $this->builder(10, 7)->build(['aa', 'bbb', 'cccc'], fn (): mixed => $this->memory(), static fn (): bool => false);
        self::assertSame(self::report(3, 1, 2, 'interrupted'), $result);
    }

    public function testLargeLazySourceDoesNotAccumulateRunsOrPaths(): void
    {
        $codec = new AudioInventoryPathRunCodec(64);
        $builder = new AudioInventoryPathRunBuilder($codec, 100, 4096);
        $produced = $serialized = $runs = $maxMemory = 0;
        $baseline = memory_get_usage();
        $source = (static function () use (&$produced, &$serialized): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                if ($produced - $serialized >= 100) { self::fail('Source advanced before the full block was finalized.'); }
                ++$produced; yield sprintf('SYN/%08d.mp3', 100000 - $i);
            }
        })();
        $result = $builder->build($source, static function (): mixed {
            $stream = fopen('php://memory', 'w+b');
            if ($stream === false) { throw new \RuntimeException('Cannot allocate isolated run.'); }
            return $stream;
        }, static function (mixed $stream, array $run) use ($codec, &$serialized, &$runs, &$maxMemory, $baseline): void {
            // Owner consumes and closes one run immediately; no global resource registry.
            try {
                rewind($stream);
                $previous = null; $count = 0;
                foreach ($codec->read($stream) as $path) {
                    if ($previous !== null && strcmp($previous, $path) >= 0) { self::fail('Run is not strictly sorted.'); }
                    $previous = $path; ++$count;
                }
                if ($count !== 100 || $run['path_count'] !== $count || $run['path_bytes'] > 4096) { self::fail('Run bounds violated.'); }
                $serialized += $count; ++$runs;
                $maxMemory = max($maxMemory, memory_get_usage() - $baseline);
            } finally { fclose($stream); }
        });
        self::assertSame(100000, $produced);
        self::assertSame(100000, $serialized);
        self::assertSame(1000, $runs);
        self::assertLessThan(2 * 1024 * 1024, $maxMemory);
        self::assertSame(self::report(100000, 1000, 100000), $result);
    }

    private function builder(int $maxPaths = 3, int $maxBytes = 100): AudioInventoryPathRunBuilder
    {
        return new AudioInventoryPathRunBuilder(new AudioInventoryPathRunCodec(1024), $maxPaths, $maxBytes);
    }

    private function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot allocate isolated memory stream.'); }
        $this->streams[] = $stream;
        return $stream;
    }

    private function failingStream(): mixed
    {
        if (!stream_wrapper_register('zinzinebuilder', AudioInventoryRunBuilderFailingStream::class)) { throw new \RuntimeException('Cannot register test wrapper.'); }
        $this->wrapperRegistered = true;
        AudioInventoryRunBuilderFailingStream::$bytes = '';
        AudioInventoryRunBuilderFailingStream::$closeCalls = 0;
        $stream = fopen('zinzinebuilder://isolated', 'wb');
        if ($stream === false) { throw new \RuntimeException('Cannot open test stream.'); }
        $this->streams[] = $stream;
        return $stream;
    }

    private static function report(int $received = 0, int $runs = 0, int $serialized = 0, string $status = 'completed'): array
    {
        return ['status' => $status, 'paths_received' => $received, 'runs_produced' => $runs, 'paths_serialized' => $serialized];
    }
}

/** A caller-owned stream failing after the real codec has written its header. */
final class AudioInventoryRunBuilderFailingStream
{
    public mixed $context;
    public static string $bytes = '';
    public static int $closeCalls = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $bytes): int|false
    {
        if (strlen(self::$bytes) >= 8) { return false; }
        $part = substr($bytes, 0, 8 - strlen(self::$bytes)); self::$bytes .= $part;
        return strlen($part);
    }
    public function stream_tell(): int { return strlen(self::$bytes); }
    public function stream_eof(): bool { return false; }
    public function stream_stat(): array { return ['size' => strlen(self::$bytes)]; }
    public function stream_flush(): bool { return true; }
    public function stream_close(): void { ++self::$closeCalls; }
}
