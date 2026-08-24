<?php

namespace App\Service;

use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\ProgrammationRuleRepository;

class ProgrammationGridBuilder
{
    public function __construct(
        private ProgrammationRuleRepository $programmationRuleRepository,
    ) {}

    public function buildForWeek(\DateTimeImmutable $startOfWeek, \DateTimeImmutable $endOfWeek): array
    {
        $daySegments = array_fill(0, 7, []);

        $rules = $this->programmationRuleRepository->findAll();

        foreach ($rules as $rule) {
            if (!$rule instanceof ProgrammationRule) {
                continue;
            }

            if ($rule->isDeleted() || !$rule->isActive()) {
                continue;
            }

            $firstBroadcastSlots = [];
            $rebroadcastSlots = [];

            foreach ($rule->getSlots() as $slot) {
                if (!$slot instanceof ProgrammationRuleSlot) {
                    continue;
                }

                if (!$slot->isActive() || $slot->isDeleted()) {
                    continue;
                }

                if ($slot->getBroadcastRank() === 1) {
                    $firstBroadcastSlots[] = $slot;
                } else {
                    $rebroadcastSlots[] = $slot;
                }
            }

            foreach ($firstBroadcastSlots as $firstSlot) {
                $firstOccurrences = $this->generateSlotOccurrencesForWeekWithLookAround(
                    $rule,
                    $firstSlot,
                    $startOfWeek,
                    $endOfWeek
                );

                foreach ($firstOccurrences as $firstStartsAt) {
                    if ($firstStartsAt >= $startOfWeek && $firstStartsAt < $endOfWeek) {
                        $this->addSegment(
                            $daySegments,
                            $rule,
                            $firstSlot,
                            $firstStartsAt,
                            $startOfWeek,
                            $firstStartsAt
                        );
                    }

                    foreach ($rebroadcastSlots as $rebroadcastSlot) {
                        $rebroadcastStartsAt = $this->computeStartsAtFromAnchor(
                            $firstStartsAt,
                            $rebroadcastSlot
                        );

                        if ($rebroadcastStartsAt < $startOfWeek || $rebroadcastStartsAt >= $endOfWeek) {
                            continue;
                        }

                        $this->addSegment(
                            $daySegments,
                            $rule,
                            $rebroadcastSlot,
                            $rebroadcastStartsAt,
                            $startOfWeek,
                            $firstStartsAt
                        );
                    }
                }
            }
        }

        foreach ($daySegments as &$segments) {
            usort(
                $segments,
                static function (array $a, array $b): int {
                    $startsAtComparison = strcmp($a['startsAt'], $b['startsAt']);

                    if ($startsAtComparison !== 0) {
                        return $startsAtComparison;
                    }

                    return $a['duration'] <=> $b['duration'];
                }
            );
        }
        unset($segments);

        foreach ($daySegments as &$segments) {
            $segments = $this->detectConflictsForDay($segments);
        }
        unset($segments);

        return $daySegments;
    }

    private function generateSlotOccurrencesForWeekWithLookAround(
        ProgrammationRule $rule,
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $startOfWeek,
        \DateTimeImmutable $endOfWeek
    ): array {
        $rangeStart = $startOfWeek->modify('-8 weeks');
        $rangeEnd = $endOfWeek->modify('+8 weeks');

        $occurrences = [];

        if ($slot->isWeekly()) {
            $cursorWeekStart = $this->getRadioWeekStart($rangeStart);

            while ($cursorWeekStart < $rangeEnd) {
                $visibleDate = $this->findDayInDisplayedWeek($cursorWeekStart, $slot->getDayOfWeek());

                if ($visibleDate !== null) {
                    $startsAt = $this->applyTime($visibleDate, $slot);

                    if (
                        $startsAt >= $rangeStart
                        && $startsAt < $rangeEnd
                        && $this->weeklyOccurrenceMatchesRule($rule, $slot, $startsAt)
                        && $this->weekMatchesParity($slot, $startsAt)
                    ) {
                        $occurrences[$startsAt->format('Y-m-d H:i:s')] = $startsAt;
                    }
                }

                $cursorWeekStart = $cursorWeekStart->modify('+7 days');
            }
        }

        if ($slot->isMonthly()) {
            $monthCursor = $rangeStart->modify('first day of this month')->setTime(0, 0, 0);
            $lastMonth = $rangeEnd->modify('first day of this month')->setTime(0, 0, 0);

            while ($monthCursor <= $lastMonth) {
                if ($this->monthMatchesInterval($rule, $monthCursor, $slot->getMonthInterval())) {
                    $baseDate = $this->resolveMonthlyOccurrenceDate(
                        (int) $monthCursor->format('Y'),
                        (int) $monthCursor->format('m'),
                        $slot->getDayOfWeek(),
                        $slot->getMonthlyOccurrence()
                    );

                    if ($baseDate !== null && $this->dateMatchesRuleWindow($rule, $baseDate)) {
                        $visibleDate = $baseDate->modify(sprintf('+%d days', $slot->getWeekOffset() * 7));
                        $startsAt = $this->applyTime($visibleDate, $slot);

                        if ($startsAt >= $rangeStart && $startsAt < $rangeEnd) {
                            $occurrences[$startsAt->format('Y-m-d H:i:s')] = $startsAt;
                        }
                    }
                }

                $monthCursor = $monthCursor->modify('first day of next month')->setTime(0, 0, 0);
            }
        }

        ksort($occurrences);

        return array_values($occurrences);
    }

    private function computeStartsAtFromAnchor(
        \DateTimeImmutable $anchorDate,
        ProgrammationRuleSlot $slot
    ): \DateTimeImmutable {
        $anchorWeekStart = $this->getRadioWeekStart($anchorDate);

        $targetDate = $anchorWeekStart
            ->modify(sprintf('+%d days', $this->radioDayIndexFromDayOfWeek($slot->getDayOfWeek())))
            ->modify(sprintf('+%d days', $slot->getWeekOffset() * 7));

        return $this->applyTime($targetDate, $slot);
    }

    private function radioDayIndexFromDayOfWeek(?int $dayOfWeek): int
    {
        return match ($dayOfWeek) {
            2 => 0,
            3 => 1,
            4 => 2,
            5 => 3,
            6 => 4,
            7 => 5,
            1 => 6,
            default => 0,
        };
    }

    private function addSegment(
        array &$daySegments,
        ProgrammationRule $rule,
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $startOfWeek,
        \DateTimeImmutable $firstBroadcastStartsAt
    ): void {
        $dayIndex = (int) $startOfWeek->diff($startsAt)->days;

        if ($dayIndex < 0 || $dayIndex > 6) {
            return;
        }

        $hour = (int) $startsAt->format('H');
        $minute = (int) $startsAt->format('i');

        $startIndex = $hour * 4 + intdiv($minute, 15);
        $startIndex = max(0, min(95, $startIndex));

        $duration = $slot->getDurationMinutes() ?? 15;
        $endsAt = $startsAt->modify(sprintf('+%d minutes', $duration));

        $category = $rule->getCategory();
        $categoryTitle = $category?->getTitre() ?? 'Catégorie inconnue';
        $categorySlug = $category?->getSlug();

        $segmentKey = $this->buildSegmentKey(
            $slot->getId(),
            $startsAt
        );

        $daySegments[$dayIndex][] = [
            'segmentKey' => $segmentKey,

            'title' => $categoryTitle,
            'displayTitle' => $categoryTitle,

            'categoryTitle' => $categoryTitle,
            'categorySlug' => $categorySlug,

            'duration' => $duration,
            'startIndex' => $startIndex,

            'ruleId' => $rule->getId(),
            'ruleNumber' => $rule->getRuleNumber(),
            'ruleDisplayName' => $rule->getDisplayName(),

            'slotId' => $slot->getId(),
            'broadcastRank' => $slot->getBroadcastRank(),

            /*
         * Occurrence de rang 1 à l'origine de ce segment.
         *
         * Pour une première diffusion :
         * firstBroadcastStartsAt === startsAt
         *
         * Pour une rediffusion :
         * firstBroadcastStartsAt correspond à l'occurrence
         * de la première diffusion liée à cette rediffusion.
         */
            'firstBroadcastStartsAt' => $firstBroadcastStartsAt->format('Y-m-d H:i:s'),

            'startsAt' => $startsAt->format('Y-m-d H:i:s'),
            'endsAt' => $endsAt->format('Y-m-d H:i:s'),

            'hasConflict' => false,
            'conflictType' => null,
            'conflictSeverity' => null,
            'conflictCount' => 0,
            'conflictWith' => [],
        ];
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function generateSlotOccurrencesForWeek(
        ProgrammationRule $rule,
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $startOfWeek,
        \DateTimeImmutable $endOfWeek
    ): array {
        $occurrences = [];

        if ($slot->isWeekly()) {
            $visibleDate = $this->findDayInDisplayedWeek($startOfWeek, $slot->getDayOfWeek());

            if ($visibleDate !== null) {
                $startsAt = $this->applyTime($visibleDate, $slot);

                if (
                    $startsAt >= $startOfWeek
                    && $startsAt < $endOfWeek
                    && $this->weeklyOccurrenceMatchesRule($rule, $slot, $startsAt)
                    && $this->weekMatchesParity($slot, $startsAt)
                ) {
                    $occurrences[] = $startsAt;
                }
            }
        }

        if ($slot->isMonthly()) {
            $monthsToCheck = [
                $startOfWeek->modify('first day of last month')->setTime(0, 0, 0),
                $startOfWeek->modify('first day of this month')->setTime(0, 0, 0),
                $startOfWeek->modify('first day of next month')->setTime(0, 0, 0),
            ];

            foreach ($monthsToCheck as $monthCursor) {
                if (!$this->monthMatchesInterval($rule, $monthCursor, $slot->getMonthInterval())) {
                    continue;
                }

                $baseDate = $this->resolveMonthlyOccurrenceDate(
                    (int) $monthCursor->format('Y'),
                    (int) $monthCursor->format('m'),
                    $slot->getDayOfWeek(),
                    $slot->getMonthlyOccurrence()
                );

                if ($baseDate === null) {
                    continue;
                }

                if (!$this->dateMatchesRuleWindow($rule, $baseDate)) {
                    continue;
                }

                $visibleDate = $baseDate->modify(sprintf('+%d days', $slot->getWeekOffset() * 7));
                $startsAt = $this->applyTime($visibleDate, $slot);

                if ($startsAt < $startOfWeek || $startsAt >= $endOfWeek) {
                    continue;
                }

                $key = $startsAt->format('Y-m-d H:i:s');
                $occurrences[$key] = $startsAt;
            }
        }

        return array_values($occurrences);
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     *
     * @return array<int, array<string, mixed>>
     */
    private function detectConflictsForDay(array $segments): array
    {
        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            $currentStart = new \DateTimeImmutable($segments[$i]['startsAt']);
            $currentEnd = new \DateTimeImmutable($segments[$i]['endsAt']);

            for ($j = $i + 1; $j < $count; $j++) {
                $nextStart = new \DateTimeImmutable($segments[$j]['startsAt']);
                $nextEnd = new \DateTimeImmutable($segments[$j]['endsAt']);

                // Comme c'est trié par heure de début, on peut s'arrêter tôt
                if ($nextStart >= $currentEnd) {
                    break;
                }

                if (!$this->segmentsOverlap($currentStart, $currentEnd, $nextStart, $nextEnd)) {
                    continue;
                }

                $conflictType = $this->resolveConflictType($segments[$i], $segments[$j]);
                $conflictSeverity = $this->resolveConflictSeverity($currentStart, $currentEnd, $nextStart, $nextEnd);

                $segments[$i]['hasConflict'] = true;
                $segments[$j]['hasConflict'] = true;

                $segments[$i]['conflictType'] = $this->mergeConflictType(
                    $segments[$i]['conflictType'],
                    $conflictType
                );
                $segments[$j]['conflictType'] = $this->mergeConflictType(
                    $segments[$j]['conflictType'],
                    $conflictType
                );

                $segments[$i]['conflictSeverity'] = $this->mergeConflictSeverity(
                    $segments[$i]['conflictSeverity'],
                    $conflictSeverity
                );
                $segments[$j]['conflictSeverity'] = $this->mergeConflictSeverity(
                    $segments[$j]['conflictSeverity'],
                    $conflictSeverity
                );

                $this->appendConflictReference($segments[$i], $segments[$j]);
                $this->appendConflictReference($segments[$j], $segments[$i]);
            }
        }

        foreach ($segments as &$segment) {
            $segment['conflictCount'] = count($segment['conflictWith']);
        }
        unset($segment);

        return $segments;
    }

    private function segmentsOverlap(
        \DateTimeImmutable $startA,
        \DateTimeImmutable $endA,
        \DateTimeImmutable $startB,
        \DateTimeImmutable $endB
    ): bool {
        return $startA < $endB && $endA > $startB;
    }

    /**
     * Retourne un type de conflit lisible côté front.
     */
    private function resolveConflictType(array $segmentA, array $segmentB): string
    {
        if (($segmentA['slotId'] ?? null) === ($segmentB['slotId'] ?? null)) {
            return 'same_slot_overlap';
        }

        if (($segmentA['ruleId'] ?? null) === ($segmentB['ruleId'] ?? null)) {
            return 'same_rule_overlap';
        }

        return 'rule_overlap';
    }

    private function resolveConflictSeverity(
        \DateTimeImmutable $startA,
        \DateTimeImmutable $endA,
        \DateTimeImmutable $startB,
        \DateTimeImmutable $endB
    ): string {
        $sameStart = $startA == $startB;
        $sameEnd = $endA == $endB;

        if ($sameStart && $sameEnd) {
            return 'total';
        }

        if (($startA <= $startB && $endA >= $endB) || ($startB <= $startA && $endB >= $endA)) {
            return 'contained';
        }

        return 'partial';
    }

    private function mergeConflictType(?string $currentType, string $newType): string
    {
        if ($currentType === null) {
            return $newType;
        }

        if ($currentType === $newType) {
            return $currentType;
        }

        return 'multiple';
    }

    private function mergeConflictSeverity(?string $currentSeverity, string $newSeverity): string
    {
        if ($currentSeverity === null) {
            return $newSeverity;
        }

        if ($currentSeverity === $newSeverity) {
            return $currentSeverity;
        }

        $priority = [
            'partial' => 1,
            'contained' => 2,
            'total' => 3,
        ];

        return ($priority[$newSeverity] ?? 0) > ($priority[$currentSeverity] ?? 0)
            ? $newSeverity
            : $currentSeverity;
    }

    /**
     * @param array<string, mixed> $segment
     * @param array<string, mixed> $other
     */
    private function appendConflictReference(array &$segment, array $other): void
    {
        $reference = [
            'segmentKey' => $other['segmentKey'],
            'slotId' => $other['slotId'],
            'ruleId' => $other['ruleId'],
            'ruleNumber' => $other['ruleNumber'],
            'ruleDisplayName' => $other['ruleDisplayName'],
            'categoryTitle' => $other['categoryTitle'],
            'broadcastRank' => $other['broadcastRank'],
            'startsAt' => $other['startsAt'],
            'endsAt' => $other['endsAt'],
        ];

        foreach ($segment['conflictWith'] as $existing) {
            if (($existing['segmentKey'] ?? null) === $reference['segmentKey']) {
                return;
            }
        }

        $segment['conflictWith'][] = $reference;
    }

    private function buildSegmentKey(?int $slotId, \DateTimeImmutable $startsAt): string
    {
        return sprintf(
            '%s_%s',
            $slotId ?? 'slot_unknown',
            $startsAt->format('YmdHis')
        );
    }

    private function weeklyOccurrenceMatchesRule(
        ProgrammationRule $rule,
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $visibleStartsAt
    ): bool {
        $anchorDate = $visibleStartsAt->modify(sprintf('-%d days', $slot->getWeekOffset() * 7));

        return $this->dateMatchesRuleWindow($rule, $anchorDate);
    }

    private function weekMatchesParity(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $date
    ): bool {
        $weekParity = $slot->getWeekParity();

        if ($weekParity === null || $weekParity === '') {
            return true;
        }

        $radioWeekStart = $this->getRadioWeekStart($date);
        $weekNumber = (int) $radioWeekStart->format('W');

        return match ($weekParity) {
            ProgrammationRuleSlot::WEEK_PARITY_EVEN => $weekNumber % 2 === 0,
            ProgrammationRuleSlot::WEEK_PARITY_ODD => $weekNumber % 2 === 1,
            default => true,
        };
    }

    private function getRadioWeekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $date = $date->setTime(0, 0);

        // 1 = lundi, 2 = mardi, ..., 7 = dimanche
        $dayOfWeek = (int) $date->format('N');

        if ($dayOfWeek >= 2) {
            // Mardi = 0 jour à retirer, mercredi = 1, ..., dimanche = 5
            return $date->modify(sprintf('-%d days', $dayOfWeek - 2));
        }

        // Lundi appartient encore à la semaine radio commencée le mardi précédent
        return $date->modify('-6 days');
    }

    private function dateMatchesRuleWindow(
        ProgrammationRule $rule,
        \DateTimeImmutable $date
    ): bool {
        $validFrom = $this->toImmutableStartOfDay($rule->getValidFrom());
        $validUntil = $this->toImmutableEndOfDay($rule->getValidUntil());

        if ($validFrom !== null && $date < $validFrom) {
            return false;
        }

        if ($validUntil !== null && $date > $validUntil) {
            return false;
        }

        return true;
    }

    private function findDayInDisplayedWeek(
        \DateTimeImmutable $startOfWeek,
        ?int $dayOfWeek
    ): ?\DateTimeImmutable {
        if ($dayOfWeek === null) {
            return null;
        }

        for ($i = 0; $i < 7; $i++) {
            $candidate = $startOfWeek->modify(sprintf('+%d days', $i));

            if ((int) $candidate->format('N') === $dayOfWeek) {
                return $candidate;
            }
        }

        return null;
    }

    private function applyTime(
        \DateTimeImmutable $date,
        ProgrammationRuleSlot $slot
    ): \DateTimeImmutable {
        $startTime = $slot->getStartTime();

        if ($startTime === null) {
            return $date->setTime(0, 0, 0);
        }

        return $date->setTime(
            (int) $startTime->format('H'),
            (int) $startTime->format('i'),
            0
        );
    }

    private function monthMatchesInterval(
        ProgrammationRule $rule,
        \DateTimeImmutable $monthDate,
        int $monthInterval
    ): bool {
        if ($monthInterval <= 1) {
            return true;
        }

        $anchor = $this->toImmutableStartOfDay($rule->getValidFrom())
            ?? $monthDate->setDate((int) $monthDate->format('Y'), 1, 1)->setTime(0, 0, 0);

        $anchorYear = (int) $anchor->format('Y');
        $anchorMonth = (int) $anchor->format('n');

        $currentYear = (int) $monthDate->format('Y');
        $currentMonth = (int) $monthDate->format('n');

        $diffInMonths = (($currentYear - $anchorYear) * 12) + ($currentMonth - $anchorMonth);

        return $diffInMonths >= 0 && $diffInMonths % $monthInterval === 0;
    }

    private function resolveMonthlyOccurrenceDate(
        int $year,
        int $month,
        ?int $dayOfWeek,
        ?int $monthlyOccurrence
    ): ?\DateTimeImmutable {
        if ($dayOfWeek === null || $monthlyOccurrence === null) {
            return null;
        }

        $firstDay = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $lastDay = $firstDay->modify('last day of this month');

        $matchingDays = [];

        $cursor = $firstDay;
        while ($cursor <= $lastDay) {
            if ((int) $cursor->format('N') === $dayOfWeek) {
                $matchingDays[] = $cursor;
            }

            $cursor = $cursor->modify('+1 day');
        }

        if ($monthlyOccurrence === ProgrammationRuleSlot::MONTHLY_LAST) {
            if (empty($matchingDays)) {
                return null;
            }

            $lastMatch = end($matchingDays);

            return $lastMatch instanceof \DateTimeImmutable ? $lastMatch : null;
        }

        $index = $monthlyOccurrence - 1;

        return $matchingDays[$index] ?? null;
    }

    private function toImmutableStartOfDay(?\DateTimeInterface $date): ?\DateTimeImmutable
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof \DateTimeImmutable) {
            return $date->setTime(0, 0, 0);
        }

        if ($date instanceof \DateTime) {
            return \DateTimeImmutable::createFromMutable($date)->setTime(0, 0, 0);
        }

        return null;
    }

    private function toImmutableEndOfDay(?\DateTimeInterface $date): ?\DateTimeImmutable
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof \DateTimeImmutable) {
            return $date->setTime(23, 59, 59);
        }

        if ($date instanceof \DateTime) {
            return \DateTimeImmutable::createFromMutable($date)->setTime(23, 59, 59);
        }

        return null;
    }
}
