<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\CategoriesRepository;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;

final class GridViewBuilder
{
    public function __construct(
        private readonly ProgrammationGridBuilder $programmationGridBuilder,
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        private readonly GridConflictDetector $gridConflictDetector,
        private readonly CategoriesRepository $categoriesRepository,
    ) {}

    public function build(\DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd): array
    {
        $hasValidatedWeek = $this->diffusionRepository->hasDiffusionsBetween($weekStart, $weekEnd);

        $daySegments = $this->programmationGridBuilder->buildForWeek($weekStart, $weekEnd);

        $daySegments = $this->gridOccurrenceProjectionService->applyForWeek(
            $daySegments,
            $weekStart,
            $weekEnd
        );

        $daySegments = $this->gridConflictDetector->detectForWeek($daySegments);

        $hydratedGrid = $hasValidatedWeek
            ? $this->hydrateDiffusionGrid($daySegments, $weekStart, $weekEnd)
            : $this->hydrateDraftGrid($daySegments, $weekStart, $weekEnd);

        return [
            'daySegments' => $hydratedGrid['daySegments'],
            'manualDraftsByDay' => $hydratedGrid['manualDraftsByDay'],
            'specialCategories' => $this->findSpecialCategories(),
            'hasValidatedWeek' => $hasValidatedWeek,
            'gridMode' => $hasValidatedWeek ? 'diffusion' : 'draft',
        ];
    }

    private function hydrateDraftGrid(
        array $daySegments,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd
    ): array {
        $drafts = $this->draftRepository->findByWeek($weekStart, $weekEnd);

        $extraDraftLookupPairs = [];

        foreach ($daySegments as $segments) {
            foreach ($segments as $seg) {
                $slotId = $seg['slotId'] ?? null;
                $originalStartsAt = $seg['originalStartsAt'] ?? null;
                $startsAt = $seg['startsAt'] ?? null;

                if (!$slotId || !$originalStartsAt || !$startsAt) {
                    continue;
                }

                if ($originalStartsAt === $startsAt) {
                    continue;
                }

                $originalDate = $originalStartsAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($originalStartsAt)
                    : new \DateTimeImmutable((string) $originalStartsAt);

                $key = sprintf('%d|%s', (int) $slotId, $originalDate->format('Y-m-d H:i:s'));

                $extraDraftLookupPairs[$key] = [
                    'slotId' => (int) $slotId,
                    'startsAt' => $originalDate,
                ];
            }
        }

        if ([] !== $extraDraftLookupPairs) {
            $extraDrafts = $this->draftRepository->findRegularDraftsBySlotAndStartsAtPairs(
                array_values($extraDraftLookupPairs)
            );

            $drafts = array_merge($drafts, $extraDrafts);
        }

        $draftIndex = [];
        $manualDraftsByDay = array_fill(0, 7, []);

        foreach ($drafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            $startsAt = $draft->getHoraireDiffusion();

            if (!$startsAt instanceof \DateTimeInterface) {
                continue;
            }

            if ($draft->getSlot() instanceof ProgrammationRuleSlot) {
                $key = $this->buildDraftKey(
                    $draft->getSlot()->getId(),
                    $startsAt
                );

                if (null !== $key) {
                    $draftIndex[$key] = $draft;
                }

                continue;
            }

            $dayIndex = $this->getManualDraftDayIndex($startsAt, $weekStart, $weekEnd);

            if (null === $dayIndex) {
                continue;
            }

            $duration = $draft->getDurationMinutes() ?? $draft->getEmission()?->getDuree() ?? 15;

            if ($duration < 1) {
                $duration = 15;
            }

            $minutesFromMidnight = ((int) $startsAt->format('H') * 60) + (int) $startsAt->format('i');
            $startIndex = max(0, min(95, (int) floor($minutesFromMidnight / 15)));

            $manualDraftsByDay[$dayIndex][] = [
                'id' => $draft->getId(),
                'startIndex' => $startIndex,
                'duration' => $duration,
                'startsAt' => $startsAt->format('Y-m-d H:i:s'),
                'endsAt' => $draft->getEndsAt()?->format('Y-m-d H:i:s'),
                'title' => $draft->getEmission()?->getTitre() ?? 'Émission',
                'categoryTitle' => $draft->getEmission()?->getCategorie()?->getTitre() ?? 'Hors règle',
                'categorySlug' => $draft->getEmission()?->getCategorie()?->getSlug() ?? '',
                'draftType' => $draft->getDraftType(),
                'emissionId' => $draft->getEmission()?->getId(),
                'assigned' => true,
                'isManualDraft' => true,
                'emissionIsAutoGenerated' => $draft->getEmission()?->isAutoGenerated() ?? false,
                'broadcastRank' => $draft->getNombreDiffusion(),
                'nombreDiffusion' => $draft->getNombreDiffusion(),
                'assignmentGroupKey' => $draft->getAssignmentGroupKey(),
            ];
        }

        foreach ($manualDraftsByDay as &$draftsForDay) {
            usort(
                $draftsForDay,
                static fn(array $a, array $b): int => strcmp($a['startsAt'], $b['startsAt'])
            );
        }
        unset($draftsForDay);

        foreach ($daySegments as &$segments) {
            foreach ($segments as &$seg) {
                $seg['assigned'] = false;
                $seg['emissionId'] = null;
                $seg['emissionTitle'] = null;
                $seg['emissionIsAutoGenerated'] = false;
                $seg['draftId'] = null;
                $seg['categoryTitle'] = $seg['categoryTitle'] ?? ($seg['title'] ?? 'Catégorie inconnue');
                $seg['categorySlug'] = $seg['categorySlug'] ?? null;
                $seg['displayTitle'] = $seg['title'] ?? 'Créneau';

                $slotId = $seg['slotId'] ?? null;
                $startsAt = $seg['originalStartsAt'] ?? $seg['startsAt'] ?? null;

                if (!$slotId || !$startsAt) {
                    continue;
                }

                if (
                    ($seg['isBlocking'] ?? true) === false ||
                    ($seg['isCancelled'] ?? false) === true ||
                    ($seg['isRescheduledOrigin'] ?? false) === true
                ) {
                    continue;
                }

                $startsAtDate = $startsAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($startsAt)
                    : new \DateTimeImmutable((string) $startsAt);

                $key = $this->buildDraftKey((int) $slotId, $startsAtDate);

                if (!isset($draftIndex[$key])) {
                    continue;
                }

                $draft = $draftIndex[$key];
                $emission = $draft->getEmission();

                if ($emission instanceof Emission) {
                    $seg['assigned'] = true;
                    $seg['draftId'] = $draft->getId();

                    $seg['emissionId'] = $emission->getId();
                    $seg['emissionTitle'] = $emission->getTitre();
                    $seg['displayTitle'] = $emission->getTitre();
                    $seg['emissionIsAutoGenerated'] = $emission->isAutoGenerated();

                    $seg['categoryTitle'] = $emission->getCategorie()?->getTitre()
                        ?? $seg['categoryTitle'];

                    $seg['categorySlug'] = $emission->getCategorie()?->getSlug()
                        ?? $seg['categorySlug'];
                }
            }
        }
        unset($segments, $seg);

        return [
            'daySegments' => $daySegments,
            'manualDraftsByDay' => $manualDraftsByDay,
        ];
    }

    private function hydrateDiffusionGrid(
        array $daySegments,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd
    ): array {
        $diffusions = $this->diffusionRepository->findByWeek($weekStart, $weekEnd);

        $diffusionIndex = [];
        $manualDiffusionsByDay = array_fill(0, 7, []);

        foreach ($diffusions as $diffusion) {
            $startsAt = $diffusion->getHoraireDiffusion();

            if (!$startsAt instanceof \DateTimeInterface) {
                continue;
            }

            $key = $startsAt->format('Y-m-d H:i:s');
            $diffusionIndex[$key] = $diffusion;
        }

        foreach ($daySegments as &$segments) {
            foreach ($segments as &$seg) {
                $seg['assigned'] = false;
                $seg['emissionId'] = null;
                $seg['emissionTitle'] = null;
                $seg['emissionIsAutoGenerated'] = false;
                $seg['draftId'] = null;
                $seg['diffusionId'] = null;
                $seg['categoryTitle'] = $seg['categoryTitle'] ?? ($seg['title'] ?? 'Catégorie inconnue');
                $seg['categorySlug'] = $seg['categorySlug'] ?? null;
                $seg['displayTitle'] = $seg['title'] ?? 'Créneau';
                $seg['isValidatedDiffusion'] = false;
                $seg['isReadonly'] = false;
                $seg['sourceType'] = 'empty';

                $startsAt = $seg['originalStartsAt'] ?? $seg['startsAt'] ?? null;

                if (!$startsAt) {
                    continue;
                }

                if (
                    ($seg['isBlocking'] ?? true) === false ||
                    ($seg['isCancelled'] ?? false) === true ||
                    ($seg['isRescheduledOrigin'] ?? false) === true
                ) {
                    continue;
                }

                $startsAtDate = $startsAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($startsAt)
                    : new \DateTimeImmutable((string) $startsAt);

                $key = $startsAtDate->format('Y-m-d H:i:s');

                if (!isset($diffusionIndex[$key])) {
                    continue;
                }

                $diffusion = $diffusionIndex[$key];
                $emission = $diffusion->getEmission();

                if (!$emission instanceof Emission) {
                    continue;
                }

                $seg['assigned'] = true;
                $seg['draftId'] = null;
                $seg['diffusionId'] = $diffusion->getId();

                $seg['emissionId'] = $emission->getId();
                $seg['emissionTitle'] = $emission->getTitre();
                $seg['displayTitle'] = $emission->getTitre();
                $seg['emissionIsAutoGenerated'] = $emission->isAutoGenerated();

                $seg['categoryTitle'] = $emission->getCategorie()?->getTitre()
                    ?? $seg['categoryTitle'];

                $seg['categorySlug'] = $emission->getCategorie()?->getSlug()
                    ?? $seg['categorySlug'];

                $seg['broadcastRank'] = $diffusion->getNombreDiffusion();
                $seg['nombreDiffusion'] = $diffusion->getNombreDiffusion();
                $seg['assignmentGroupKey'] = $diffusion->getAssignmentGroupKey();

                $seg['isValidatedDiffusion'] = true;
                $seg['isReadonly'] = true;
                $seg['sourceType'] = 'diffusion';

                unset($diffusionIndex[$key]);
            }
        }
        unset($segments, $seg);

        foreach ($diffusionIndex as $diffusion) {
            $startsAt = $diffusion->getHoraireDiffusion();

            if (!$startsAt instanceof \DateTimeInterface) {
                continue;
            }

            $dayIndex = $this->getManualDraftDayIndex($startsAt, $weekStart, $weekEnd);

            if (null === $dayIndex) {
                continue;
            }

            $duration = $diffusion->getDurationMinutes()
                ?? $diffusion->getEmission()?->getDuree()
                ?? 15;

            if ($duration < 1) {
                $duration = 15;
            }

            $minutesFromMidnight = ((int) $startsAt->format('H') * 60) + (int) $startsAt->format('i');
            $startIndex = max(0, min(95, (int) floor($minutesFromMidnight / 15)));

            $manualDiffusionsByDay[$dayIndex][] = [
                'id' => $diffusion->getId(),
                'diffusionId' => $diffusion->getId(),
                'draftId' => null,
                'startIndex' => $startIndex,
                'duration' => $duration,
                'startsAt' => $startsAt->format('Y-m-d H:i:s'),
                'endsAt' => $diffusion->getEndsAt()?->format('Y-m-d H:i:s'),
                'title' => $diffusion->getEmission()?->getTitre() ?? 'Émission',
                'categoryTitle' => $diffusion->getEmission()?->getCategorie()?->getTitre() ?? 'Hors règle',
                'categorySlug' => $diffusion->getEmission()?->getCategorie()?->getSlug() ?? '',
                'draftType' => 'validated_diffusion',
                'emissionId' => $diffusion->getEmission()?->getId(),
                'assigned' => true,
                'isManualDraft' => false,
                'isValidatedDiffusion' => true,
                'isReadonly' => true,
                'sourceType' => 'diffusion',
                'emissionIsAutoGenerated' => $diffusion->getEmission()?->isAutoGenerated() ?? false,
                'broadcastRank' => $diffusion->getNombreDiffusion(),
                'nombreDiffusion' => $diffusion->getNombreDiffusion(),
                'assignmentGroupKey' => $diffusion->getAssignmentGroupKey(),
            ];
        }

        foreach ($manualDiffusionsByDay as &$diffusionsForDay) {
            usort(
                $diffusionsForDay,
                static fn(array $a, array $b): int => strcmp($a['startsAt'], $b['startsAt'])
            );
        }
        unset($diffusionsForDay);

        return [
            'daySegments' => $daySegments,
            'manualDraftsByDay' => $manualDiffusionsByDay,
        ];
    }

    private function getManualDraftDayIndex(
        \DateTimeInterface $startsAt,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd
    ): ?int {
        $date = \DateTimeImmutable::createFromInterface($startsAt);

        if ($date < $weekStart || $date >= $weekEnd) {
            return null;
        }

        $diffDays = $weekStart->diff($date->setTime(0, 0, 0))->days;

        if (false === $diffDays || $diffDays < 0 || $diffDays > 6) {
            return null;
        }

        return $diffDays;
    }

    private function buildDraftKey(?int $slotId, ?\DateTimeInterface $horaire): ?string
    {
        if (null === $slotId || null === $horaire) {
            return null;
        }

        return $slotId . '|' . $horaire->format('Y-m-d H:i:s');
    }

    private function findSpecialCategories(): array
    {
        return $this->categoriesRepository->createQueryBuilder('c')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->orderBy('c.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
