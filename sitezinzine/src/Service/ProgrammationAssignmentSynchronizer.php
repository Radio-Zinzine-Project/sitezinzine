<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\PendingRebroadcastRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ProgrammationAssignmentSynchronizer
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly PendingRebroadcastRepository $pendingRepository,
        private readonly ProgrammationGridBuilder $gridBuilder,
        private readonly PendingRebroadcastService $pendingRebroadcastService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Synchronise les groupes existants d'une règle après une modification
     * structurelle de ses slots.
     *
     * Cette méthode constitue l'entrée autonome historique du service :
     * elle applique la synchronisation puis flush l'UnitOfWork.
     */
    public function synchronizeRule(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array {
        $result = $this->synchronizeRuleInCurrentUnitOfWork(
            $rule,
            $now
        );

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Synchronise les groupes existants d'une règle dans l'UnitOfWork courante.
     *
     * IMPORTANT :
     *
     * Cette méthode n'effectue aucun flush.
     *
     * Elle est destinée à être utilisée par un service d'orchestration qui
     * englobe plusieurs opérations métier dans une transaction unique.
     */
    public function synchronizeRuleInCurrentUnitOfWork(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array {
        return $this->doSynchronizeRule(
            $rule,
            $now
        );
    }

    /**
     * Implémentation unique de la synchronisation structurelle.
     *
     * Aucun flush ne doit être effectué ici.
     */
    private function doSynchronizeRule(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array {
        $ruleId = $rule->getId();

        if (null === $ruleId) {
            throw new \LogicException(
                'La règle doit être persistée avant de pouvoir synchroniser ses affectations.'
            );
        }

        $now ??= new \DateTimeImmutable();

        $drafts = $this->draftRepository
            ->findRegularGroupsByRule($ruleId);

        $groups = $this->groupDraftsByAssignmentGroupKey(
            $drafts
        );

        $result = [
            'processedGroups' => 0,
            'historicalGroups' => 0,
            'invalidatedGroups' => 0,
            'movedRegularDrafts' => 0,
            'createdRegularDrafts' => 0,
            'removedRegularDrafts' => 0,
            'removedManualRebroadcasts' => 0,
            'createdPendingRebroadcasts' => 0,
            'unpublishedDiffusions' => 0,
        ];

        foreach ($groups as $groupKey => $groupDrafts) {
            ++$result['processedGroups'];

            $groupResult = $this->synchronizeGroup(
                $rule,
                $groupKey,
                $groupDrafts,
                $now
            );

            foreach ($groupResult as $key => $value) {
                if (!array_key_exists($key, $result)) {
                    continue;
                }

                $result[$key] += $value;
            }
        }

        return $result;
    }

    /**
     * Invalide explicitement tous les groupes futurs d'une règle.
     *
     * Cette méthode constitue l'entrée autonome historique du service :
     * elle applique l'invalidation puis flush l'UnitOfWork.
     *
     * Les groupes dont la première Diffusion publiée appartient déjà au passé
     * restent entièrement gelés.
     */
    public function invalidateRule(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array {
        $result = $this->invalidateRuleInCurrentUnitOfWork(
            $rule,
            $now
        );

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Invalide explicitement une règle dans l'UnitOfWork courante.
     *
     * IMPORTANT :
     *
     * Cette méthode n'effectue aucun flush.
     *
     * Elle est destinée à être appelée par un service d'orchestration qui
     * possède déjà la responsabilité de la transaction et du flush final.
     */
    public function invalidateRuleInCurrentUnitOfWork(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array {
        return $this->doInvalidateRule(
            $rule,
            $now
        );
    }

    /**
     * Implémentation unique de l'invalidation explicite.
     *
     * Aucun flush ne doit être effectué ici.
     */
    private function doInvalidateRule(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array {
        $ruleId = $rule->getId();

        if (null === $ruleId) {
            throw new \LogicException(
                'La règle doit être persistée avant de pouvoir invalider ses affectations.'
            );
        }

        $now ??= new \DateTimeImmutable();

        $drafts = $this->draftRepository
            ->findRegularGroupsByRule($ruleId);

        $groups = $this->groupDraftsByAssignmentGroupKey(
            $drafts
        );

        $result = [
            'processedGroups' => 0,
            'historicalGroups' => 0,
            'invalidatedGroups' => 0,
            'movedRegularDrafts' => 0,
            'createdRegularDrafts' => 0,
            'removedRegularDrafts' => 0,
            'removedManualRebroadcasts' => 0,
            'createdPendingRebroadcasts' => 0,

            /*
         * Conservé temporairement pour compatibilité.
         *
         * Toute dévalidation appartient désormais au service
         * d'orchestration.
         */
            'unpublishedDiffusions' => 0,
        ];

        foreach ($groups as $groupKey => $groupDrafts) {
            ++$result['processedGroups'];

            $diffusions = $this->diffusionRepository
                ->findByAssignmentGroupKey(
                    $groupKey
                );

            /*
         * Le synchronizer conserve la responsabilité du gel historique :
         * un groupe déjà commencé ne doit subir aucune mutation.
         */
            if ($this->isHistoricalGroup(
                $diffusions,
                $now
            )) {
                ++$result['historicalGroups'];

                continue;
            }

            /*
         * À ce stade, ProgrammationRuleMutationService a déjà dévalidé
         * les éventuelles semaines publiées du groupe.
         *
         * Le synchronizer ne supprime donc que les données de travail.
         */
            $groupResult = $this->invalidateWholeGroup(
                $groupKey,
                $groupDrafts
            );

            ++$result['invalidatedGroups'];

            $result['removedRegularDrafts'] +=
                $groupResult['removedRegularDrafts'];

            $result['removedManualRebroadcasts'] +=
                $groupResult['removedManualRebroadcasts'];

            /*
         * Cette valeur reste actuellement toujours à zéro.
         */
            $result['unpublishedDiffusions'] +=
                $groupResult['unpublishedDiffusions'];
        }

        return $result;
    }

    private function synchronizeGroup(
        ProgrammationRule $rule,
        string $groupKey,
        array $groupDrafts,
        \DateTimeImmutable $now
    ): array {
        $result = [
            'historicalGroups' => 0,
            'invalidatedGroups' => 0,
            'movedRegularDrafts' => 0,
            'createdRegularDrafts' => 0,
            'removedRegularDrafts' => 0,
            'removedManualRebroadcasts' => 0,
            'createdPendingRebroadcasts' => 0,

            /*
         * Conservé temporairement pour compatibilité avec les consommateurs
         * existants du résultat.
         *
         * Le synchronizer ne dépublie désormais plus aucune Diffusion.
         */
            'unpublishedDiffusions' => 0,
        ];

        $firstDraft = $this->findFirstRegularDraft($groupDrafts);

        if (!$firstDraft instanceof DiffusionDraft) {
            throw new \LogicException(sprintf(
                'Le groupe régulier "%s" ne possède aucun Draft de rang 1.',
                $groupKey
            ));
        }

        $origin = $firstDraft->getHoraireDiffusion();

        if (!$origin instanceof \DateTimeImmutable) {
            throw new \LogicException(sprintf(
                'Le groupe régulier "%s" ne possède pas d’origine valide.',
                $groupKey
            ));
        }

        $diffusions = $this->diffusionRepository
            ->findByAssignmentGroupKey($groupKey);

        /*
     * Dès qu'un groupe publié a commencé, il est historique.
     *
     * On ne touche alors :
     * - ni aux Diffusion publiées ;
     * - ni aux Drafts du groupe ;
     * - ni au Parc à rediff.
     */
        if ($this->isHistoricalGroup($diffusions, $now)) {
            $result['historicalGroups'] = 1;

            return $result;
        }

        /*
     * IMPORTANT :
     *
     * L'état actif/inactif de la règle n'est volontairement pas utilisé ici.
     *
     * Une règle peut avoir été désactivée automatiquement parce qu'une
     * modification de créneau a créé un conflit structurel. Dans ce cas,
     * la nouvelle structure doit être synchronisée sans provoquer la
     * disparition complète de ses affectations.
     *
     * Une désactivation manuelle ou une suppression volontaire de la règle
     * passe par ProgrammationRuleMutationService::invalidateRule().
     */
        [$weekStart, $weekEnd] = $this->resolveRadioWeekBounds(
            $origin
        );

        $segments = $this->gridBuilder
            ->buildRuleOccurrencesForWeek(
                $rule,
                $weekStart,
                $weekEnd
            );

        $expectedSegments = $this->findSegmentsForOrigin(
            $segments,
            $origin
        );

        /*
     * Si la nouvelle règle ne produit plus cette première occurrence,
     * l'ancien groupe n'existe plus.
     *
     * On ne migre jamais arbitrairement l'émission vers une nouvelle
     * occurrence de rang 1.
     *
     * IMPORTANT :
     *
     * le synchronizer ne dépublie aucune Diffusion.
     *
     * Si le groupe possède des Diffusion publiées futures,
     * ProgrammationRuleMutationService doit avoir dévalidé leurs semaines
     * avant d'arriver ici.
     */
        if (!$this->containsFirstBroadcast($expectedSegments, $origin)) {
            $invalidated = $this->invalidateWholeGroup(
                $groupKey,
                $groupDrafts
            );

            $result['invalidatedGroups'] = 1;

            foreach ($invalidated as $key => $value) {
                $result[$key] += $value;
            }

            return $result;
        }

        /*
     * Le groupe existe toujours.
     *
     * On synchronise les passages réguliers par broadcastRank,
     * jamais selon leur ordre chronologique.
     */
        $expectedByRank = $this->indexExpectedSegmentsByRank(
            $expectedSegments
        );

        $regularDraftsByRank = $this->indexRegularDraftsByRank(
            $groupDrafts
        );

        $emission = $firstDraft->getEmission();

        if (!$emission instanceof Emission) {
            throw new \LogicException(sprintf(
                'Le groupe "%s" ne possède pas d’émission valide.',
                $groupKey
            ));
        }

        $slotsById = $this->indexRuleSlotsById($rule);

        /*
     * On valide l'ordre structurel des rangs AVANT d'appliquer
     * les mutations.
     *
     * Exemple :
     *
     * rang 1 : mardi
     * rang 2 : vendredi
     * rang 3 : jeudi
     *
     * Le rang 3 est invalide. Il ne devient jamais rang 2.
     */
        $validExpectedRanks = $this->resolveChronologicallyValidRanks(
            $expectedByRank
        );

        /*
     * Synchronisation des rangs réguliers existants.
     */
        foreach ($regularDraftsByRank as $rank => $regularDraft) {
            if (1 === $rank) {
                /*
             * Le rang 1 est déjà garanti par la présence de l'origine.
             *
             * On le resynchronise uniquement si une donnée a réellement
             * changé, par exemple sa durée ou son slot.
             */
                $segment = $expectedByRank[$rank] ?? null;

                if (\is_array($segment)) {
                    /*
                 * On résout le slot attendu AVANT la comparaison.
                 *
                 * Cela permet de comparer directement l'identité de l'objet
                 * Doctrine, y compris lorsqu'un nouveau slot n'a pas encore
                 * reçu d'ID SQL.
                 */
                    $expectedSlot = $this->resolveSlotFromSegment(
                        $segment,
                        $slotsById,
                        $rule
                    );

                    if ($this->regularDraftNeedsUpdate(
                        $regularDraft,
                        $segment,
                        $expectedSlot
                    )) {
                        $this->updateRegularDraftFromSegment(
                            $regularDraft,
                            $segment,
                            $slotsById,
                            $rule
                        );

                        ++$result['movedRegularDrafts'];
                    }
                }

                continue;
            }

            $segment = $expectedByRank[$rank] ?? null;

            /*
         * Le slot a disparu ou le rang est devenu chronologiquement
         * incohérent avec un rang précédent.
         *
         * La semaine publiée éventuelle a déjà été dévalidée par
         * ProgrammationRuleMutationService.
         *
         * Le synchronizer s'occupe uniquement du Draft et du Parc.
         */
            if (
                !\is_array($segment)
                || !isset($validExpectedRanks[$rank])
            ) {
                $this->pendingRebroadcastService->createForGroup(
                    $emission,
                    $groupKey
                );

                ++$result['createdPendingRebroadcasts'];
                ++$result['removedRegularDrafts'];

                $this->entityManager->remove($regularDraft);

                continue;
            }

            /*
         * Même principe pour les rediffusions régulières :
         *
         * on résout le slot réellement attendu afin que le remplacement
         * d'un slot par un nouvel objet de même rang, même horaire et même
         * durée soit malgré tout détecté.
         */
            $expectedSlot = $this->resolveSlotFromSegment(
                $segment,
                $slotsById,
                $rule
            );

            if ($this->regularDraftNeedsUpdate(
                $regularDraft,
                $segment,
                $expectedSlot
            )) {
                $this->updateRegularDraftFromSegment(
                    $regularDraft,
                    $segment,
                    $slotsById,
                    $rule
                );

                ++$result['movedRegularDrafts'];
            }
        }

        /*
     * Ajout des nouveaux slots réguliers.
     *
     * Un nouveau rang reçoit automatiquement la même émission
     * que le groupe existant, à condition que son ordre soit cohérent.
     */
        foreach ($expectedByRank as $rank => $segment) {
            if (isset($regularDraftsByRank[$rank])) {
                continue;
            }

            if (!isset($validExpectedRanks[$rank])) {
                continue;
            }

            $slot = $this->resolveSlotFromSegment(
                $segment,
                $slotsById,
                $rule
            );

            $startsAt = $this->segmentDate(
                $segment,
                'startsAt'
            );

            $duration = $this->segmentDuration($segment);

            $newDraft = (new DiffusionDraft())
                ->setEmission($emission)
                ->setSlot($slot)
                ->setSchedule($startsAt, $duration)
                ->setNombreDiffusion($rank)
                ->setDraftType(DiffusionDraft::TYPE_REGULAR)
                ->setAssignmentGroupKey($groupKey)
                ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT);

            $this->entityManager->persist($newDraft);

            ++$result['createdRegularDrafts'];
        }

        /*
     * Une rediffusion manuelle appartenant à un groupe régulier
     * doit rester après toutes les diffusions régulières valides.
     *
     * Si une modification de règle la fait passer avant ou pendant
     * les régulières, elle retourne dans le Parc.
     *
     * Là encore, une éventuelle semaine publiée a déjà été dévalidée
     * par ProgrammationRuleMutationService.
     */
        $lastRegularEnd = $this->findLastValidRegularEnd(
            $expectedByRank,
            $validExpectedRanks
        );

        if ($lastRegularEnd instanceof \DateTimeImmutable) {
            foreach ($groupDrafts as $draft) {
                if (!$draft instanceof DiffusionDraft) {
                    continue;
                }

                if (
                    DiffusionDraft::TYPE_MANUAL_REBROADCAST
                    !== $draft->getDraftType()
                ) {
                    continue;
                }

                $startsAt = $draft->getHoraireDiffusion();

                if (
                    !$startsAt instanceof \DateTimeImmutable
                    || $startsAt >= $lastRegularEnd
                ) {
                    continue;
                }

                $this->pendingRebroadcastService->createForGroup(
                    $emission,
                    $groupKey
                );

                ++$result['createdPendingRebroadcasts'];
                ++$result['removedManualRebroadcasts'];

                $this->entityManager->remove($draft);
            }
        }

        return $result;
    }

    /**
     * Invalide complètement les données de travail d'un groupe futur.
     *
     * Lorsque la première diffusion régulière disparaît :
     *
     * - tous les Drafts du groupe disparaissent ;
     * - tous les Pending du groupe disparaissent ;
     * - aucun nouveau Pending n'est créé puisque le groupe n'existe plus ;
     * - aucune Diffusion n'est modifiée ici.
     *
     * La dévalidation d'éventuelles semaines publiées appartient
     * exclusivement à ProgrammationRuleMutationService et
     * GridUnpublicationService.
     *
     * @param DiffusionDraft[] $groupDrafts
     *
     * @return array{
     *     movedRegularDrafts:int,
     *     createdRegularDrafts:int,
     *     removedRegularDrafts:int,
     *     removedManualRebroadcasts:int,
     *     createdPendingRebroadcasts:int,
     *     unpublishedDiffusions:int
     * }
     */
    private function invalidateWholeGroup(
        string $groupKey,
        array $groupDrafts
    ): array {
        $result = [
            'movedRegularDrafts' => 0,
            'createdRegularDrafts' => 0,
            'removedRegularDrafts' => 0,
            'removedManualRebroadcasts' => 0,
            'createdPendingRebroadcasts' => 0,

            /*
         * Conservé temporairement pour compatibilité avec le format
         * historique du résultat.
         *
         * Le synchronizer ne dépublie désormais plus de Diffusion.
         */
            'unpublishedDiffusions' => 0,
        ];

        foreach ($groupDrafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            if (
                DiffusionDraft::TYPE_REGULAR
                === $draft->getDraftType()
            ) {
                ++$result['removedRegularDrafts'];
            } elseif (
                DiffusionDraft::TYPE_MANUAL_REBROADCAST
                === $draft->getDraftType()
            ) {
                ++$result['removedManualRebroadcasts'];
            }

            $this->entityManager->remove($draft);
        }

        /*
     * Un Pending appartient au groupe régulier.
     *
     * Si le groupe lui-même disparaît, ses Pending n'ont plus
     * aucune origine valide et doivent donc eux aussi disparaître.
     */
        $pendingRebroadcasts = $this->pendingRepository
            ->findByAssignmentGroupKey(
                $groupKey
            );

        foreach ($pendingRebroadcasts as $pendingRebroadcast) {
            $this->entityManager->remove(
                $pendingRebroadcast
            );
        }

        return $result;
    }

    /**
     * @param Diffusion[] $diffusions
     */
    private function isHistoricalGroup(
        array $diffusions,
        \DateTimeImmutable $now
    ): bool {
        $firstPublishedStartsAt = null;

        foreach ($diffusions as $diffusion) {
            if (!$diffusion instanceof Diffusion) {
                continue;
            }

            if (
                Diffusion::STATUS_PUBLISHED
                !== $diffusion->getPublicationStatus()
            ) {
                continue;
            }

            $startsAt = $diffusion->getHoraireDiffusion();

            if (!$startsAt instanceof \DateTimeInterface) {
                continue;
            }

            $immutableStartsAt = \DateTimeImmutable::createFromInterface(
                $startsAt
            );

            if (
                null === $firstPublishedStartsAt
                || $immutableStartsAt < $firstPublishedStartsAt
            ) {
                $firstPublishedStartsAt = $immutableStartsAt;
            }
        }

        return null !== $firstPublishedStartsAt
            && $firstPublishedStartsAt < $now;
    }

    /**
     * @param DiffusionDraft[] $drafts
     *
     * @return array<string, DiffusionDraft[]>
     */
    private function groupDraftsByAssignmentGroupKey(
        array $drafts
    ): array {
        $groups = [];

        foreach ($drafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            $groupKey = $draft->getAssignmentGroupKey();

            if (!\is_string($groupKey) || '' === trim($groupKey)) {
                continue;
            }

            $groups[$groupKey][] = $draft;
        }

        return $groups;
    }

    /**
     * @param DiffusionDraft[] $groupDrafts
     */
    private function findFirstRegularDraft(
        array $groupDrafts
    ): ?DiffusionDraft {
        foreach ($groupDrafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            if (
                DiffusionDraft::TYPE_REGULAR === $draft->getDraftType()
                && 1 === $draft->getNombreDiffusion()
            ) {
                return $draft;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     *
     * @return array<int, array<string, mixed>>
     */
    private function findSegmentsForOrigin(
        array $segments,
        \DateTimeImmutable $origin
    ): array {
        $originKey = $origin->format('Y-m-d H:i:s');

        return array_values(array_filter(
            $segments,
            static fn(array $segment): bool => ($segment['firstBroadcastStartsAt'] ?? null)
                === $originKey
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     */
    private function containsFirstBroadcast(
        array $segments,
        \DateTimeImmutable $origin
    ): bool {
        $originKey = $origin->format('Y-m-d H:i:s');

        foreach ($segments as $segment) {
            if (
                1 === (int) ($segment['broadcastRank'] ?? 0)
                && ($segment['startsAt'] ?? null) === $originKey
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     *
     * @return array<int, array<string, mixed>>
     */
    private function indexExpectedSegmentsByRank(
        array $segments
    ): array {
        $result = [];

        foreach ($segments as $segment) {
            $rank = (int) ($segment['broadcastRank'] ?? 0);

            if ($rank < 1) {
                continue;
            }

            if (isset($result[$rank])) {
                throw new \LogicException(sprintf(
                    'Plusieurs occurrences régulières utilisent le rang %d pour le même groupe.',
                    $rank
                ));
            }

            $result[$rank] = $segment;
        }

        ksort($result);

        return $result;
    }

    /**
     * @param DiffusionDraft[] $drafts
     *
     * @return array<int, DiffusionDraft>
     */
    private function indexRegularDraftsByRank(
        array $drafts
    ): array {
        $result = [];

        foreach ($drafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            if (
                DiffusionDraft::TYPE_REGULAR
                !== $draft->getDraftType()
            ) {
                continue;
            }

            $rank = $draft->getNombreDiffusion();

            if (null === $rank || $rank < 1) {
                throw new \LogicException(
                    'Un Draft régulier possède un rang invalide.'
                );
            }

            if (isset($result[$rank])) {
                throw new \LogicException(sprintf(
                    'Plusieurs Drafts réguliers utilisent le rang %d dans le même groupe.',
                    $rank
                ));
            }

            $result[$rank] = $draft;
        }

        ksort($result);

        return $result;
    }

    /**
     * @return array<int, ProgrammationRuleSlot>
     */
    private function indexRuleSlotsById(
        ProgrammationRule $rule
    ): array {
        $result = [];

        foreach ($rule->getSlots() as $slot) {
            if (!$slot instanceof ProgrammationRuleSlot) {
                continue;
            }

            if (!$slot->isActive() || $slot->isDeleted()) {
                continue;
            }

            $slotId = $slot->getId();

            if (null === $slotId) {
                continue;
            }

            $result[$slotId] = $slot;
        }

        return $result;
    }

    /**
     * Recherche un slot actif de la règle à partir de son rang structurel.
     *
     * Cette résolution est utilisée uniquement lorsqu'un segment provient
     * d'un nouveau ProgrammationRuleSlot qui n'a pas encore reçu d'ID Doctrine.
     *
     * Un broadcastRank doit identifier un unique slot actif dans la structure
     * attendue de la règle. En cas d'ambiguïté, on refuse de choisir
     * arbitrairement.
     */
    private function findRuleSlotByBroadcastRank(
        ProgrammationRule $rule,
        int $broadcastRank
    ): ProgrammationRuleSlot {
        if ($broadcastRank < 1) {
            throw new \LogicException(
                'Le segment possède un rang de diffusion invalide.'
            );
        }

        $matchingSlot = null;

        foreach ($rule->getSlots() as $slot) {
            if (!$slot instanceof ProgrammationRuleSlot) {
                continue;
            }

            if (!$slot->isActive() || $slot->isDeleted()) {
                continue;
            }

            if ($slot->getBroadcastRank() !== $broadcastRank) {
                continue;
            }

            if ($matchingSlot instanceof ProgrammationRuleSlot) {
                throw new \LogicException(sprintf(
                    'Plusieurs slots actifs utilisent le rang %d dans la même règle.',
                    $broadcastRank
                ));
            }

            $matchingSlot = $slot;
        }

        if (!$matchingSlot instanceof ProgrammationRuleSlot) {
            throw new \LogicException(sprintf(
                'Aucun slot actif de rang %d n’existe dans la règle.',
                $broadcastRank
            ));
        }

        return $matchingSlot;
    }

    /**
     * @param array<int, array<string, mixed>> $expectedByRank
     *
     * @return array<int, true>
     */
    private function resolveChronologicallyValidRanks(
        array $expectedByRank
    ): array {
        $validRanks = [];
        $previousStartsAt = null;

        foreach ($expectedByRank as $rank => $segment) {
            $startsAt = $this->segmentDate(
                $segment,
                'startsAt'
            );

            if (
                null !== $previousStartsAt
                && $startsAt <= $previousStartsAt
            ) {
                /*
                 * Le rang courant devient invalide mais ne modifie pas
                 * la référence chronologique du dernier rang valide.
                 *
                 * Exemple :
                 * rang 2 vendredi
                 * rang 3 jeudi   -> invalide
                 * rang 4 samedi  -> peut rester valide après rang 2
                 */
                continue;
            }

            $validRanks[$rank] = true;
            $previousStartsAt = $startsAt;
        }

        return $validRanks;
    }

    /**
     * Détermine si un Draft régulier doit réellement être modifié.
     *
     * @param array<string, mixed> $segment
     */
    private function regularDraftNeedsUpdate(
        DiffusionDraft $draft,
        array $segment,
        ProgrammationRuleSlot $expectedSlot
    ): bool {
        $expectedRank = (int) ($segment['broadcastRank'] ?? 0);
        $expectedDuration = $this->segmentDuration($segment);
        $expectedStartsAt = $this->segmentDate(
            $segment,
            'startsAt'
        );

        $currentStartsAt = $draft->getHoraireDiffusion();

        if (
            !$currentStartsAt instanceof \DateTimeImmutable
            || $currentStartsAt->format('Y-m-d H:i:s')
            !== $expectedStartsAt->format('Y-m-d H:i:s')
        ) {
            return true;
        }

        /*
     * L'identité du slot ne doit pas dépendre uniquement de son ID SQL.
     *
     * Un slot nouvellement créé peut légitimement avoir un ID null
     * jusqu'au flush final. L'objet Doctrine lui-même représente alors
     * l'identité attendue dans l'UnitOfWork courante.
     */
        if ($draft->getSlot() !== $expectedSlot) {
            return true;
        }

        if ($draft->getNombreDiffusion() !== $expectedRank) {
            return true;
        }

        return $draft->getDurationMinutes() !== $expectedDuration;
    }

    /**
     * @param array<string, mixed>               $segment
     * @param array<int, ProgrammationRuleSlot> $slotsById
     */
    private function updateRegularDraftFromSegment(
        DiffusionDraft $draft,
        array $segment,
        array $slotsById,
        ProgrammationRule $rule
    ): void {
        $slot = $this->resolveSlotFromSegment(
            $segment,
            $slotsById,
            $rule
        );

        $startsAt = $this->segmentDate(
            $segment,
            'startsAt'
        );

        $duration = $this->segmentDuration($segment);

        $draft
            ->setSlot($slot)
            ->setSchedule($startsAt, $duration)
            ->setNombreDiffusion(
                (int) $segment['broadcastRank']
            );
    }

    /**
     * @param array<string, mixed>               $segment
     * @param array<int, ProgrammationRuleSlot> $slotsById
     */
    private function resolveSlotFromSegment(
        array $segment,
        array $slotsById,
        ProgrammationRule $rule
    ): ProgrammationRuleSlot {
        $slotId = $segment['slotId'] ?? null;

        /*
     * Cas normal : le slot existe déjà en base.
     */
        if (null !== $slotId) {
            $slotId = (int) $slotId;
            $slot = $slotsById[$slotId] ?? null;

            if (!$slot instanceof ProgrammationRuleSlot) {
                throw new \LogicException(sprintf(
                    'Le slot #%d attendu par la programmation est introuvable.',
                    $slotId
                ));
            }

            return $slot;
        }

        /*
     * Cas d'une création :
     *
     * le ProgrammationRuleSlot fait déjà partie de la structure de la règle
     * mais Doctrine ne lui a pas encore attribué d'ID.
     *
     * On le résout donc par son identité structurelle temporaire :
     * son broadcastRank.
     */
        $broadcastRank = (int) (
            $segment['broadcastRank'] ?? 0
        );

        return $this->findRuleSlotByBroadcastRank(
            $rule,
            $broadcastRank
        );
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function segmentDate(
        array $segment,
        string $key
    ): \DateTimeImmutable {
        $value = $segment[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new \LogicException(sprintf(
                'Le segment ne possède pas de valeur valide pour "%s".',
                $key
            ));
        }

        return new \DateTimeImmutable($value);
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function segmentDuration(array $segment): int
    {
        $duration = (int) ($segment['duration'] ?? 0);

        if ($duration < 1) {
            throw new \LogicException(
                'Le segment possède une durée invalide.'
            );
        }

        return $duration;
    }

    /**
     * @param array<int, array<string, mixed>> $expectedByRank
     * @param array<int, true>                 $validExpectedRanks
     */
    private function findLastValidRegularEnd(
        array $expectedByRank,
        array $validExpectedRanks
    ): ?\DateTimeImmutable {
        $lastEnd = null;

        foreach ($expectedByRank as $rank => $segment) {
            if (!isset($validExpectedRanks[$rank])) {
                continue;
            }

            $endsAt = $this->segmentDate(
                $segment,
                'endsAt'
            );

            if (null === $lastEnd || $endsAt > $lastEnd) {
                $lastEnd = $endsAt;
            }
        }

        return $lastEnd;
    }

    /**
     * @return array{
     *     0:\DateTimeImmutable,
     *     1:\DateTimeImmutable
     * }
     */
    private function resolveRadioWeekBounds(
        \DateTimeImmutable $date
    ): array {
        $date = $date->setTime(0, 0, 0);

        $dayOfWeek = (int) $date->format('N');

        $daysSinceTuesday = match ($dayOfWeek) {
            2 => 0,
            3 => 1,
            4 => 2,
            5 => 3,
            6 => 4,
            7 => 5,
            1 => 6,
            default => 0,
        };

        $weekStart = 0 === $daysSinceTuesday
            ? $date
            : $date->modify(
                sprintf('-%d days', $daysSinceTuesday)
            );

        return [
            $weekStart,
            $weekStart->modify('+7 days'),
        ];
    }
}
