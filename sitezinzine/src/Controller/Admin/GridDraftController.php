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

#[Route('/admin/grid-drafts', name: 'admin.grid_draft.')]
#[IsGranted('ROLE_ADMIN')]
class GridDraftController extends AbstractController
{
    #[Route('/manual', name: 'manual_create', methods: ['POST'])]
    public function createManual(
        Request $request,
        EmissionRepository $emissionRepository,
        DiffusionDraftRepository $draftRepository,
        EntityManagerInterface $em
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
        EntityManagerInterface $em
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

        if (null === $draftId || '' === $draftId) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètre draftId manquant',
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

        $em->remove($draft);
        $em->flush();

        return $this->json([
            'success' => true,
            'draftId' => (int) $draftId,
        ]);
    }

    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(
        Request $request,
        DiffusionDraftRepository $draftRepository,
        EntityManagerInterface $em
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
}
