<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Entity\Diffusion;
use App\Repository\PendingRebroadcastRepository;
use App\Repository\DiffusionRepository;
use App\Repository\DiffusionDraftRepository;
use App\Repository\EmissionRepository;
use App\Service\RegularRebroadcastPlacementService;
use App\Service\RegularPendingVisibility;
use App\Service\NonRegularPendingVisibility;
use App\Service\NonRegularGroupMutation;
use App\Service\PendingRebroadcastService;
use App\Service\RadioWeek;
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
        DiffusionRepository $diffusionRepository,
        DiffusionDraftRepository $diffusionDraftRepository,
        EmissionRepository $emissionRepository,
        Request $request,
        RegularPendingVisibility $visibility,
        NonRegularPendingVisibility $nonRegularVisibility
    ): JsonResponse {
        $weekStart = null;
        if ($request->query->has('weekStart')) {
            $raw = $request->query->all()['weekStart'];
            $date = \is_string($raw) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $raw)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('Europe/Paris')) : false;
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                return $this->json(['success' => false, 'error' => 'Semaine affichée invalide.', 'count' => 0, 'items' => []], 400);
            }
            $weekStart = RadioWeek::start($date);
        }
        // Sans contexte explicite, conserver intégralement l'ancien affichage.
        $pendingRebroadcasts = $pendingRepository->findAllForPool();
        $groupKeys = array_values(array_unique(array_map(
            static fn(PendingRebroadcast $pending): string => $pending->getAssignmentGroupKey(), $pendingRebroadcasts
        )));
        $draftsByGroup = [];
        foreach ($diffusionDraftRepository->findForPoolByGroupKeys($groupKeys) as $draft) {
            $draftsByGroup[$draft->getAssignmentGroupKey()][] = $draft;
        }
        $diffusionsByGroup = [];
        foreach ($diffusionRepository->findForPoolByGroupKeys($groupKeys) as $diffusion) {
            $diffusionsByGroup[$diffusion->getAssignmentGroupKey()][] = $diffusion;
        }
        if ($weekStart !== null) {
            $pendingRebroadcasts = $visibility->filter($pendingRebroadcasts, $weekStart, $draftsByGroup, $diffusionsByGroup);
            $pendingRebroadcasts = $nonRegularVisibility->filter($pendingRebroadcasts, $weekStart, $draftsByGroup, $diffusionsByGroup);
        }

        $items = [];

        /*
     * 1. Rediffusions régulières existantes.
     *
     * On conserve leur fonctionnement et leur historique
     * de groupe sans modifier les PendingRebroadcast.
     */
        foreach ($pendingRebroadcasts as $pendingRebroadcast) {
            $emission = $pendingRebroadcast->getEmission();

            if (!$emission instanceof Emission) {
                continue;
            }

            $category = $emission->getCategorie();
            $durationMinutes = $emission->getDuree();

            if (!\is_int($durationMinutes) || $durationMinutes <= 0) {
                $durationMinutes = 15;
            }

            $assignmentGroupKey = $pendingRebroadcast->getAssignmentGroupKey();
            $groupDiffusions = [];


            if ('' !== trim($assignmentGroupKey)) {
                $diffusionIndexes = [];
                $groupDiffusionIds = [];

                foreach ($diffusionsByGroup[$assignmentGroupKey] ?? [] as $diffusion) {
                    if ($diffusion->getEmission()?->getId() !== $emission->getId()) {
                        continue;
                    }

                    $horaire = $diffusion->getHoraireDiffusion();

                    if (!$horaire instanceof \DateTimeInterface) {
                        continue;
                    }

                    $diffusionId = $diffusion->getId();
                    $index = count($groupDiffusions);

                    $groupDiffusions[] = [
                        'date' => $horaire->format('Y-m-d H:i:s'),
                        'number' => $diffusion->getNombreDiffusion(),
                    ];

                    if (null !== $diffusionId) {
                        $diffusionIndexes[$diffusionId] = $index;
                        $groupDiffusionIds[$diffusionId] = $diffusion;
                    }
                }

                foreach ($draftsByGroup[$assignmentGroupKey] ?? [] as $draft) {
                    if (
                        $draft->isDeleted()
                        || !$draft->isDraft()
                        || $draft->getEmission()?->getId() !== $emission->getId()
                    ) {
                        continue;
                    }

                    $horaire = $draft->getHoraireDiffusion();

                    if (!$horaire instanceof \DateTimeInterface) {
                        continue;
                    }

                    $publishedDiffusion = $draft->getPublishedDiffusion();

                    // Un lien vers une ancienne émission ne représente pas
                    // le passage actuel du draft de cette émission.
                    if (
                        null !== $publishedDiffusion
                        && $publishedDiffusion->getEmission()?->getId() === $emission->getId()
                    ) {
                        $diffusionId = $publishedDiffusion->getId();

                        // On ne remplace que les diffusions dévalidées
                        // appartenant au groupe actuellement affiché.
                        if (
                            null !== $diffusionId
                            && isset($diffusionIndexes[$diffusionId])
                            && $groupDiffusionIds[$diffusionId]
                            ->getPublicationStatus() === Diffusion::STATUS_UNPUBLISHED
                        ) {
                            $groupDiffusions[$diffusionIndexes[$diffusionId]] = [
                                'date' => $horaire->format('Y-m-d H:i:s'),
                                'number' => $draft->getNombreDiffusion(),
                            ];
                        }

                        // Une Diffusion liée ne doit jamais apparaître deux fois.
                        continue;
                    }

                    $groupDiffusions[] = [
                        'date' => $horaire->format('Y-m-d H:i:s'),
                        'number' => $draft->getNombreDiffusion(),
                    ];
                }

                usort(
                    $groupDiffusions,
                    static function (array $left, array $right): int {
                        $dateComparison = strcmp(
                            $left['date'],
                            $right['date']
                        );

                        if (0 !== $dateComparison) {
                            return $dateComparison;
                        }

                        return ($left['number'] ?? 0)
                            <=> ($right['number'] ?? 0);
                    }
                );
            }


            $items[] = [
                'type' => 'pending_rebroadcast',
                'id' => $pendingRebroadcast->getId(),
                'emissionId' => $emission->getId(),
                'title' => $emission->getTitre(),
                'category' => $category?->getTitre(),
                'durationMinutes' => $durationMinutes,
                'assignmentGroupKey' => $assignmentGroupKey,
                'createdAt' => $pendingRebroadcast
                    ->getCreatedAt()
                    ->format(\DateTimeInterface::ATOM),
                'previousDiffusions' => $groupDiffusions,
            ];
        }

        /*
     * 2. Émissions non régulières récentes.
     *
     * Leur présence dans le parc est calculée à la lecture.
     * Aucun PendingRebroadcast n'est créé.
     */
        $nonRegularEmissions = $emissionRepository
            ->findRecentNonRegularEmissionsForPool(
                new \DateTimeImmutable()
            );

        foreach ($nonRegularEmissions as $emission) {
            $durationMinutes = $emission->getDuree();

            if (!\is_int($durationMinutes) || $durationMinutes <= 0) {
                $durationMinutes = 15;
            }

            $items[] = [
                'type' => 'non_regular_emission',
                'id' => null,
                'emissionId' => $emission->getId(),
                'title' => $emission->getTitre(),
                'category' => $emission->getCategorie()?->getTitre(),
                'durationMinutes' => $durationMinutes,
                'assignmentGroupKey' => null,
                'createdAt' => $emission->getDatepub()
                    ?->format(\DateTimeInterface::ATOM),
                'previousDiffusions' => [],
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
        if (str_starts_with($pendingRebroadcast->getAssignmentGroupKey(), 'rule_')) {
            return $this->json([
                'success' => false,
                'error' => 'Un groupe régulier ne peut recevoir qu’une seule proposition supplémentaire.',
            ], 409);
        }

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
        RegularRebroadcastPlacementService $placementService,
        PendingRebroadcastService $pendingService,
        EntityManagerInterface $entityManager,
        NonRegularGroupMutation $groupMutation
    ): JsonResponse {

        if (!$pendingRebroadcast->isPending()) {
            return $this->json([
                'success' => false,
                'error' => 'Cette rediffusion n’est plus disponible dans le parc.',
            ], 400);
        }
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
            if (str_starts_with($pendingRebroadcast->getAssignmentGroupKey(), 'rule_')) {
                $draft = $placementService->place($pendingRebroadcast, $startsAt);
            } else {
                $emissionId = $pendingRebroadcast->getEmission()->getId();
                $groupKey = $pendingRebroadcast->getAssignmentGroupKey();
                $draft = $groupMutation->run($emissionId, $groupKey, function () use (
                    $pendingRebroadcast, $startsAt, $placementService, $pendingService, $entityManager, $emissionId, $groupKey
                ) {
                    $connection = $entityManager->getConnection();
                    $current = $connection->fetchAssociative('SELECT status, emission_id, assignment_group_key FROM pending_rebroadcast WHERE id = ? FOR UPDATE', [$pendingRebroadcast->getId()]);
                    if ($current === false || $current['status'] !== PendingRebroadcast::STATUS_PENDING
                        || (int) $current['emission_id'] !== $emissionId || $current['assignment_group_key'] !== $groupKey) {
                        throw new \DomainException('Cette rediffusion n’est plus disponible dans le parc.');
                    }
                    $draft = $placementService->place($pendingRebroadcast, $startsAt);
                    $pendingService->proposeNextForNonRegularGroup($draft->getEmission(), $draft->getAssignmentGroupKey());
                    $entityManager->flush();
                    return $draft;
                });
            }
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
        $pendingRebroadcast->dismiss();
        $entityManager->flush();

        return $this->json([
            'success' => true,
        ]);
    }
}
