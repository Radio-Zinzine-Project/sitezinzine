<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryPhysicalCollector;
use App\Service\AudioLibrary\Inventory\AudioInventoryStorageDecision;
use PHPUnit\Framework\TestCase;

/** Pure, synthetic observations; no disk, authenticated attestation or storage certification. */
final class AudioInventoryPhysicalCollectorTest extends TestCase
{
    public function testEmptyTraversalCompletesButCannotSupplyMissingCompletenessEvidence(): void
    {
        $context = self::context();
        $before = $context;
        $calls = 0;
        $report = (new AudioInventoryPhysicalCollector())->collect([], $context,
            static function (): void { self::fail('An empty traversal has no file.'); },
            static function () use (&$calls): string { ++$calls; return 'g2'; },
        );
        self::assertSame(self::report(), $report);
        self::assertSame(2, $calls);
        self::assertSame($before, $context);
        $decision = $this->decide($report, false);
        self::assertSame('unverified', $decision['state']);
        self::assertSame(['completeness_missing'], $decision['reasons']);
        self::assertFalse($decision['absence_conclusions_allowed']);
    }

    public function testFilesAreDeliveredUnchangedInOrderWithoutSortingOrDeduplication(): void
    {
        $files = [self::file('Z/Émission (ancienne).MP3'), self::file('a/archive.mp3'), self::file('a/archive.mp3')];
        $received = [];
        $report = (new AudioInventoryPhysicalCollector())->collect($files, self::context(),
            static function (array $file) use (&$received): void { $received[] = $file; },
            static fn (): string => 'g2',
        );
        self::assertSame($files, $received);
        self::assertSame(self::report(), $report);
    }

    public function testEachFileIsConsumedBeforeTheProducerAdvances(): void
    {
        $steps = [];
        $files = (static function () use (&$steps): \Generator {
            $steps[] = 'produce:a'; yield self::file('a.mp3');
            $steps[] = 'produce:b'; yield self::file('b.mp3');
            $steps[] = 'exhausted';
        })();
        $report = (new AudioInventoryPhysicalCollector())->collect($files, self::context(),
            static function (array $file) use (&$steps): bool { $steps[] = 'consume:' . $file['relative_path'][0]; return true; },
            static function () use (&$steps): string { $steps[] = 'generation'; return 'g2'; },
        );
        self::assertSame(['generation', 'produce:a', 'consume:a', 'produce:b', 'consume:b', 'exhausted', 'generation'], $steps);
        self::assertSame(self::report(), $report);
    }

    /** @dataProvider errorCounts */
    public function testScannerErrorsAreCountedAndNeverDeliveredAsFiles(int $count): void
    {
        $observations = (static function () use ($count): \Generator {
            yield self::file('file.mp3');
            for ($i = 0; $i < $count; ++$i) {
                yield ['type' => 'error', 'relative_path' => $i === 0 ? '' : 'private-link', 'reason' => 'symlink_skipped'];
            }
        })();
        $files = 0;
        $report = (new AudioInventoryPhysicalCollector())->collect($observations, self::context(),
            static function (array $file) use (&$files): bool { ++$files; return true; }, static fn (): string => 'g2',
        );
        self::assertSame(1, $files);
        self::assertSame(self::report(errors: $count), $report);
        $decision = $this->decide($report);
        self::assertSame('incomplete', $decision['state']);
        self::assertSame(['collection_errors'], $decision['reasons']);
        self::assertFalse($decision['absence_conclusions_allowed']);
    }

    public static function errorCounts(): array { return [[1], [3]]; }

    public function testExplicitInterruptionNeverAdvancesToTheNextObservation(): void
    {
        $produced = $consumed = $generationCalls = 0;
        $observations = (static function () use (&$produced): \Generator {
            ++$produced; yield ['type' => 'error', 'relative_path' => '', 'reason' => 'unreadable_directory'];
            ++$produced; yield self::file('first.mp3');
            self::fail('The iterator must not resume after the consumer returned false.');
            yield self::file('second.mp3');
        })();
        $report = (new AudioInventoryPhysicalCollector())->collect($observations, self::context(),
            static function () use (&$consumed): bool { ++$consumed; return false; },
            static function () use (&$generationCalls): string { ++$generationCalls; return 'g2'; },
        );
        self::assertSame(2, $produced);
        self::assertSame(1, $consumed);
        self::assertSame(2, $generationCalls);
        self::assertSame(self::report('interrupted', 1), $report);
        self::assertFalse($this->decide($report)['absence_conclusions_allowed']);
        // The caller owns the generator and releases it; no further iteration.
        unset($observations);
    }

    public function testRefusalOfTheLastFileStillReportsInterruption(): void
    {
        $report = (new AudioInventoryPhysicalCollector())->collect([self::file('only.mp3')], self::context(),
            static fn (): bool => false, static fn (): string => 'g2',
        );
        self::assertSame(self::report('interrupted'), $report);
        self::assertSame('incomplete', $this->decide($report)['state']);
    }

    public function testOnlyStrictFalseInterruptsTheConsumer(): void
    {
        $consumed = 0;
        $report = (new AudioInventoryPhysicalCollector())->collect([self::file('a.mp3'), self::file('b.mp3')], self::context(),
            static function () use (&$consumed): int { ++$consumed; return 0; }, static fn (): string => 'g2',
        );
        self::assertSame(2, $consumed);
        self::assertSame(self::report(), $report);
    }

    /** @dataProvider failureStages */
    public function testOriginalExceptionIsPropagatedWithoutAFailedOrSuccessfulReport(string $stage): void
    {
        $failure = new \RuntimeException('Synthetic original failure');
        $generationCalls = $consumed = 0;
        $observations = (static function () use ($failure, $stage): \Generator {
            yield self::file('first.mp3');
            if ($stage === 'iteration') { throw $failure; }
        })();
        $result = null;
        try {
            $result = (new AudioInventoryPhysicalCollector())->collect($observations, self::context(),
                static function () use ($failure, $stage, &$consumed): bool {
                    ++$consumed;
                    if ($stage === 'consumer') { throw $failure; }
                    return true;
                },
                static function () use ($failure, $stage, &$generationCalls): string {
                    ++$generationCalls;
                    if (($stage === 'generation_before' && $generationCalls === 1)
                        || ($stage === 'generation_after' && $generationCalls === 2)) { throw $failure; }
                    return 'g2';
                },
            );
            self::fail('The original failure must propagate.');
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertNull($result);
        self::assertSame($stage === 'generation_after' ? 2 : 1, $generationCalls);
        self::assertSame($stage === 'generation_before' ? 0 : 1, $consumed);
    }

    public static function failureStages(): array { return [['iteration'], ['consumer'], ['generation_before'], ['generation_after']]; }

    public function testNoSecondaryGenerationReadCanMaskAnIterationFailure(): void
    {
        $failure = new \RuntimeException('Iterator failed');
        $calls = 0;
        $observations = (static function () use ($failure): \Generator { throw $failure; yield self::file('never.mp3'); })();
        try {
            (new AudioInventoryPhysicalCollector())->collect($observations, self::context(), static fn (): bool => true,
                static function () use (&$calls): string {
                    if (++$calls > 1) { throw new \LogicException('Secondary generation failure'); }
                    return 'g2';
                },
            );
            self::fail('Expected iterator failure.');
        } catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertSame(1, $calls);
    }

    /** @dataProvider generationPairs */
    public function testObservedGenerationsAreNeverReplacedByExpectedGeneration(string $before, string $after, array $reasons): void
    {
        $calls = 0;
        $report = (new AudioInventoryPhysicalCollector())->collect([], self::context(), static fn (): bool => true,
            static function () use (&$calls, $before, $after): string { return $calls++ === 0 ? $before : $after; },
        );
        self::assertSame(self::report(before: $before, after: $after), $report);
        $decision = $this->decide($report);
        self::assertSame('incomplete', $decision['state']);
        self::assertSame($reasons, $decision['reasons']);
        self::assertFalse($decision['absence_conclusions_allowed']);
    }

    public static function generationPairs(): array
    {
        return [
            ['g2', 'g3', ['collection_generation_mismatch', 'collection_generation_changed']],
            ['g1', 'g1', ['collection_generation_mismatch']],
        ];
    }

    /** @dataProvider invalidContexts */
    public function testContextIsValidatedBeforeGenerationReadsAndIteration(array $context): void
    {
        $observations = (static function (): \Generator { self::fail('Invalid context must not start iteration.'); yield self::file('never.mp3'); })();
        $collector = new AudioInventoryPhysicalCollector();
        $this->expectException(\InvalidArgumentException::class);
        $collector->collect($observations, $context,
            static function (): void { self::fail('No file may be consumed.'); },
            static function (): string { self::fail('No generation may be read.'); },
        );
    }

    public static function invalidContexts(): array
    {
        $cases = ['empty' => [[]]];
        foreach (array_keys(self::context()) as $field) {
            $missing = self::context(); unset($missing[$field]);
            $cases['missing ' . $field] = [$missing];
        }
        foreach (['library_id' => '', 'generation' => null, 'scope' => ' ', 'source' => true, 'evidence_id' => 0, 'storage_kind' => 'production'] as $field => $value) {
            $cases['invalid ' . $field] = [array_replace(self::context(), [$field => $value])];
        }
        return $cases;
    }

    public function testContextIsNotUsedToSmuggleSuccessOrCompletenessIntoTheReport(): void
    {
        $context = self::context() + ['status' => 'verified', 'error_count' => 0, 'generation_before' => 'fake', 'completeness' => true, 'secret' => 'hidden'];
        $before = $context;
        $report = (new AudioInventoryPhysicalCollector())->collect([], $context, static fn (): bool => true, static fn (): string => 'g2');
        self::assertSame(self::report(), $report);
        self::assertSame($before, $context);
        self::assertArrayNotHasKey('completeness', $report);
    }

    /** @dataProvider invalidObservations */
    public function testMalformedObservationsCannotBeSilentlySkipped(mixed $observation): void
    {
        $collector = new AudioInventoryPhysicalCollector();
        $this->expectException(\UnexpectedValueException::class);
        $collector->collect([$observation], self::context(),
            static function (): void { self::fail('Invalid observation must not reach the consumer.'); }, static fn (): string => 'g2',
        );
    }

    public static function invalidObservations(): array
    {
        return [[null], [[]], [['type' => 'unknown', 'relative_path' => 'a.mp3']], [['type' => 'file']],
            [['type' => 'file', 'relative_path' => '']], [['type' => 'error', 'relative_path' => '']],
            [['type' => 'error', 'relative_path' => '', 'reason' => false]]];
    }

    /** @dataProvider invalidGenerations */
    public function testInvalidGenerationCannotBePresentedAsAValidObservation(mixed $generation): void
    {
        $collector = new AudioInventoryPhysicalCollector();
        $this->expectException(\UnexpectedValueException::class);
        $collector->collect([], self::context(), static fn (): bool => true, static fn (): mixed => $generation);
    }

    public static function invalidGenerations(): array { return [[null], [''], [' '], [true], [2]]; }

    public function testLazyLargeTraversalUsesBoundedMemoryAndReturnsNoPathList(): void
    {
        $produced = $consumed = $maxMemory = 0;
        $baseline = memory_get_usage();
        $observations = (static function () use (&$produced, &$consumed): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                if ($produced !== $consumed) { self::fail('Producer must not run ahead of consumer.'); }
                ++$produced;
                yield self::file('SYN/' . $i . '.mp3');
            }
        })();
        $report = (new AudioInventoryPhysicalCollector())->collect($observations, self::context(),
            static function (array $file) use (&$consumed, &$maxMemory, $baseline): void {
                ++$consumed;
                $maxMemory = max($maxMemory, memory_get_usage() - $baseline);
            }, static fn (): string => 'g2',
        );
        self::assertSame(100000, $produced);
        self::assertSame(100000, $consumed);
        self::assertLessThan(2 * 1024 * 1024, $maxMemory);
        self::assertSame(self::report(), $report);
    }

    public function testObservationOnlyAllowsUnknownMetadataAndNoGenerationReader(): void
    {
        $context = ['scope' => 'web_full', 'source' => 'physical-collector', 'evidence_id' => 'actual-observation-17'];
        $files = [];
        $report = (new AudioInventoryPhysicalCollector())->collect([self::file('old.mp3')], $context,
            static function ($file) use (&$files): void { $files[] = $file; }, null, 'observation_only');
        self::assertSame([self::file('old.mp3')], $files); self::assertSame('observation_only', $report['collection_mode']);
        foreach (['library_id', 'storage_kind', 'generation', 'generation_before', 'generation_after'] as $key) { self::assertArrayHasKey($key, $report); self::assertNull($report[$key]); }
        self::assertSame($context['evidence_id'], $report['evidence_id']); self::assertSame('completed', $report['status']);
        self::assertArrayNotHasKey('completeness', $report); self::assertArrayNotHasKey('storage_certified', $report);
    }

    public function testObservationOnlyPreservesKnownMetadataAndActualGenerationObservations(): void
    {
        $calls = 0;
        $report = (new AudioInventoryPhysicalCollector())->collect([], self::context(), static fn () => true,
            static function () use (&$calls): string { return ++$calls === 1 ? 'observed-before' : 'observed-after'; }, 'observation_only');
        foreach (self::context() as $key => $value) { self::assertSame($value, $report[$key]); }
        self::assertSame(2, $calls); self::assertSame('observed-before', $report['generation_before']); self::assertSame('observed-after', $report['generation_after']);
        self::assertSame('observation_only', $report['collection_mode']);
    }

    public function testKnownDeclaredGenerationIsNotUsedAsAnAbsentObservation(): void
    {
        $report = (new AudioInventoryPhysicalCollector())->collect([], self::context(), static fn () => true, null, 'observation_only');
        self::assertSame('g2', $report['generation']); self::assertNull($report['generation_before']); self::assertNull($report['generation_after']);
    }

    public function testExplicitStrictModePreservesTheExactExistingReport(): void
    {
        $report = (new AudioInventoryPhysicalCollector())->collect([], self::context(), static fn () => true, static fn () => 'g2', 'strict');
        self::assertSame(self::report(), $report);
    }

    public function testUnknownModeIsRejectedBeforeAnyObservationOrCallback(): void
    {
        $source = (static function (): \Generator { self::fail('Invalid mode iterated source.'); yield self::file('never'); })();
        $this->expectException(\InvalidArgumentException::class);
        (new AudioInventoryPhysicalCollector())->collect($source, self::context(), static function (): void { self::fail('Invalid mode called consumer.'); },
            static function (): string { self::fail('Invalid mode observed generation.'); }, 'typo');
    }

    public function testStrictModeRejectsMissingGenerationReader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AudioInventoryPhysicalCollector())->collect([], self::context(), static fn () => true, null);
    }

    /** @dataProvider invalidObservationOnlyContexts */
    public function testObservationOnlyDoesNotAcceptInvalidOrMissingProvenance(array $context): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AudioInventoryPhysicalCollector())->collect([], $context, static fn () => true, null, 'observation_only');
    }
    public static function invalidObservationOnlyContexts(): array
    {
        $base = ['library_id' => null, 'storage_kind' => null, 'generation' => null, 'scope' => 'test_subset', 'source' => 'collector', 'evidence_id' => 'observation-1'];
        $cases = [];
        foreach (['scope', 'source', 'evidence_id'] as $key) { $bad = $base; unset($bad[$key]); $cases[] = [$bad]; }
        foreach (['library_id' => '', 'generation' => false, 'storage_kind' => 'production', 'scope' => ' ', 'evidence_id' => 17] as $key => $value) { $cases[] = [array_replace($base, [$key => $value])]; }
        return $cases;
    }

    public function testObservationOnlyInterruptionAndErrorCountingDoNotDrainSource(): void
    {
        $source = (static function (): \Generator {
            yield ['type' => 'error', 'relative_path' => '', 'reason' => 'unreadable_directory'];
            yield self::file('one.mp3'); self::fail('Interrupted source drained.');
        })();
        $context = ['scope' => 'test_subset', 'source' => 'collector', 'evidence_id' => 'observation-1'];
        $report = (new AudioInventoryPhysicalCollector())->collect($source, $context, static fn () => false, null, 'observation_only');
        self::assertSame('interrupted', $report['status']); self::assertSame(1, $report['error_count']); self::assertNull($report['generation_after']);
    }

    public function testObservationOnlyDoesNotMaskSourceExceptionWithAnotherGenerationRead(): void
    {
        $failure = new \DomainException('source'); $calls = 0;
        $source = (static function () use ($failure): \Generator { yield self::file('one.mp3'); throw $failure; })();
        try {
            (new AudioInventoryPhysicalCollector())->collect($source, self::context(), static fn () => true,
                static function () use (&$calls): string { if (++$calls > 1) { throw new \LogicException('secondary'); } return 'actual'; }, 'observation_only');
            self::fail('Source exception concealed.');
        } catch (\DomainException $actual) { self::assertSame($failure, $actual); }
        self::assertSame(1, $calls);
    }

    private static function context(): array
    {
        return ['library_id' => 'synthetic-library', 'storage_kind' => 'synthetic', 'generation' => 'g2',
            'scope' => 'synthetic/all', 'source' => 'physical-collector', 'evidence_id' => 'collection-run-17'];
    }

    private static function file(string $path): array { return ['type' => 'file', 'relative_path' => $path]; }

    private static function report(string $status = 'completed', int $errors = 0, string $before = 'g2', string $after = 'g2'): array
    {
        return self::context() + ['status' => $status, 'error_count' => $errors, 'generation_before' => $before, 'generation_after' => $after];
    }

    private function decide(array $report, bool $withCompleteness = true): array
    {
        $context = self::context();
        $expected = array_intersect_key($context, array_flip(['library_id', 'storage_kind', 'generation', 'scope'])) + ['trusted_sources' => ['physical-collector']];
        $observations = ['availability' => 'accessible', 'identity' => $context, 'collection' => $report];
        if ($withCompleteness) { $observations['completeness'] = $context + ['status' => 'established', 'validity' => 'current']; }
        return (new AudioInventoryStorageDecision())->decide($expected, $observations);
    }
}
