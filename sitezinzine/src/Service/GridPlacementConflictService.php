<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiffusionDraft;
use App\Entity\GridSlotArbitration;
use App\Repository\DiffusionDraftRepository;
use App\Repository\GridSlotArbitrationRepository;

class GridPlacementConflictService
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly GridSlotArbitrationRepository $arbitrationRepository,
        private readonly ProgrammationGridBuilder $programmationGridBuilder,
        private readonly GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        private readonly GridConflictDetector $gridConflictDetector
    ) {
    }

    /**
     * @return DiffusionDraft[]
     */
    public function findBlockingDraftOverlaps(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?int $excludeDraftId = null
    ): array {
        $drafts = $this->draftRepository->findOverlappingDrafts(
            $startsAt,
            $endsAt,
            $excludeDraftId
        );

        return array_values(array_filter(
            $drafts,
            function (DiffusionDraft $draft): bool {
                if (DiffusionDraft::TYPE_REGULAR !== $draft->getDraftType()) {
                    return true;
                }

                $slot = $draft->getSlot();
                $draftStartsAt = $draft->getHoraireDiffusion();

                if (
                    null === $slot
                    || null === $slot->getId()
                    || !$draftStartsAt instanceof \DateTimeImmutable
                ) {
                    return true;
                }

                $arbitration = $this->arbitrationRepository->findOneBy([
                    'slot' => $slot,
                    'originalStartsAt' => $draftStartsAt,
                ]);

                if (!$arbitration instanceof GridSlotArbitration) {
                    return true;
                }

                return !(
                    $arbitration->isCancelAction()
                    || $arbitration->isRescheduleAction()
                );
            }
        ));
    }

    public function hasBlockingDraftOverlap(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?int $excludeDraftId = null
    ): bool {
        return [] !== $this->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt,
            $excludeDraftId
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBlockingRegularOverlaps(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt
    ): array {
        $weekStart = $this->getRadioWeekStart($startsAt);
        $weekEnd = $weekStart->modify('+7 days');

        $daySegments = $this->programmationGridBuilder->buildForWeek(
            $weekStart,
            $weekEnd
        );

        $daySegments = $this->gridOccurrenceProjectionService->applyForWeek(
            $daySegments,
            $weekStart,
            $weekEnd
        );

        return $this->gridConflictDetector->findBlockingOverlapsForRange(
            $daySegments,
            $startsAt,
            $endsAt
        );
    }

    public function hasBlockingRegularOverlap(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt
    ): bool {
        return [] !== $this->findBlockingRegularOverlaps(
            $startsAt,
            $endsAt
        );
    }

    public function hasBlockingOverlap(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?int $excludeDraftId = null
    ): bool {
        if ($this->hasBlockingRegularOverlap($startsAt, $endsAt)) {
            return true;
        }

        return $this->hasBlockingDraftOverlap(
            $startsAt,
            $endsAt,
            $excludeDraftId
        );
    }

    private function getRadioWeekStart(
        \DateTimeImmutable $date
    ): \DateTimeImmutable {
        $dayOfWeek = (int) $date->format('N');
        $daysSinceTuesday = ($dayOfWeek + 5) % 7;

        return $date
            ->modify(sprintf('-%d days', $daysSinceTuesday))
            ->setTime(0, 0);
    }
}