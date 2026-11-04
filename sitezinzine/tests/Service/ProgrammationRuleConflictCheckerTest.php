<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\ProgrammationRuleSlotRepository;
use App\Service\ProgrammationRuleConflictChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProgrammationRuleConflictCheckerTest extends TestCase
{
    private ProgrammationRuleSlotRepository&MockObject $repository;
    private ProgrammationRuleConflictChecker $checker;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(
            ProgrammationRuleSlotRepository::class
        );

        $this->checker = new ProgrammationRuleConflictChecker(
            $this->repository
        );
    }

    public function testWeeklySlotsWithSameScheduleConflict(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            weekParity: null
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            weekParity: null
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testWeeklyOverlappingTimesConflict(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:30',
            duration: 60
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testWeeklyTouchingTimesDoNotConflict(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '21:00',
            duration: 60
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testWeeklySameEvenParityConflicts(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_EVEN
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_EVEN
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testWeeklyUnrestrictedParityConflictsWithEvenParity(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            weekParity: null
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_EVEN
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testWeeklyEvenAndOddParitiesDoNotConflict(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_EVEN
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_ODD
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testWeeklyDifferentDaysDoNotConflict(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 3,
            startTime: '20:00',
            duration: 60
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testMonthlySameOccurrenceConflicts(): void
    {
        $candidate = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND
        );

        $existing = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testMonthlySameOccurrenceWithOverlappingTimesConflicts(): void
    {
        $candidate = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND
        );

        $existing = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:30',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testMonthlyDifferentOccurrencesDoNotConflict(): void
    {
        $candidate = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FIRST
        );

        $existing = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testMonthlyFourthAndLastOccurrencesDoNotStructurallyConflict(): void
    {
        $candidate = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FOURTH
        );

        $existing = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_LAST
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testMonthlySameIntervalAndSameAnchorConflict(): void
    {
        $candidateRule = $this->rule(
            validFrom: new \DateTimeImmutable('2026-01-01')
        );

        $existingRule = $this->rule(
            validFrom: new \DateTimeImmutable('2026-01-01')
        );

        $candidate = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND,
            monthInterval: 2,
            rule: $candidateRule
        );

        $existing = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND,
            monthInterval: 2,
            rule: $existingRule
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testMonthlySameIntervalWithShiftedAnchorDoesNotConflict(): void
    {
        $candidateRule = $this->rule(
            validFrom: new \DateTimeImmutable('2026-01-01')
        );

        $existingRule = $this->rule(
            validFrom: new \DateTimeImmutable('2026-02-01')
        );

        $candidate = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND,
            monthInterval: 2,
            rule: $candidateRule
        );

        $existing = $this->monthlySlot(
            dayOfWeek: 1,
            startTime: '20:00',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_SECOND,
            monthInterval: 2,
            rule: $existingRule
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testRulesWithDisjointValidityWindowsDoNotConflict(): void
    {
        $candidateRule = $this->rule(
            validFrom: new \DateTimeImmutable('2027-01-01')
        );

        $existingRule = $this->rule(
            validUntil: new \DateTimeImmutable('2026-12-31')
        );

        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            rule: $candidateRule
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            rule: $existingRule
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testRulesWithOverlappingValidityWindowsConflict(): void
    {
        $candidateRule = $this->rule(
            validFrom: new \DateTimeImmutable('2026-06-01'),
            validUntil: new \DateTimeImmutable('2027-06-30')
        );

        $existingRule = $this->rule(
            validFrom: new \DateTimeImmutable('2026-01-01'),
            validUntil: new \DateTimeImmutable('2026-12-31')
        );

        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            rule: $candidateRule
        );

        $existing = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            rule: $existingRule
        );

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testInactiveCandidateDoesNotConflict(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            active: false
        );

        $this->repository
            ->expects(self::never())
            ->method('findActiveStructuralConflictCandidates');

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testDeletedCandidateDoesNotConflict(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $candidate->softDelete();

        $this->repository
            ->expects(self::never())
            ->method('findActiveStructuralConflictCandidates');

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testCandidateDoesNotConflictWithItselfDuringEdit(): void
    {
        $candidate = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $this->identify($candidate, 42);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$candidate]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testRebroadcastConflictsWithAnotherRulesFirstBroadcast(): void
    {
        $ruleA = $this->rule();
        $ruleB = $this->rule();

        $firstA = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60,
            broadcastRank: 1,
            rule: $ruleA
        );

        $rebroadcastA = $this->weeklySlot(
            dayOfWeek: 4,
            startTime: '18:00',
            duration: 60,
            broadcastRank: 2,
            rule: $ruleA
        );

        $firstB = $this->weeklySlot(
            dayOfWeek: 4,
            startTime: '18:00',
            duration: 60,
            broadcastRank: 1,
            rule: $ruleB
        );

        // Le rang 1 est présent pour représenter le schéma complet
        // de programmation de la règle A.
        $ruleA->addSlot($firstA);
        $ruleA->addSlot($rebroadcastA);

        $ruleB->addSlot($firstB);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$firstB]);

        self::assertSame(
            [$firstB],
            $this->checker->findStructuralConflicts($rebroadcastA)
        );
    }

    public function testRebroadcastConflictsWithItsOwnFirstBroadcast(): void
    {
        $rule = $this->rule();

        $first = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 120,
            broadcastRank: 1,
            rule: $rule
        );

        $rebroadcast = $this->weeklySlot(
            dayOfWeek: 2,
            startTime: '21:00',
            duration: 60,
            broadcastRank: 2,
            rule: $rule
        );

        $rule->addSlot($first);
        $rule->addSlot($rebroadcast);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$first]);

        self::assertSame(
            [$first],
            $this->checker->findStructuralConflicts($rebroadcast)
        );
    }

    public function testInactiveRuleCanStillBeCheckedForReactivation(): void
    {
        $candidateRule = (new ProgrammationRule())
            ->setIsActive(false);

        $existingRule = new ProgrammationRule();

        $candidate = (new ProgrammationRuleSlot())
            ->setRule($candidateRule)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $existing = (new ProgrammationRuleSlot())
            ->setRule($existingRule)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $candidateRule->addSlot($candidate);
        $existingRule->addSlot($existing);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts(
                $candidate,
                ignoreCandidateRuleState: true
            )
        );
    }

    public function testInactiveRuleWithNoConflictCanBeActivated(): void
    {
        $rule = (new ProgrammationRule())
            ->setIsActive(false);

        $slot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $rule->addSlot($slot);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([]);

        self::assertFalse(
            $this->checker->hasRuleStructuralConflicts($rule)
        );

        self::assertTrue(
            $this->checker->canActivateRule($rule)
        );
    }

    public function testInactiveRuleWithConflictCannotBeActivated(): void
    {
        $candidateRule = (new ProgrammationRule())
            ->setIsActive(false);

        $existingRule = new ProgrammationRule();

        $candidate = (new ProgrammationRuleSlot())
            ->setRule($candidateRule)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $existing = (new ProgrammationRuleSlot())
            ->setRule($existingRule)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('20:30:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $candidateRule->addSlot($candidate);
        $existingRule->addSlot($existing);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertTrue(
            $this->checker->hasRuleStructuralConflicts($candidateRule)
        );

        self::assertFalse(
            $this->checker->canActivateRule($candidateRule)
        );
    }

    public function testInactiveRuleDetectsConflictBetweenItsOwnSlots(): void
    {
        $rule = (new ProgrammationRule())
            ->setIsActive(false);

        $first = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $rebroadcast = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('20:30:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $rule->addSlot($first);
        $rule->addSlot($rebroadcast);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([]);

        $conflicts = $this->checker->findRuleStructuralConflicts($rule);

        self::assertCount(1, $conflicts);
        self::assertSame($first, $conflicts[0]['candidate']);
        self::assertSame($rebroadcast, $conflicts[0]['conflicting']);

        self::assertTrue(
            $this->checker->hasRuleStructuralConflicts($rule)
        );

        self::assertFalse(
            $this->checker->canActivateRule($rule)
        );
    }

    private function rule(
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validUntil = null
    ): ProgrammationRule {
        return (new ProgrammationRule())
            ->setValidFrom($validFrom)
            ->setValidUntil($validUntil);
    }

    public function testMonthlyRebroadcastWithDifferentOccurrenceDoesNotConflict(): void
    {
        $candidateRule = $this->rule();
        $existingRule = $this->rule();

        $candidate = $this->monthlySlot(
            dayOfWeek: 3,
            startTime: '12:30',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FOURTH,
            broadcastRank: 2,
            weekOffset: 0,
            rule: $candidateRule
        );

        $existing = $this->monthlySlot(
            dayOfWeek: 3,
            startTime: '12:30',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FIRST,
            broadcastRank: 1,
            weekOffset: 0,
            rule: $existingRule
        );

        $candidateRule->addSlot($candidate);
        $existingRule->addSlot($existing);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testMonthlyRebroadcastWithSameOccurrenceConflicts(): void
    {
        $candidateRule = $this->rule();
        $existingRule = $this->rule();

        $candidate = $this->monthlySlot(
            dayOfWeek: 3,
            startTime: '12:30',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FOURTH,
            broadcastRank: 2,
            weekOffset: 0,
            rule: $candidateRule
        );

        $existing = $this->monthlySlot(
            dayOfWeek: 3,
            startTime: '12:30',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FOURTH,
            broadcastRank: 1,
            weekOffset: 0,
            rule: $existingRule
        );

        $candidateRule->addSlot($candidate);
        $existingRule->addSlot($existing);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existing]);

        self::assertSame(
            [$existing],
            $this->checker->findStructuralConflicts($candidate)
        );
    }

    public function testRebroadcastsFromDifferentMonthlyOccurrencesDoNotConflict(): void
    {
        $candidateRule = $this->rule();
        $existingRule = $this->rule();

        // Architecture du trouble :
        // 1re diffusion = 1er mercredi du mois
        $candidateOrigin = $this->monthlySlot(
            dayOfWeek: 3,
            startTime: '12:30',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FIRST,
            broadcastRank: 1,
            weekOffset: 0,
            rule: $candidateRule
        );

        // 1re rediffusion = jeudi 09:30 de la semaine radio suivante
        $candidateRebroadcast = $this->weeklySlot(
            dayOfWeek: 4,
            startTime: '09:30',
            duration: 60,
            weekParity: null,
            broadcastRank: 2,
            weekOffset: 1,
            rule: $candidateRule
        );

        $candidateRule->addSlot($candidateOrigin);
        $candidateRule->addSlot($candidateRebroadcast);

        // Vortex et rotatives :
        // 1re diffusion = 3e mercredi du mois
        $existingOrigin = $this->monthlySlot(
            dayOfWeek: 3,
            startTime: '17:00',
            duration: 30,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_THIRD,
            broadcastRank: 1,
            weekOffset: 0,
            rule: $existingRule
        );

        // 1re rediffusion = jeudi 09:30 de la semaine radio suivante
        $existingRebroadcast = $this->weeklySlot(
            dayOfWeek: 4,
            startTime: '09:30',
            duration: 30,
            weekParity: null,
            broadcastRank: 2,
            weekOffset: 1,
            rule: $existingRule
        );

        $existingRule->addSlot($existingOrigin);
        $existingRule->addSlot($existingRebroadcast);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existingRebroadcast]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts($candidateRebroadcast)
        );
    }

    public function testRebroadcastsFromSameMonthlyOccurrenceConflict(): void
    {
        $candidateRule = $this->rule();
        $existingRule = $this->rule();

        $candidateOrigin = $this->monthlySlot(
            dayOfWeek: 3,
            startTime: '12:30',
            duration: 60,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FIRST,
            broadcastRank: 1,
            weekOffset: 0,
            rule: $candidateRule
        );

        $candidateRebroadcast = $this->weeklySlot(
            dayOfWeek: 4,
            startTime: '09:30',
            duration: 60,
            weekParity: null,
            broadcastRank: 2,
            weekOffset: 1,
            rule: $candidateRule
        );

        $candidateRule->addSlot($candidateOrigin);
        $candidateRule->addSlot($candidateRebroadcast);

        $existingOrigin = $this->monthlySlot(
            dayOfWeek: 3,
            startTime: '17:00',
            duration: 30,
            monthlyOccurrence: ProgrammationRuleSlot::MONTHLY_FIRST,
            broadcastRank: 1,
            weekOffset: 0,
            rule: $existingRule
        );

        $existingRebroadcast = $this->weeklySlot(
            dayOfWeek: 4,
            startTime: '09:30',
            duration: 30,
            weekParity: null,
            broadcastRank: 2,
            weekOffset: 1,
            rule: $existingRule
        );

        $existingRule->addSlot($existingOrigin);
        $existingRule->addSlot($existingRebroadcast);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existingRebroadcast]);

        self::assertSame(
            [$existingRebroadcast],
            $this->checker->findStructuralConflicts($candidateRebroadcast)
        );
    }

    public function testWeeklyRebroadcastsWithOppositeParitiesDoNotConflict(): void
    {
        $candidateRule = $this->rule();
        $existingRule = $this->rule();

        $candidateOrigin = $this->weeklySlot(
            dayOfWeek: 7,
            startTime: '19:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_ODD,
            broadcastRank: 1,
            rule: $candidateRule
        );

        $candidateRebroadcast = $this->weeklySlot(
            dayOfWeek: 1,
            startTime: '00:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_ODD,
            broadcastRank: 2,
            rule: $candidateRule
        );

        $existingOrigin = $this->weeklySlot(
            dayOfWeek: 7,
            startTime: '19:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_EVEN,
            broadcastRank: 1,
            rule: $existingRule
        );

        $existingRebroadcast = $this->weeklySlot(
            dayOfWeek: 1,
            startTime: '00:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_EVEN,
            broadcastRank: 2,
            rule: $existingRule
        );

        $candidateRule->addSlot($candidateOrigin);
        $candidateRule->addSlot($candidateRebroadcast);

        $existingRule->addSlot($existingOrigin);
        $existingRule->addSlot($existingRebroadcast);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existingRebroadcast]);

        self::assertSame(
            [],
            $this->checker->findStructuralConflicts(
                $candidateRebroadcast
            )
        );
    }

    public function testWeeklyRebroadcastsWithSameParityConflict(): void
    {
        $candidateRule = $this->rule();
        $existingRule = $this->rule();

        $candidateOrigin = $this->weeklySlot(
            dayOfWeek: 7,
            startTime: '19:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_ODD,
            broadcastRank: 1,
            rule: $candidateRule
        );

        $candidateRebroadcast = $this->weeklySlot(
            dayOfWeek: 1,
            startTime: '00:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_ODD,
            broadcastRank: 2,
            rule: $candidateRule
        );

        $existingOrigin = $this->weeklySlot(
            dayOfWeek: 7,
            startTime: '19:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_ODD,
            broadcastRank: 1,
            rule: $existingRule
        );

        $existingRebroadcast = $this->weeklySlot(
            dayOfWeek: 1,
            startTime: '00:00',
            duration: 60,
            weekParity: ProgrammationRuleSlot::WEEK_PARITY_ODD,
            broadcastRank: 2,
            rule: $existingRule
        );

        $candidateRule->addSlot($candidateOrigin);
        $candidateRule->addSlot($candidateRebroadcast);

        $existingRule->addSlot($existingOrigin);
        $existingRule->addSlot($existingRebroadcast);

        $this->repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn([$existingRebroadcast]);

        self::assertSame(
            [$existingRebroadcast],
            $this->checker->findStructuralConflicts(
                $candidateRebroadcast
            )
        );
    }

    private function weeklySlot(
        int $dayOfWeek,
        string $startTime,
        int $duration,
        ?string $weekParity = null,
        int $broadcastRank = 1,
        int $weekOffset = 0,
        ?ProgrammationRule $rule = null,
        bool $active = true
    ): ProgrammationRuleSlot {
        $rule ??= $this->rule();

        return (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable($startTime))
            ->setDurationMinutes($duration)
            ->setBroadcastRank($broadcastRank)
            ->setWeekOffset($weekOffset)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setWeekParity($weekParity)
            ->setIsActive($active);
    }

    private function monthlySlot(
        int $dayOfWeek,
        string $startTime,
        int $duration,
        int $monthlyOccurrence,
        int $monthInterval = 1,
        int $broadcastRank = 1,
        int $weekOffset = 0,
        ?ProgrammationRule $rule = null
    ): ProgrammationRuleSlot {
        $rule ??= $this->rule();

        return (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable($startTime))
            ->setDurationMinutes($duration)
            ->setBroadcastRank($broadcastRank)
            ->setWeekOffset($weekOffset)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_MONTHLY)
            ->setMonthlyOccurrence($monthlyOccurrence)
            ->setMonthInterval($monthInterval);
    }

    private function identify(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }
}
