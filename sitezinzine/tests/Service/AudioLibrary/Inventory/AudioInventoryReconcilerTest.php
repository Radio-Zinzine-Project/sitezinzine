<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryClassifier;
use App\Service\AudioLibrary\Inventory\AudioInventoryReconciler;
use PHPUnit\Framework\TestCase;

final class AudioInventoryReconcilerTest extends TestCase
{
    private function service(int $limit = 8): AudioInventoryReconciler { return new AudioInventoryReconciler(new AudioInventoryClassifier(), $limit); }
    private static function association(string $path, int $id = 17, string $origin = 'thumbnailMp3', bool $missing = false): array
    {
        return ['storage' => 'emissionsMp3', 'relative_path' => $path, 'emission_id' => $id, 'origin' => $origin, 'thumbnail_missing' => $missing];
    }
    private function context(): array
    {
        return [
            'storage' => 'emissionsMp3', 'scope' => 'web_full',
            'references' => ['status' => 'completed', 'references_complete' => true, 'uncertainty_count' => 0, 'temporal_consistency' => 'repeatable_read', 'pages_read' => 1, 'association_count' => 1, 'next_after_id' => 17],
            'physical_collection' => ['library_id' => 'library', 'storage_kind' => 'real', 'generation' => 'g1', 'scope' => 'web_full', 'source' => 'fixture-observer', 'evidence_id' => 'fixture', 'status' => 'completed', 'error_count' => 0, 'generation_before' => 'g1', 'generation_after' => 'g1'],
            'storage_decision' => ['state' => 'verified', 'reasons' => [], 'library_id' => 'library', 'storage_kind' => 'real', 'generation' => 'g1', 'absence_conclusions_allowed' => true],
        ];
    }
    private function collect(iterable $physical, iterable $associations, ?array $context = null, int $limit = 8): array
    {
        $rows = [];
        $report = $this->service($limit)->reconcile($physical, $associations, $context ?? $this->context(), static function (array $row) use (&$rows): void { $rows[] = $row; });
        return [$rows, $report];
    }

    public function testFullOuterJoinIncludesMatchedPhysicalOnlyAndReferenceOnlyPaths(): void
    {
        $a = self::association('a'); $c = self::association('c');
        [$rows, $report] = $this->collect(['a', 'b'], [$a, $c]);
        self::assertSame(['a', 'b', 'c'], array_column($rows, 'relative_path'));
        self::assertSame(['associated', 'orphan_candidate', 'broken_reference'], array_column($rows, 'classification'));
        self::assertSame([true, true, false], array_column($rows, 'present'));
        self::assertSame([true, true, false], array_column($rows, 'physical_observed'));
        self::assertSame([[$a], [], [$c]], array_column($rows, 'associations'));
        self::assertSame(['status' => 'completed', 'processed_paths' => 3, 'physical_paths' => 2, 'referenced_paths' => 2, 'results_emitted' => 3], $report);
        foreach ($rows as $row) { self::assertTrue($row['references_complete']); self::assertSame('web_full', $row['scope']); }
    }

    public function testMultipleAssociationsKeepClassifierPriorityAndAllOrigins(): void
    {
        $associations = [self::association('a'), self::association('a', 17, 'local_audio_url', true), self::association('a', 18)];
        [$rows] = $this->collect([], $associations);
        self::assertSame('multiple_associations', $rows[0]['classification']); self::assertFalse($rows[0]['present']);
        self::assertSame([17, 18], $rows[0]['emission_ids']); self::assertSame($associations, $rows[0]['associations']);
        self::assertSame([], $rows[0]['missing_thumbnail_emission_ids']);
    }

    public function testTwoOriginsAndRepeatedRowsAreNotSilentlyRemoved(): void
    {
        $physical = self::association('a'); $local = self::association('a', 17, 'local_audio_url', true);
        [$rows] = $this->collect(['a'], [$physical, $local, $local]);
        self::assertSame('associated', $rows[0]['classification']); self::assertSame([17], $rows[0]['emission_ids']);
        self::assertSame([$physical, $local, $local], $rows[0]['associations']);
        [$rows] = $this->collect(['a'], [$local]); self::assertSame([17], $rows[0]['missing_thumbnail_emission_ids']);
    }

    public function testExactBinaryNamesRemainDistinctWithoutNormalization(): void
    {
        $physical = ['A', 'a', "dir/a\n.mp3", 'dir\\name', 'é', "\xff"];
        $associations = [self::association('A'), self::association('e'), self::association('É')];
        [$rows] = $this->collect($physical, $associations);
        self::assertSame(['A', 'a', "dir/a\n.mp3", 'dir\\name', 'e', 'É', 'é', "\xff"], array_column($rows, 'relative_path'));
        self::assertSame('associated', $rows[0]['classification']); self::assertSame('orphan_candidate', $rows[1]['classification']);
    }

    /** @dataProvider physicalDisorders */
    public function testPhysicalOrderAndUniquenessAreRequired(array $paths): void
    {
        $this->expectException(\UnexpectedValueException::class); $this->collect($paths, []);
    }
    public static function physicalDisorders(): array { return [[['b', 'a']], [['a', 'a']]]; }

    public function testAssociationDisorderIsRefusedIncludingRemainingTail(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->collect([], [self::association('a'), self::association('c'), self::association('b')]);
    }

    /** @dataProvider malformedAssociations */
    public function testMalformedAssociationIsRejected(mixed $row): void
    {
        $this->expectException(\UnexpectedValueException::class); $this->collect([], [$row]);
    }
    public static function malformedAssociations(): array
    {
        $valid = self::association('a');
        return [[null], ['a'], [[]], [array_diff_key($valid, ['thumbnail_missing' => true])],
            [array_replace($valid, ['emission_id' => '17'])], [array_replace($valid, ['emission_id' => 0])],
            [array_replace($valid, ['origin' => 'external'])], [array_replace($valid, ['thumbnail_missing' => 0])],
            [array_replace($valid, ['storage' => 'other'])], [array_replace($valid, ['relative_path' => '../outside'])]];
    }

    /** @dataProvider invalidPaths */
    public function testInvalidRelativePhysicalPathsAreRejected(mixed $path): void
    {
        $this->expectException(\UnexpectedValueException::class); $this->collect([$path], []);
    }
    public static function invalidPaths(): array { return [[null], [17], [''], ['/absolute'], ['C:/drive'], ['a/../b'], ['a//b'], ["nul\0name"]]; }

    public function testAssociationLimitAcceptsExactBoundButNeverTruncates(): void
    {
        $a = self::association('a'); $b = self::association('a', 18);
        [$rows] = $this->collect(['a'], [$a, $b], null, 2); self::assertCount(2, $rows[0]['associations']);
        $called = false;
        try { $this->service(1)->reconcile(['a'], [$a, $b], $this->context(), static function () use (&$called): void { $called = true; }); self::fail('Group truncated.'); }
        catch (\LengthException) { self::assertFalse($called); }
    }

    /** @dataProvider invalidLimits */
    public function testAssociationLimitMustBePositive(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->service($limit);
    }
    public static function invalidLimits(): array { return [[0], [-1]]; }

    /** @dataProvider unreliableReferences */
    public function testIncompleteSqlOrUncertainUrlPreventsOrphanConclusion(string $field, mixed $value): void
    {
        $context = $this->context(); $context['references'][$field] = $value;
        [$rows] = $this->collect(['a', 'b'], [self::association('b')], $context);
        self::assertSame('indeterminate', $rows[0]['classification']); self::assertFalse($rows[0]['references_complete']);
        self::assertSame('associated', $rows[1]['classification']); self::assertTrue($rows[0]['present']);
    }
    public static function unreliableReferences(): array { return [['status', 'interrupted'], ['references_complete', false], ['uncertainty_count', 1], ['temporal_consistency', 'unknown']]; }

    /** @dataProvider unreliableStorage */
    public function testMissingPhysicalObservationNeedsCoherentFullStorageProof(string $fault): void
    {
        $context = $this->context();
        switch ($fault) {
            case 'unverified': $context['storage_decision']['state'] = 'unverified'; break;
            case 'forbidden': $context['storage_decision']['absence_conclusions_allowed'] = false; break;
            case 'subset': $context['scope'] = $context['physical_collection']['scope'] = 'test_subset'; break;
            case 'errors': $context['physical_collection']['error_count'] = 1; break;
            case 'interrupted': $context['physical_collection']['status'] = 'interrupted'; break;
            case 'generation': $context['physical_collection']['generation_after'] = 'g2'; break;
            case 'identity': $context['physical_collection']['library_id'] = 'other'; break;
            case 'scope': $context['physical_collection']['scope'] = 'other'; break;
            case 'synthetic': $context['physical_collection']['storage_kind'] = $context['storage_decision']['storage_kind'] = 'synthetic'; break;
            case 'missing_collection': unset($context['physical_collection']); break;
            case 'missing_decision': unset($context['storage_decision']); break;
        }
        [$rows] = $this->collect(['a'], [self::association('b')], $context);
        self::assertTrue($rows[0]['present']); self::assertSame('orphan_candidate', $rows[0]['classification']);
        self::assertNull($rows[1]['present']); self::assertFalse($rows[1]['physical_observed']);
        self::assertFalse($rows[1]['absence_conclusions_allowed']); self::assertSame('indeterminate', $rows[1]['classification']);
    }
    public static function unreliableStorage(): array
    {
        return array_map(static fn ($fault) => [$fault], ['unverified', 'forbidden', 'subset', 'errors', 'interrupted', 'generation', 'identity', 'scope', 'synthetic', 'missing_collection', 'missing_decision']);
    }

    public function testMultipleAssociationsRemainPriorityEvenWithUnknownPresence(): void
    {
        $context = $this->context(); unset($context['storage_decision']);
        [$rows] = $this->collect([], [self::association('a'), self::association('a', 18)], $context);
        self::assertSame('multiple_associations', $rows[0]['classification']); self::assertNull($rows[0]['present']);
    }

    public function testMissingReferenceEvidenceFailsClosed(): void
    {
        $context = ['storage' => 'emissionsMp3', 'scope' => 'web_full'];
        [$rows] = $this->collect(['a'], [self::association('b')], $context);
        self::assertSame(['indeterminate', 'indeterminate'], array_column($rows, 'classification'));
        self::assertFalse($rows[0]['references_complete']); self::assertNull($rows[1]['present']);
    }

    public function testInvalidContextIsRejectedBeforeSourceIteration(): void
    {
        $source = (static function (): \Generator { self::fail('Source consumed before context validation.'); yield 'a'; })();
        $this->expectException(\InvalidArgumentException::class); $this->service()->reconcile($source, [], [], static fn () => null);
    }

    public function testStrictFalseInterruptsWithoutAdvancingPhysicalSource(): void
    {
        $source = (static function (): \Generator { yield 'a'; self::fail('Physical source resumed after interruption.'); yield 'b'; })();
        $report = $this->service()->reconcile($source, [], $this->context(), static fn () => false);
        self::assertSame(['status' => 'interrupted', 'processed_paths' => 1, 'physical_paths' => 1, 'referenced_paths' => 0, 'results_emitted' => 1], $report);
        unset($source);
    }

    public function testOnlyStrictFalseInterruptsIncludingFinalResult(): void
    {
        foreach ([null, 0, '', 'false'] as $result) {
            self::assertSame('completed', $this->service()->reconcile(['a'], [], $this->context(), static fn () => $result)['status']);
        }
        self::assertSame('interrupted', $this->service()->reconcile([], [self::association('a')], $this->context(), static fn () => false)['status']);
    }

    public function testConsumerExceptionIsPreservedWithoutResumingPhysicalSource(): void
    {
        $failure = new \RuntimeException('consumer');
        $source = (static function (): \Generator { yield 'a'; throw new \LogicException('Do not resume source.'); })();
        try { $this->service()->reconcile($source, [], $this->context(), static function () use ($failure): void { throw $failure; }); self::fail('Consumer failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        unset($source);
    }

    /** @dataProvider sourceSides */
    public function testSourceExceptionNeverReturnsACompletionReport(bool $physical): void
    {
        $failure = new \RuntimeException('source');
        $source = (static function () use ($failure, $physical): \Generator { yield $physical ? 'a' : self::association('a'); throw $failure; })();
        $report = null;
        try { $report = $this->service()->reconcile($physical ? $source : [], $physical ? [] : $source, $this->context(), static fn () => null); self::fail('Source failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); self::assertNull($report); }
    }
    public static function sourceSides(): array { return [[true], [false]]; }

    public function testEmptyStreamsCompleteWithZeroCounters(): void
    {
        [$rows, $report] = $this->collect([], []);
        self::assertSame([], $rows); self::assertSame(['status' => 'completed', 'processed_paths' => 0, 'physical_paths' => 0, 'referenced_paths' => 0, 'results_emitted' => 0], $report);
    }

    public function testLargeGeneratedJoinUsesBoundedMemoryAndProgressiveConsumption(): void
    {
        $delivered = 0; $growth = 0; $baseline = memory_get_usage();
        $physical = (static function () use (&$delivered, &$growth, $baseline): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                if ($i - $delivered > 1) { self::fail('Physical input accumulated.'); }
                $growth = max($growth, memory_get_usage() - $baseline); yield sprintf('SYN/%08d', $i);
            }
        })();
        $associations = (static function () use (&$delivered): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                if ($i - $delivered > 1) { self::fail('Associations accumulated.'); }
                yield self::association(sprintf('SYN/%08d', $i), $i + 1);
            }
        })();
        $report = $this->service(1)->reconcile($physical, $associations, $this->context(), static function (array $row) use (&$delivered): void {
            if ($row['relative_path'] !== sprintf('SYN/%08d', $delivered) || $row['classification'] !== 'associated') { self::fail('Join changed data.'); }
            ++$delivered;
        });
        self::assertSame(100000, $delivered); self::assertSame(100000, $report['processed_paths']); self::assertSame(100000, $report['referenced_paths']);
        self::assertSame('completed', $report['status']); self::assertLessThan(2 * 1024 * 1024, $growth);
    }
}
