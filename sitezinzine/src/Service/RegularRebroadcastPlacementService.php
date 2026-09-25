<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\DiffusionDraftRepository;
use Doctrine\ORM\EntityManagerInterface;

class RegularRebroadcastPlacementService
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly GridPlacementConflictService $placementConflictService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function place(
        PendingRebroadcast $pendingRebroadcast,
        \DateTimeImmutable $startsAt
    ): DiffusionDraft {
        $emission = $pendingRebroadcast->getEmission();

        if (!$emission instanceof Emission) {
            throw new \DomainException('Émission introuvable.');
        }

        $assignmentGroupKey = $pendingRebroadcast->getAssignmentGroupKey();

        if ('' === trim($assignmentGroupKey)) {
            throw new \DomainException(
                'Cette rediffusion en attente ne possède pas de groupe.'
            );
        }

        if (
            0 !== (int) $startsAt->format('s')
            || 0 !== ((int) $startsAt->format('i') % 15)
        ) {
            throw new \DomainException(
                'L’heure doit être alignée sur un quart d’heure.'
            );
        }

        $duration = (int) ($emission->getDuree() ?? 0);

        if ($duration < 1) {
            $duration = 15;
        }

        $groupDrafts = $this->draftRepository
            ->findByAssignmentGroupKey($assignmentGroupKey);

        $maxRegularRank = 0;
        $hasRegularDraft = false;
        $lastRegularEndsAt = null;

        foreach ($groupDrafts as $groupDraft) {
            if (!$groupDraft instanceof DiffusionDraft) {
                continue;
            }

            if (DiffusionDraft::TYPE_REGULAR !== $groupDraft->getDraftType()) {
                continue;
            }

            $hasRegularDraft = true;

            $maxRegularRank = max(
                $maxRegularRank,
                (int) $groupDraft->getNombreDiffusion()
            );

            $regularEndsAt = $groupDraft->getEndsAt();

            if (
                $regularEndsAt instanceof \DateTimeImmutable
                && (
                    null === $lastRegularEndsAt
                    || $regularEndsAt > $lastRegularEndsAt
                )
            ) {
                $lastRegularEndsAt = $regularEndsAt;
            }
        }

        if (!$hasRegularDraft) {
            throw new \DomainException(
                'Aucune diffusion régulière trouvée pour ce groupe.'
            );
        }

        if (
            $lastRegularEndsAt instanceof \DateTimeImmutable
            && $startsAt < $lastRegularEndsAt
        ) {
            throw new \DomainException(
                'Une rediffusion ponctuelle doit être placée après toutes les diffusions régulières du groupe.'
            );
        }

        $endsAt = $startsAt->modify(
            sprintf('+%d minutes', $duration)
        );

        if (
            $this->placementConflictService->hasBlockingRegularOverlap(
                $startsAt,
                $endsAt
            )
        ) {
            throw new \DomainException(
                'Ce créneau chevauche déjà une programmation régulière.'
            );
        }

        if (
            $this->placementConflictService->hasBlockingDraftOverlap(
                $startsAt,
                $endsAt
            )
        ) {
            throw new \DomainException(
                'Ce créneau chevauche déjà une programmation existante.'
            );
        }

        $draft = new DiffusionDraft();

        $draft
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setNombreDiffusion($maxRegularRank + 1)
            ->setAssignmentGroupKey($assignmentGroupKey)
            ->setSchedule($startsAt, $duration);

        $this->entityManager->persist($draft);

        /*
         * Premier flush :
         * le nouveau draft devient visible par la requête de renumérotation.
         */
        $this->entityManager->flush();

        $this->renumberManualRebroadcasts(
            $assignmentGroupKey,
            $maxRegularRank
        );

        /*
         * Le pending n'est supprimé qu'une fois toutes les validations
         * terminées et le draft effectivement créé.
         */
        $this->entityManager->remove($pendingRebroadcast);

        $this->entityManager->flush();

        return $draft;
    }

    private function renumberManualRebroadcasts(
        string $assignmentGroupKey,
        int $maxRegularRank
    ): void {
        $groupDrafts = $this->draftRepository->findBy(
            [
                'assignmentGroupKey' => $assignmentGroupKey,
            ],
            [
                'horaireDiffusion' => 'ASC',
            ]
        );

        $rank = $maxRegularRank + 1;

        foreach ($groupDrafts as $groupDraft) {
            if (!$groupDraft instanceof DiffusionDraft) {
                continue;
            }

            if (
                DiffusionDraft::TYPE_MANUAL_REBROADCAST
                !== $groupDraft->getDraftType()
            ) {
                continue;
            }

            $groupDraft->setNombreDiffusion($rank);
            ++$rank;
        }
    }
}