<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Repository\CategoriesRepository;
use App\Repository\DiffusionDraftRepository;
use App\Repository\EmissionRepository;
use App\Service\LiveEmissionCreator;
use App\Service\GridPlacementConflictService;
use App\Service\PendingRebroadcastService;
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
        EntityManagerInterface $em,
        GridPlacementConflictService $placementConflictService
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
        if ($placementConflictService->hasBlockingRegularOverlap(
            $startsAt,
            $endsAt
        )) {
            return $this->json([
                'success' => false,
                'conflict' => true,
                'error' => 'Ce créneau chevauche déjà une programmation régulière.',
            ], 409);
        }
        $overlaps = $placementConflictService->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt
        );

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
        LiveEmissionCreator $liveEmissionCreator,
        EntityManagerInterface $em,
        GridPlacementConflictService $placementConflictService
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

        $duration = (int) ($emission->getDuree() ?? 0);
        if ($duration < 1) {
            $duration = 60;
        }

        $endsAt = $startsAt->modify(sprintf('+%d minutes', $duration));
        if ($placementConflictService->hasBlockingRegularOverlap(
            $startsAt,
            $endsAt
        )) {
            return $this->json([
                'success' => false,
                'conflict' => true,
                'error' => 'Ce créneau chevauche déjà une programmation régulière.',
            ], 409);
        }
        $overlaps = $placementConflictService->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt
        );

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
        PendingRebroadcastService $pendingRebroadcastService,
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

        if (!\in_array(
            $deleteMode,
            ['single', 'rebroadcasts', 'group'],
            true
        )) {
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
        $groupDrafts = [];
        $groupHasRegularDrafts = false;

        /*
     * Le groupe doit être chargé même pour une suppression "single".
     *
     * Cela permet :
     * - de savoir si une rediffusion appartient à un groupe régulier ;
     * - de décider si elle doit retourner dans le Parc à rediff ;
     * - de renuméroter correctement les rediffusions restantes.
     */
        if ($groupKey) {
            $groupDrafts = $draftRepository->findBy([
                'assignmentGroupKey' => $groupKey,
            ]);

            $groupHasRegularDrafts = \count(array_filter(
                $groupDrafts,
                static fn(DiffusionDraft $item): bool =>
                DiffusionDraft::TYPE_REGULAR === $item->getDraftType()
            )) > 0;
        }

        if ($groupKey && 'single' !== $deleteMode) {
            if ('rebroadcasts' === $deleteMode) {
                $draftsToDelete = array_values(array_filter(
                    $groupDrafts,
                    static fn(DiffusionDraft $item): bool =>
                    DiffusionDraft::TYPE_MANUAL_REBROADCAST
                        === $item->getDraftType()
                ));
            }

            if ('group' === $deleteMode) {
                /*
             * Un groupe issu d'une programmation régulière ne doit jamais
             * perdre ses diffusions régulières depuis cette action.
             *
             * "Supprimer le groupe" depuis une rediffusion ponctuelle
             * signifie donc supprimer toutes les rediffusions manuelles
             * ajoutées à ce groupe.
             *
             * Pour un groupe entièrement manuel, le comportement historique
             * est conservé : tout le groupe est supprimé.
             */
                if ($groupHasRegularDrafts) {
                    $draftsToDelete = array_values(array_filter(
                        $groupDrafts,
                        static fn(DiffusionDraft $item): bool =>
                        DiffusionDraft::TYPE_MANUAL_REBROADCAST
                            === $item->getDraftType()
                    ));
                } else {
                    $draftsToDelete = $groupDrafts;
                }
            }
        }

        if ([] === $draftsToDelete) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun draft à supprimer.',
            ], 400);
        }

        /*
     * Une rediffusion retirée individuellement d'un groupe régulier
     * retourne dans le Parc à rediff.
     *
     * Les groupes entièrement manuels ne sont pas concernés.
     *
     * Les suppressions explicites "rebroadcasts" et "group" ne recréent
     * pas de PendingRebroadcast.
     */
        if (
            'single' === $deleteMode
            && DiffusionDraft::TYPE_MANUAL_REBROADCAST === $draft->getDraftType()
            && $groupHasRegularDrafts
            && null !== $groupKey
        ) {
            $emission = $draft->getEmission();

            if ($emission instanceof Emission) {
                $pendingRebroadcastService->createForGroup(
                    $emission,
                    $groupKey
                );
            }
        }

        foreach ($draftsToDelete as $item) {
            if ($item instanceof DiffusionDraft) {
                $em->remove($item);
            }
        }

        /*
     * Si le groupe continue d'exister, on renumérote ce qui reste.
     *
     * Les entités passées à remove() sont encore visibles par les requêtes
     * Doctrine tant que le flush n'a pas eu lieu. On transmet donc
     * explicitement la liste des Drafts en cours de suppression afin
     * qu'ils soient ignorés pendant la renumérotation.
     */
        if ($groupKey) {
            if ($groupHasRegularDrafts) {
                $this->renumberRegularGroupManualRebroadcasts(
                    $groupKey,
                    $draftRepository,
                    $draftsToDelete
                );
            } elseif ('group' !== $deleteMode) {
                $remainingDrafts = $draftRepository->findBy(
                    ['assignmentGroupKey' => $groupKey],
                    ['horaireDiffusion' => 'ASC']
                );

                $rank = 1;

                foreach ($remainingDrafts as $remainingDraft) {
                    if (\in_array($remainingDraft, $draftsToDelete, true)) {
                        continue;
                    }

                    $remainingDraft->setNombreDiffusion($rank);
                    ++$rank;
                }
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
        GridPlacementConflictService $placementConflictService
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

        $groupKey = $draft->getAssignmentGroupKey();
        $isRegularOriginGroup = false;

        /*
     * Une rediffusion ponctuelle appartenant à un groupe régulier
     * doit toujours rester après toutes les diffusions régulières
     * de ce groupe.
     */
        if (
            DiffusionDraft::TYPE_MANUAL_REBROADCAST === $draft->getDraftType()
            && $groupKey
        ) {
            $groupDrafts = $draftRepository->findByAssignmentGroupKey(
                $groupKey
            );

            $lastRegularEndsAt = null;

            foreach ($groupDrafts as $groupDraft) {
                if (
                    !$groupDraft instanceof DiffusionDraft
                    || DiffusionDraft::TYPE_REGULAR !== $groupDraft->getDraftType()
                ) {
                    continue;
                }

                $isRegularOriginGroup = true;

                $regularEndsAt = $groupDraft->getEndsAt();

                if (
                    $regularEndsAt instanceof \DateTimeImmutable
                    && (
                        null === $lastRegularEndsAt
                        || $regularEndsAt > $lastRegularEndsAt
                    )
                ) {
                    $lastRegularEndsAt = $regularEndsAt;
                }
            }

            if (
                $lastRegularEndsAt instanceof \DateTimeImmutable
                && $newStartsAt < $lastRegularEndsAt
            ) {
                return $this->json([
                    'success' => false,
                    'error' => 'Une rediffusion ponctuelle doit être placée après toutes les diffusions régulières du groupe.',
                ], 400);
            }
        }

        $duration = $draft->getDurationMinutes()
            ?? $draft->getEmission()?->getDuree()
            ?? 15;

        if ($duration < 1) {
            $duration = 15;
        }

        $newEndsAt = $newStartsAt->modify(
            sprintf('+%d minutes', $duration)
        );

        if ($placementConflictService->hasBlockingRegularOverlap(
            $newStartsAt,
            $newEndsAt
        )) {
            return $this->json([
                'success' => false,
                'conflict' => true,
                'error' => 'Ce déplacement chevauche déjà une programmation régulière.',
            ], 409);
        }

        $overlappingDrafts = $placementConflictService->findBlockingDraftOverlaps(
            $newStartsAt,
            $newEndsAt,
            $draft->getId()
        );

        if (\count($overlappingDrafts) > 0) {
            return $this->json([
                'success' => false,
                'error' => 'Ce déplacement chevauche déjà une autre programmation.',
            ], 409);
        }

        $draft->setSchedule($newStartsAt, $duration);

        $em->flush();

        /*
     * Deux politiques différentes :
     *
     * - groupe d'origine régulière :
     *   les rangs réguliers restent intacts et seules les
     *   rediffusions ponctuelles sont renumérotées ;
     *
     * - groupe entièrement ponctuel :
     *   on conserve la renumérotation historique du groupe.
     */
        if ($groupKey) {
            if ($isRegularOriginGroup) {
                $this->renumberRegularGroupManualRebroadcasts(
                    $groupKey,
                    $draftRepository
                );
            } else {
                $this->renumberDraftGroupChronologically(
                    $groupKey,
                    $draftRepository
                );
            }
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'draftId' => $draft->getId(),
            'startsAt' => $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            'endsAt' => $draft->getEndsAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    #[Route('/regular-rebroadcasts', name: 'regular_rebroadcasts_create', methods: ['POST'])]
    public function createRegularRebroadcasts(
        Request $request,
        DiffusionDraftRepository $draftRepository,
        EntityManagerInterface $em,
        GridPlacementConflictService $placementConflictService
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

        if (
            $draftId <= 0
            || !\is_array($rebroadcasts)
            || [] === $rebroadcasts
        ) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants.',
            ], 400);
        }

        $parentDraft = $draftRepository->find($draftId);

        if (
            !$parentDraft instanceof DiffusionDraft
            || DiffusionDraft::TYPE_REGULAR !== $parentDraft->getDraftType()
            || 1 !== $parentDraft->getNombreDiffusion()
        ) {
            return $this->json([
                'success' => false,
                'error' => 'Première diffusion régulière introuvable.',
            ], 404);
        }

        $groupKey = $parentDraft->getAssignmentGroupKey();

        if (!$groupKey) {
            return $this->json([
                'success' => false,
                'error' => 'Cette diffusion régulière ne possède pas de groupe.',
            ], 400);
        }

        $emission = $parentDraft->getEmission();

        if (!$emission instanceof Emission) {
            return $this->json([
                'success' => false,
                'error' => 'Émission introuvable.',
            ], 404);
        }

        $duration = (int) (
            $parentDraft->getDurationMinutes()
            ?? $emission->getDuree()
            ?? 15
        );

        if ($duration < 1) {
            $duration = 15;
        }

        $groupDrafts = $draftRepository->findByAssignmentGroupKey(
            $groupKey
        );

        $maxNombreDiffusion = 0;
        $lastRegularEndsAt = null;

        foreach ($groupDrafts as $groupDraft) {
            if (!$groupDraft instanceof DiffusionDraft) {
                continue;
            }

            $maxNombreDiffusion = max(
                $maxNombreDiffusion,
                (int) $groupDraft->getNombreDiffusion()
            );

            if (DiffusionDraft::TYPE_REGULAR !== $groupDraft->getDraftType()) {
                continue;
            }

            $regularEndsAt = $groupDraft->getEndsAt();

            if (
                $regularEndsAt instanceof \DateTimeImmutable
                && (
                    null === $lastRegularEndsAt
                    || $regularEndsAt > $lastRegularEndsAt
                )
            ) {
                $lastRegularEndsAt = $regularEndsAt;
            }
        }

        $createdDrafts = [];

        foreach ($rebroadcasts as $rawStartsAt) {
            if (
                !\is_string($rawStartsAt)
                || '' === trim($rawStartsAt)
            ) {
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

            if (
                $lastRegularEndsAt instanceof \DateTimeImmutable
                && $startsAt < $lastRegularEndsAt
            ) {
                return $this->json([
                    'success' => false,
                    'error' => 'Une rediffusion ponctuelle doit être placée après toutes les diffusions régulières du groupe.',
                ], 400);
            }

            $endsAt = $startsAt->modify(
                sprintf('+%d minutes', $duration)
            );

            $regularOverlaps = $placementConflictService->findBlockingRegularOverlaps(
                $startsAt,
                $endsAt
            );

            if (\count($regularOverlaps) > 0) {
                return $this->json([
                    'success' => false,
                    'conflict' => true,
                    'error' => 'Une rediffusion chevauche déjà une programmation régulière.',
                    'debug' => $regularOverlaps,
                ], 409);
            }

            $overlappingDrafts = $placementConflictService->findBlockingDraftOverlaps(
                $startsAt,
                $endsAt
            );

            if (\count($overlappingDrafts) > 0) {
                return $this->json([
                    'success' => false,
                    'conflict' => true,
                    'error' => 'Une rediffusion chevauche déjà une programmation existante.',
                ], 409);
            }

            ++$maxNombreDiffusion;

            $draft = new DiffusionDraft();

            $draft
                ->setEmission($emission)
                ->setDraftType(
                    DiffusionDraft::TYPE_MANUAL_REBROADCAST
                )
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

        /*
     * On enregistre d'abord les nouvelles rediffusions afin qu'elles
     * soient incluses dans la requête de renumérotation du groupe.
     */
        $em->flush();

        /*
     * Les rangs réguliers restent intacts.
     * Seules les rediffusions ponctuelles sont renumérotées
     * chronologiquement après les régulières.
     */
        $this->renumberRegularGroupManualRebroadcasts(
            $groupKey,
            $draftRepository
        );

        $em->flush();

        return $this->json([
            'success' => true,
            'createdCount' => \count($createdDrafts),
            'assignmentGroupKey' => $groupKey,
        ]);
    }

    private function renumberRegularGroupManualRebroadcasts(
        string $assignmentGroupKey,
        DiffusionDraftRepository $draftRepository,
        array $draftsToIgnore = []
    ): void {
        $groupDrafts = $draftRepository->findBy(
            ['assignmentGroupKey' => $assignmentGroupKey],
            ['horaireDiffusion' => 'ASC']
        );

        /*
     * On travaille avec les identifiants et non avec l'identité
     * des objets Doctrine.
     *
     * Les Drafts passés à EntityManager::remove() existent encore
     * en base jusqu'au flush final et peuvent donc toujours être
     * retournés par le repository.
     */
        $ignoredDraftIds = [];

        foreach ($draftsToIgnore as $draftToIgnore) {
            if (!$draftToIgnore instanceof DiffusionDraft) {
                continue;
            }

            $ignoredId = $draftToIgnore->getId();

            if (null !== $ignoredId) {
                $ignoredDraftIds[] = $ignoredId;
            }
        }

        $maxRegularRank = 0;
        $manualRebroadcasts = [];

        foreach ($groupDrafts as $groupDraft) {
            if (!$groupDraft instanceof DiffusionDraft) {
                continue;
            }

            $groupDraftId = $groupDraft->getId();

            if (
                null !== $groupDraftId
                && \in_array($groupDraftId, $ignoredDraftIds, true)
            ) {
                continue;
            }

            if (DiffusionDraft::TYPE_REGULAR === $groupDraft->getDraftType()) {
                $maxRegularRank = max(
                    $maxRegularRank,
                    (int) $groupDraft->getNombreDiffusion()
                );

                continue;
            }

            if (
                DiffusionDraft::TYPE_MANUAL_REBROADCAST
                === $groupDraft->getDraftType()
            ) {
                $manualRebroadcasts[] = $groupDraft;
            }
        }

        $rank = $maxRegularRank + 1;

        foreach ($manualRebroadcasts as $manualRebroadcast) {
            $manualRebroadcast->setNombreDiffusion($rank);
            ++$rank;
        }
    }

    #[Route('/rebroadcasts', name: 'rebroadcasts_create', methods: ['POST'])]
    public function createRebroadcasts(
        Request $request,
        DiffusionDraftRepository $draftRepository,
        EntityManagerInterface $em,
        GridPlacementConflictService $placementConflictService
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

        $duration = (int) (
            $parentDraft->getDurationMinutes()
            ?? $emission->getDuree()
        );

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
            $maxNombreDiffusion = max(
                $maxNombreDiffusion,
                (int) $groupDraft->getNombreDiffusion()
            );
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

            $regularOverlaps = $placementConflictService->findBlockingRegularOverlaps(
                $startsAt,
                $endsAt
            );

            if (\count($regularOverlaps) > 0) {
                return $this->json([
                    'success' => false,
                    'conflict' => true,
                    'error' => 'Une rediffusion chevauche déjà une programmation régulière.',
                    'debug' => $regularOverlaps,
                ], 409);
            }

            $overlappingDrafts = $placementConflictService->findBlockingDraftOverlaps(
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

        $this->renumberDraftGroupChronologically(
            $groupKey,
            $draftRepository
        );

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
            ['horaireDiffusion' => 'ASC']
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
            ['horaireDiffusion' => 'ASC']
        );

        $items = [];

        foreach ($groupDrafts as $item) {

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

    private function renumberDraftGroupChronologically(
        string $assignmentGroupKey,
        DiffusionDraftRepository $draftRepository
    ): void {
        $groupDrafts = $draftRepository->findBy(
            ['assignmentGroupKey' => $assignmentGroupKey],
            ['horaireDiffusion' => 'ASC']
        );

        $rank = 1;

        foreach ($groupDrafts as $groupDraft) {

            $groupDraft->setNombreDiffusion($rank);
            ++$rank;
        }
    }
}
