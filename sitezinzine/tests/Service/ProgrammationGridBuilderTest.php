<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\ProgrammationRuleRepository;
use App\Service\ProgrammationGridBuilder;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProgrammationGridBuilderTest extends TestCase
{
    private ProgrammationRuleRepository&MockObject $repository;
    private ProgrammationGridBuilder $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(
            ProgrammationRuleRepository::class
        );

        $this->service = new ProgrammationGridBuilder(
            $this->repository
        );
    }

    public function testBuildReturnsEmptyRadioWeekWithoutRules(): void
    {
        $this->repository
            ->method('findAll')
            ->willReturn([]);

        $result = $this->build();

        self::assertSame(
            array_fill(0, 7, []),
            $result
        );
    }

    public function testInactiveRuleIsIgnored(): void
    {
        $rule = $this->createRule(
            1,
            [],
            false
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        self::assertSame(
            array_fill(0, 7, []),
            $this->build()
        );
    }

    public function testDeletedRuleIsIgnored(): void
    {
        $rule = $this->createRule(
            1,
            [],
            true,
            true
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        self::assertSame(
            array_fill(0, 7, []),
            $this->build()
        );
    }

    public function testInactiveAndDeletedSlotsAreIgnored(): void
    {
        $inactive = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1,
            0,
            false
        );

        $deleted = $this->createWeeklySlot(
            11,
            2,
            '11:00',
            60,
            1,
            0,
            true,
            true
        );

        $rule = $this->createRule(
            1,
            [$inactive, $deleted]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        self::assertSame(
            array_fill(0, 7, []),
            $this->build()
        );
    }

    public function testWeeklyFirstBroadcastCreatesSegment(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            2,
            '10:30',
            60,
            1
        );

        $category = $this->createCategory(
            'Musique',
            'MUS'
        );

        $rule = $this->createRule(
            1,
            [$slot],
            true,
            false,
            $category
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $result = $this->build();

        self::assertCount(1, $result[0]);

        $segment = $result[0][0];

        self::assertSame(
            'Musique',
            $segment['title']
        );

        self::assertSame(
            'Musique',
            $segment['displayTitle']
        );

        self::assertSame(
            'MUS',
            $segment['categorySlug']
        );

        self::assertSame(
            $category,
            $segment['category']
        );

        self::assertSame(
            60,
            $segment['duration']
        );

        self::assertSame(
            42,
            $segment['startIndex']
        );

        self::assertSame(
            1,
            $segment['ruleId']
        );

        self::assertSame(
            10,
            $segment['slotId']
        );

        self::assertSame(
            1,
            $segment['broadcastRank']
        );

        self::assertSame(
            '2026-09-08 10:30:00',
            $segment['startsAt']
        );

        self::assertSame(
            '2026-09-08 11:30:00',
            $segment['endsAt']
        );

        self::assertSame(
            $segment['startsAt'],
            $segment['firstBroadcastStartsAt']
        );

        self::assertFalse(
            $segment['hasConflict']
        );

        self::assertSame(
            [],
            $segment['conflictWith']
        );
    }

    public function testMondayBelongsToLastDayOfRadioWeek(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            1,
            '08:00',
            30,
            1
        );

        $rule = $this->createRule(
            1,
            [$slot]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $result = $this->build();

        self::assertCount(1, $result[6]);

        self::assertSame(
            '2026-09-14 08:00:00',
            $result[6][0]['startsAt']
        );
    }

    public function testMissingStartTimeUsesMidnight(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            2,
            null,
            null,
            1
        );

        $rule = $this->createRule(
            1,
            [$slot]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $result = $this->build();

        self::assertCount(1, $result[0]);

        self::assertSame(
            '2026-09-08 00:00:00',
            $result[0][0]['startsAt']
        );

        self::assertSame(
            0,
            $result[0][0]['startIndex']
        );

        self::assertSame(
            15,
            $result[0][0]['duration']
        );

        self::assertSame(
            '2026-09-08 00:15:00',
            $result[0][0]['endsAt']
        );
    }

    public function testRuleWithoutCategoryUsesFallbackTitle(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $rule = $this->createRule(
            1,
            [$slot]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $segment = $this->build()[0][0];

        self::assertSame(
            'Catégorie inconnue',
            $segment['categoryTitle']
        );

        self::assertSame(
            'Catégorie inconnue',
            $segment['displayTitle']
        );

        self::assertNull(
            $segment['categorySlug']
        );

        self::assertNull(
            $segment['category']
        );
    }

    public function testRebroadcastIsLinkedToFirstBroadcast(): void
    {
        $first = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $rebroadcast = $this->createWeeklySlot(
            11,
            4,
            '15:30',
            60,
            2
        );

        $rule = $this->createRule(
            1,
            [$first, $rebroadcast]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $result = $this->build();

        self::assertCount(1, $result[0]);
        self::assertCount(1, $result[2]);

        $firstSegment = $result[0][0];
        $rebroadcastSegment = $result[2][0];

        self::assertSame(
            '2026-09-08 10:00:00',
            $firstSegment['startsAt']
        );

        self::assertSame(
            '2026-09-10 15:30:00',
            $rebroadcastSegment['startsAt']
        );

        self::assertSame(
            2,
            $rebroadcastSegment['broadcastRank']
        );

        self::assertSame(
            '2026-09-08 10:00:00',
            $rebroadcastSegment['firstBroadcastStartsAt']
        );
    }

    public function testRebroadcastWithWeekOffsetCanComeFromPreviousFirstBroadcast(): void
    {
        $first = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        /*
         * Rediffusion le mercredi de la semaine
         * suivant la première diffusion.
         */
        $rebroadcast = $this->createWeeklySlot(
            11,
            3,
            '12:00',
            60,
            2,
            1
        );

        $rule = $this->createRule(
            1,
            [$first, $rebroadcast]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $result = $this->build();

        /*
         * La rediffusion visible le 9 septembre provient
         * de la première diffusion du 1er septembre.
         */
        self::assertCount(1, $result[1]);

        $rebroadcastSegment = $result[1][0];

        self::assertSame(
            '2026-09-09 12:00:00',
            $rebroadcastSegment['startsAt']
        );

        self::assertSame(
            '2026-09-01 10:00:00',
            $rebroadcastSegment['firstBroadcastStartsAt']
        );
    }

    public function testRuleValidFromExcludesEarlierOccurrences(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $rule = $this->createRule(
            1,
            [$slot],
            true,
            false,
            null,
            new \DateTimeImmutable(
                '2026-09-09'
            )
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        self::assertSame(
            array_fill(0, 7, []),
            $this->build()
        );
    }

    public function testRuleValidUntilExcludesLaterOccurrences(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            4,
            '10:00',
            60,
            1
        );

        $rule = $this->createRule(
            1,
            [$slot],
            true,
            false,
            null,
            null,
            new \DateTimeImmutable(
                '2026-09-09'
            )
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        self::assertSame(
            array_fill(0, 7, []),
            $this->build()
        );
    }

    public function testMutableValidityDatesAreSupported(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $rule = $this->createRule(
            1,
            [$slot],
            true,
            false,
            null,
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable('2026-09-08')
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        self::assertCount(
            1,
            $this->build()[0]
        );
    }

    public function testEvenWeekParityCreatesOccurrenceOnEvenWeek(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1,
            0,
            true,
            false,
            ProgrammationRuleSlot::WEEK_PARITY_EVEN
        );

        $rule = $this->createRule(
            1,
            [$slot]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        /*
         * 8 septembre 2026 = semaine ISO 37, donc impaire.
         */
        self::assertSame(
            array_fill(0, 7, []),
            $this->build()
        );
    }

    public function testOddWeekParityCreatesOccurrenceOnOddWeek(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1,
            0,
            true,
            false,
            ProgrammationRuleSlot::WEEK_PARITY_ODD
        );

        $rule = $this->createRule(
            1,
            [$slot]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        self::assertCount(
            1,
            $this->build()[0]
        );
    }

    public function testUnknownWeekParityDoesNotExcludeOccurrence(): void
    {
        $slot = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1,
            0,
            true,
            false,
            'unexpected'
        );

        $rule = $this->createRule(
            1,
            [$slot]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        self::assertCount(
            1,
            $this->build()[0]
        );
    }

    public function testMonthlyFirstOccurrenceCreatesSegment(): void
    {
        /*
         * Premier jeudi de septembre 2026 = 3 septembre.
         *
         * Avec weekOffset 1, l'occurrence visible devient
         * jeudi 10 septembre.
         */
        $slot = $this->createMonthlySlot(
            10,
            4,
            '14:00',
            60,
            1,
            1,
            1
        );

        $rule = $this->createRule(
            1,
            [$slot]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $result = $this->build();

        self::assertCount(1, $result[2]);

        self::assertSame(
            '2026-09-10 14:00:00',
            $result[2][0]['startsAt']
        );
    }

    public function testMonthlyLastOccurrenceIsResolved(): void
    {
        /*
         * Dernier mardi d'août 2026 = 25 août.
         * Avec weekOffset 2 => 8 septembre.
         */
        $slot = $this->createMonthlySlot(
            10,
            2,
            '09:00',
            30,
            1,
            ProgrammationRuleSlot::MONTHLY_LAST,
            2
        );

        $rule = $this->createRule(
            1,
            [$slot]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $result = $this->build();

        self::assertCount(1, $result[0]);

        self::assertSame(
            '2026-09-08 09:00:00',
            $result[0][0]['startsAt']
        );
    }

    public function testMonthlyIntervalCanExcludeMonth(): void
    {
        $slot = $this->createMonthlySlot(
            10,
            2,
            '10:00',
            60,
            1,
            2,
            0,
            2
        );

        $rule = $this->createRule(
            1,
            [$slot],
            true,
            false,
            null,
            new \DateTimeImmutable(
                '2026-08-01'
            )
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        /*
         * Septembre est à un mois de l'ancre août.
         * Avec intervalle 2 il ne correspond pas.
         */
        self::assertSame(
            array_fill(0, 7, []),
            $this->build()
        );
    }

    public function testSegmentsAreSortedByStartTime(): void
    {
        $late = $this->createWeeklySlot(
            10,
            2,
            '18:00',
            60,
            1
        );

        $early = $this->createWeeklySlot(
            11,
            2,
            '08:00',
            60,
            1
        );

        $rule = $this->createRule(
            1,
            [$late, $early]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $segments = $this->build()[0];

        self::assertCount(2, $segments);

        self::assertSame(
            '2026-09-08 08:00:00',
            $segments[0]['startsAt']
        );

        self::assertSame(
            '2026-09-08 18:00:00',
            $segments[1]['startsAt']
        );
    }

    public function testSegmentsWithSameStartAreSortedByDuration(): void
    {
        $long = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $short = $this->createWeeklySlot(
            11,
            2,
            '10:00',
            30,
            1
        );

        $rule = $this->createRule(
            1,
            [$long, $short]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $segments = $this->build()[0];

        self::assertSame(
            30,
            $segments[0]['duration']
        );

        self::assertSame(
            60,
            $segments[1]['duration']
        );
    }

    public function testSameSlotOverlapProducesTotalConflict(): void
    {
        /*
         * Deux règles distinctes mais volontairement le même slotId.
         */
        $slotA = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $slotB = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $ruleA = $this->createRule(
            1,
            [$slotA]
        );

        $ruleB = $this->createRule(
            2,
            [$slotB]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([
                $ruleA,
                $ruleB,
            ]);

        $segments = $this->build()[0];

        self::assertCount(2, $segments);

        foreach ($segments as $segment) {
            self::assertTrue(
                $segment['hasConflict']
            );

            self::assertSame(
                'same_slot_overlap',
                $segment['conflictType']
            );

            self::assertSame(
                'total',
                $segment['conflictSeverity']
            );

            self::assertSame(
                1,
                $segment['conflictCount']
            );

            self::assertCount(
                1,
                $segment['conflictWith']
            );
        }
    }

    public function testSameRuleOverlapProducesContainedConflict(): void
    {
        $slotA = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            120,
            1
        );

        $slotB = $this->createWeeklySlot(
            11,
            2,
            '10:30',
            30,
            1
        );

        $rule = $this->createRule(
            1,
            [$slotA, $slotB]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $segments = $this->build()[0];

        foreach ($segments as $segment) {
            self::assertSame(
                'same_rule_overlap',
                $segment['conflictType']
            );

            self::assertSame(
                'contained',
                $segment['conflictSeverity']
            );
        }
    }

    public function testDifferentRulesProducePartialConflict(): void
    {
        $slotA = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $slotB = $this->createWeeklySlot(
            11,
            2,
            '10:30',
            60,
            1
        );

        $ruleA = $this->createRule(
            1,
            [$slotA]
        );

        $ruleB = $this->createRule(
            2,
            [$slotB]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([
                $ruleA,
                $ruleB,
            ]);

        $segments = $this->build()[0];

        foreach ($segments as $segment) {
            self::assertTrue(
                $segment['hasConflict']
            );

            self::assertSame(
                'rule_overlap',
                $segment['conflictType']
            );

            self::assertSame(
                'partial',
                $segment['conflictSeverity']
            );
        }
    }

    public function testTouchingSegmentsDoNotConflict(): void
    {
        $first = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            60,
            1
        );

        $second = $this->createWeeklySlot(
            11,
            2,
            '11:00',
            60,
            1
        );

        $rule = $this->createRule(
            1,
            [$first, $second]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([$rule]);

        $segments = $this->build()[0];

        self::assertFalse(
            $segments[0]['hasConflict']
        );

        self::assertFalse(
            $segments[1]['hasConflict']
        );
    }

    public function testMultipleConflictTypesAreMerged(): void
    {
        $slotA = $this->createWeeklySlot(
            10,
            2,
            '10:00',
            120,
            1
        );

        $slotB = $this->createWeeklySlot(
            11,
            2,
            '10:15',
            30,
            1
        );

        $slotC = $this->createWeeklySlot(
            12,
            2,
            '10:30',
            60,
            1
        );

        $ruleA = $this->createRule(
            1,
            [$slotA, $slotB]
        );

        $ruleB = $this->createRule(
            2,
            [$slotC]
        );

        $this->repository
            ->method('findAll')
            ->willReturn([
                $ruleA,
                $ruleB,
            ]);

        $segments = $this->build()[0];

        $main = null;

        foreach ($segments as $segment) {
            if ($segment['slotId'] === 10) {
                $main = $segment;
                break;
            }
        }

        self::assertNotNull($main);

        self::assertSame(
            'multiple',
            $main['conflictType']
        );

        self::assertSame(
            2,
            $main['conflictCount']
        );
    }

    private function build(): array
    {
        return $this->service->buildForWeek(
            new \DateTimeImmutable(
                '2026-09-08 00:00:00'
            ),
            new \DateTimeImmutable(
                '2026-09-15 00:00:00'
            )
        );
    }

    private function createCategory(
        string $title,
        string $slug
    ): Categories&MockObject {
        $category = $this->createMock(
            Categories::class
        );

        $category
            ->method('getTitre')
            ->willReturn($title);

        $category
            ->method('getSlug')
            ->willReturn($slug);

        return $category;
    }

    /**
     * @param ProgrammationRuleSlot[] $slots
     */
    private function createRule(
        int $id,
        array $slots,
        bool $active = true,
        bool $deleted = false,
        ?Categories $category = null,
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validUntil = null
    ): ProgrammationRule&MockObject {
        $rule = $this->createMock(
            ProgrammationRule::class
        );

        $rule
            ->method('getId')
            ->willReturn($id);

        $rule
            ->method('isActive')
            ->willReturn($active);

        $rule
            ->method('isDeleted')
            ->willReturn($deleted);

        $rule
            ->method('getSlots')
            ->willReturn(
                new ArrayCollection($slots)
            );

        $rule
            ->method('getCategory')
            ->willReturn($category);

        $rule
            ->method('getValidFrom')
            ->willReturn($validFrom);

        $rule
            ->method('getValidUntil')
            ->willReturn($validUntil);

        $rule
            ->method('getRuleNumber')
            ->willReturn($id);

        $rule
            ->method('getDisplayName')
            ->willReturn(
                'Règle ' . $id
            );

        return $rule;
    }

    private function createWeeklySlot(
        int $id,
        ?int $dayOfWeek,
        ?string $startTime,
        ?int $duration,
        int $broadcastRank,
        int $weekOffset = 0,
        bool $active = true,
        bool $deleted = false,
        ?string $weekParity = null
    ): ProgrammationRuleSlot&MockObject {
        $slot = $this->createMock(
            ProgrammationRuleSlot::class
        );

        $slot
            ->method('getId')
            ->willReturn($id);

        $slot
            ->method('isActive')
            ->willReturn($active);

        $slot
            ->method('isDeleted')
            ->willReturn($deleted);

        $slot
            ->method('isWeekly')
            ->willReturn(true);

        $slot
            ->method('isMonthly')
            ->willReturn(false);

        $slot
            ->method('getDayOfWeek')
            ->willReturn($dayOfWeek);

        $slot
            ->method('getStartTime')
            ->willReturn(
                $startTime !== null
                    ? new \DateTimeImmutable(
                        $startTime
                    )
                    : null
            );

        $slot
            ->method('getDurationMinutes')
            ->willReturn($duration);

        $slot
            ->method('getBroadcastRank')
            ->willReturn($broadcastRank);

        $slot
            ->method('getWeekOffset')
            ->willReturn($weekOffset);

        $slot
            ->method('getWeekParity')
            ->willReturn($weekParity);

        return $slot;
    }

    private function createMonthlySlot(
        int $id,
        ?int $dayOfWeek,
        ?string $startTime,
        ?int $duration,
        int $broadcastRank,
        ?int $monthlyOccurrence,
        int $weekOffset = 0,
        int $monthInterval = 1
    ): ProgrammationRuleSlot&MockObject {
        $slot = $this->createMock(
            ProgrammationRuleSlot::class
        );

        $slot
            ->method('getId')
            ->willReturn($id);

        $slot
            ->method('isActive')
            ->willReturn(true);

        $slot
            ->method('isDeleted')
            ->willReturn(false);

        $slot
            ->method('isWeekly')
            ->willReturn(false);

        $slot
            ->method('isMonthly')
            ->willReturn(true);

        $slot
            ->method('getDayOfWeek')
            ->willReturn($dayOfWeek);

        $slot
            ->method('getStartTime')
            ->willReturn(
                $startTime !== null
                    ? new \DateTimeImmutable(
                        $startTime
                    )
                    : null
            );

        $slot
            ->method('getDurationMinutes')
            ->willReturn($duration);

        $slot
            ->method('getBroadcastRank')
            ->willReturn($broadcastRank);

        $slot
            ->method('getMonthlyOccurrence')
            ->willReturn($monthlyOccurrence);

        $slot
            ->method('getWeekOffset')
            ->willReturn($weekOffset);

        $slot
            ->method('getMonthInterval')
            ->willReturn($monthInterval);

        $slot
            ->method('getWeekParity')
            ->willReturn(null);

        return $slot;
    }
}
