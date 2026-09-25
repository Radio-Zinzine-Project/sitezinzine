<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiffusionDraft;
use App\Entity\GridSlotArbitration;
use App\Repository\DiffusionDraftRepository;
use App\Repository\GridSlotArbitrationRepository;

class GridDraftOverlapService
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly GridSlotArbitrationRepository $arbitrationRepository
    ) {
    }

    /**
     * @return DiffusionDraft[]
     */
    public function findBlockingOverlaps(
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

    public function hasBlockingOverlap(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?int $excludeDraftId = null
    ): bool {
        return [] !== $this->findBlockingOverlaps(
            $startsAt,
            $endsAt,
            $excludeDraftId
        );
    }
}