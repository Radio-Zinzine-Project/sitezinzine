<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Entity\ProgrammationRuleSlot;
use App\Entity\GridSlotArbitration;
use App\Repository\GridSlotArbitrationRepository;
use App\Repository\DiffusionDraftRepository;
use App\Repository\PendingRebroadcastRepository;
use Doctrine\ORM\EntityManagerInterface;

class GridAssignmentService
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly PendingRebroadcastRepository $pendingRebroadcastRepository,
        private readonly GridSlotArbitrationRepository $arbitrationRepository,
        private readonly GridUnpublicationService $unpublicationService,
        private readonly GridPublicationService $publicationService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function assign(
        ProgrammationRuleSlot $slot,
        Emission $emission,
        \DateTimeImmutable $selectedDate
    ): bool {
        $rule = $slot->getRule();

        if ($rule === null) {
            throw new \RuntimeException('Règle introuvable.');
        }

        if ($slot->getBroadcastRank() !== 1) {
            throw new \RuntimeException(
                'L’affectation doit se faire sur la première diffusion. Les rediffusions sont générées automatiquement.'
            );
        }

        $assignmentGroupKey = $this->buildAssignmentGroupKey(
            $rule->getId(),
            $selectedDate
        );

        $occurrences = [];

        foreach ($rule->getSlots() as $relatedSlot) {
            if (!$relatedSlot->isActive() || $relatedSlot->isDeleted()) {
                continue;
            }

            $occurrences[] = [
                'slot' => $relatedSlot,
                'startsAt' => $this->computeStartsAtFromAnchor(
                    $selectedDate,
                    $relatedSlot
                ),
            ];
        }

        return $this->em->wrapInTransaction(
            function () use (
                $occurrences,
                $emission,
                $assignmentGroupKey,
                $rule
            ): bool {
                /*
             * ==========================================================
             * 1. RÉCUPÉRER LES REDIFFUSIONS SUPPLÉMENTAIRES
             * ==========================================================
             */
                $groupDrafts = $this->draftRepository
                    ->findByAssignmentGroupKey($assignmentGroupKey);

                $manualRebroadcasts = [];

                foreach ($groupDrafts as $groupDraft) {
                    if (
                        $groupDraft->getDraftType()
                        !== DiffusionDraft::TYPE_MANUAL_REBROADCAST
                    ) {
                        continue;
                    }

                    $manualRebroadcasts[] = $groupDraft;
                }

                /*
                * ==========================================================
                * 2. IDENTIFIER TOUTES LES SEMAINES CONCERNÉES
                * ==========================================================
                *
                * Les diffusions régulières passent par getAffectedRadioWeeks()
                * afin de conserver la prise en compte des arbitrages.
                *
                * Les rediffusions supplémentaires n'ont pas de slot :
                * leur semaine est calculée directement depuis leur horaire.
                */

                // Semaines des diffusions régulières, arbitrages compris.
                $affectedWeeks = $this->getAffectedRadioWeeks($occurrences);

                // Indexation par date pour éviter les doublons.
                $weeksByDate = [];

                foreach ($affectedWeeks as $weekStart) {
                    $weeksByDate[$weekStart->format('Y-m-d')] = $weekStart;
                }

                // Ajout des semaines des rediffusions supplémentaires.
                foreach ($manualRebroadcasts as $manualDraft) {
                    $startsAt = $manualDraft->getHoraireDiffusion();

                    if (!$startsAt instanceof \DateTimeInterface) {
                        continue;
                    }

                    $weekStart = $this->getRadioWeekStart(
                        \DateTimeImmutable::createFromInterface($startsAt)
                    );

                    $weeksByDate[$weekStart->format('Y-m-d')] = $weekStart;
                }

                // Ordre chronologique pour la dépublication/republication.
                ksort($weeksByDate);

                $affectedWeeks = array_values($weeksByDate);

                /*
             * ==========================================================
             * 3. IDENTIFIER LES SEMAINES DÉJÀ PUBLIÉES
             * ==========================================================
             */
                $publishedWeeks = [];

                foreach ($affectedWeeks as $weekStart) {
                    $preview = $this->unpublicationService
                        ->previewWeek($weekStart);

                    if ($preview['publishedDiffusionCount'] === 0) {
                        continue;
                    }

                    if (!$preview['canUnpublish']) {
                        throw new \DomainException(sprintf(
                            'Impossible de modifier l’affectation : la semaine du %s ne peut pas être dévalidée automatiquement.',
                            $weekStart->format('d/m/Y')
                        ));
                    }

                    $publishedWeeks[] = $weekStart;
                }

                /*
             * ==========================================================
             * 4. DÉVALIDER LES SEMAINES PUBLIÉES
             * ==========================================================
             */
                foreach ($publishedWeeks as $weekStart) {
                    $this->unpublicationService
                        ->applyWeekUnpublication($weekStart);
                }

                /*
             * ==========================================================
             * 5. METTRE À JOUR R1/R2/R3
             * ==========================================================
             */
                foreach ($occurrences as $occurrence) {
                    $this->upsertDraft(
                        $occurrence['slot'],
                        $emission,
                        $occurrence['startsAt'],
                        $assignmentGroupKey
                    );
                }

                /*
             * ==========================================================
             * 6. PROPAGER B AUX REDIFFUSIONS SUPPLÉMENTAIRES
             * ==========================================================
             *
             * On conserve :
             * - l'identifiant du Draft ;
             * - son horaire ;
             * - sa durée ;
             * - son rang ;
             * - son groupe ;
             * - son éventuel lien avec une Diffusion publiée.
             */
                foreach ($manualRebroadcasts as $manualDraft) {
                    $manualDraft->setEmission($emission);
                }


                /*
                 * ==========================================================
                 * 7. SYNCHRONISER LES PENDING
                 * ==========================================================
                 *
                 * Seule l'option de la règle autorise une proposition
                 * supplémentaire, y compris pour une autre catégorie.
                 *
                 * Une exclusion dismissed appartient à une émission.
                 * Elle ne doit jamais être transférée ou annulée
                 * automatiquement.
                 */

                $needsAdditionalRebroadcast =
                    PendingRebroadcastService::regularRuleAllowsAdditionalRebroadcast($rule);

                $pendingRebroadcasts = $this->pendingRebroadcastRepository
                    ->findBy([
                        'assignmentGroupKey' => $assignmentGroupKey,
                    ]);

                /*
                 * Identifier d'abord si l'émission affectée
                 * possède déjà une exclusion volontaire.
                 *
                 * Cette vérification doit précéder toute
                 * réaffectation des Pending actifs.
                 */
                $hasDismissedForCurrentEmission = false;

                foreach ($pendingRebroadcasts as $pending) {
                    if (
                        $pending->isDismissed()
                        && $pending->getEmission()?->getId()
                        === $emission->getId()
                    ) {
                        $hasDismissedForCurrentEmission = true;
                        break;
                    }
                }

                if (
                    !$needsAdditionalRebroadcast
                    || $hasDismissedForCurrentEmission
                ) {
                    /*
                     * L'émission n'est pas éligible, ou elle
                     * possède déjà une exclusion volontaire.
                     *
                     * Supprimer uniquement les Pending actifs
                     * du groupe.
                     *
                     * Tous les dismissed restent intacts,
                     * avec leur émission d'origine.
                     */
                    foreach ($pendingRebroadcasts as $pending) {
                        if ($pending->isPending()) {
                            $this->em->remove($pending);
                        }
                    }
                } else {
                    $hasActivePending = false;

                    foreach ($pendingRebroadcasts as $pending) {
                        /*
                         * Les exclusions historiques ne sont
                         * jamais transférées.
                         */
                        if (!$pending->isPending()) {
                            continue;
                        }

                        /*
                         * Les Pending actifs suivent
                         * l'émission nouvellement affectée.
                         *
                         * On conserve leurs identifiants.
                         */
                        $pending->setEmission($emission);
                        $hasActivePending = true;
                    }

                    /*
                     * Aucun Pending actif et aucune rediffusion
                     * supplémentaire déjà programmée :
                     * créer un Pending pour cette émission.
                     *
                     * Les dismissed appartenant à d'autres
                     * émissions ne bloquent pas sa création.
                     */
                    if (
                        !$hasActivePending
                        && $manualRebroadcasts === []
                    ) {
                        $pending = (new PendingRebroadcast())
                            ->setEmission($emission)
                            ->setAssignmentGroupKey($assignmentGroupKey);

                        $this->em->persist($pending);
                    }
                }

                /*
                * ==========================================================
                * 8. ENREGISTRER LES MODIFICATIONS
                * ==========================================================
                */
                $this->em->flush();

                /*
                * ==========================================================
                * 9. REPUBLIER LES SEMAINES INITIALEMENT PUBLIÉES
                * ==========================================================
                */
                foreach ($publishedWeeks as $weekStart) {
                    $preview = $this->publicationService
                        ->previewWeekPublication($weekStart);

                    if ($preview['hasBlockingConflicts']) {
                        throw new \DomainException(sprintf(
                            'Impossible de republier la semaine du %s : des conflits bloquants subsistent.',
                            $weekStart->format('d/m/Y')
                        ));
                    }

                    if (!$preview['canPublish']) {
                        continue;
                    }

                    $this->publicationService
                        ->applyWeekPublication($weekStart);
                }

                return true;
            }
        );
    }

    public function remove(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $selectedDate
    ): bool {
        $draft = $this->draftRepository
            ->findOneRegularBySlotAndHoraire($slot, $selectedDate);

        if (!$draft instanceof DiffusionDraft) {
            return false;
        }

        $assignmentGroupKey = $draft->getAssignmentGroupKey();

        /*
     * Compatibilité avec les anciens Drafts
     * dépourvus de clé de groupe.
     */
        if (!$assignmentGroupKey) {
            $drafts = [$draft];
        } else {
            $drafts = $this->draftRepository
                ->findByAssignmentGroupKey($assignmentGroupKey);
        }

        /*
     * Identifier toutes les semaines concernées.
     *
     * On tient compte :
     * - des horaires d'origine des Drafts ;
     * - des arbitrages ayant déplacé des occurrences ;
     * - des horaires des Diffusions déjà publiées.
     */
        $occurrences = [];
        $affectedWeeks = [];

        foreach ($drafts as $draftToRemove) {
            if (!$draftToRemove instanceof DiffusionDraft) {
                continue;
            }

            $draftSlot = $draftToRemove->getSlot();
            $startsAt = $draftToRemove->getHoraireDiffusion();

            if (
                $draftSlot instanceof ProgrammationRuleSlot
                && $startsAt instanceof \DateTimeInterface
            ) {
                $originalStartsAt = \DateTimeImmutable::createFromInterface(
                    $startsAt
                );

                $occurrences[] = [
                    'slot' => $draftSlot,
                    'startsAt' => $originalStartsAt,
                ];
            }

            $publishedDiffusion = $draftToRemove->getPublishedDiffusion();

            if ($publishedDiffusion !== null) {
                $publishedStartsAt = $publishedDiffusion->getHoraireDiffusion();

                if ($publishedStartsAt instanceof \DateTimeInterface) {
                    $publishedWeekStart = $this->getRadioWeekStart(
                        \DateTimeImmutable::createFromInterface(
                            $publishedStartsAt
                        )
                    );

                    $affectedWeeks[$publishedWeekStart->format('Y-m-d')] = $publishedWeekStart;
                }
            }
        }

        foreach ($this->getAffectedRadioWeeks($occurrences) as $weekStart) {
            $affectedWeeks[$weekStart->format('Y-m-d')] = $weekStart;
        }

        ksort($affectedWeeks);

        /*
     * Une seule transaction pour :
     * - dévalider ;
     * - supprimer les affectations ;
     * - republier les autres émissions si nécessaire.
     */
        return $this->em->wrapInTransaction(
            function () use (
                $drafts,
                $assignmentGroupKey,
                $affectedWeeks
            ): bool {
                $publishedWeeks = [];

                /*
             * Prévalidation de toutes les semaines publiées.
             */
                foreach ($affectedWeeks as $weekStart) {
                    $preview = $this->unpublicationService
                        ->previewWeek($weekStart);

                    if ($preview['publishedDiffusionCount'] === 0) {
                        continue;
                    }

                    if (!$preview['canUnpublish']) {
                        throw new \DomainException(sprintf(
                            'Impossible de supprimer l’affectation : la semaine du %s ne peut pas être dévalidée automatiquement.',
                            $weekStart->format('d/m/Y')
                        ));
                    }

                    $publishedWeeks[] = $weekStart;
                }

                /*
             * Dévalidation des semaines initialement publiées.
             * Les Diffusions historiques restent en base.
             */
                foreach ($publishedWeeks as $weekStart) {
                    $this->unpublicationService
                        ->applyWeekUnpublication($weekStart);
                }

                /*
             * Suppression des Drafts du groupe.
             */
                foreach ($drafts as $draftToRemove) {
                    if ($draftToRemove instanceof DiffusionDraft) {
                        $this->em->remove($draftToRemove);
                    }
                }

                /*
             * Suppression des Pending actifs du groupe.
             *
             * Les Pending dismissed sont conservés :
             * ils représentent une exclusion volontaire
             * qui ne doit jamais être annulée automatiquement.
             */
                if ($assignmentGroupKey) {
                    $pendingRebroadcasts = $this->pendingRebroadcastRepository
                        ->findBy([
                            'assignmentGroupKey' => $assignmentGroupKey,
                        ]);

                    foreach ($pendingRebroadcasts as $pendingRebroadcast) {
                        if (
                            $pendingRebroadcast instanceof PendingRebroadcast
                            && $pendingRebroadcast->isPending()
                        ) {
                            $this->em->remove($pendingRebroadcast);
                        }
                    }
                }

                /*
             * Les requêtes de prévisualisation doivent voir
             * les suppressions effectuées.
             */
                $this->em->flush();

                /*
             * Republier uniquement les semaines qui étaient
             * publiées avant la suppression.
             */
                foreach ($publishedWeeks as $weekStart) {
                    $preview = $this->publicationService
                        ->previewWeekPublication($weekStart);

                    if ($preview['hasBlockingConflicts']) {
                        throw new \DomainException(sprintf(
                            'Impossible de republier la semaine du %s : des conflits bloquants subsistent.',
                            $weekStart->format('d/m/Y')
                        ));
                    }

                    if (!$preview['canPublish']) {
                        continue;
                    }

                    $this->publicationService
                        ->applyWeekPublication($weekStart);
                }

                return $assignmentGroupKey !== null
                    && $assignmentGroupKey !== '';
            }
        );
    }

    private function upsertDraft(
        ProgrammationRuleSlot $slot,
        Emission $emission,
        \DateTimeImmutable $startsAt,
        string $groupKey
    ): void {
        $durationMinutes = $slot->getDurationMinutes();

        if ($durationMinutes === null || $durationMinutes <= 0) {
            $durationMinutes = $emission->getDuree();
        }

        if ($durationMinutes === null || $durationMinutes <= 0) {
            throw new \RuntimeException(
                'Impossible de déterminer la durée de la diffusion.'
            );
        }

        // Recherche également les drafts supprimés logiquement.
        // La contrainte SQL interdit deux drafts pour un même slot/horaire.
        $draft = $this->draftRepository
            ->findOneRegularIncludingDeletedBySlotAndHoraire(
                $slot,
                $startsAt
            );

        if ($draft === null) {
            $draft = new DiffusionDraft();
        } elseif ($draft->isDeleted()) {
            // On réutilise la ligne existante au lieu d'en créer une nouvelle.
            $draft->restore();
        }

        $draft
            ->setSlot($slot)
            ->setSchedule($startsAt, $durationMinutes)
            ->setEmission($emission)
            ->setNombreDiffusion($slot->getBroadcastRank())
            ->setDraftType(DiffusionDraft::TYPE_REGULAR)
            ->setAssignmentGroupKey($groupKey);

        $this->em->persist($draft);
    }

    private function buildAssignmentGroupKey(
        ?int $ruleId,
        \DateTimeImmutable $originDate
    ): string {
        if ($ruleId === null) {
            throw new \RuntimeException(
                'Impossible de générer une clé de groupe sans ID de règle.'
            );
        }

        return sprintf(
            'rule_%d_origin_%s',
            $ruleId,
            $originDate->format('Ymd_Hi')
        );
    }

    private function resolveDurationMinutes(
        ProgrammationRuleSlot $slot,
        Emission $emission
    ): int {
        $slotDuration = $slot->getDurationMinutes();

        if (\is_int($slotDuration) && $slotDuration > 0) {
            return $slotDuration;
        }

        $emissionDuration = $emission->getDuree();

        if (\is_int($emissionDuration) && $emissionDuration > 0) {
            return $emissionDuration;
        }

        return 15;
    }

    private function computeStartsAtFromAnchor(
        \DateTimeImmutable $anchorDate,
        ProgrammationRuleSlot $slot
    ): \DateTimeImmutable {
        $anchorWeekStart = $this->getRadioWeekStart($anchorDate);

        $targetDate = $anchorWeekStart
            ->modify(sprintf(
                '+%d days',
                $this->radioDayIndexFromDayOfWeek(
                    $slot->getDayOfWeek()
                )
            ))
            ->modify(sprintf(
                '+%d days',
                $slot->getWeekOffset() * 7
            ));

        $startTime = $slot->getStartTime();

        if ($startTime === null) {
            return $targetDate->setTime(0, 0, 0);
        }

        return $targetDate->setTime(
            (int) $startTime->format('H'),
            (int) $startTime->format('i'),
            0
        );
    }

    private function getRadioWeekStart(
        \DateTimeImmutable $date
    ): \DateTimeImmutable {
        $midnight = $date->setTime(0, 0, 0);
        $dayOfWeek = (int) $midnight->format('N');

        return match ($dayOfWeek) {
            2 => $midnight,
            3 => $midnight->modify('-1 day'),
            4 => $midnight->modify('-2 days'),
            5 => $midnight->modify('-3 days'),
            6 => $midnight->modify('-4 days'),
            7 => $midnight->modify('-5 days'),
            1 => $midnight->modify('-6 days'),
            default => $midnight,
        };
    }

    private function radioDayIndexFromDayOfWeek(
        ?int $dayOfWeek
    ): int {
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

    /**
     * Identifie les semaines radio concernées par les occurrences.
     *
     * Prend en compte :
     * - la semaine de l'horaire d'origine ;
     * - la semaine de destination en cas d'arbitrage.
     *
     * @param array<int, array{
     *     slot: ProgrammationRuleSlot,
     *     startsAt: \DateTimeImmutable
     * }> $occurrences
     *
     * @return \DateTimeImmutable[]
     */
    private function getAffectedRadioWeeks(array $occurrences): array
    {
        $weeks = [];

        foreach ($occurrences as $occurrence) {
            $slot = $occurrence['slot'];
            $startsAt = $occurrence['startsAt'];

            $originalWeekStart = $this->getRadioWeekStart($startsAt);

            $weeks[$originalWeekStart->format('Y-m-d')] = $originalWeekStart;

            $arbitration = $this->arbitrationRepository
                ->findOneActiveForOccurrence($slot, $startsAt);

            if (
                !$arbitration instanceof GridSlotArbitration
                || !$arbitration->isRescheduleAction()
            ) {
                continue;
            }

            $rescheduledStartsAt = $arbitration->getRescheduledStartsAt();

            if (!$rescheduledStartsAt instanceof \DateTimeInterface) {
                continue;
            }

            $effectiveWeekStart = $this->getRadioWeekStart(
                \DateTimeImmutable::createFromInterface($rescheduledStartsAt)
            );

            $weeks[$effectiveWeekStart->format('Y-m-d')] = $effectiveWeekStart;
        }

        ksort($weeks);

        return array_values($weeks);
    }
}
