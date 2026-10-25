<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Entity\DiffusionDraft;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\PendingRebroadcastRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PendingRebroadcastService implements PendingRebroadcastPopulatorInterface
{
    private const MAX_DIFFUSIONS_FOR_POOL = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly PendingRebroadcastRepository $pendingRebroadcastRepository,
        private readonly DiffusionDraftRepository $draftRepository,
    ) {}

    /**
     * Crée une rediffusion en attente pour un groupe.
     *
     * Le flush est volontairement laissé à l'appelant afin que la création
     * du PendingRebroadcast puisse faire partie d'une opération Doctrine
     * plus large et rester atomique.
     */
    public function createForGroup(
        Emission $emission,
        string $assignmentGroupKey
    ): PendingRebroadcast {
        if ('' === trim($assignmentGroupKey)) {
            throw new \InvalidArgumentException(
                'La clé du groupe d’affectation ne peut pas être vide.'
            );
        }

        $pendingRebroadcast = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($assignmentGroupKey);

        $this->entityManager->persist($pendingRebroadcast);

        return $pendingRebroadcast;
    }

    /**
     * @param Diffusion[] $diffusions
     *
     * @return PendingRebroadcast[]
     */
    public function populateFromPublishedDiffusions(
        array $diffusions,
        \DateTimeInterface $before
    ): array {
        if ([] === $diffusions) {
            return [];
        }

        usort(
            $diffusions,
            static function (Diffusion $left, Diffusion $right): int {
                $leftStartsAt = $left->getHoraireDiffusion();
                $rightStartsAt = $right->getHoraireDiffusion();

                if (null === $leftStartsAt) {
                    return null === $rightStartsAt ? 0 : 1;
                }

                if (null === $rightStartsAt) {
                    return -1;
                }

                return $leftStartsAt <=> $rightStartsAt;
            }
        );

        /** @var array<int, array<string, Diffusion>> $sources */
        $sources = [];

        foreach ($diffusions as $diffusion) {
            if (!$diffusion->isPublished()) {
                continue;
            }

            $emission = $diffusion->getEmission();
            $emissionId = $emission?->getId();
            $groupKey = $diffusion->getAssignmentGroupKey();

            if (
                null === $emissionId
                || null === $groupKey
                || '' === trim($groupKey)
                || str_starts_with($groupKey, 'rule_')
            ) {
                continue;
            }

            // Une source par couple émission / groupe.
            $sources[$emissionId][$groupKey] ??= $diffusion;
        }

        if ([] === $sources) {
            return [];
        }

        $groupKeys = [];

        foreach ($sources as $groups) {
            foreach (array_keys($groups) as $groupKey) {
                $groupKeys[$groupKey] = true;
            }
        }

        $publishedCounts = $this->diffusionRepository
            ->countPublishedBeforeByEmissionAndGroup(
                array_keys($groupKeys),
                $before
            );

        /*
     * On récupère les insertions Doctrine non flushées.
     * Elles doivent être prises en compte comme les Pending
     * déjà présents en base.
     */
        $scheduledInsertions = $this->entityManager
            ->getUnitOfWork()
            ->getScheduledEntityInsertions();

        $created = [];

        // Cache pour éviter de rechercher plusieurs fois le même groupe.
        $pendingsByGroup = [];
        $draftsByGroup = [];

        foreach ($sources as $emissionId => $groups) {
            foreach ($groups as $groupKey => $sourceDiffusion) {
                if (
                    ($publishedCounts[$emissionId][$groupKey] ?? 0)
                    >= self::MAX_DIFFUSIONS_FOR_POOL
                ) {
                    continue;
                }

                $emission = $sourceDiffusion->getEmission();

                if (!$emission instanceof Emission) {
                    continue;
                }

                /*
             * Un draft de rediffusion supplémentaire bloque
             * uniquement son émission, pas les autres émissions
             * partageant le même assignmentGroupKey.
             */
                if (!array_key_exists($groupKey, $draftsByGroup)) {
                    $draftsByGroup[$groupKey] = $this->draftRepository
                        ->findByAssignmentGroupKey($groupKey);
                }

                $hasPlacedRebroadcast = false;

                foreach ($draftsByGroup[$groupKey] as $draft) {
                    if (
                        $draft->getEmission()?->getId() === $emissionId
                        && $draft->getDraftType()
                        === DiffusionDraft::TYPE_MANUAL_REBROADCAST
                    ) {
                        $hasPlacedRebroadcast = true;
                        break;
                    }
                }

                if ($hasPlacedRebroadcast) {
                    continue;
                }

                /*
             * Recherche des Pending déjà enregistrés.
             * On conserve aussi les dismissed pour ne jamais
             * recréer automatiquement une exclusion volontaire.
             */
                if (!array_key_exists($groupKey, $pendingsByGroup)) {
                    $pendingsByGroup[$groupKey] =
                        $this->pendingRebroadcastRepository
                        ->findByAssignmentGroupKey($groupKey);

                    foreach ($scheduledInsertions as $entity) {
                        if (
                            $entity instanceof PendingRebroadcast
                            && $entity->getAssignmentGroupKey() === $groupKey
                        ) {
                            $pendingsByGroup[$groupKey][] = $entity;
                        }
                    }
                }

                $alreadyExists = false;

                foreach ($pendingsByGroup[$groupKey] as $pending) {
                    if ($pending->getEmission()?->getId() === $emissionId) {
                        $alreadyExists = true;
                        break;
                    }
                }

                if ($alreadyExists) {
                    continue;
                }

                /*
             * createForGroup() persiste sans flush.
             * On ajoute immédiatement la nouvelle entité au cache
             * pour éviter une duplication pendant cet appel.
             */
                $pending = $this->createForGroup(
                    $emission,
                    $groupKey
                );

                $pendingsByGroup[$groupKey][] = $pending;
                $created[] = $pending;
            }
        }

        return $created;
    }

    public function restoreToPoolIfEligible(
        Emission $emission,
        string $assignmentGroupKey
    ): ?PendingRebroadcast {
        if ('' === trim($assignmentGroupKey)) {
            throw new \InvalidArgumentException(
                'La clé du groupe d’affectation ne peut pas être vide.'
            );
        }

        $existingPendings = $this->pendingRebroadcastRepository
            ->findByAssignmentGroupKey($assignmentGroupKey);

        /*
     * Les requêtes du repository ne voient pas nécessairement
     * les nouvelles entités persistées avant flush().
     *
     * On complète donc les résultats avec les PendingRebroadcast
     * déjà programmés pour insertion par Doctrine.
     */
        foreach (
            $this->entityManager->getUnitOfWork()
                ->getScheduledEntityInsertions() as $entity
        ) {
            if (
                !$entity instanceof PendingRebroadcast
                || $entity->getAssignmentGroupKey() !== $assignmentGroupKey
            ) {
                continue;
            }

            $existingPendings[] = $entity;
        }

        /*
     * On compare les identifiants métier plutôt que
     * les références des objets PHP.
     */
        $emissionId = $emission->getId();

        $isSameEmission = static function (
            ?Emission $candidate
        ) use ($emission, $emissionId): bool {
            if ($candidate === $emission) {
                return true;
            }

            return null !== $emissionId
                && $candidate?->getId() === $emissionId;
        };

        /*
     * Une exclusion volontaire reste prioritaire pour
     * le couple (émission, groupe).
     */
        foreach ($existingPendings as $pending) {
            if (!$isSameEmission($pending->getEmission())) {
                continue;
            }

            if ($pending->isDismissed()) {
                return null;
            }
        }

        /*
     * Réutiliser le Pending actif, qu'il soit déjà en base
     * ou simplement en attente d'insertion.
     */
        foreach ($existingPendings as $pending) {
            if (!$isSameEmission($pending->getEmission())) {
                continue;
            }

            if ($pending->isPending()) {
                return $pending;
            }
        }

        return $this->createForGroup(
            $emission,
            $assignmentGroupKey
        );
    }
}
