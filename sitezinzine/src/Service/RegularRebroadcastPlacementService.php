<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use Doctrine\ORM\EntityManagerInterface;

class RegularRebroadcastPlacementService
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly GridPlacementConflictService $placementConflictService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function place(
        PendingRebroadcast $pendingRebroadcast,
        \DateTimeImmutable $startsAt
    ): DiffusionDraft {
        $emission = $pendingRebroadcast->getEmission();

        if (!$pendingRebroadcast->isPending()) {
            throw new \DomainException(
                'Cette rediffusion n’est plus disponible dans le parc.'
            );
        }

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

        /*
        * Une semaine radiophonique validée ne peut pas
        * recevoir de nouvelle affectation.
        *
        * La semaine commence le mardi à 00:00
        * et se termine le mardi suivant à 00:00
        * (borne de fin exclusive).
        */
        $dayOfWeek = (int) $startsAt->format('N');

        $daysSinceTuesday = ($dayOfWeek + 5) % 7;

        $weekStart = $startsAt
            ->setTime(0, 0, 0)
            ->modify(sprintf('-%d days', $daysSinceTuesday));

        $weekEnd = $weekStart->modify('+7 days');

        if ($this->diffusionRepository->hasDiffusionsBetween(
            $weekStart,
            $weekEnd
        )) {
            throw new \DomainException(
                'Impossible de modifier une semaine validée.'
            );
        }

        $duration = (int) ($emission->getDuree() ?? 0);

        if ($duration < 1) {
            $duration = 15;
        }

        $groupDrafts = $this->draftRepository
            ->findByAssignmentGroupKey($assignmentGroupKey);

        $publishedDiffusions = $this->diffusionRepository
            ->findPublishedByAssignmentGroupKey($assignmentGroupKey);

        /*
        * On distingue les rangs des diffusions régulières
        * et celui de la première diffusion manuelle.
        *
        * Une émission ponctuelle possède un manual_special
        * de rang 1, même si aucune diffusion régulière
        * n'existe dans son groupe.
        */

        $maxLinkedRebroadcastRank = 0;
        $maxRegularRank = 0;
        $maxManualInitialRank = 0;
        $hasRegularDraft = false;
        $lastRegularEndsAt = null;

        foreach ($groupDrafts as $groupDraft) {
            if (!$groupDraft instanceof DiffusionDraft) {
                continue;
            }

            if (
                DiffusionDraft::TYPE_MANUAL_REBROADCAST
                === $groupDraft->getDraftType()
            ) {
                $linkedDiffusion = $groupDraft->getPublishedDiffusion();

                if ($linkedDiffusion instanceof Diffusion) {
                    $linkedRank = $linkedDiffusion->getNombreDiffusion();

                    if (null !== $linkedRank && $linkedRank > 0) {
                        $maxLinkedRebroadcastRank = max(
                            $maxLinkedRebroadcastRank,
                            $linkedRank
                        );
                    }
                }
            }

            /*
            * Première diffusion d'un groupe manuel.
            *
            * Son rang est réservé et ne doit jamais
            * être réattribué à une rediffusion.
            */
            if (
                DiffusionDraft::TYPE_MANUAL_SPECIAL
                === $groupDraft->getDraftType()
            ) {
                $maxManualInitialRank = max(
                    $maxManualInitialRank,
                    (int) $groupDraft->getNombreDiffusion()
                );
            }

            /*
            * Les autres traitements concernent uniquement
            * les diffusions régulières.
            */
            if (
                DiffusionDraft::TYPE_REGULAR
                !== $groupDraft->getDraftType()
            ) {
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

        /*
        * Un groupe issu d'une règle doit toujours posséder
        * une diffusion régulière.
        *
        * Une rediffusion issue d'une non-régulière peut,
        * elle, exister sans draft regular.
        */
        if (
            str_starts_with($assignmentGroupKey, 'rule_')
            && !$hasRegularDraft
        ) {
            throw new \DomainException(
                'Aucune diffusion régulière trouvée pour ce groupe.'
            );
        }

        /*
        * Pour un groupe régulier, une rediffusion ponctuelle
        * doit être placée après la dernière diffusion régulière.
        */
        if (
            $hasRegularDraft
            && $lastRegularEndsAt instanceof \DateTimeImmutable
            && $startsAt < $lastRegularEndsAt
        ) {
            throw new \DomainException(
                'Une rediffusion ponctuelle doit être placée après toutes les diffusions régulières du groupe.'
            );
        }

        /*
        * Les Diffusion publiées occupent déjà des rangs.
        *
        * Un DiffusionDraft possédant une publishedDiffusion
        * représente la même occurrence et ne doit donc jamais
        * être compté comme une occurrence supplémentaire.
        */
        $maxPublishedRank = 0;

        foreach ($publishedDiffusions as $publishedDiffusion) {
            if (!$publishedDiffusion instanceof Diffusion) {
                continue;
            }

            $maxPublishedRank = max(
                $maxPublishedRank,
                (int) $publishedDiffusion->getNombreDiffusion()
            );
        }

        /*
        * Le rang de départ doit tenir compte :
        *
        * - des diffusions régulières ;
        * - de la première diffusion manuelle ;
        * - des diffusions déjà publiées.
        *
        * Exemple :
        * manual_special = 1
        * première rediffusion = 2
        * deuxième rediffusion = 3
        */
        $maxExistingRank = max(
            $maxRegularRank,
            $maxManualInitialRank,
            $maxPublishedRank,
            $maxLinkedRebroadcastRank
        );

        $nextRebroadcastRank = $maxExistingRank + 1;

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
            ->setNombreDiffusion($nextRebroadcastRank)
            ->setAssignmentGroupKey($assignmentGroupKey)
            ->setSchedule($startsAt, $duration);

        $this->entityManager->persist($draft);

        /*
     * Le nouveau Draft doit être visible par la requête
     * de renumérotation.
     */
        $this->entityManager->flush();

        /*
     * Les rangs des diffusions régulières, des premières
     * diffusions manuelles et des diffusions publiées
     * restent réservés.
     *
     * Les rediffusions manuelles encore en draft
     * sont renumérotées chronologiquement à partir
     * du premier rang disponible.
     */
        $this->renumberManualRebroadcasts(
            $assignmentGroupKey,
            $maxExistingRank
        );

        /*
     * Le Pending n'est supprimé qu'une fois toutes
     * les validations terminées et le Draft créé.
     */
        $this->entityManager->remove($pendingRebroadcast);

        $this->entityManager->flush();

        return $draft;
    }

    private function renumberManualRebroadcasts(
        string $assignmentGroupKey,
        int $maxExistingRank
    ): void {
        $groupDrafts = $this->draftRepository->findBy(
            [
                'assignmentGroupKey' => $assignmentGroupKey,
            ],
            [
                'horaireDiffusion' => 'ASC',
                'id' => 'ASC',
            ]
        );

        /*
         * Les rangs déjà occupés par les diffusions régulières
         * ou publiées ne doivent pas être réattribués.
         */
        $nextRank = $maxExistingRank + 1;

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

            /*
             * Si le Draft est déjà lié à une Diffusion publiée,
             * les deux lignes représentent exactement la même
             * occurrence.
             *
             * Le rang de la Diffusion publiée est donc la référence.
             */
            $publishedDiffusion = $groupDraft->getPublishedDiffusion();

            if ($publishedDiffusion instanceof Diffusion) {
                $publishedRank = $publishedDiffusion->getNombreDiffusion();

                if (
                    null !== $publishedRank
                    && $publishedRank > 0
                ) {
                    $groupDraft->setNombreDiffusion($publishedRank);
                }

                continue;
            }

            /*
             * Le Draft n'a pas encore été publié :
             * il représente une nouvelle occurrence et reçoit
             * le prochain rang disponible.
             */
            $groupDraft->setNombreDiffusion($nextRank);

            ++$nextRank;
        }
    }
}
