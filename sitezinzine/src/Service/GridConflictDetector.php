<?php

declare(strict_types=1);

namespace App\Service;

class GridConflictDetector
{
    /**
     * @param array<int, array<int, array<string, mixed>>> $daySegments
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function detectForWeek(array $daySegments): array
    {
        foreach ($daySegments as &$segments) {
            $segments = $this->detectForDay($segments);
        }
        unset($segments);

        return $daySegments;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     *
     * @return array<int, array<string, mixed>>
     */
    public function detectForDay(array $segments): array
    {
        foreach ($segments as &$segment) {
            $segment['hasConflict'] = false;
            $segment['conflictType'] = null;
            $segment['conflictSeverity'] = null;
            $segment['conflictCount'] = 0;
            $segment['conflictWith'] = [];
        }
        unset($segment);

        usort(
            $segments,
            static function (array $a, array $b): int {
                $startsAtComparison = strcmp((string) ($a['startsAt'] ?? ''), (string) ($b['startsAt'] ?? ''));

                if (0 !== $startsAtComparison) {
                    return $startsAtComparison;
                }

                return ((int) ($a['duration'] ?? 0)) <=> ((int) ($b['duration'] ?? 0));
            }
        );

        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            if (($segments[$i]['isBlocking'] ?? true) === false) {
                continue;
            }

            $currentStart = new \DateTimeImmutable((string) $segments[$i]['startsAt']);
            $currentEnd = new \DateTimeImmutable((string) $segments[$i]['endsAt']);

            for ($j = $i + 1; $j < $count; $j++) {
                if (($segments[$j]['isBlocking'] ?? true) === false) {
                    continue;
                }

                $nextStart = new \DateTimeImmutable((string) $segments[$j]['startsAt']);
                $nextEnd = new \DateTimeImmutable((string) $segments[$j]['endsAt']);

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
     * @param array<string, mixed> $segmentA
     * @param array<string, mixed> $segmentB
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
        if (null === $currentType) {
            return $newType;
        }

        if ($currentType === $newType) {
            return $currentType;
        }

        return 'multiple';
    }

    private function mergeConflictSeverity(?string $currentSeverity, string $newSeverity): string
    {
        if (null === $currentSeverity) {
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
            'segmentKey' => $other['segmentKey'] ?? null,
            'slotId' => $other['slotId'] ?? null,
            'ruleId' => $other['ruleId'] ?? null,
            'ruleNumber' => $other['ruleNumber'] ?? null,
            'ruleDisplayName' => $other['ruleDisplayName'] ?? null,
            'categoryTitle' => $other['categoryTitle'] ?? null,
            'broadcastRank' => $other['broadcastRank'] ?? null,
            'startsAt' => $other['startsAt'] ?? null,
            'endsAt' => $other['endsAt'] ?? null,
            'isProjectedOverride' => $other['isProjectedOverride'] ?? false,
            'projectionType' => $other['projectionType'] ?? null,
        ];

        foreach ($segment['conflictWith'] as $existing) {
            if (($existing['segmentKey'] ?? null) === $reference['segmentKey']) {
                return;
            }
        }

        $segment['conflictWith'][] = $reference;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $daySegments
     *
     * @return array<int, array<string, mixed>>
     */
    public function findBlockingOverlapsForRange(
        array $daySegments,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt
    ): array {
        $overlaps = [];

        foreach ($daySegments as $segments) {
            foreach ($segments as $segment) {
                if (($segment['isBlocking'] ?? true) === false) {
                    continue;
                }

                if (($segment['isCancelled'] ?? false) === true) {
                    continue;
                }

                if (empty($segment['startsAt']) || empty($segment['endsAt'])) {
                    continue;
                }

                $segmentStart = new \DateTimeImmutable((string) $segment['startsAt']);
                $segmentEnd = new \DateTimeImmutable((string) $segment['endsAt']);

                if ($this->segmentsOverlap($startsAt, $endsAt, $segmentStart, $segmentEnd)) {
                    $overlaps[] = $segment;
                }
            }
        }

        return $overlaps;
    }
}
