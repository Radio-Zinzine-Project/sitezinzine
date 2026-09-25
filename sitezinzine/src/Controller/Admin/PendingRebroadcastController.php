<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\DiffusionDraftRepository;
use App\Repository\PendingRebroadcastRepository;
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
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        DiffusionDraftRepository $draftRepository,
        PendingRebroadcastRepository $pendingRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return $this->json([
                'success' => false,
                'error' => 'Payload JSON invalide.',
            ], 400);
        }

        $draftId = (int) ($data['draftId'] ?? 0);

        if ($draftId <= 0) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètre draftId manquant.',
            ], 400);
        }

        $draft = $draftRepository->find($draftId);

        if (
            !$draft instanceof DiffusionDraft
            || DiffusionDraft::TYPE_REGULAR !== $draft->getDraftType()
            || 1 !== $draft->getNombreDiffusion()
        ) {
            return $this->json([
                'success' => false,
                'error' => 'Première diffusion régulière introuvable.',
            ], 404);
        }

        $assignmentGroupKey = $draft->getAssignmentGroupKey();

        if (!$assignmentGroupKey) {
            return $this->json([
                'success' => false,
                'error' => 'Cette diffusion régulière ne possède pas de groupe.',
            ], 400);
        }

        $emission = $draft->getEmission();

        if (!$emission instanceof Emission) {
            return $this->json([
                'success' => false,
                'error' => 'Émission introuvable.',
            ], 404);
        }

        /*
         * L'ajout direct depuis la grille n'est autorisé qu'une fois
         * pour un même groupe.
         *
         * Les occurrences supplémentaires sont créées explicitement
         * via l'action "Dupliquer" du parc.
         */
        if ($pendingRepository->existsForAssignmentGroupKey($assignmentGroupKey)) {
            return $this->json([
                'success' => false,
                'error' => 'Ce groupe possède déjà une rediffusion dans le parc.',
            ], 409);
        }

        $pendingRebroadcast = new PendingRebroadcast();

        $pendingRebroadcast
            ->setEmission($emission)
            ->setAssignmentGroupKey($assignmentGroupKey);

        $entityManager->persist($pendingRebroadcast);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'pendingRebroadcastId' => $pendingRebroadcast->getId(),
            'assignmentGroupKey' => $assignmentGroupKey,
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