<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GridSlotArbitration;
use App\Repository\GridSlotArbitrationRepository;

class GridOccurrenceProjectionService
{
    public function __construct(
        private GridSlotArbitrationRepository $gridSlotArbitrationRepository,
    ) {}

    /**
     * @param array<int, array<int, array<string, mixed>>> $daySegments
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function applyForWeek(
        array $daySegments,
        \DateTimeImmutable $startOfWeek,
        \DateTimeImmutable $endOfWeek
    ): array {
        $arbitrations = $this->gridSlotArbitrationRepository->findRelevantForWeek($startOfWeek, $endOfWeek);

        /** @var array<string, GridSlotArbitration> $arbitrationByOriginalOccurrenceKey */
        $arbitrationByOriginalOccurrenceKey = [];

        foreach ($arbitrations as $arbitration) {
            $slot = $arbitration->getSlot();
            $originalStartsAt = $arbitration->getOriginalStartsAt();

            if (null === $slot || null === $slot->getId() || null === $originalStartsAt) {
                continue;
            }

            $key = $this->buildOccurrenceKey((int) $slot->getId(), $originalStartsAt);
            $arbitrationByOriginalOccurrenceKey[$key] = $arbitration;
        }

        $projectedSegments = array_fill(0, 7, []);

        /** @var array<string, bool> $alreadyInjectedProjectedKeys */
        $alreadyInjectedProjectedKeys = [];

        foreach ($daySegments as $dayIndex => $segments) {
            foreach ($segments as $segment) {
                $segment = $this->withDefaultProjectionFlags($segment);

                $slotId = (int) ($segment['slotId'] ?? 0);
                $startsAtRaw = $segment['startsAt'] ?? null;

                if ($slotId <= 0 || empty($startsAtRaw)) {
                    $projectedSegments[$dayIndex][] = $segment;
                    continue;
                }

                $startsAt = new \DateTimeImmutable((string) $startsAtRaw);
                $occurrenceKey = $this->buildOccurrenceKey($slotId, $startsAt);

                if (!isset($arbitrationByOriginalOccurrenceKey[$occurrenceKey])) {
                    $segment['originalStartsAt'] = $segment['startsAt'];
                    $projectedSegments[$dayIndex][] = $segment;
                    continue;
                }

                $arbitration = $arbitrationByOriginalOccurrenceKey[$occurrenceKey];

                if ($arbitration->isCancelAction()) {
                    $segment['isCancelled'] = true;
                    $segment['canRestore'] = true;
                    $segment['isBlocking'] = false;
                    $segment['projectionType'] = $arbitration->getAction();
                    $segment['originalStartsAt'] = $segment['startsAt'];
                    $segment['canBeRescheduled'] = false;

                    $projectedSegments[$dayIndex][] = $segment;
                    continue;
                }

                if ($arbitration->isRescheduleAction()) {
                    $rescheduledStartsAt = $arbitration->getRescheduledStartsAt();
                    $rescheduledEndsAt = $arbitration->getRescheduledEndsAt();

                    if (null === $rescheduledStartsAt || null === $rescheduledEndsAt) {
                        continue;
                    }

                    if ($rescheduledStartsAt >= $startOfWeek && $rescheduledStartsAt < $endOfWeek) {
                        $projectedDayIndex = (int) $startOfWeek->diff($rescheduledStartsAt)->days;

                        if ($projectedDayIndex >= 0 && $projectedDayIndex <= 6) {
                            $projectedSegment = $this->buildProjectedSegment(
                                $segment,
                                $arbitration,
                                $rescheduledStartsAt,
                                $rescheduledEndsAt
                            );

                            $projectedSegments[$projectedDayIndex][] = $projectedSegment;
                            $alreadyInjectedProjectedKeys[$projectedSegment['segmentKey']] = true;
                        }
                    }

                    $originSegment = $segment;
                    $originSegment['isRescheduledOrigin'] = true;
                    $originSegment['canRestore'] = true;
                    $originSegment['isBlocking'] = false;
                    $originSegment['projectionType'] = $arbitration->getAction();
                    $originSegment['originalStartsAt'] = $segment['startsAt'];
                    $originSegment['canBeRescheduled'] = false;

                    $projectedSegments[$dayIndex][] = $originSegment;
                    continue;
                }

                $segment['originalStartsAt'] = $segment['startsAt'];
                $projectedSegments[$dayIndex][] = $segment;
            }
        }

        foreach ($arbitrations as $arbitration) {
            if (!$arbitration->isRescheduleAction()) {
                continue;
            }

            $slot = $arbitration->getSlot();
            $originalStartsAt = $arbitration->getOriginalStartsAt();
            $originalEndsAt = $arbitration->getOriginalEndsAt();
            $rescheduledStartsAt = $arbitration->getRescheduledStartsAt();
            $rescheduledEndsAt = $arbitration->getRescheduledEndsAt();

            if (
                null === $slot
                || null === $slot->getId()
                || null === $originalStartsAt
                || null === $originalEndsAt
                || null === $rescheduledStartsAt
                || null === $rescheduledEndsAt
            ) {
                continue;
            }

            if ($rescheduledStartsAt < $startOfWeek || $rescheduledStartsAt >= $endOfWeek) {
                continue;
            }

            $projectedSegmentKey = sprintf(
                '%s_projected_%s',
                $slot->getId(),
                $rescheduledStartsAt->format('YmdHis')
            );

            if (isset($alreadyInjectedProjectedKeys[$projectedSegmentKey])) {
                continue;
            }

            $projectedDayIndex = (int) $startOfWeek->diff($rescheduledStartsAt)->days;

            if ($projectedDayIndex < 0 || $projectedDayIndex > 6) {
                continue;
            }

            $baseSegment = $this->buildFallbackSegmentFromArbitration(
                $arbitration,
                $originalStartsAt,
                $originalEndsAt
            );

            $projectedSegment = $this->buildProjectedSegment(
                $baseSegment,
                $arbitration,
                $rescheduledStartsAt,
                $rescheduledEndsAt
            );

            $projectedSegments[$projectedDayIndex][] = $projectedSegment;
            $alreadyInjectedProjectedKeys[$projectedSegment['segmentKey']] = true;
        }

        return $projectedSegments;
    }

    /**
     * @param array<string, mixed> $baseSegment
     *
     * @return array<string, mixed>
     */
    private function buildProjectedSegment(
        array $baseSegment,
        GridSlotArbitration $arbitration,
        \DateTimeImmutable $newStartsAt,
        \DateTimeImmutable $newEndsAt
    ): array {
        $hour = (int) $newStartsAt->format('H');
        $minute = (int) $newStartsAt->format('i');
        $startIndex = $hour * 4 + intdiv($minute, 15);
        $startIndex = max(0, min(95, $startIndex));

        $segment = $baseSegment;
        $segment['segmentKey'] = sprintf(
            '%s_projected_%s',
            $baseSegment['slotId'] ?? 'slot_unknown',
            $newStartsAt->format('YmdHis')
        );

        $segment['startsAt'] = $newStartsAt->format('Y-m-d H:i:s');
        $segment['endsAt'] = $newEndsAt->format('Y-m-d H:i:s');
        $segment['startIndex'] = $startIndex;

        $segment['isProjectedOverride'] = true;
        $segment['projectionType'] = $arbitration->getAction();
        $segment['originalStartsAt'] = $arbitration->getOriginalStartsAt()?->format('Y-m-d H:i:s');
        $segment['canBeRescheduled'] = true;

        $segment['isBlocking'] = true;
        $segment['isCancelled'] = false;
        $segment['canRestore'] = false;
        $segment['isRescheduledOrigin'] = false;

        return $segment;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFallbackSegmentFromArbitration(
        GridSlotArbitration $arbitration,
        \DateTimeImmutable $originalStartsAt,
        \DateTimeImmutable $originalEndsAt
    ): array {
        $slot = $arbitration->getSlot();
        $rule = $slot?->getRule();
        $category = $rule?->getCategory();
        $duration = (int) (($originalEndsAt->getTimestamp() - $originalStartsAt->getTimestamp()) / 60);

        return [
            'segmentKey' => sprintf(
                '%s_%s',
                $slot?->getId() ?? 'slot_unknown',
                $originalStartsAt->format('YmdHis')
            ),
            'title' => $category?->getTitre() ?? 'Catégorie inconnue',
            'displayTitle' => $category?->getTitre() ?? 'Catégorie inconnue',
            'categoryTitle' => $category?->getTitre() ?? 'Catégorie inconnue',
            'categorySlug' => $category?->getSlug(),
            'duration' => $duration > 0 ? $duration : 15,
            'startIndex' => 0,
            'ruleId' => $rule?->getId(),
            'ruleNumber' => $rule?->getRuleNumber(),
            'ruleDisplayName' => $rule?->getDisplayName(),
            'slotId' => $slot?->getId(),
            'broadcastRank' => $slot?->getBroadcastRank(),
            'startsAt' => $originalStartsAt->format('Y-m-d H:i:s'),
            'endsAt' => $originalEndsAt->format('Y-m-d H:i:s'),
            'hasConflict' => false,
            'conflictType' => null,
            'conflictSeverity' => null,
            'conflictCount' => 0,
            'conflictWith' => [],
            'assigned' => false,
            'emissionId' => null,
            'emissionTitle' => null,
            'emissionIsAutoGenerated' => false,
            'isProjectedOverride' => false,
            'projectionType' => null,
            'originalStartsAt' => $originalStartsAt->format('Y-m-d H:i:s'),
            'canBeRescheduled' => true,
            'isCancelled' => false,
            'canRestore' => false,
            'isBlocking' => true,
            'isRescheduledOrigin' => false,
        ];
    }

    private function buildOccurrenceKey(int $slotId, \DateTimeImmutable $startsAt): string
    {
        return sprintf('%d|%s', $slotId, $startsAt->format('Y-m-d H:i:s'));
    }

    /**
     * @param array<string, mixed> $segment
     *
     * @return array<string, mixed>
     */
    private function withDefaultProjectionFlags(array $segment): array
    {
        $segment['isProjectedOverride'] = $segment['isProjectedOverride'] ?? false;
        $segment['projectionType'] = $segment['projectionType'] ?? null;
        $segment['originalStartsAt'] = $segment['originalStartsAt'] ?? ($segment['startsAt'] ?? null);
        $segment['canBeRescheduled'] = $segment['canBeRescheduled'] ?? true;

        $segment['isCancelled'] = $segment['isCancelled'] ?? false;
        $segment['canRestore'] = $segment['canRestore'] ?? false;
        $segment['isBlocking'] = $segment['isBlocking'] ?? true;
        $segment['isRescheduledOrigin'] = $segment['isRescheduledOrigin'] ?? false;

        return $segment;
    }
}
