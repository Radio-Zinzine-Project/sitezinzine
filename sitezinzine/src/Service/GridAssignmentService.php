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

        $affectedWeeks = $this->getAffectedRadioWeeks($occurrences);

        return $this->em->wrapInTransaction(
            function () use (
                $occurrences,
                $affectedWeeks,
                $emission,
                $assignmentGroupKey
            ): bool {
                $publishedWeeks = [];

                /*
             * Identifier les semaines initialement publiées
             * avant toute modification.
             */
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
             * Dévalider uniquement les semaines qui étaient
             * publiées avant le remplacement.
             */
                foreach ($publishedWeeks as $weekStart) {
                    $this->unpublicationService
                        ->applyWeekUnpublication($weekStart);
                }

                /*
             * Remplacer l'émission dans tous les Drafts
             * de l'affectation R1/R2/R3.
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
             * Synchroniser les changements avant les previews
             * de publication, qui effectuent des requêtes Doctrine.
             */
                $this->em->flush();

                /*
             * Republier uniquement les semaines initialement
             * publiées, et seulement si elles possèdent encore
             * des occurrences publiables.
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
             *
             * On ne modifie rien tant que nous n'avons pas
             * vérifié que toutes peuvent être dévalidées.
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
             *
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
             * Suppression des rediffusions en attente
             * associées au même groupe.
             */
                if ($assignmentGroupKey) {
                    $pendingRebroadcasts = $this->pendingRebroadcastRepository
                        ->findBy([
                            'assignmentGroupKey' => $assignmentGroupKey,
                        ]);

                    foreach ($pendingRebroadcasts as $pendingRebroadcast) {
                        if ($pendingRebroadcast instanceof PendingRebroadcast) {
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
             *
             * Si une semaine ne contient plus aucun Draft
             * publiable, elle reste dévalidée.
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

                /*
             * Comportement historique :
             * - true : groupe supprimé ;
             * - false : Draft isolé sans clé de groupe.
             */
                return $assignmentGroupKey !== null
                    && $assignmentGroupKey !== '';
            }
        );
    }

    private function upsertDraft(
        ProgrammationRuleSlot $slot,
        Emission $emission,
        \DateTimeImmutable $startsAt,
        string $assignmentGroupKey
    ): DiffusionDraft {
        $duration = $this->resolveDurationMinutes($slot, $emission);

        $draft = $this->draftRepository->findOneRegularBySlotAndHoraire(
            $slot,
            $startsAt
        );

        if (!$draft instanceof DiffusionDraft) {
            $draft = new DiffusionDraft();
        }

        $draft
            ->setSlot($slot)
            ->setSchedule($startsAt, $duration)
            ->setEmission($emission)
            ->setNombreDiffusion($slot->getBroadcastRank())
            ->setDraftType(DiffusionDraft::TYPE_REGULAR)
            ->setAssignmentGroupKey($assignmentGroupKey);

        $this->em->persist($draft);

        return $draft;
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
