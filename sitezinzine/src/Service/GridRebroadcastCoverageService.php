<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\ProgrammationRuleSlotRepository;
use Doctrine\ORM\EntityManagerInterface;

final class GridRebroadcastCoverageService
{
    public const STATUS_NORMAL = 'normal';
    public const STATUS_OVERRIDE = 'override';
    public const STATUS_MISSING = 'missing';
    public const STATUS_ARBITRATED = 'arbitrated';
    public const STATUS_SOURCE_NOT_FOUND = 'source_not_found';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_PENDING_SOURCE = 'pending_source';

    public function __construct(
        private readonly ProgrammationGridBuilder $programmationGridBuilder,
        private readonly GridOccurrenceProjectionService $projectionService,
        private readonly ProgrammationRuleSlotRepository $slotRepository,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function previewWeek(\DateTimeImmutable $weekStart): array
    {
        $weekStart = $this->getRadioWeekStart($weekStart);
        $weekEnd = $weekStart->modify('+7 days');

        /*
         * On part exactement de la même grille théorique que l'admin.
         */
        $daySegments = $this->programmationGridBuilder->buildForWeek(
            $weekStart,
            $weekEnd
        );

        /*
         * Puis on applique les arbitrages.
         *
         * Cela permet de ne pas considérer comme "missing"
         * un créneau volontairement annulé ou déplacé.
         */
        $daySegments = $this->projectionService->applyForWeek(
            $daySegments,
            $weekStart,
            $weekEnd
        );

        $items = [];

        $counts = [
            self::STATUS_NORMAL => 0,
            self::STATUS_OVERRIDE => 0,
            self::STATUS_MISSING => 0,
            self::STATUS_PENDING_SOURCE => 0,
            self::STATUS_ARBITRATED => 0,
            self::STATUS_SOURCE_NOT_FOUND => 0,
            self::STATUS_AMBIGUOUS => 0,
        ];

        foreach ($daySegments as $segments) {
            foreach ($segments as $segment) {
                $broadcastRank = (int) ($segment['broadcastRank'] ?? 1);

                /*
                 * Le service ne contrôle que les rediffusions régulières.
                 */
                if ($broadcastRank <= 1) {
                    continue;
                }

                $item = $this->analyseRebroadcastSegment(
                    $segment,
                    $weekStart,
                    $weekEnd
                );

                if (null === $item) {
                    continue;
                }

                $items[] = $item;

                $status = $item['status'];

                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
            }
        }

        usort(
            $items,
            static function (array $a, array $b): int {
                return strcmp(
                    $a['targetStartsAt'] ?? '',
                    $b['targetStartsAt'] ?? ''
                );
            }
        );

        return [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'items' => $items,
            'counts' => $counts,
            'total' => count($items),

            /*
             * Ce sont les seuls cas qui pourront plus tard donner lieu
             * à une création automatique de DiffusionDraft.
             */
            'fillableCount' => $counts[self::STATUS_MISSING],
        ];
    }

    private function analyseRebroadcastSegment(
        array $segment,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd
    ): ?array {
        $slotId = (int) ($segment['slotId'] ?? 0);
        $broadcastRank = (int) ($segment['broadcastRank'] ?? 1);

        if ($slotId <= 0 || $broadcastRank <= 1) {
            return null;
        }

        $slot = $this->slotRepository->find($slotId);

        if (
            !$slot instanceof ProgrammationRuleSlot
            || !$slot->isActive()
            || $slot->isDeleted()
        ) {
            return null;
        }

        $targetStartsAt = $this->toImmutable(
            $segment['startsAt'] ?? null
        );

        /*
     * Le draft régulier reste associé à son occurrence d'origine.
     * C'est déjà le principe utilisé par la grille lorsqu'une occurrence
     * est projetée à une autre date.
     */
        $originalStartsAt = $this->toImmutable(
            $segment['originalStartsAt']
                ?? $segment['startsAt']
                ?? null
        );

        $firstBroadcastStartsAt = $this->toImmutable(
            $segment['firstBroadcastStartsAt'] ?? null
        );

        if (
            null === $targetStartsAt
            || null === $originalStartsAt
        ) {
            return null;
        }

        /*
     * Annulé ou déplacé volontairement :
     * ce n'est pas un créneau à compléter automatiquement.
     */
        if (
            ($segment['isCancelled'] ?? false) === true
            || ($segment['isRescheduledOrigin'] ?? false) === true
            || ($segment['isProjectedOverride'] ?? false) === true
            || ($segment['isBlocking'] ?? true) === false
        ) {
            return $this->buildItem(
                status: self::STATUS_ARBITRATED,
                segment: $segment,
                slot: $slot,
                targetStartsAt: $targetStartsAt,
                originalStartsAt: $originalStartsAt,
                firstBroadcastStartsAt: $firstBroadcastStartsAt,
            );
        }

        /*
     * Le lien vers la première diffusion doit être fourni
     * directement par ProgrammationGridBuilder.
     *
     * Ici, son absence est réellement anormale : on ne peut
     * même pas déterminer quelle occurrence de rang 1 rechercher.
     */
        if (null === $firstBroadcastStartsAt) {
            return $this->buildItem(
                status: self::STATUS_SOURCE_NOT_FOUND,
                segment: $segment,
                slot: $slot,
                targetStartsAt: $targetStartsAt,
                originalStartsAt: $originalStartsAt,
                firstBroadcastStartsAt: null,
            );
        }

        /*
     * On regarde ce qui a réellement été diffusé à l'occurrence
     * de rang 1.
     */
        $sourceDiffusions = $this->diffusionRepository->findAllAtHoraire(
            $firstBroadcastStartsAt
        );

        if ([] === $sourceDiffusions) {
            /*
         * Si la première diffusion appartient à la semaine actuellement
         * en cours de programmation, son absence dans Diffusion est normale.
         *
         * Elle n'a simplement pas encore été validée/publiée.
         */
            if (
                $firstBroadcastStartsAt >= $weekStart
                && $firstBroadcastStartsAt < $weekEnd
            ) {
                return $this->buildItem(
                    status: self::STATUS_PENDING_SOURCE,
                    segment: $segment,
                    slot: $slot,
                    targetStartsAt: $targetStartsAt,
                    originalStartsAt: $originalStartsAt,
                    firstBroadcastStartsAt: $firstBroadcastStartsAt,
                );
            }

            /*
         * La première diffusion appartient à une période antérieure,
         * donc on aurait dû pouvoir la retrouver dans Diffusion.
         */
            return $this->buildItem(
                status: self::STATUS_SOURCE_NOT_FOUND,
                segment: $segment,
                slot: $slot,
                targetStartsAt: $targetStartsAt,
                originalStartsAt: $originalStartsAt,
                firstBroadcastStartsAt: $firstBroadcastStartsAt,
            );
        }

        /*
     * Plusieurs Diffusion exactement au même horaire :
     * on refuse de choisir arbitrairement une source.
     */
        if (count($sourceDiffusions) > 1) {
            return $this->buildItem(
                status: self::STATUS_AMBIGUOUS,
                segment: $segment,
                slot: $slot,
                targetStartsAt: $targetStartsAt,
                originalStartsAt: $originalStartsAt,
                firstBroadcastStartsAt: $firstBroadcastStartsAt,
                sourceDiffusions: $sourceDiffusions,
            );
        }

        /** @var Diffusion $sourceDiffusion */
        $sourceDiffusion = $sourceDiffusions[0];

        /*
     * Existe-t-il déjà une programmation sur cette rediffusion ?
     *
     * Si oui, elle est prioritaire sur la suggestion issue de la règle.
     */
        $existingDraft = $this->draftRepository
            ->findOneActiveDraftBySlotAndHoraire(
                $slot,
                $originalStartsAt
            );

        /*
     * La source historique est connue mais aucun draft n'existe
     * sur la rediffusion : le créneau peut être prérempli.
     */
        if (!$existingDraft instanceof DiffusionDraft) {
            return $this->buildItem(
                status: self::STATUS_MISSING,
                segment: $segment,
                slot: $slot,
                targetStartsAt: $targetStartsAt,
                originalStartsAt: $originalStartsAt,
                firstBroadcastStartsAt: $firstBroadcastStartsAt,
                sourceDiffusion: $sourceDiffusion,
            );
        }

        $sourceEmission = $sourceDiffusion->getEmission();
        $programmedEmission = $existingDraft->getEmission();

        /*
     * Même émission :
     * comportement normal de la règle.
     */
        if (
            null !== $sourceEmission
            && null !== $programmedEmission
            && $sourceEmission->getId() === $programmedEmission->getId()
        ) {
            return $this->buildItem(
                status: self::STATUS_NORMAL,
                segment: $segment,
                slot: $slot,
                targetStartsAt: $targetStartsAt,
                originalStartsAt: $originalStartsAt,
                firstBroadcastStartsAt: $firstBroadcastStartsAt,
                sourceDiffusion: $sourceDiffusion,
                existingDraft: $existingDraft,
            );
        }

        /*
     * Une autre émission est programmée sur la rediffusion.
     *
     * C'est autorisé : on le signale simplement comme override.
     */
        return $this->buildItem(
            status: self::STATUS_OVERRIDE,
            segment: $segment,
            slot: $slot,
            targetStartsAt: $targetStartsAt,
            originalStartsAt: $originalStartsAt,
            firstBroadcastStartsAt: $firstBroadcastStartsAt,
            sourceDiffusion: $sourceDiffusion,
            existingDraft: $existingDraft,
        );
    }

    /**
     * @param Diffusion[] $sourceDiffusions
     */
    private function buildItem(
        string $status,
        array $segment,
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $targetStartsAt,
        \DateTimeImmutable $originalStartsAt,
        ?\DateTimeImmutable $firstBroadcastStartsAt,
        ?Diffusion $sourceDiffusion = null,
        ?DiffusionDraft $existingDraft = null,
        array $sourceDiffusions = [],
    ): array {
        $rule = $slot->getRule();

        $sourceEmission = $sourceDiffusion?->getEmission();
        $programmedEmission = $existingDraft?->getEmission();

        return [
            'status' => $status,

            'ruleId' => $rule?->getId(),
            'ruleNumber' => $rule?->getRuleNumber(),
            'ruleDisplayName' => $rule?->getDisplayName(),

            'slotId' => $slot->getId(),
            'broadcastRank' => $slot->getBroadcastRank(),

            'categoryId' => $rule?->getCategory()?->getId(),
            'categoryTitle' => $rule?->getCategory()?->getTitre(),

            'targetStartsAt' => $targetStartsAt->format('Y-m-d H:i:s'),
            'originalStartsAt' => $originalStartsAt->format('Y-m-d H:i:s'),

            'firstBroadcastStartsAt' => $firstBroadcastStartsAt?->format(
                'Y-m-d H:i:s'
            ),

            'sourceDiffusionId' => $sourceDiffusion?->getId(),

            'expectedEmissionId' => $sourceEmission?->getId(),
            'expectedEmissionTitle' => $sourceEmission?->getTitre(),

            'draftId' => $existingDraft?->getId(),

            'programmedEmissionId' => $programmedEmission?->getId(),
            'programmedEmissionTitle' => $programmedEmission?->getTitre(),

            'ambiguousSourceDiffusionIds' => array_values(array_map(
                static fn(Diffusion $diffusion): ?int => $diffusion->getId(),
                $sourceDiffusions
            )),

            /*
             * Quelques informations de projection utiles pour le debug
             * et pour l'affichage futur.
             */
            'isProjectedOverride' => (bool) ($segment['isProjectedOverride'] ?? false),
            'isCancelled' => (bool) ($segment['isCancelled'] ?? false),
            'isRescheduledOrigin' => (bool) ($segment['isRescheduledOrigin'] ?? false),
            'projectionType' => $segment['projectionType'] ?? null,
        ];
    }

    private function toImmutable(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function getRadioWeekStart(
        \DateTimeImmutable $date
    ): \DateTimeImmutable {
        $date = $date->setTime(0, 0, 0);

        $dayOfWeek = (int) $date->format('N');

        if ($dayOfWeek >= 2) {
            return $date->modify(
                sprintf('-%d days', $dayOfWeek - 2)
            );
        }

        return $date->modify('-6 days');
    }

    public function fillMissingRebroadcastsForWeek(
        \DateTimeImmutable $weekStart
    ): array {
        return $this->entityManager->wrapInTransaction(
            function () use ($weekStart): array {
                /*
             * On recalcule toujours la preview au moment de l'écriture.
             * On ne se fie jamais à une preview affichée précédemment.
             */
                $preview = $this->previewWeek($weekStart);

                $createdDrafts = [];
                $updatedSourceDiffusions = [];
                $skipped = [];

                foreach ($preview['items'] as $item) {
                    /*
                 * On ne remplit QUE les vrais "missing".
                 *
                 * pending_source, override, arbitrated,
                 * source_not_found, ambiguous et normal
                 * ne sont jamais modifiés ici.
                 */
                    if (($item['status'] ?? null) !== self::STATUS_MISSING) {
                        continue;
                    }

                    $slotId = (int) ($item['slotId'] ?? 0);
                    $sourceDiffusionId = (int) ($item['sourceDiffusionId'] ?? 0);

                    if ($slotId <= 0 || $sourceDiffusionId <= 0) {
                        $skipped[] = [
                            'reason' => 'invalid_reference',
                            'item' => $item,
                        ];

                        continue;
                    }

                    $slot = $this->slotRepository->find($slotId);
                    $sourceDiffusion = $this->diffusionRepository->find(
                        $sourceDiffusionId
                    );

                    if (
                        !$slot instanceof ProgrammationRuleSlot
                        || !$sourceDiffusion instanceof Diffusion
                    ) {
                        $skipped[] = [
                            'reason' => 'entity_not_found',
                            'item' => $item,
                        ];

                        continue;
                    }

                    $emission = $sourceDiffusion->getEmission();

                    if (null === $emission) {
                        $skipped[] = [
                            'reason' => 'source_emission_not_found',
                            'item' => $item,
                        ];

                        continue;
                    }

                    $startsAt = $this->toImmutable(
                        $item['originalStartsAt'] ?? null
                    );

                    $firstBroadcastStartsAt = $this->toImmutable(
                        $item['firstBroadcastStartsAt'] ?? null
                    );

                    if (
                        null === $startsAt
                        || null === $firstBroadcastStartsAt
                    ) {
                        $skipped[] = [
                            'reason' => 'invalid_schedule',
                            'item' => $item,
                        ];

                        continue;
                    }

                    /*
                 * Protection supplémentaire :
                 * la preview disait "missing", mais quelque chose peut
                 * avoir été créé entre-temps.
                 */
                    $existingDraft = $this->draftRepository
                        ->findOneActiveDraftBySlotAndHoraire(
                            $slot,
                            $startsAt
                        );

                    if ($existingDraft instanceof DiffusionDraft) {
                        $skipped[] = [
                            'reason' => 'draft_already_exists',
                            'draftId' => $existingDraft->getId(),
                            'item' => $item,
                        ];

                        continue;
                    }

                    $durationMinutes = $slot->getDurationMinutes() ?? 15;

                    if ($durationMinutes < 1) {
                        $durationMinutes = 15;
                    }

                    /*
                 * Si la première diffusion possède déjà un groupe,
                 * c'est lui qui fait foi.
                 *
                 * Sinon on génère un groupe stable à partir de :
                 * - la règle
                 * - l'occurrence de première diffusion
                 */
                    $assignmentGroupKey = $sourceDiffusion->getAssignmentGroupKey();

                    if (
                        null === $assignmentGroupKey
                        || '' === trim($assignmentGroupKey)
                    ) {
                        $ruleId = $slot->getRule()?->getId();

                        if (null === $ruleId) {
                            $skipped[] = [
                                'reason' => 'rule_not_found',
                                'item' => $item,
                            ];

                            continue;
                        }

                        $assignmentGroupKey = $this->buildAssignmentGroupKey(
                            $ruleId,
                            $firstBroadcastStartsAt
                        );

                        $sourceDiffusion->setAssignmentGroupKey(
                            $assignmentGroupKey
                        );

                        $updatedSourceDiffusions[$sourceDiffusion->getId()] =
                            $sourceDiffusion;
                    }

                    $draft = new DiffusionDraft();

                    $draft
                        ->setEmission($emission)
                        ->setSlot($slot)
                        ->setSchedule(
                            $startsAt,
                            $durationMinutes
                        )
                        ->setNombreDiffusion(
                            $slot->getBroadcastRank()
                        )
                        ->setDraftType(
                            DiffusionDraft::TYPE_REGULAR
                        )
                        ->setAssignmentGroupKey(
                            $assignmentGroupKey
                        )
                        ->setPublicationStatus(
                            DiffusionDraft::STATUS_DRAFT
                        );

                    $this->entityManager->persist($draft);

                    $createdDrafts[] = $draft;
                }

                /*
             * Un seul flush pour l'ensemble de l'opération.
             */
                $this->entityManager->flush();

                return [
                    'weekStart' => $preview['weekStart'],
                    'weekEnd' => $preview['weekEnd'],

                    'createdCount' => count($createdDrafts),
                    'updatedSourceDiffusionCount' => count(
                        $updatedSourceDiffusions
                    ),
                    'skippedCount' => count($skipped),

                    'createdDrafts' => $createdDrafts,
                    'updatedSourceDiffusions' => array_values(
                        $updatedSourceDiffusions
                    ),
                    'skipped' => $skipped,
                ];
            }
        );
    }

    private function buildAssignmentGroupKey(
        int $ruleId,
        \DateTimeImmutable $firstBroadcastStartsAt
    ): string {
        return sprintf(
            'rule_%d_origin_%s',
            $ruleId,
            $firstBroadcastStartsAt->format('Ymd_Hi')
        );
    }
}
