<?php

namespace App\Tests\Entity;

use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use PHPUnit\Framework\TestCase;

class ProgrammationRuleSlotTest extends TestCase
{
    public function testInitialValues(): void
    {
        $before = new \DateTimeImmutable();

        $slot = new ProgrammationRuleSlot();

        $after = new \DateTimeImmutable();

        $this->assertNull($slot->getId());
        $this->assertNull($slot->getRule());
        $this->assertNull($slot->getDayOfWeek());
        $this->assertNull($slot->getStartTime());
        $this->assertNull($slot->getDurationMinutes());

        $this->assertSame(1, $slot->getBroadcastRank());
        $this->assertSame(0, $slot->getWeekOffset());

        $this->assertSame(
            ProgrammationRuleSlot::RECURRENCE_WEEKLY,
            $slot->getRecurrenceType()
        );

        $this->assertNull($slot->getMonthlyOccurrence());
        $this->assertSame(1, $slot->getMonthInterval());
        $this->assertNull($slot->getWeekParity());

        $this->assertTrue($slot->isActive());
        $this->assertTrue($slot->getIsActive());

        $this->assertNull($slot->getDeletedAt());
        $this->assertFalse($slot->isDeleted());

        $this->assertTrue($slot->isWeekly());
        $this->assertFalse($slot->isMonthly());

        $this->assertNull($slot->getEndTime());

        $this->assertSame(
            '1re diffusion',
            $slot->getBroadcastLabel()
        );

        $this->assertSame(
            'Même semaine radio',
            $slot->getWeekOffsetLabel()
        );

        $this->assertSame(
            'Hebdomadaire',
            $slot->getRecurrenceLabel()
        );

        $this->assertNull(
            $slot->getMonthlyOccurrenceLabel()
        );

        $this->assertNull(
            $slot->getWeekParityLabel()
        );

        $this->assertSame(
            'Tous les mois',
            $slot->getMonthIntervalLabel()
        );

        $this->assertSame(
            'Jour inconnu',
            $slot->getDayLabel()
        );

        $this->assertSame(
            'Jour inconnu',
            $slot->getRadioDayLabel()
        );

        $this->assertSame(
            '1re diffusion - Hebdomadaire - Jour inconnu --:--',
            (string) $slot
        );

        $this->assertGreaterThanOrEqual(
            $before,
            $slot->getCreatedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $slot->getCreatedAt()
        );

        $this->assertSame(
            $slot->getCreatedAt(),
            $slot->getUpdatedAt()
        );
    }

    public function testBasicGettersAndSetters(): void
    {
        $slot = new ProgrammationRuleSlot();

        $rule = new ProgrammationRule();
        $startTime = new \DateTimeImmutable('10:30');

        $result = $slot
            ->setRule($rule)
            ->setDayOfWeek(2)
            ->setStartTime($startTime)
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(1)
            ->setIsActive(false);

        $this->assertSame($slot, $result);
        $this->assertSame($rule, $slot->getRule());
        $this->assertSame(2, $slot->getDayOfWeek());
        $this->assertSame($startTime, $slot->getStartTime());
        $this->assertSame(60, $slot->getDurationMinutes());
        $this->assertSame(2, $slot->getBroadcastRank());
        $this->assertSame(1, $slot->getWeekOffset());
        $this->assertFalse($slot->isActive());
    }

    public function testNullableFieldsCanBeCleared(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot
            ->setRule(new ProgrammationRule())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('10:00'))
            ->setDurationMinutes(60)
            ->setMonthlyOccurrence(
                ProgrammationRuleSlot::MONTHLY_SECOND
            )
            ->setWeekParity(
                ProgrammationRuleSlot::WEEK_PARITY_EVEN
            );

        $slot
            ->setRule(null)
            ->setDayOfWeek(null)
            ->setStartTime(null)
            ->setDurationMinutes(null)
            ->setMonthlyOccurrence(null)
            ->setWeekParity(null);

        $this->assertNull($slot->getRule());
        $this->assertNull($slot->getDayOfWeek());
        $this->assertNull($slot->getStartTime());
        $this->assertNull($slot->getDurationMinutes());
        $this->assertNull($slot->getMonthlyOccurrence());
        $this->assertNull($slot->getWeekParity());
    }

    public function testDayOfWeekAcceptsValuesFromOneToSeven(): void
    {
        for ($day = 1; $day <= 7; $day++) {
            $slot = new ProgrammationRuleSlot();

            $slot->setDayOfWeek($day);

            $this->assertSame($day, $slot->getDayOfWeek());
        }
    }

    public function testDayOfWeekRejectsValueBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setDayOfWeek(0);
    }

    public function testDayOfWeekRejectsValueAboveSeven(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setDayOfWeek(8);
    }

    public function testDurationMustBeGreaterThanZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setDurationMinutes(0);
    }

    public function testNegativeDurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setDurationMinutes(-15);
    }

    public function testBroadcastRankAcceptsOneToFour(): void
    {
        foreach ([1, 2, 3, 4] as $rank) {
            $slot = new ProgrammationRuleSlot();

            $slot->setBroadcastRank($rank);

            $this->assertSame(
                $rank,
                $slot->getBroadcastRank()
            );
        }
    }

    public function testInvalidBroadcastRankIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setBroadcastRank(5);
    }

    public function testNegativeWeekOffsetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setWeekOffset(-1);
    }

    public function testWeekOffsetAcceptsZeroAndPositiveValues(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setWeekOffset(0);
        $this->assertSame(0, $slot->getWeekOffset());

        $slot->setWeekOffset(3);
        $this->assertSame(3, $slot->getWeekOffset());
    }

    public function testWeeklyRecurrenceResetsMonthlyFields(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_MONTHLY
            )
            ->setMonthlyOccurrence(
                ProgrammationRuleSlot::MONTHLY_SECOND
            )
            ->setMonthInterval(3);

        $slot->setRecurrenceType(
            ProgrammationRuleSlot::RECURRENCE_WEEKLY
        );

        $this->assertTrue($slot->isWeekly());
        $this->assertFalse($slot->isMonthly());

        $this->assertNull(
            $slot->getMonthlyOccurrence()
        );

        $this->assertSame(
            1,
            $slot->getMonthInterval()
        );
    }

    public function testMonthlyRecurrenceClearsWeekParity(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setWeekParity(
            ProgrammationRuleSlot::WEEK_PARITY_ODD
        );

        $slot->setRecurrenceType(
            ProgrammationRuleSlot::RECURRENCE_MONTHLY
        );

        $this->assertTrue($slot->isMonthly());
        $this->assertFalse($slot->isWeekly());
        $this->assertNull($slot->getWeekParity());
    }

    public function testInvalidRecurrenceTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setRecurrenceType('invalid');
    }

    public function testMonthlyOccurrenceAcceptsAllowedValues(): void
    {
        $allowed = [
            ProgrammationRuleSlot::MONTHLY_FIRST,
            ProgrammationRuleSlot::MONTHLY_SECOND,
            ProgrammationRuleSlot::MONTHLY_THIRD,
            ProgrammationRuleSlot::MONTHLY_FOURTH,
            ProgrammationRuleSlot::MONTHLY_LAST,
            null,
        ];

        foreach ($allowed as $value) {
            $slot = new ProgrammationRuleSlot();

            $slot->setMonthlyOccurrence($value);

            $this->assertSame(
                $value,
                $slot->getMonthlyOccurrence()
            );
        }
    }

    public function testInvalidMonthlyOccurrenceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setMonthlyOccurrence(5);
    }

    public function testMonthIntervalMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setMonthInterval(0);
    }

    public function testWeekParityAcceptsEvenOddAndNull(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setWeekParity(
            ProgrammationRuleSlot::WEEK_PARITY_EVEN
        );

        $this->assertSame(
            ProgrammationRuleSlot::WEEK_PARITY_EVEN,
            $slot->getWeekParity()
        );

        $slot->setWeekParity(
            ProgrammationRuleSlot::WEEK_PARITY_ODD
        );

        $this->assertSame(
            ProgrammationRuleSlot::WEEK_PARITY_ODD,
            $slot->getWeekParity()
        );

        $slot->setWeekParity(null);

        $this->assertNull($slot->getWeekParity());
    }

    public function testInvalidWeekParityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProgrammationRuleSlot())
            ->setWeekParity('invalid');
    }

    public function testWeekParityLabels(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setWeekParity(
            ProgrammationRuleSlot::WEEK_PARITY_EVEN
        );

        $this->assertSame(
            'Semaines paires',
            $slot->getWeekParityLabel()
        );

        $slot->setWeekParity(
            ProgrammationRuleSlot::WEEK_PARITY_ODD
        );

        $this->assertSame(
            'Semaines impaires',
            $slot->getWeekParityLabel()
        );

        $slot->setWeekParity(null);

        $this->assertNull($slot->getWeekParityLabel());
    }

    public function testMonthIntervalLabels(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setMonthInterval(1);

        $this->assertSame(
            'Tous les mois',
            $slot->getMonthIntervalLabel()
        );

        $slot->setMonthInterval(2);

        $this->assertSame(
            'Tous les 2 mois',
            $slot->getMonthIntervalLabel()
        );

        $slot->setMonthInterval(4);

        $this->assertSame(
            'Tous les 4 mois',
            $slot->getMonthIntervalLabel()
        );
    }

    public function testBroadcastLabels(): void
    {
        $expected = [
            1 => '1re diffusion',
            2 => '1re rediffusion',
            3 => '2e rediffusion',
            4 => '3e rediffusion',
        ];

        foreach ($expected as $rank => $label) {
            $slot = new ProgrammationRuleSlot();

            $slot->setBroadcastRank($rank);

            $this->assertSame(
                $label,
                $slot->getBroadcastLabel()
            );
        }
    }

    public function testWeekOffsetLabels(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setWeekOffset(0);

        $this->assertSame(
            'Même semaine radio',
            $slot->getWeekOffsetLabel()
        );

        $slot->setWeekOffset(1);

        $this->assertSame(
            'Semaine radio suivante',
            $slot->getWeekOffsetLabel()
        );

        $slot->setWeekOffset(3);

        $this->assertSame(
            '3 semaines radio plus tard',
            $slot->getWeekOffsetLabel()
        );
    }

    public function testRecurrenceLabels(): void
    {
        $slot = new ProgrammationRuleSlot();

        $this->assertSame(
            'Hebdomadaire',
            $slot->getRecurrenceLabel()
        );

        $slot->setRecurrenceType(
            ProgrammationRuleSlot::RECURRENCE_MONTHLY
        );

        $this->assertSame(
            'Mensuelle',
            $slot->getRecurrenceLabel()
        );
    }

    public function testMonthlyOccurrenceLabels(): void
    {
        $expected = [
            ProgrammationRuleSlot::MONTHLY_FIRST => '1er',
            ProgrammationRuleSlot::MONTHLY_SECOND => '2e',
            ProgrammationRuleSlot::MONTHLY_THIRD => '3e',
            ProgrammationRuleSlot::MONTHLY_FOURTH => '4e',
            ProgrammationRuleSlot::MONTHLY_LAST => 'Dernier',
        ];

        foreach ($expected as $occurrence => $label) {
            $slot = new ProgrammationRuleSlot();

            $slot->setMonthlyOccurrence($occurrence);

            $this->assertSame(
                $label,
                $slot->getMonthlyOccurrenceLabel()
            );
        }
    }

    public function testDayLabels(): void
    {
        $expected = [
            1 => 'Lundi',
            2 => 'Mardi',
            3 => 'Mercredi',
            4 => 'Jeudi',
            5 => 'Vendredi',
            6 => 'Samedi',
            7 => 'Dimanche',
        ];

        foreach ($expected as $day => $label) {
            $slot = new ProgrammationRuleSlot();

            $slot->setDayOfWeek($day);

            $this->assertSame(
                $label,
                $slot->getDayLabel()
            );

            $this->assertSame(
                $label,
                $slot->getRadioDayLabel()
            );
        }
    }

    public function testEndTimeIsCalculatedFromStartAndDuration(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot
            ->setStartTime(
                new \DateTimeImmutable('2000-01-01 10:30:00')
            )
            ->setDurationMinutes(90);

        $endTime = $slot->getEndTime();

        $this->assertNotNull($endTime);

        $this->assertSame(
            '12:00',
            $endTime->format('H:i')
        );
    }

    public function testEndTimeIsNullWithoutStartTime(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setDurationMinutes(60);

        $this->assertNull($slot->getEndTime());
    }

    public function testEndTimeIsNullWithoutDuration(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setStartTime(
            new \DateTimeImmutable('10:00')
        );

        $this->assertNull($slot->getEndTime());
    }

    public function testToStringUsesCurrentValues(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot
            ->setBroadcastRank(2)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_MONTHLY
            )
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('10:30')
            );

        $this->assertSame(
            '1re rediffusion - Mensuelle - Mardi 10:30',
            (string) $slot
        );
    }

    public function testSetDeletedAtDisablesSlot(): void
    {
        $slot = new ProgrammationRuleSlot();

        $deletedAt = new \DateTimeImmutable(
            '2026-09-06 14:00:00'
        );

        $result = $slot->setDeletedAt($deletedAt);

        $this->assertSame($slot, $result);
        $this->assertSame(
            $deletedAt,
            $slot->getDeletedAt()
        );

        $this->assertTrue($slot->isDeleted());
        $this->assertFalse($slot->isActive());
    }

    public function testClearingDeletedAtDoesNotReactivateAutomatically(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->setDeletedAt(
            new \DateTimeImmutable('2026-09-06')
        );

        $slot->setDeletedAt(null);

        $this->assertFalse($slot->isDeleted());
        $this->assertFalse($slot->isActive());
    }

    public function testSoftDelete(): void
    {
        $slot = new ProgrammationRuleSlot();

        $result = $slot->softDelete();

        $this->assertSame($slot, $result);
        $this->assertTrue($slot->isDeleted());
        $this->assertFalse($slot->isActive());
        $this->assertNotNull($slot->getDeletedAt());
    }

    public function testRestore(): void
    {
        $slot = new ProgrammationRuleSlot();

        $slot->softDelete();

        $result = $slot->restore();

        $this->assertSame($slot, $result);
        $this->assertFalse($slot->isDeleted());
        $this->assertNull($slot->getDeletedAt());
        $this->assertTrue($slot->isActive());
    }

    public function testCreatedAtCanBeSetExplicitly(): void
    {
        $slot = new ProgrammationRuleSlot();

        $date = new \DateTimeImmutable(
            '2025-01-01 10:00:00'
        );

        $result = $slot->setCreatedAt($date);

        $this->assertSame($slot, $result);
        $this->assertSame(
            $date,
            $slot->getCreatedAt()
        );
    }

    public function testUpdatedAtCanBeSetExplicitly(): void
    {
        $slot = new ProgrammationRuleSlot();

        $date = new \DateTimeImmutable(
            '2025-01-01 10:00:00'
        );

        $result = $slot->setUpdatedAt($date);

        $this->assertSame($slot, $result);
        $this->assertSame(
            $date,
            $slot->getUpdatedAt()
        );
    }

    public function testMutatingMethodTouchesUpdatedAt(): void
    {
        $slot = new ProgrammationRuleSlot();

        $oldDate = new \DateTimeImmutable(
            '2000-01-01 00:00:00'
        );

        $slot->setUpdatedAt($oldDate);

        $slot->setDayOfWeek(2);

        $this->assertGreaterThan(
            $oldDate,
            $slot->getUpdatedAt()
        );
    }
}