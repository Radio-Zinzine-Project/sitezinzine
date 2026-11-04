<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GridConflictDetector;
use PHPUnit\Framework\TestCase;

class GridConflictDetectorTest extends TestCase
{
    private GridConflictDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new GridConflictDetector();
    }

    public function testTwoAdjacentSegmentsDoNotConflict(): void
    {
        $segments = [
            [
                'segmentKey' => 'segment_1',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 20:00:00',
                'duration' => 60,
            ],
            [
                'segmentKey' => 'segment_2',
                'slotId' => 2,
                'ruleId' => 11,
                'startsAt' => '2026-09-06 20:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 120,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        self::assertCount(2, $result);

        self::assertFalse($result[0]['hasConflict']);
        self::assertFalse($result[1]['hasConflict']);

        self::assertSame(0, $result[0]['conflictCount']);
        self::assertSame(0, $result[1]['conflictCount']);

        self::assertSame([], $result[0]['conflictWith']);
        self::assertSame([], $result[1]['conflictWith']);
    }

    public function testTwoOverlappingSegmentsConflict(): void
    {
        $segments = [
            [
                'segmentKey' => 'segment_1',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 21:00:00',
                'duration' => 120,
            ],
            [
                'segmentKey' => 'segment_2',
                'slotId' => 2,
                'ruleId' => 11,
                'startsAt' => '2026-09-06 20:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 120,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        self::assertTrue($result[0]['hasConflict']);
        self::assertTrue($result[1]['hasConflict']);

        self::assertSame('rule_overlap', $result[0]['conflictType']);
        self::assertSame('rule_overlap', $result[1]['conflictType']);

        self::assertSame('partial', $result[0]['conflictSeverity']);
        self::assertSame('partial', $result[1]['conflictSeverity']);

        self::assertSame(1, $result[0]['conflictCount']);
        self::assertSame(1, $result[1]['conflictCount']);
    }

    public function testExactSamePeriodProducesTotalConflict(): void
    {
        $segments = [
            [
                'segmentKey' => 'segment_1',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 20:00:00',
                'duration' => 60,
            ],
            [
                'segmentKey' => 'segment_2',
                'slotId' => 2,
                'ruleId' => 11,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 20:00:00',
                'duration' => 60,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        self::assertTrue($result[0]['hasConflict']);
        self::assertTrue($result[1]['hasConflict']);

        self::assertSame('total', $result[0]['conflictSeverity']);
        self::assertSame('total', $result[1]['conflictSeverity']);
    }

    public function testContainedSegmentProducesContainedConflict(): void
    {
        $segments = [
            [
                'segmentKey' => 'segment_1',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 180,
            ],
            [
                'segmentKey' => 'segment_2',
                'slotId' => 2,
                'ruleId' => 11,
                'startsAt' => '2026-09-06 20:00:00',
                'endsAt' => '2026-09-06 21:00:00',
                'duration' => 60,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        self::assertTrue($result[0]['hasConflict']);
        self::assertTrue($result[1]['hasConflict']);

        self::assertSame('contained', $result[0]['conflictSeverity']);
        self::assertSame('contained', $result[1]['conflictSeverity']);
    }

    public function testSameSlotProducesSameSlotOverlapType(): void
    {
        $segments = [
            [
                'segmentKey' => 'segment_1',
                'slotId' => 42,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 21:00:00',
                'duration' => 120,
            ],
            [
                'segmentKey' => 'segment_2',
                'slotId' => 42,
                'ruleId' => 11,
                'startsAt' => '2026-09-06 20:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 120,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        self::assertSame('same_slot_overlap', $result[0]['conflictType']);
        self::assertSame('same_slot_overlap', $result[1]['conflictType']);
    }

    public function testSameRuleProducesSameRuleOverlapType(): void
    {
        $segments = [
            [
                'segmentKey' => 'segment_1',
                'slotId' => 1,
                'ruleId' => 50,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 21:00:00',
                'duration' => 120,
            ],
            [
                'segmentKey' => 'segment_2',
                'slotId' => 2,
                'ruleId' => 50,
                'startsAt' => '2026-09-06 20:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 120,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        self::assertSame('same_rule_overlap', $result[0]['conflictType']);
        self::assertSame('same_rule_overlap', $result[1]['conflictType']);
    }

    public function testNonBlockingSegmentIsIgnored(): void
    {
        $segments = [
            [
                'segmentKey' => 'segment_1',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 21:00:00',
                'duration' => 120,
            ],
            [
                'segmentKey' => 'segment_2',
                'slotId' => 2,
                'ruleId' => 11,
                'startsAt' => '2026-09-06 20:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 120,
                'isBlocking' => false,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        self::assertFalse($result[0]['hasConflict']);
        self::assertFalse($result[1]['hasConflict']);

        self::assertSame(0, $result[0]['conflictCount']);
        self::assertSame(0, $result[1]['conflictCount']);
    }

    public function testConflictReferenceContainsOtherSegmentInformation(): void
    {
        $segments = [
            [
                'segmentKey' => 'segment_1',
                'slotId' => 1,
                'ruleId' => 10,
                'ruleNumber' => 1,
                'ruleDisplayName' => 'Règle 1',
                'categoryTitle' => 'Musique',
                'broadcastRank' => 1,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 21:00:00',
                'duration' => 120,
            ],
            [
                'segmentKey' => 'segment_2',
                'slotId' => 2,
                'ruleId' => 20,
                'ruleNumber' => 2,
                'ruleDisplayName' => 'Règle 2',
                'categoryTitle' => 'Actualités',
                'broadcastRank' => 2,
                'startsAt' => '2026-09-06 20:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 120,
                'isProjectedOverride' => true,
                'projectionType' => 'reschedule',
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        self::assertCount(1, $result[0]['conflictWith']);

        $reference = $result[0]['conflictWith'][0];

        self::assertSame('segment_2', $reference['segmentKey']);
        self::assertSame(2, $reference['slotId']);
        self::assertSame(20, $reference['ruleId']);
        self::assertSame(2, $reference['ruleNumber']);
        self::assertSame('Règle 2', $reference['ruleDisplayName']);
        self::assertSame('Actualités', $reference['categoryTitle']);
        self::assertSame(2, $reference['broadcastRank']);
        self::assertSame('2026-09-06 20:00:00', $reference['startsAt']);
        self::assertSame('2026-09-06 22:00:00', $reference['endsAt']);
        self::assertTrue($reference['isProjectedOverride']);
        self::assertSame('reschedule', $reference['projectionType']);
    }

    public function testDetectForWeekProcessesEachDayIndependently(): void
    {
        $daySegments = [
            0 => [
                [
                    'segmentKey' => 'monday_1',
                    'slotId' => 1,
                    'ruleId' => 10,
                    'startsAt' => '2026-09-07 19:00:00',
                    'endsAt' => '2026-09-07 21:00:00',
                    'duration' => 120,
                ],
                [
                    'segmentKey' => 'monday_2',
                    'slotId' => 2,
                    'ruleId' => 20,
                    'startsAt' => '2026-09-07 20:00:00',
                    'endsAt' => '2026-09-07 22:00:00',
                    'duration' => 120,
                ],
            ],
            1 => [
                [
                    'segmentKey' => 'tuesday_1',
                    'slotId' => 3,
                    'ruleId' => 30,
                    'startsAt' => '2026-09-08 19:00:00',
                    'endsAt' => '2026-09-08 20:00:00',
                    'duration' => 60,
                ],
                [
                    'segmentKey' => 'tuesday_2',
                    'slotId' => 4,
                    'ruleId' => 40,
                    'startsAt' => '2026-09-08 20:00:00',
                    'endsAt' => '2026-09-08 21:00:00',
                    'duration' => 60,
                ],
            ],
        ];

        $result = $this->detector->detectForWeek($daySegments);

        self::assertTrue($result[0][0]['hasConflict']);
        self::assertTrue($result[0][1]['hasConflict']);

        self::assertFalse($result[1][0]['hasConflict']);
        self::assertFalse($result[1][1]['hasConflict']);
    }

    public function testFindBlockingOverlapsForRangeReturnsOnlyRealOverlaps(): void
    {
        $daySegments = [
            0 => [
                [
                    'segmentKey' => 'segment_1',
                    'startsAt' => '2026-09-06 18:00:00',
                    'endsAt' => '2026-09-06 19:00:00',
                ],
                [
                    'segmentKey' => 'segment_2',
                    'startsAt' => '2026-09-06 19:30:00',
                    'endsAt' => '2026-09-06 20:30:00',
                ],
                [
                    'segmentKey' => 'segment_3',
                    'startsAt' => '2026-09-06 20:00:00',
                    'endsAt' => '2026-09-06 21:00:00',
                    'isBlocking' => false,
                ],
                [
                    'segmentKey' => 'segment_4',
                    'startsAt' => '2026-09-06 19:45:00',
                    'endsAt' => '2026-09-06 20:15:00',
                    'isCancelled' => true,
                ],
            ],
        ];

        $result = $this->detector->findBlockingOverlapsForRange(
            $daySegments,
            new \DateTimeImmutable('2026-09-06 19:00:00'),
            new \DateTimeImmutable('2026-09-06 20:00:00')
        );

        self::assertCount(1, $result);
        self::assertSame('segment_2', $result[0]['segmentKey']);
    }

    public function testFindBlockingOverlapsForRangeDoesNotConsiderTouchingBoundaryAsOverlap(): void
    {
        $daySegments = [
            0 => [
                [
                    'segmentKey' => 'segment_before',
                    'startsAt' => '2026-09-06 18:00:00',
                    'endsAt' => '2026-09-06 19:00:00',
                ],
                [
                    'segmentKey' => 'segment_after',
                    'startsAt' => '2026-09-06 20:00:00',
                    'endsAt' => '2026-09-06 21:00:00',
                ],
            ],
        ];

        $result = $this->detector->findBlockingOverlapsForRange(
            $daySegments,
            new \DateTimeImmutable('2026-09-06 19:00:00'),
            new \DateTimeImmutable('2026-09-06 20:00:00')
        );

        self::assertSame([], $result);
    }

    public function testMultipleDifferentConflictTypesAreMergedAsMultiple(): void
    {
        $segments = [
            [
                'segmentKey' => 'main',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 180,
            ],
            [
                'segmentKey' => 'same_rule',
                'slotId' => 2,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:30:00',
                'endsAt' => '2026-09-06 20:30:00',
                'duration' => 60,
            ],
            [
                'segmentKey' => 'other_rule',
                'slotId' => 3,
                'ruleId' => 30,
                'startsAt' => '2026-09-06 21:00:00',
                'endsAt' => '2026-09-06 23:00:00',
                'duration' => 120,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        $main = $this->findSegmentByKey($result, 'main');

        self::assertTrue($main['hasConflict']);
        self::assertSame('multiple', $main['conflictType']);
        self::assertSame(2, $main['conflictCount']);
    }

    public function testIdenticalConflictTypesKeepSameType(): void
    {
        $segments = [
            [
                'segmentKey' => 'main',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 180,
            ],
            [
                'segmentKey' => 'other_1',
                'slotId' => 2,
                'ruleId' => 20,
                'startsAt' => '2026-09-06 19:30:00',
                'endsAt' => '2026-09-06 20:30:00',
                'duration' => 60,
            ],
            [
                'segmentKey' => 'other_2',
                'slotId' => 3,
                'ruleId' => 30,
                'startsAt' => '2026-09-06 21:00:00',
                'endsAt' => '2026-09-06 23:00:00',
                'duration' => 120,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        $main = $this->findSegmentByKey($result, 'main');

        self::assertSame('rule_overlap', $main['conflictType']);
        self::assertSame(2, $main['conflictCount']);
    }

    public function testHigherConflictSeverityReplacesLowerSeverity(): void
    {
        $segments = [
            [
                'segmentKey' => 'main',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 180,
            ],
            [
                // contained par main
                'segmentKey' => 'contained',
                'slotId' => 2,
                'ruleId' => 20,
                'startsAt' => '2026-09-06 19:30:00',
                'endsAt' => '2026-09-06 20:30:00',
                'duration' => 60,
            ],
            [
                // chevauchement seulement partiel avec main
                'segmentKey' => 'partial',
                'slotId' => 3,
                'ruleId' => 30,
                'startsAt' => '2026-09-06 21:00:00',
                'endsAt' => '2026-09-06 23:00:00',
                'duration' => 120,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        $main = $this->findSegmentByKey($result, 'main');

        /*
     * Le premier conflit rencontré est "contained".
     * Le second est "partial".
     *
     * "contained" est plus sévère et doit donc être conservé.
     */
        self::assertSame('contained', $main['conflictSeverity']);
        self::assertSame(2, $main['conflictCount']);
    }

    public function testHigherNewSeverityReplacesCurrentSeverity(): void
    {
        $segments = [
            [
                'segmentKey' => 'main',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 180,
            ],
            [
                // premier conflit : partial
                'segmentKey' => 'partial',
                'slotId' => 2,
                'ruleId' => 20,
                'startsAt' => '2026-09-06 18:00:00',
                'endsAt' => '2026-09-06 20:00:00',
                'duration' => 120,
            ],
            [
                // second conflit : contained
                'segmentKey' => 'contained',
                'slotId' => 3,
                'ruleId' => 30,
                'startsAt' => '2026-09-06 20:30:00',
                'endsAt' => '2026-09-06 21:30:00',
                'duration' => 60,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        $main = $this->findSegmentByKey($result, 'main');

        self::assertSame('contained', $main['conflictSeverity']);
        self::assertSame(2, $main['conflictCount']);
    }

    public function testIdenticalConflictSeveritiesKeepSameSeverity(): void
    {
        $segments = [
            [
                'segmentKey' => 'main',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 23:00:00',
                'duration' => 240,
            ],
            [
                'segmentKey' => 'contained_1',
                'slotId' => 2,
                'ruleId' => 20,
                'startsAt' => '2026-09-06 19:30:00',
                'endsAt' => '2026-09-06 20:30:00',
                'duration' => 60,
            ],
            [
                'segmentKey' => 'contained_2',
                'slotId' => 3,
                'ruleId' => 30,
                'startsAt' => '2026-09-06 21:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 60,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        $main = $this->findSegmentByKey($result, 'main');

        self::assertSame('contained', $main['conflictSeverity']);
        self::assertSame(2, $main['conflictCount']);
    }

    public function testDuplicateSegmentKeyIsNotAddedTwiceToConflictReferences(): void
    {
        $segments = [
            [
                'segmentKey' => 'main',
                'slotId' => 1,
                'ruleId' => 10,
                'startsAt' => '2026-09-06 19:00:00',
                'endsAt' => '2026-09-06 23:00:00',
                'duration' => 240,
            ],
            [
                'segmentKey' => 'duplicate',
                'slotId' => 2,
                'ruleId' => 20,
                'startsAt' => '2026-09-06 19:30:00',
                'endsAt' => '2026-09-06 20:30:00',
                'duration' => 60,
            ],
            [
                /*
             * Même segmentKey volontairement.
             * appendConflictReference() ne doit pas créer
             * une deuxième référence pour main.
             */
                'segmentKey' => 'duplicate',
                'slotId' => 3,
                'ruleId' => 30,
                'startsAt' => '2026-09-06 21:00:00',
                'endsAt' => '2026-09-06 22:00:00',
                'duration' => 60,
            ],
        ];

        $result = $this->detector->detectForDay($segments);

        $main = $this->findSegmentByKey($result, 'main');

        self::assertTrue($main['hasConflict']);
        self::assertCount(1, $main['conflictWith']);
        self::assertSame('duplicate', $main['conflictWith'][0]['segmentKey']);
        self::assertSame(1, $main['conflictCount']);
    }

    public function testFindBlockingOverlapsForRangeIgnoresSegmentsWithoutDates(): void
    {
        $daySegments = [
            0 => [
                [
                    'segmentKey' => 'without_start',
                    'endsAt' => '2026-09-06 20:00:00',
                ],
                [
                    'segmentKey' => 'without_end',
                    'startsAt' => '2026-09-06 19:00:00',
                ],
                [
                    'segmentKey' => 'empty_dates',
                    'startsAt' => '',
                    'endsAt' => '',
                ],
                [
                    'segmentKey' => 'valid',
                    'startsAt' => '2026-09-06 19:30:00',
                    'endsAt' => '2026-09-06 20:30:00',
                ],
            ],
        ];

        $result = $this->detector->findBlockingOverlapsForRange(
            $daySegments,
            new \DateTimeImmutable('2026-09-06 19:00:00'),
            new \DateTimeImmutable('2026-09-06 20:00:00')
        );

        self::assertCount(1, $result);
        self::assertSame('valid', $result[0]['segmentKey']);
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     *
     * @return array<string, mixed>
     */
    private function findSegmentByKey(array $segments, string $segmentKey): array
    {
        foreach ($segments as $segment) {
            if (($segment['segmentKey'] ?? null) === $segmentKey) {
                return $segment;
            }
        }

        self::fail(sprintf(
            'Le segment "%s" est introuvable.',
            $segmentKey
        ));
    }
}
