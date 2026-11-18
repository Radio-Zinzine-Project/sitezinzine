<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryPathComparator;
use PHPUnit\Framework\TestCase;

/** Pure byte-exact stream comparison, not a proof of real storage completeness. */
final class AudioInventoryPathComparatorTest extends TestCase
{
    /** @dataProvider comparisons */
    public function testExactCountsAndDifferenceOrder(array $expected, array $observed, int $matched, array $differences): void
    {
        $before = [$expected, $observed];
        $received = [];
        $result = (new AudioInventoryPathComparator())->compare($expected, $observed,
            static function (array $difference) use (&$received): void { $received[] = $difference; },
        );
        self::assertSame($differences, $received);
        $missing = count(array_filter($differences, static fn (array $difference): bool => $difference['type'] === 'missing'));
        self::assertSame(self::result('completed', $matched, $missing, count($differences) - $missing), $result);
        self::assertSame($before, [$expected, $observed]);
    }

    public static function comparisons(): array
    {
        return [
            'empty streams are equal without certifying a library' => [[], [], 0, []],
            'identical sorted paths' => [['A/ancien.mp3', 'Z/Émission.MP3'], ['A/ancien.mp3', 'Z/Émission.MP3'], 2, []],
            'missing middle path' => [['a.mp3', 'b.mp3', 'c.mp3'], ['a.mp3', 'c.mp3'], 2, [self::difference('missing', 'b.mp3')]],
            'unexpected middle path' => [['a.mp3', 'c.mp3'], ['a.mp3', 'b.mp3', 'c.mp3'], 2, [self::difference('unexpected', 'b.mp3')]],
            'only missing paths' => [['a.mp3', 'b.mp3'], [], 0, [self::difference('missing', 'a.mp3'), self::difference('missing', 'b.mp3')]],
            'only unexpected paths' => [[], ['a.mp3', 'b.mp3'], 0, [self::difference('unexpected', 'a.mp3'), self::difference('unexpected', 'b.mp3')]],
            'interleaved differences' => [['a.mp3', 'c.mp3', 'e.mp3', 'g.mp3'], ['b.mp3', 'c.mp3', 'f.mp3', 'h.mp3'], 1, [
                self::difference('missing', 'a.mp3'), self::difference('unexpected', 'b.mp3'), self::difference('missing', 'e.mp3'),
                self::difference('unexpected', 'f.mp3'), self::difference('missing', 'g.mp3'), self::difference('unexpected', 'h.mp3'),
            ]],
            'case is significant' => [['Archive.mp3'], ['archive.mp3'], 0, [self::difference('missing', 'Archive.mp3'), self::difference('unexpected', 'archive.mp3')]],
            'accent is significant' => [['Ete.mp3'], ['Été.mp3'], 0, [self::difference('missing', 'Ete.mp3'), self::difference('unexpected', 'Été.mp3')]],
            'Unicode is not normalized' => [["e\u{0301}te.mp3"], ['éte.mp3'], 0, [self::difference('missing', "e\u{0301}te.mp3"), self::difference('unexpected', 'éte.mp3')]],
            'literal backslash is not converted to directory separator' => [['dir/name.mp3'], ['dir\\name.mp3'], 0, [self::difference('missing', 'dir/name.mp3'), self::difference('unexpected', 'dir\\name.mp3')]],
            'historical filenames are not restricted to new naming rules' => [['0', 'Archives/Émission du 1er mai (copie).MP3'], ['0', 'Archives/Émission du 1er mai (copie).MP3'], 2, []],
        ];
    }

    /** @dataProvider invalidOrderings */
    public function testDuplicatesOrDisorderAreRejectedWithoutSorting(array $expected, array $observed): void
    {
        $comparator = new AudioInventoryPathComparator();
        $this->expectException(\UnexpectedValueException::class);
        $comparator->compare($expected, $observed, static fn (): bool => true);
    }

    public static function invalidOrderings(): array
    {
        return [
            'duplicate expected' => [['a.mp3', 'a.mp3'], ['a.mp3']],
            'duplicate observed' => [['a.mp3'], ['a.mp3', 'a.mp3']],
            'disorder expected' => [['b.mp3', 'a.mp3'], ['a.mp3', 'b.mp3']],
            'disorder observed' => [['a.mp3', 'b.mp3'], ['b.mp3', 'a.mp3']],
            'late duplicate expected' => [['a.mp3', 'b.mp3', 'c.mp3', 'c.mp3'], ['a.mp3', 'b.mp3', 'c.mp3']],
            'late duplicate observed' => [['a.mp3', 'b.mp3', 'c.mp3'], ['a.mp3', 'b.mp3', 'c.mp3', 'c.mp3']],
            'late disorder expected' => [['a.mp3', 'b.mp3', 'd.mp3', 'c.mp3'], ['a.mp3', 'b.mp3', 'c.mp3', 'd.mp3']],
            'late disorder observed' => [['a.mp3', 'b.mp3', 'c.mp3', 'd.mp3'], ['a.mp3', 'b.mp3', 'd.mp3', 'c.mp3']],
            'expected tail checked after observed exhausted' => [['a.mp3', 'c.mp3', 'b.mp3'], []],
            'observed tail checked after expected exhausted' => [[], ['a.mp3', 'c.mp3', 'b.mp3']],
            'numeric-looking strings use strcmp order' => [['2', '10'], ['10', '2']],
        ];
    }

    /** @dataProvider invalidPaths */
    public function testMalformedPathsInEitherStreamCannotProduceASuccess(mixed $path, bool $inExpected): void
    {
        $comparator = new AudioInventoryPathComparator();
        $this->expectException(\UnexpectedValueException::class);
        $comparator->compare($inExpected ? [$path] : [], $inExpected ? [] : [$path], static fn (): bool => true);
    }

    public static function invalidPaths(): array
    {
        $cases = [];
        $paths = [null, false, 17, [], '', '/absolute.mp3', '//server/share.mp3', 'C:/absolute.mp3', 'C:\\absolute.mp3', '\\\\server\\share.mp3',
            '.', '..', './file.mp3', '../file.mp3', 'dir/./file.mp3', 'dir/../file.mp3', 'dir//file.mp3', 'dir/', "file\0.mp3"];
        foreach ($paths as $index => $path) {
            $cases['expected ' . $index] = [$path, true];
            $cases['observed ' . $index] = [$path, false];
        }
        return $cases;
    }

    /** @dataProvider interruptionSides */
    public function testFalseInterruptsWithoutAdvancingEitherStream(bool $missing): void
    {
        $producedExpected = $producedObserved = 0;
        $expected = (static function () use (&$producedExpected, $missing): \Generator {
            ++$producedExpected; yield $missing ? 'a.mp3' : 'b.mp3';
            self::fail('Expected iterator must not advance after interruption.');
            yield 'z.mp3';
        })();
        $observed = (static function () use (&$producedObserved, $missing): \Generator {
            ++$producedObserved; yield $missing ? 'b.mp3' : 'a.mp3';
            self::fail('Observed iterator must not advance after interruption.');
            yield 'z.mp3';
        })();
        $differences = [];
        $result = (new AudioInventoryPathComparator())->compare($expected, $observed,
            static function (array $difference) use (&$differences): bool { $differences[] = $difference; return false; },
        );
        self::assertSame(1, $producedExpected);
        self::assertSame(1, $producedObserved);
        self::assertSame([self::difference($missing ? 'missing' : 'unexpected', 'a.mp3')], $differences);
        self::assertSame(self::result('interrupted', 0, $missing ? 1 : 0, $missing ? 0 : 1), $result);
        unset($expected, $observed);
    }

    public static function interruptionSides(): array { return [[true], [false]]; }

    public function testRefusingTheLastDifferenceStillDoesNotAnnounceCompletion(): void
    {
        self::assertSame(self::result('interrupted', 1, 1, 0), (new AudioInventoryPathComparator())->compare(
            ['a.mp3', 'b.mp3'], ['a.mp3'], static fn (): bool => false,
        ));
    }

    public function testOnlyStrictFalseInterrupts(): void
    {
        $calls = 0;
        $result = (new AudioInventoryPathComparator())->compare(['a.mp3', 'b.mp3'], [],
            static function () use (&$calls): int { ++$calls; return 0; },
        );
        self::assertSame(2, $calls);
        self::assertSame(self::result('completed', 0, 2, 0), $result);
    }

    /** @dataProvider failingStreams */
    public function testIterableFailurePropagatesWithoutPartialSuccess(bool $expectedFails, bool $late): void
    {
        $failure = new \RuntimeException('Synthetic iterator failure');
        $failing = (static function () use ($failure, $late): \Generator { if ($late) { yield 'a.mp3'; } throw $failure; })();
        $result = null;
        try {
            $result = (new AudioInventoryPathComparator())->compare($expectedFails ? $failing : ['a.mp3'], $expectedFails ? ['a.mp3'] : $failing, static fn (): bool => true);
            self::fail('Expected original iterable failure.');
        } catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertNull($result);
    }

    public static function failingStreams(): array { return [[true, false], [false, false], [true, true], [false, true]]; }

    public function testConsumerFailureIsNotMaskedByResumingAnIterator(): void
    {
        $failure = new \RuntimeException('Consumer failed');
        $expected = (static function (): \Generator { yield 'a.mp3'; throw new \LogicException('Secondary iterator error'); })();
        $result = null;
        try {
            $result = (new AudioInventoryPathComparator())->compare($expected, [], static function () use ($failure): void { throw $failure; });
            self::fail('Expected original consumer failure.');
        } catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertNull($result);
        unset($expected);
    }

    public function testIteratorAggregatesAreRequestedOnlyOnce(): void
    {
        $expected = new class implements \IteratorAggregate {
            public int $calls = 0;
            public function getIterator(): \Traversable { if (++$this->calls !== 1) { throw new \LogicException('Replayed iterator'); } yield 'a.mp3'; }
        };
        $observed = new class implements \IteratorAggregate {
            public int $calls = 0;
            public function getIterator(): \Traversable { if (++$this->calls !== 1) { throw new \LogicException('Replayed iterator'); } yield 'a.mp3'; }
        };
        self::assertSame(self::result('completed', 1, 0, 0), (new AudioInventoryPathComparator())->compare($expected, $observed,
            static function (): void { self::fail('Identical paths have no differences.'); },
        ));
        self::assertSame(1, $expected->calls);
        self::assertSame(1, $observed->calls);
    }

    public function testLargeIdenticalStreamsAreMergedWithOneElementOfLookahead(): void
    {
        $expectedCount = $observedCount = $maxMemory = 0;
        $baseline = memory_get_usage();
        $expected = (static function () use (&$expectedCount, &$observedCount): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                if (++$expectedCount - $observedCount > 1) { self::fail('Expected stream was eagerly loaded.'); }
                yield sprintf('SYN/%08d.mp3', $i);
            }
        })();
        $observed = (static function () use (&$expectedCount, &$observedCount, &$maxMemory, $baseline): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                if (++$observedCount - $expectedCount > 1) { self::fail('Observed stream was eagerly loaded.'); }
                $maxMemory = max($maxMemory, memory_get_usage() - $baseline);
                yield sprintf('SYN/%08d.mp3', $i);
            }
        })();
        $result = (new AudioInventoryPathComparator())->compare($expected, $observed, static function (): void { self::fail('No differences expected.'); });
        self::assertSame(100000, $expectedCount);
        self::assertSame(100000, $observedCount);
        self::assertLessThan(2 * 1024 * 1024, $maxMemory);
        self::assertSame(self::result('completed', 100000, 0, 0), $result);
    }

    public function testLargeDifferenceStreamDoesNotAccumulatePathsOrDifferences(): void
    {
        $paths = (static function (): \Generator { for ($i = 0; $i < 100000; ++$i) { yield sprintf('SYN/%08d.mp3', $i); } })();
        $calls = $maxMemory = 0;
        $baseline = memory_get_usage();
        $result = (new AudioInventoryPathComparator())->compare($paths, [],
            static function (array $difference) use (&$calls, &$maxMemory, $baseline): void {
                ++$calls;
                $maxMemory = max($maxMemory, memory_get_usage() - $baseline);
            },
        );
        self::assertSame(100000, $calls);
        self::assertLessThan(2 * 1024 * 1024, $maxMemory);
        self::assertSame(self::result('completed', 0, 100000, 0), $result);
    }

    private static function difference(string $type, string $path): array { return ['type' => $type, 'relative_path' => $path]; }

    private static function result(string $status, int $matched, int $missing, int $unexpected): array
    {
        return ['status' => $status, 'matched_count' => $matched, 'missing_count' => $missing,
            'unexpected_count' => $unexpected, 'paths_equal' => $status === 'completed' && $missing === 0 && $unexpected === 0];
    }
}
