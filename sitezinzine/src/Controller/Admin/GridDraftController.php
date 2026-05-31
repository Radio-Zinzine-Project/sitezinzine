<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Repository\CategoriesRepository;
use App\Repository\DiffusionDraftRepository;
use App\Repository\EmissionRepository;
use App\Service\LiveEmissionCreator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\GridConflictDetector;
use App\Service\GridOccurrenceProjectionService;
use App\Service\ProgrammationGridBuilder;

#[Route('/admin/grid-drafts', name: 'admin.grid_draft.')]
#[IsGranted('ROLE_ADMIN')]
class GridDraftController extends AbstractController
{
    #[Route('/manual', name: 'manual_create', methods: ['POST'])]
    public function createManual(
        Request $request,
        EmissionRepository $emissionRepository,
        DiffusionDraftRepository $draftRepository,
        EntityManagerInterface $em,
        ProgrammationGridBuilder $programmationGridBuilder,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector
    ): JsonResponse {
        $emissionId = $request->request->get('emissionId');
        $startsAtRaw = $request->request->get('startsAt');
        $draftType = $request->request->get('draftType', DiffusionDraft::TYPE_MANUAL_SPECIAL);
        $durationRaw = $request->request->get('durationMinutes');

        if (!$emissionId || !$startsAtRaw) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        /** @var Emission|null $emission */
        $emission = $emissionRepository->find($emissionId);

        if (!$emission instanceof Emission) {
            return $this->json([
                'success' => false,
                'error' => 'Émission introuvable',
            ], 404);
        }

        try {
            $startsAt = new \DateTimeImmutable($startsAtRaw);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        if (!\in_array($draftType, [
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            DiffusionDraft::TYPE_MANUAL_LIVE,
        ], true)) {
            return $this->json([
                'success' => false,
                'error' => 'Type de draft manuel invalide',
            ], 400);
        }

        $duration = null !== $durationRaw && '' !== $durationRaw
            ? (int) $durationRaw
            : (int) ($emission->getDuree() ?? 0);

        if ($duration < 1) {
            return $this->json([
                'success' => false,
                'error' => 'Durée invalide',
            ], 400);
        }

        $minute = (int) $startsAt->format('i');
        if ($minute % 15 !== 0) {
            return $this->json([
                'success' => false,
                'error' => 'L’heure doit être alignée sur un quart d’heure.',
            ], 400);
        }

        $endsAt = $startsAt->modify(sprintf('+%d minutes', $duration));
        if ($this->hasRegularBlockingOverlap(
            $startsAt,
            $endsAt,
            $programmationGridBuilder,
            $gridOccurrenceProjectionService,
            $gridConflictDetector
        )) {
            return $this->json([
                'success' => false,
                'conflict' => true,
                'error' => 'Ce créneau chevauche déjà une programmation régulière.',
            ], 409);
        }
        $overlaps = $draftRepository->findOverlappingDrafts($startsAt, $endsAt);

        if (\count($overlaps) > 0) {
            return $this->json([
                'success' => false,
                'conflict' => true,
                'error' => 'Ce créneau chevauche déjà une programmation existante.',
                'conflicts' => array_map(
                    static function (DiffusionDraft $draft): array {
                        return [
                            'id' => $draft->getId(),
                            'startsAt' => $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
                            'endsAt' => $draft->getEndsAt()?->format('Y-m-d H:i:s'),
                            'emissionTitle' => $draft->getEmission()?->getTitre() ?? 'Émission inconnue',
                            'draftType' => $draft->getDraftType(),
                            'nombreDiffusion' => $draft->getNombreDiffusion(),
                        ];
                    },
                    $overlaps
                ),
            ], 409);
        }

        $draft = new DiffusionDraft();
        $draft
            ->setEmission($emission)
            ->setDraftType($draftType)
            ->setNombreDiffusion(1)
            ->setSchedule($startsAt, $duration);

        $em->persist($draft);
        $em->flush();

        if (\in_array($draft->getDraftType(), [
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            DiffusionDraft::TYPE_MANUAL_LIVE,
        ], true)) {
            $draft->setAssignmentGroupKey(
                'manual_' . $draft->getId()
            );

            $em->flush();
        }

        return $this->json([
            'success' => true,
            'draftId' => $draft->getId(),
            'emissionId' => $emission->getId(),
            'emissionTitle' => $emission->getTitre(),
            'startsAt' => $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            'endsAt' => $draft->getEndsAt()?->format('Y-m-d H:i:s'),
            'durationMinutes' => $draft->getDurationMinutes(),
            'draftType' => $draft->getDraftType(),
        ]);
    }

    #[Route('/manual-live', name: 'manual_live_create', methods: ['POST'])]
    public function createManualLive(
        Request $request,
        CategoriesRepository $categoriesRepository,
        DiffusionDraftRepository $draftRepository,
        LiveEmissionCreator $liveEmissionCreator,
        EntityManagerInterface $em,
        ProgrammationGridBuilder $programmationGridBuilder,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector,
    ): JsonResponse {
        $categoryId = $request->request->get('categoryId');
        $startsAtRaw = $request->request->get('startsAt');

        if (!$categoryId || !$startsAtRaw) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        $category = $categoriesRepository->find($categoryId);

        if (!$category || !$category->isActive() || $category->isSoftDelete()) {
            return $this->json([
                'success' => false,
                'error' => 'Catégorie invalide',
            ], 404);
        }

        try {
            $startsAt = new \DateTimeImmutable($startsAtRaw);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        $minute = (int) $startsAt->format('i');
        if ($minute % 15 !== 0) {
            return $this->json([
                'success' => false,
                'error' => 'L’heure doit être alignée sur un quart d’heure.',
            ], 400);
        }

        try {
            $emission = $liveEmissionCreator->createManualForCategory($category, $startsAt);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        if (!$emission instanceof Emission) {
            return $this->json([
                'success' => false,
                'error' => 'Impossible de créer le direct.',
            ], 500);
        }

        $duration = (int) ($emission->getDuree() ?? 0);
        if ($duration < 1) {
            $duration = 60;
        }

        $endsAt = $startsAt->modify(sprintf('+%d minutes', $duration));
        if ($this->hasRegularBlockingOverlap(
            $startsAt,
            $endsAt,
            $programmationGridBuilder,
            $gridOccurrenceProjectionService,
            $gridConflictDetector
        )) {
            return $this->json([
                'success' => false,
                'conflict' => true,
                'error' => 'Ce créneau chevauche déjà une programmation régulière.',
            ], 409);
        }
        $overlaps = $draftRepository->findOverlappingDrafts($startsAt, $endsAt);

        if (\count($overlaps) > 0) {
            return $this->json([
                'success' => false,
                'conflict' => true,
                'error' => 'Ce créneau chevauche déjà une programmation existante.',
                'conflicts' => array_map(
                    static function (DiffusionDraft $draft): array {
                        return [
                            'id' => $draft->getId(),
                            'startsAt' => $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
                            'endsAt' => $draft->getEndsAt()?->format('Y-m-d H:i:s'),
                            'emissionTitle' => $draft->getEmission()?->getTitre() ?? 'Émission inconnue',
                            'draftType' => $draft->getDraftType(),
                            'nombreDiffusion' => $draft->getNombreDiffusion(),
                        ];
                    },
                    $overlaps
                ),
            ], 409);
        }

        $draft = new DiffusionDraft();
        $draft
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_LIVE)
            ->setNombreDiffusion(1)
            ->setSchedule($startsAt, $duration);

        $em->persist($draft);
        $em->flush();

        $draft->setAssignmentGroupKey(
            'manual_' . $draft->getId()
        );

        $em->flush();

        return $this->json([
            'success' => true,
            'draftId' => $draft->getId(),
            'emissionId' => $emission->getId(),
            'emissionTitle' => $emission->getTitre(),
            'startsAt' => $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            'endsAt' => $draft->getEndsAt()?->format('Y-m-d H:i:s'),
            'durationMinutes' => $draft->getDurationMinutes(),
            'draftType' => $draft->getDraftType(),
        ]);
    }

#[Route('/delete', name: 'delete', methods: ['POST'])]
public function delete(
    Request $request,
    DiffusionDraftRepository $draftRepository,
    EntityManagerInterface $em
): JsonResponse {
    $data = json_decode($request->getContent(), true);

    if (!\is_array($data)) {
        return $this->json([
            'success' => false,
            'error' => 'Payload JSON invalide',
        ], 400);
    }

    $draftId = $data['draftId'] ?? null;
    $deleteMode = $data['deleteMode'] ?? 'single';

    if (null === $draftId || '' === $draftId) {
        return $this->json([
            'success' => false,
            'error' => 'Paramètre draftId manquant',
        ], 400);
    }

    if (!\in_array($deleteMode, ['single', 'rebroadcasts', 'group'], true)) {
        return $this->json([
            'success' => false,
            'error' => 'Mode de suppression invalide',
        ], 400);
    }

    $draft = $draftRepository->find((int) $draftId);

    if (!$draft instanceof DiffusionDraft) {
        return $this->json([
            'success' => false,
            'error' => 'Draft introuvable',
        ], 404);
    }

    if (!\in_array($draft->getDraftType(), [
        DiffusionDraft::TYPE_MANUAL_SPECIAL,
        DiffusionDraft::TYPE_MANUAL_REBROADCAST,
        DiffusionDraft::TYPE_MANUAL_LIVE,
    ], true)) {
        return $this->json([
            'success' => false,
            'error' => 'Ce draft ne peut pas être supprimé via cette action',
        ], 403);
    }

    $draftsToDelete = [$draft];
    $groupKey = $draft->getAssignmentGroupKey();

    if ($groupKey && 'single' !== $deleteMode) {
        $groupDrafts = $draftRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        if ('rebroadcasts' === $deleteMode) {
            $draftsToDelete = array_values(array_filter(
                $groupDrafts,
                static fn (DiffusionDraft $item): bool =>
                    DiffusionDraft::TYPE_MANUAL_REBROADCAST === $item->getDraftType()
            ));
        }

        if ('group' === $deleteMode) {
            $draftsToDelete = $groupDrafts;
        }
    }

    if ([] === $draftsToDelete) {
        return $this->json([
            'success' => false,
            'error' => 'Aucun draft à supprimer.',
        ], 400);
    }

    foreach ($draftsToDelete as $item) {
        if ($item instanceof DiffusionDraft) {
            $em->remove($item);
        }
    }

    if ($groupKey && 'group' !== $deleteMode) {
        $remainingDrafts = $draftRepository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['horaireDiffusion' => 'ASC']
        );

        $rank = 1;

        foreach ($remainingDrafts as $remainingDraft) {
            if (!$remainingDraft instanceof DiffusionDraft) {
                continue;
            }

            if (\in_array($remainingDraft, $draftsToDelete, true)) {
                continue;
            }

            $remainingDraft->setNombreDiffusion($rank);
            ++$rank;
        }
    }

    $em->flush();

    return $this->json([
        'success' => true,
        'draftId' => (int) $draftId,
        'deleteMode' => $deleteMode,
        'deletedCount' => \count($draftsToDelete),
    ]);
}

    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(
        Request $request,
        DiffusionDraftRepository $draftRepository,
        EntityManagerInterface $em,
        ProgrammationGridBuilder $programmationGridBuilder,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector,
    ): JsonResponse {
        $draftId = $request->request->getInt('draftId');
        $startsAt = $request->request->get('startsAt');

        if ($draftId <= 0 || !$startsAt) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        $draft = $draftRepository->find($draftId);

        if (!$draft instanceof DiffusionDraft) {
            return $this->json([
                'success' => false,
                'error' => 'Draft introuvable.',
            ], 404);
        }

        if (!$draft->isManual()) {
            return $this->json([
                'success' => false,
                'error' => 'Seules les programmations ponctuelles peuvent être déplacées ici.',
            ], 400);
        }

        try {
            $newStartsAt = new \DateTimeImmutable($startsAt);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide.',
            ], 400);
        }

        $duration = $draft->getDurationMinutes()
            ?? $draft->getEmission()?->getDuree()
            ?? 15;

        if ($duration < 1) {
            $duration = 15;
        }

        $newEndsAt = $newStartsAt->modify(sprintf('+%d minutes', $duration));
        if ($this->hasRegularBlockingOverlap(
            $newStartsAt,
            $newEndsAt,
            $programmationGridBuilder,
            $gridOccurrenceProjectionService,
            $gridConflictDetector
        )) {
            return $this->json([
                'success' => false,
                'conflict' => true,
                'error' => 'Ce déplacement chevauche déjà une programmation régulière.',
            ], 409);
        }

        $overlappingDrafts = $draftRepository->findOverlappingDrafts(
            $newStartsAt,
            $newEndsAt,
            $draft->getId()
        );

        if (count($overlappingDrafts) > 0) {
            return $this->json([
                'success' => false,
                'error' => 'Ce déplacement chevauche déjà une autre programmation.',
            ], 409);
        }

        $draft->setSchedule($newStartsAt, $duration);

        $em->flush();

        return $this->json([
            'success' => true,
            'draftId' => $draft->getId(),
            'startsAt' => $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            'endsAt' => $draft->getEndsAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    private function hasRegularBlockingOverlap(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ProgrammationGridBuilder $programmationGridBuilder,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector
    ): bool {
        return \count($this->findRegularBlockingOverlaps(
            $startsAt,
            $endsAt,
            $programmationGridBuilder,
            $gridOccurrenceProjectionService,
            $gridConflictDetector
        )) > 0;
    }

    private function findRegularBlockingOverlaps(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ProgrammationGridBuilder $programmationGridBuilder,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector
    ): array {
        $weekStart = $this->getRadioWeekStart($startsAt);
        $weekEnd = $weekStart->modify('+7 days');

        $daySegments = $programmationGridBuilder->buildForWeek($weekStart, $weekEnd);
        $daySegments = $gridOccurrenceProjectionService->applyForWeek($daySegments, $weekStart, $weekEnd);

        return $gridConflictDetector->findBlockingOverlapsForRange(
            $daySegments,
            $startsAt,
            $endsAt
        );
    }

    private function getRadioWeekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $dayOfWeek = (int) $date->format('N');
        $daysSinceTuesday = ($dayOfWeek + 5) % 7;

        return $date
            ->modify(sprintf('-%d days', $daysSinceTuesday))
            ->setTime(0, 0);
    }

    #[Route('/rebroadcasts', name: 'rebroadcasts_create', methods: ['POST'])]
    public function createRebroadcasts(
        Request $request,
        DiffusionDraftRepository $draftRepository,
        EntityManagerInterface $em,
        ProgrammationGridBuilder $programmationGridBuilder,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return $this->json([
                'success' => false,
                'error' => 'Payload JSON invalide.',
            ], 400);
        }

        $draftId = (int) ($data['draftId'] ?? 0);
        $rebroadcasts = $data['rebroadcasts'] ?? [];

        if ($draftId <= 0 || !\is_array($rebroadcasts) || [] === $rebroadcasts) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants.',
            ], 400);
        }

        $parentDraft = $draftRepository->find($draftId);

        if (!$parentDraft instanceof DiffusionDraft || !$parentDraft->isManual()) {
            return $this->json([
                'success' => false,
                'error' => 'Programmation ponctuelle introuvable.',
            ], 404);
        }

        if (DiffusionDraft::TYPE_MANUAL_REBROADCAST === $parentDraft->getDraftType()) {
            return $this->json([
                'success' => false,
                'error' => 'Impossible d’ajouter des rediffs depuis une rediffusion.',
            ], 400);
        }

        $emission = $parentDraft->getEmission();

        if (!$emission instanceof Emission) {
            return $this->json([
                'success' => false,
                'error' => 'Émission introuvable.',
            ], 404);
        }

        $duration = $parentDraft->getDurationMinutes()
            ?? $emission->getDuree()
            ?? 15;

        if ($duration < 1) {
            $duration = 15;
        }

        $groupKey = $parentDraft->getAssignmentGroupKey();

        if (!$groupKey) {
            $groupKey = 'manual_' . $parentDraft->getId();
            $parentDraft->setAssignmentGroupKey($groupKey);
        }

        $existingGroupDrafts = $draftRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        $maxNombreDiffusion = 1;

        foreach ($existingGroupDrafts as $groupDraft) {
            if ($groupDraft instanceof DiffusionDraft) {
                $maxNombreDiffusion = max(
                    $maxNombreDiffusion,
                    (int) $groupDraft->getNombreDiffusion()
                );
            }
        }

        $createdDrafts = [];

        foreach ($rebroadcasts as $rawStartsAt) {
            if (!\is_string($rawStartsAt) || '' === trim($rawStartsAt)) {
                continue;
            }

            try {
                $startsAt = new \DateTimeImmutable($rawStartsAt);
            } catch (\Exception) {
                return $this->json([
                    'success' => false,
                    'error' => 'Date de rediffusion invalide.',
                ], 400);
            }

            $minute = (int) $startsAt->format('i');

            if ($minute % 15 !== 0) {
                return $this->json([
                    'success' => false,
                    'error' => 'Les heures doivent être alignées sur un quart d’heure.',
                ], 400);
            }

            $endsAt = $startsAt->modify(sprintf('+%d minutes', $duration));

            $regularOverlaps = $this->findRegularBlockingOverlaps(
                $startsAt,
                $endsAt,
                $programmationGridBuilder,
                $gridOccurrenceProjectionService,
                $gridConflictDetector
            );

            if (\count($regularOverlaps) > 0) {
                return $this->json([
                    'success' => false,
                    'conflict' => true,
                    'error' => 'Une rediffusion chevauche déjà une programmation régulière.',
                    'debug' => $regularOverlaps,
                ], 409);
            }

            $overlappingDrafts = $draftRepository->findOverlappingDrafts(
                $startsAt,
                $endsAt
            );

            if (\count($overlappingDrafts) > 0) {
                return $this->json([
                    'success' => false,
                    'conflict' => true,
                    'error' => 'Une rediffusion chevauche déjà une programmation ponctuelle.',
                ], 409);
            }

            $maxNombreDiffusion++;

            $draft = new DiffusionDraft();
            $draft
                ->setEmission($emission)
                ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
                ->setNombreDiffusion($maxNombreDiffusion)
                ->setAssignmentGroupKey($groupKey)
                ->setSchedule($startsAt, $duration);

            $em->persist($draft);
            $createdDrafts[] = $draft;
        }

        if ([] === $createdDrafts) {
            return $this->json([
                'success' => false,
                'error' => 'Aucune rediffusion valide à créer.',
            ], 400);
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'createdCount' => \count($createdDrafts),
            'assignmentGroupKey' => $groupKey,
        ]);
    }

    #[Route('/{draftId}/rebroadcasts', name: 'rebroadcasts_list', methods: ['GET'])]
    public function listRebroadcasts(
        int $draftId,
        DiffusionDraftRepository $draftRepository
    ): JsonResponse {
        $draft = $draftRepository->find($draftId);

        if (!$draft instanceof DiffusionDraft) {
            return $this->json([
                'items' => [],
            ], 404);
        }

        $groupKey = $draft->getAssignmentGroupKey();

        if (!$groupKey) {
            return $this->json([
                'items' => [],
            ]);
        }

        $groupDrafts = $draftRepository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['nombreDiffusion' => 'ASC']
        );

        $items = [];

        foreach ($groupDrafts as $item) {
            if (
                !$item instanceof DiffusionDraft ||
                DiffusionDraft::TYPE_MANUAL_REBROADCAST !== $item->getDraftType()
            ) {
                continue;
            }

            $items[] = [
                'id' => $item->getId(),
                'startsAt' => $item->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
                'endsAt' => $item->getEndsAt()?->format('Y-m-d H:i:s'),
                'number' => max(
                    1,
                    ((int) $item->getNombreDiffusion()) - 1
                ),
            ];
        }

        return $this->json([
            'items' => $items,
        ]);
    }

    #[Route('/{draftId}/group', name: 'group', methods: ['GET'])]
    public function group(
        int $draftId,
        DiffusionDraftRepository $draftRepository
    ): JsonResponse {
        $draft = $draftRepository->find($draftId);

        if (!$draft instanceof DiffusionDraft) {
            return $this->json([
                'success' => false,
                'error' => 'Draft introuvable.',
                'items' => [],
            ], 404);
        }

        $groupKey = $draft->getAssignmentGroupKey();

        if (!$groupKey) {
            return $this->json([
                'success' => true,
                'assignmentGroupKey' => null,
                'items' => [[
                    'id' => $draft->getId(),
                    'label' => $this->buildDraftGroupLabel($draft),
                    'draftType' => $draft->getDraftType(),
                    'nombreDiffusion' => $draft->getNombreDiffusion(),
                    'startsAt' => $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
                    'endsAt' => $draft->getEndsAt()?->format('Y-m-d H:i:s'),
                ]],
            ]);
        }

        $groupDrafts = $draftRepository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['nombreDiffusion' => 'ASC']
        );

        $items = [];

        foreach ($groupDrafts as $item) {
            if (!$item instanceof DiffusionDraft) {
                continue;
            }

            $items[] = [
                'id' => $item->getId(),
                'label' => $this->buildDraftGroupLabel($item),
                'draftType' => $item->getDraftType(),
                'nombreDiffusion' => $item->getNombreDiffusion(),
                'startsAt' => $item->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
                'endsAt' => $item->getEndsAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json([
            'success' => true,
            'assignmentGroupKey' => $groupKey,
            'items' => $items,
        ]);
    }

    private function buildDraftGroupLabel(DiffusionDraft $draft): string
    {
        $nombreDiffusion = (int) ($draft->getNombreDiffusion() ?? 1);

        if ($nombreDiffusion <= 1) {
            return '1re diffusion';
        }

        return sprintf(
            'Rediffusion %d',
            $nombreDiffusion - 1
        );
    }
}
