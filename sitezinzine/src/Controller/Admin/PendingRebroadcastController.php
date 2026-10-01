<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\PendingRebroadcastRepository;
use App\Repository\DiffusionRepository;
use App\Service\RegularRebroadcastPlacementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/pending-rebroadcasts', name: 'admin.pending_rebroadcast.')]
#[IsGranted('ROLE_ADMIN')]
class PendingRebroadcastController extends AbstractController
{

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        PendingRebroadcastRepository $pendingRepository,
        DiffusionRepository $diffusionRepository
    ): JsonResponse {
        $pendingRebroadcasts = $pendingRepository->findAllForPool();

        $items = [];

        foreach ($pendingRebroadcasts as $pendingRebroadcast) {
            $emission = $pendingRebroadcast->getEmission();

            if (!$emission instanceof Emission) {
                continue;
            }

            $category = $emission->getCategorie();

            $durationMinutes = $emission->getDuree();

            if (
                !\is_int($durationMinutes)
                || $durationMinutes <= 0
            ) {
                $durationMinutes = 15;
            }

            $assignmentGroupKey =
                $pendingRebroadcast->getAssignmentGroupKey();

            $previousDiffusions = [];

            if (
                '' !== trim($assignmentGroupKey)
            ) {
                foreach (
                    $diffusionRepository->findPublishedByAssignmentGroupKey(
                        $assignmentGroupKey
                    ) as $diffusion
                ) {
                    $horaire = $diffusion->getHoraireDiffusion();

                    if (!$horaire instanceof \DateTimeInterface) {
                        continue;
                    }

                    $previousDiffusions[] = [
                        'date' => $horaire->format('Y-m-d H:i:s'),
                        'number' => $diffusion->getNombreDiffusion(),
                    ];
                }
            }

            $items[] = [
                'id' => $pendingRebroadcast->getId(),
                'emissionId' => $emission->getId(),
                'title' => $emission->getTitre(),
                'category' => $category?->getTitre(),
                'durationMinutes' => $durationMinutes,
                'assignmentGroupKey' => $assignmentGroupKey,
                'createdAt' => $pendingRebroadcast
                    ->getCreatedAt()
                    ->format(\DateTimeInterface::ATOM),
                'previousDiffusions' => $previousDiffusions,
            ];
        }

        return $this->json([
            'success' => true,
            'count' => \count($items),
            'items' => $items,
        ]);
    }

    #[Route('/{id}/duplicate', name: 'duplicate', methods: ['POST'])]
    public function duplicate(
        PendingRebroadcast $pendingRebroadcast,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $emission = $pendingRebroadcast->getEmission();

        if (!$emission instanceof Emission) {
            return $this->json([
                'success' => false,
                'error' => 'Émission introuvable.',
            ], 404);
        }

        $duplicate = new PendingRebroadcast();

        $duplicate
            ->setEmission($emission)
            ->setAssignmentGroupKey(
                $pendingRebroadcast->getAssignmentGroupKey()
            );

        $entityManager->persist($duplicate);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'pendingRebroadcastId' => $duplicate->getId(),
            'assignmentGroupKey' => $duplicate->getAssignmentGroupKey(),
        ]);
    }

    #[Route('/{id}/place', name: 'place', methods: ['POST'])]
    public function place(
        PendingRebroadcast $pendingRebroadcast,
        Request $request,
        RegularRebroadcastPlacementService $placementService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return $this->json([
                'success' => false,
                'error' => 'Payload JSON invalide.',
            ], 400);
        }

        $startsAtRaw = $data['startsAt'] ?? null;

        if (!\is_string($startsAtRaw) || '' === trim($startsAtRaw)) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètre startsAt manquant.',
            ], 400);
        }

        try {
            $startsAt = new \DateTimeImmutable($startsAtRaw);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date de rediffusion invalide.',
            ], 400);
        }

        try {
            $draft = $placementService->place(
                $pendingRebroadcast,
                $startsAt
            );
        } catch (\DomainException $exception) {
            return $this->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 400);
        }

        return $this->json([
            'success' => true,
            'draftId' => $draft->getId(),
            'assignmentGroupKey' => $draft->getAssignmentGroupKey(),
            'startsAt' => $draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s'),
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(
        PendingRebroadcast $pendingRebroadcast,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $entityManager->remove($pendingRebroadcast);
        $entityManager->flush();

        return $this->json([
            'success' => true,
        ]);
    }
}
