<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\ProgrammationRule;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ProgrammationRuleMutationService implements ProgrammationRuleMutationInterface
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly ProgrammationGridBuilder $gridBuilder,
        private readonly GridUnpublicationService $unpublicationService,
        private readonly ProgrammationAssignmentSynchronizer $assignmentSynchronizer,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Applique une modification structurelle de règle.
     *
     * Ordre impératif :
     *
     * 1. analyser les Drafts existants avant toute mutation ;
     * 2. déterminer les semaines publiées impactées ;
     * 3. vérifier que toutes ces semaines peuvent être dévalidées ;
     * 4. ouvrir une transaction unique ;
     * 5. dévalider toutes les semaines concernées ;
     * 6. synchroniser les Drafts avec la nouvelle structure ;
     * 7. effectuer un unique flush ;
     * 8. valider la transaction.
     *
     * Une semaine publiée constitue une unité de validation :
     * si un passage publié futur doit être déplacé ou supprimé, toute sa
     * semaine est dévalidée avant la synchronisation.
     *
     * Les groupes historiques sont ignorés ici comme dans le synchronizer :
     * dès que leur première Diffusion publiée est antérieure à $now,
     * ils sont entièrement gelés.
     *
     * @return array{
     *     unpublishedWeeks: \DateTimeImmutable[],
     *     synchronization: array<string, int>
     * }
     */
    public function synchronizeAfterStructuralChange(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array {
        $ruleId = $rule->getId();

        if (null === $ruleId) {
            throw new \LogicException(
                'La règle doit être persistée avant de pouvoir synchroniser une modification structurelle.'
            );
        }

        $now ??= new \DateTimeImmutable();

        /*
     * IMPORTANT :
     *
     * Cette analyse doit impérativement être faite AVANT toute mutation.
     *
     * Nous avons encore ici :
     * - l'ancien horaire porté par les Drafts ;
     * - la nouvelle structure portée par la règle déjà modifiée.
     */
        $weeksToUnpublish = $this->findPublishedWeeksAffectedByStructuralChange(
            $rule,
            $now
        );

        /*
     * Première passe :
     *
     * on vérifie TOUTES les semaines avant d'en modifier une seule.
     *
     * Cela permet notamment de détecter une ancienne semaine publiée
     * contenant une Diffusion sans Draft lié avant de commencer
     * l'opération métier.
     */
        $unpublishableWeeks = [];

        foreach ($weeksToUnpublish as $weekStart) {
            $preview = $this->unpublicationService->previewWeek(
                $weekStart
            );

            $publishedDiffusionCount = (int) (
                $preview['publishedDiffusionCount'] ?? 0
            );

            /*
         * Une semaine sans Diffusion publiée n'est pas actuellement validée.
         *
         * Elle ne nécessite donc aucune dévalidation.
         */
            if (0 === $publishedDiffusionCount) {
                continue;
            }

            /*
         * Une semaine publiée mais non dévalidable bloque toute
         * la modification structurelle.
         *
         * Aucune mutation n'a encore été effectuée à ce stade.
         */
            if (!($preview['canUnpublish'] ?? false)) {
                throw new \DomainException(
                    'Cette semaine ne peut pas être dévalidée automatiquement.'
                );
            }

            $unpublishableWeeks[] = $weekStart;
        }

        /*
     * Deuxième passe :
     *
     * toutes les vérifications sont terminées.
     *
     * Dévalidation et synchronisation sont maintenant effectuées
     * dans UNE SEULE transaction.
     */
        return $this->entityManager->wrapInTransaction(
            function () use (
                $rule,
                $now,
                $unpublishableWeeks
            ): array {
                $unpublishedWeeks = [];

                foreach ($unpublishableWeeks as $weekStart) {
                    /*
                 * IMPORTANT :
                 *
                 * Pas de transaction interne et pas de flush ici.
                 * La transaction appartient à ce service d'orchestration.
                 */
                    $this->unpublicationService
                        ->applyWeekUnpublication(
                            $weekStart
                        );

                    $unpublishedWeeks[] = $weekStart;
                }

                /*
             * Même principe pour le synchronizer :
             *
             * il modifie l'UnitOfWork courante mais ne flush pas.
             */
                $synchronization = $this->assignmentSynchronizer
                    ->synchronizeRuleInCurrentUnitOfWork(
                        $rule,
                        $now
                    );

                /*
             * Unique flush de toute l'opération :
             *
             * - dévalidation des semaines ;
             * - restauration des Drafts ;
             * - déplacement/suppression/création des Drafts réguliers ;
             * - éventuels changements du Parc.
             */
                $this->entityManager->flush();

                return [
                    'unpublishedWeeks' => $unpublishedWeeks,
                    'synchronization' => $synchronization,
                ];
            }
        );
    }

    /**
     * Invalide explicitement une règle de programmation.
     *
     * Cette méthode est destinée aux décisions structurelles explicites :
     *
     * - désactivation manuelle d'une règle ;
     * - suppression d'une règle.
     *
     * Contrairement à synchronizeAfterStructuralChange(), tous les groupes
     * futurs de la règle doivent disparaître.
     *
     * Ordre impératif :
     *
     * 1. identifier les groupes futurs concernés ;
     * 2. identifier toutes leurs semaines actuellement publiées ;
     * 3. vérifier que toutes ces semaines peuvent être dévalidées ;
     * 4. ouvrir une transaction unique ;
     * 5. dévalider les semaines complètes ;
     * 6. invalider les groupes futurs dans l'UnitOfWork ;
     * 7. effectuer un unique flush ;
     * 8. valider la transaction.
     *
     * Les groupes historiques sont totalement gelés :
     * dès que leur première Diffusion publiée est antérieure à $now,
     * aucune semaine de ce groupe n'est dévalidée et aucun Draft/Pending
     * du groupe n'est supprimé.
     *
     * @return array{
     *     unpublishedWeeks: \DateTimeImmutable[],
     *     invalidation: array<string, int>
     * }
     */
    public function invalidateRule(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array {
        $ruleId = $rule->getId();

        if (null === $ruleId) {
            throw new \LogicException(
                'La règle doit être persistée avant de pouvoir être invalidée.'
            );
        }

        $now ??= new \DateTimeImmutable();

        /*
     * IMPORTANT :
     *
     * l'analyse doit être faite avant toute mutation.
     *
     * À ce stade, les Diffusion sont encore publiées et les Drafts
     * sont encore liés à leur programmation publiée.
     */
        $weeksToUnpublish =
            $this->findPublishedWeeksAffectedByExplicitInvalidation(
                $rule,
                $now
            );

        /*
     * Première passe :
     *
     * toutes les semaines sont vérifiées avant d'en dévalider une seule.
     *
     * Une ancienne semaine contenant par exemple une Diffusion legacy
     * sans Draft lié bloque donc l'intégralité de l'invalidation.
     */
        $unpublishableWeeks = [];

        foreach ($weeksToUnpublish as $weekStart) {
            $preview = $this->unpublicationService->previewWeek(
                $weekStart
            );

            $publishedDiffusionCount = (int) (
                $preview['publishedDiffusionCount'] ?? 0
            );

            /*
         * La semaine a pu cesser d'être publiée entre l'analyse et
         * la prévalidation. Dans ce cas, aucune dévalidation n'est requise.
         */
            if (0 === $publishedDiffusionCount) {
                continue;
            }

            if (!($preview['canUnpublish'] ?? false)) {
                throw new \DomainException(
                    'Cette semaine ne peut pas être dévalidée automatiquement.'
                );
            }

            $unpublishableWeeks[] = $weekStart;
        }

        /*
     * Toutes les vérifications sont terminées.
     *
     * Dévalidation des semaines et invalidation des groupes appartiennent
     * maintenant à une seule transaction.
     */
        return $this->entityManager->wrapInTransaction(
            function () use (
                $rule,
                $now,
                $unpublishableWeeks
            ): array {
                $unpublishedWeeks = [];

                foreach ($unpublishableWeeks as $weekStart) {
                    /*
                 * Pas de transaction interne et pas de flush.
                 *
                 * La transaction appartient au présent service.
                 */
                    $this->unpublicationService
                        ->applyWeekUnpublication(
                            $weekStart
                        );

                    $unpublishedWeeks[] = $weekStart;
                }

                /*
             * Les Diffusion des semaines concernées sont maintenant
             * unpublished et leurs Drafts restaurés dans l'UnitOfWork.
             *
             * Le synchronizer peut donc supprimer les groupes futurs
             * sans être responsable de la dévalidation des semaines.
             */
                $invalidation = $this->assignmentSynchronizer
                    ->invalidateRuleInCurrentUnitOfWork(
                        $rule,
                        $now
                    );

                /*
             * Flush unique :
             *
             * - dévalidation de toutes les semaines ;
             * - restauration des Drafts indépendants ;
             * - suppression des Drafts du groupe invalidé ;
             * - suppression de ses Pending.
             */
                $this->entityManager->flush();

                return [
                    'unpublishedWeeks' => $unpublishedWeeks,
                    'invalidation' => $invalidation,
                ];
            }
        );
    }

    /**
     * Détermine toutes les semaines radio actuellement publiées qui doivent
     * être dévalidées avant l'invalidation explicite d'une règle.
     *
     * Pour chaque groupe régulier :
     *
     * - groupe historique : aucune action ;
     * - groupe futur : toutes les semaines contenant encore une Diffusion
     *   publiée de ce groupe sont concernées.
     *
     * Une même semaine n'est retournée qu'une seule fois, même si plusieurs
     * groupes ou plusieurs Diffusion de la règle s'y trouvent.
     *
     * @return \DateTimeImmutable[]
     */
    private function findPublishedWeeksAffectedByExplicitInvalidation(
        ProgrammationRule $rule,
        \DateTimeImmutable $now
    ): array {
        $ruleId = $rule->getId();

        if (null === $ruleId) {
            throw new \LogicException(
                'La règle doit être persistée avant l’analyse de son invalidation.'
            );
        }

        $drafts = $this->draftRepository
            ->findRegularGroupsByRule(
                $ruleId
            );

        $groups = $this->groupDraftsByAssignmentGroupKey(
            $drafts
        );

        /*
     * Index :
     *
     * Y-m-d => mardi de la semaine radio.
     *
     * Cela évite de dévalider plusieurs fois la même semaine.
     */
        $weeks = [];

        foreach ($groups as $groupKey => $groupDrafts) {
            /*
         * groupDrafts est volontairement conservé dans la boucle :
         * sa présence garantit que nous travaillons bien sur un groupe
         * régulier appartenant à la règle concernée.
         */
            if ([] === $groupDrafts) {
                continue;
            }

            $diffusions = $this->diffusionRepository
                ->findByAssignmentGroupKey(
                    $groupKey
                );

            /*
         * Dès que la première Diffusion publiée du groupe appartient
         * au passé, l'intégralité du groupe devient historique.
         *
         * Ses rediffusions futures restent donc publiées.
         */
            if ($this->isHistoricalGroup(
                $diffusions,
                $now
            )) {
                continue;
            }

            /*
         * Le groupe n'a pas encore commencé.
         *
         * Toutes ses semaines actuellement publiées doivent être
         * dévalidées avant sa suppression.
         */
            $this->addPublishedDiffusionWeeks(
                $weeks,
                $diffusions
            );
        }

        ksort($weeks);

        return array_values($weeks);
    }

    /**
     * Détermine les semaines radio publiées qu'une modification structurelle
     * va réellement affecter.
     *
     * L'analyse est faite groupe par groupe.
     *
     * @return \DateTimeImmutable[]
     */
    private function findPublishedWeeksAffectedByStructuralChange(
        ProgrammationRule $rule,
        \DateTimeImmutable $now
    ): array {
        $ruleId = $rule->getId();

        if (null === $ruleId) {
            throw new \LogicException(
                'La règle doit être persistée avant l’analyse de ses semaines impactées.'
            );
        }

        $drafts = $this->draftRepository
            ->findRegularGroupsByRule($ruleId);

        $groups = $this->groupDraftsByAssignmentGroupKey(
            $drafts
        );

        /*
         * Index :
         *
         * Y-m-d => DateTimeImmutable du mardi de la semaine radio.
         *
         * L'index évite de dévalider plusieurs fois une même semaine lorsque
         * plusieurs groupes ou plusieurs passages sont concernés.
         */
        $weeks = [];

        foreach ($groups as $groupKey => $groupDrafts) {
            $diffusions = $this->diffusionRepository
                ->findByAssignmentGroupKey(
                    $groupKey
                );

            /*
             * Un groupe commencé est historique.
             *
             * Toute sa programmation publiée est gelée, y compris ses
             * rediffusions encore futures.
             */
            if ($this->isHistoricalGroup(
                $diffusions,
                $now
            )) {
                continue;
            }

            $firstDraft = $this->findFirstRegularDraft(
                $groupDrafts
            );

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

            [$originWeekStart, $originWeekEnd] =
                $this->resolveRadioWeekBounds(
                    $origin
                );

            /*
             * Le builder travaille déjà à partir de la nouvelle structure
             * de la règle.
             */
            $segments = $this->gridBuilder
                ->buildRuleOccurrencesForWeek(
                    $rule,
                    $originWeekStart,
                    $originWeekEnd
                );

            $expectedSegments = $this->findSegmentsForOrigin(
                $segments,
                $origin
            );

            /*
             * Si la première occurrence n'existe plus, tout le groupe futur
             * sera invalidé par le synchronizer.
             *
             * Toutes les semaines contenant encore une Diffusion publiée de
             * ce groupe sont donc impactées.
             */
            if (
                !$this->containsFirstBroadcast(
                    $expectedSegments,
                    $origin
                )
            ) {
                $this->addPublishedDiffusionWeeks(
                    $weeks,
                    $diffusions
                );

                continue;
            }

            $expectedByRank = $this->indexExpectedSegmentsByRank(
                $expectedSegments
            );

            $validExpectedRanks =
                $this->resolveChronologicallyValidRanks(
                    $expectedByRank
                );

            $regularDraftsByRank =
                $this->indexRegularDraftsByRank(
                    $groupDrafts
                );

            /*
             * On compare chaque Draft régulier existant à la structure
             * attendue.
             */
            foreach (
                $regularDraftsByRank
                as $rank => $regularDraft
            ) {
                $segment = $expectedByRank[$rank] ?? null;

                /*
                 * Le rang 1 n'est jamais supprimé ici puisque sa présence
                 * vient d'être vérifiée.
                 *
                 * Pour les autres rangs, absence de segment ou incohérence
                 * chronologique signifie suppression du Draft.
                 */
                if (
                    1 !== $rank
                    && (
                        !\is_array($segment)
                        || !isset($validExpectedRanks[$rank])
                    )
                ) {
                    if ($this->isDraftCurrentlyPublished(
                        $regularDraft
                    )) {
                        $this->addWeek(
                            $weeks,
                            $regularDraft->getHoraireDiffusion()
                        );
                    }

                    continue;
                }

                if (!\is_array($segment)) {
                    continue;
                }

                if (
                    !$this->regularDraftNeedsUpdate(
                        $regularDraft,
                        $segment
                    )
                ) {
                    continue;
                }

                /*
                 * Si le Draft actuellement publié doit changer :
                 *
                 * - sa semaine actuelle doit être dévalidée ;
                 * - sa future semaine doit également l'être si elle est déjà
                 *   publiée.
                 */
                if ($this->isDraftCurrentlyPublished(
                    $regularDraft
                )) {
                    $this->addWeek(
                        $weeks,
                        $regularDraft->getHoraireDiffusion()
                    );

                    $newStartsAt = $this->segmentDate(
                        $segment,
                        'startsAt'
                    );

                    $this->addWeekIfPublished(
                        $weeks,
                        $newStartsAt
                    );
                }
            }

            /*
             * Un nouveau rang régulier n'a évidemment encore aucune
             * Diffusion liée.
             *
             * Mais si son nouvel horaire appartient à une semaine déjà
             * validée, cette semaine doit repasser en Draft avant que le
             * synchronizer n'y ajoute le nouveau passage.
             */
            foreach (
                $expectedByRank
                as $rank => $segment
            ) {
                if (
                    isset($regularDraftsByRank[$rank])
                    || !isset($validExpectedRanks[$rank])
                ) {
                    continue;
                }

                $newStartsAt = $this->segmentDate(
                    $segment,
                    'startsAt'
                );

                $this->addWeekIfPublished(
                    $weeks,
                    $newStartsAt
                );
            }

            /*
             * Les rediffusions manuelles appartenant au groupe peuvent elles
             * aussi devenir incohérentes lorsque la dernière régulière est
             * déplacée.
             *
             * Le synchronizer les renverra alors au Parc.
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

                    if (!$this->isDraftCurrentlyPublished(
                        $draft
                    )) {
                        continue;
                    }

                    $this->addWeek(
                        $weeks,
                        $startsAt
                    );
                }
            }
        }

        ksort($weeks);

        return array_values($weeks);
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

            $immutableStartsAt =
                \DateTimeImmutable::createFromInterface(
                    $startsAt
                );

            if (
                null === $firstPublishedStartsAt
                || $immutableStartsAt < $firstPublishedStartsAt
            ) {
                $firstPublishedStartsAt =
                    $immutableStartsAt;
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

            if (
                !\is_string($groupKey)
                || '' === trim($groupKey)
            ) {
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
                DiffusionDraft::TYPE_REGULAR
                === $draft->getDraftType()
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
        $originKey = $origin->format(
            'Y-m-d H:i:s'
        );

        return array_values(
            array_filter(
                $segments,
                static fn(array $segment): bool => ($segment['firstBroadcastStartsAt'] ?? null)
                    === $originKey
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     */
    private function containsFirstBroadcast(
        array $segments,
        \DateTimeImmutable $origin
    ): bool {
        $originKey = $origin->format(
            'Y-m-d H:i:s'
        );

        foreach ($segments as $segment) {
            if (
                1 === (int) (
                    $segment['broadcastRank'] ?? 0
                )
                && ($segment['startsAt'] ?? null)
                === $originKey
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
            $rank = (int) (
                $segment['broadcastRank'] ?? 0
            );

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
     * @param array<int, array<string, mixed>> $expectedByRank
     *
     * @return array<int, true>
     */
    private function resolveChronologicallyValidRanks(
        array $expectedByRank
    ): array {
        $validRanks = [];
        $previousStartsAt = null;

        foreach (
            $expectedByRank
            as $rank => $segment
        ) {
            $startsAt = $this->segmentDate(
                $segment,
                'startsAt'
            );

            if (
                null !== $previousStartsAt
                && $startsAt <= $previousStartsAt
            ) {
                continue;
            }

            $validRanks[$rank] = true;
            $previousStartsAt = $startsAt;
        }

        return $validRanks;
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function regularDraftNeedsUpdate(
        DiffusionDraft $draft,
        array $segment
    ): bool {
        $expectedSlotId = (int) (
            $segment['slotId'] ?? 0
        );

        $expectedRank = (int) (
            $segment['broadcastRank'] ?? 0
        );

        $expectedDuration = $this->segmentDuration(
            $segment
        );

        $expectedStartsAt = $this->segmentDate(
            $segment,
            'startsAt'
        );

        $currentStartsAt =
            $draft->getHoraireDiffusion();

        if (
            !$currentStartsAt instanceof \DateTimeImmutable
            || $currentStartsAt->format('Y-m-d H:i:s')
            !== $expectedStartsAt->format('Y-m-d H:i:s')
        ) {
            return true;
        }

        if (
            $draft->getSlot()?->getId()
            !== $expectedSlotId
        ) {
            return true;
        }

        if (
            $draft->getNombreDiffusion()
            !== $expectedRank
        ) {
            return true;
        }

        return $draft->getDurationMinutes()
            !== $expectedDuration;
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function segmentDate(
        array $segment,
        string $key
    ): \DateTimeImmutable {
        $value = $segment[$key] ?? null;

        if (
            !\is_string($value)
            || '' === trim($value)
        ) {
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
    private function segmentDuration(
        array $segment
    ): int {
        $duration = (int) (
            $segment['duration'] ?? 0
        );

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

        foreach (
            $expectedByRank
            as $rank => $segment
        ) {
            if (!isset($validExpectedRanks[$rank])) {
                continue;
            }

            $endsAt = $this->segmentDate(
                $segment,
                'endsAt'
            );

            if (
                null === $lastEnd
                || $endsAt > $lastEnd
            ) {
                $lastEnd = $endsAt;
            }
        }

        return $lastEnd;
    }

    private function isDraftCurrentlyPublished(
        DiffusionDraft $draft
    ): bool {
        $diffusion = $draft->getPublishedDiffusion();

        return $diffusion instanceof Diffusion
            && Diffusion::STATUS_PUBLISHED
            === $diffusion->getPublicationStatus();
    }

    /**
     * @param array<string, \DateTimeImmutable> $weeks
     * @param Diffusion[]                       $diffusions
     */
    private function addPublishedDiffusionWeeks(
        array &$weeks,
        array $diffusions
    ): void {
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

            $startsAt =
                $diffusion->getHoraireDiffusion();

            if (!$startsAt instanceof \DateTimeInterface) {
                continue;
            }

            $this->addWeek(
                $weeks,
                \DateTimeImmutable::createFromInterface(
                    $startsAt
                )
            );
        }
    }

    /**
     * Ajoute la semaine uniquement si elle contient actuellement au moins
     * une Diffusion publiée.
     *
     * @param array<string, \DateTimeImmutable> $weeks
     */
    private function addWeekIfPublished(
        array &$weeks,
        \DateTimeImmutable $date
    ): void {
        [$weekStart, $weekEnd] =
            $this->resolveRadioWeekBounds(
                $date
            );

        $publishedDiffusions =
            $this->diffusionRepository
            ->findPublishedByWeek(
                $weekStart,
                $weekEnd
            );

        if ([] === $publishedDiffusions) {
            return;
        }

        $weeks[$weekStart->format('Y-m-d')] = $weekStart;
    }

    /**
     * @param array<string, \DateTimeImmutable> $weeks
     */
    private function addWeek(
        array &$weeks,
        ?\DateTimeImmutable $date
    ): void {
        if (!$date instanceof \DateTimeImmutable) {
            return;
        }

        [$weekStart] =
            $this->resolveRadioWeekBounds(
                $date
            );

        $weeks[$weekStart->format('Y-m-d')] = $weekStart;
    }

    /**
     * @return array{
     *     0: \DateTimeImmutable,
     *     1: \DateTimeImmutable
     * }
     */
    private function resolveRadioWeekBounds(
        \DateTimeImmutable $date
    ): array {
        $date = $date->setTime(
            0,
            0,
            0
        );

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

        $weekStart =
            0 === $daysSinceTuesday
            ? $date
            : $date->modify(
                sprintf(
                    '-%d days',
                    $daysSinceTuesday
                )
            );

        return [
            $weekStart,
            $weekStart->modify('+7 days'),
        ];
    }
}
