<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\Categories;
use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\CategoriesRepository;
use App\Repository\DiffusionDraftRepository;
use App\Repository\EmissionRepository;
use App\Repository\GridSlotArbitrationRepository;
use App\Repository\ProgrammationRuleRepository;
use App\Repository\ProgrammationRuleSlotRepository;
use App\Service\GridAssignmentService;
use App\Service\GridConflictDetector;
use App\Service\GridOccurrenceProjectionService;
use App\Service\LiveEmissionCreator;
use App\Service\ProgrammationGridBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/admin/grille', name: 'admin.grille.')]
#[IsGranted('ROLE_ADMIN')]
class GrilleController extends AbstractController
{
    #[Route('', name: 'index_current', methods: ['GET'])]
    public function indexCurrent(
        ProgrammationGridBuilder $programmationGridBuilder,
        DiffusionDraftRepository $draftRepository,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector,
        CategoriesRepository $categoriesRepository
    ): Response {
        return $this->renderGrid(
            null,
            $programmationGridBuilder,
            $draftRepository,
            $gridOccurrenceProjectionService,
            $gridConflictDetector,
            $categoriesRepository
        );
    }

    #[Route('/{startOfWeek}', name: 'index', methods: ['GET'], requirements: ['startOfWeek' => '\d{4}-\d{2}-\d{2}'])]
    public function index(
        string $startOfWeek,
        ProgrammationGridBuilder $programmationGridBuilder,
        DiffusionDraftRepository $draftRepository,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector,
        CategoriesRepository $categoriesRepository
    ): Response {
        return $this->renderGrid(
            $startOfWeek,
            $programmationGridBuilder,
            $draftRepository,
            $gridOccurrenceProjectionService,
            $gridConflictDetector,
            $categoriesRepository
        );
    }

    private function renderGrid(
        ?string $startOfWeek,
        ProgrammationGridBuilder $programmationGridBuilder,
        DiffusionDraftRepository $draftRepository,
        GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        GridConflictDetector $gridConflictDetector,
        CategoriesRepository $categoriesRepository
    ): Response {
        $startDate = $startOfWeek
            ? \DateTime::createFromFormat('Y-m-d', $startOfWeek)
            : new \DateTime();

        if (!$startDate) {
            throw $this->createNotFoundException('Date de semaine invalide.');
        }

        $startOfWeekDate = (clone $startDate)
            ->modify('this week')
            ->modify('+1 day')
            ->setTime(0, 0, 0);

        $endOfWeekDate = (clone $startOfWeekDate)->modify('+7 days');

        $jours = [];
        for ($i = 0; $i < 7; $i++) {
            $jours[] = (clone $startOfWeekDate)->modify("+$i days");
        }

        $startImmutable = \DateTimeImmutable::createFromMutable($startOfWeekDate);
        $endImmutable = \DateTimeImmutable::createFromMutable($endOfWeekDate);

        $daySegments = $programmationGridBuilder->buildForWeek($startImmutable, $endImmutable);
        $daySegments = $gridOccurrenceProjectionService->applyForWeek(
            $daySegments,
            $startImmutable,
            $endImmutable
        );
        $daySegments = $gridConflictDetector->detectForWeek($daySegments);

        $drafts = $draftRepository->findByWeek($startImmutable, $endImmutable);

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
            $extraDrafts = $draftRepository->findRegularDraftsBySlotAndStartsAtPairs(
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

            $dayIndex = $this->getManualDraftDayIndex($startsAt, $startImmutable, $endImmutable);
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

                $seg['categoryTitle'] = $seg['categoryTitle'] ?? ($seg['title'] ?? 'Catégorie inconnue');
                $seg['categorySlug'] = $seg['categorySlug'] ?? null;
                $seg['displayTitle'] = $seg['title'] ?? 'Créneau';

                $slotId = $seg['slotId'] ?? null;
                $startsAt = $seg['originalStartsAt'] ?? $seg['startsAt'] ?? null;

                if (!$slotId || !$startsAt) {
                    continue;
                }

                $startsAtDate = $startsAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($startsAt)
                    : new \DateTimeImmutable((string) $startsAt);

                $key = $this->buildDraftKey((int) $slotId, $startsAtDate);

                if (!isset($draftIndex[$key])) {
                    continue;
                }

                /** @var DiffusionDraft $draft */
                $draft = $draftIndex[$key];
                $emission = $draft->getEmission();

                if ($emission instanceof Emission) {
                    $seg['assigned'] = true;
                    $seg['emissionId'] = $emission->getId();
                    $seg['emissionTitle'] = $emission->getTitre();
                    $seg['displayTitle'] = $emission->getTitre();
                    $seg['emissionIsAutoGenerated'] = $emission->isAutoGenerated();
                }
            }
        }
        unset($segments, $seg);

        $specialCategories = $categoriesRepository->createQueryBuilder('c')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->orderBy('c.titre', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/grille/index.html.twig', [
            'startOfWeek' => $startOfWeekDate,
            'jours' => $jours,
            'daySegments' => $daySegments,
            'manualDraftsByDay' => $manualDraftsByDay,
            'specialCategories' => $specialCategories,
        ]);
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

    #[Route('/candidates', name: 'candidates', methods: ['GET'])]
    public function candidates(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        EmissionRepository $emissionRepository,
        CategoriesRepository $categoriesRepository
    ): JsonResponse {
        $slotId = $request->query->getInt('slotId');
        $startsAt = $request->query->get('startsAt');
        $extended = filter_var($request->query->get('extended', false), FILTER_VALIDATE_BOOLEAN);
        $search = trim((string) $request->query->get('q', ''));
        $overrideCategoryId = $request->query->getInt('categoryId');

        if ($slotId <= 0 || !$startsAt) {
            return $this->json(['items' => []], 400);
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json(['items' => []], 404);
        }

        try {
            $selectedDate = new \DateTimeImmutable($startsAt);
        } catch (\Exception) {
            return $this->json(['items' => []], 400);
        }

        $rule = $slot->getRule();
        $category = $rule?->getCategory();
        $broadcastRank = $slot->getBroadcastRank();
        $isOverrideCategory = false;

        if ($overrideCategoryId > 0) {
            $overrideCategory = $categoriesRepository->find($overrideCategoryId);

            if (
                $overrideCategory instanceof Categories
                && true === $overrideCategory->isActive()
                && false === $overrideCategory->isSoftDelete()
            ) {
                $category = $overrideCategory;
                $isOverrideCategory = true;
            }
        }

        if (null === $category || true !== $category->isActive() || false !== $category->isSoftDelete()) {
            return $this->json(['items' => []]);
        }

        if (null === $broadcastRank || $broadcastRank < 1) {
            return $this->json(['items' => []], 400);
        }

        $emissions = [];

        if (1 === $broadcastRank) {
            $limit = $extended || $isOverrideCategory ? 80 : 20;
            $titleSearch = mb_strlen($search) >= 3 ? $search : null;

            $candidateEmissions = $emissionRepository->findGridCandidatesByCategory(
                $category,
                $titleSearch,
                $extended || $isOverrideCategory,
                $limit
            );

            if (!$extended && !$isOverrideCategory && null === $titleSearch) {
                $candidateEmissions = array_values(array_filter(
                    $candidateEmissions,
                    static fn(Emission $emission): bool => $emission->getDiffusions()->isEmpty()
                ));

                $candidateEmissions = array_slice($candidateEmissions, 0, 10);
            }

            $emissions = $candidateEmissions;
        }

        $autoGeneratedForCurrentSlot = $emissionRepository->findAutoGeneratedForSlotAndStartsAt(
            $slot,
            $selectedDate
        );

        if (!$isOverrideCategory && $autoGeneratedForCurrentSlot instanceof Emission) {
            $alreadyPresent = false;

            foreach ($emissions as $candidateEmission) {
                if ($candidateEmission->getId() === $autoGeneratedForCurrentSlot->getId()) {
                    $alreadyPresent = true;
                    break;
                }
            }

            if (!$alreadyPresent) {
                array_unshift($emissions, $autoGeneratedForCurrentSlot);
            }
        }

        $items = [];

        foreach ($emissions as $emission) {
            $items[] = [
                'id' => $emission->getId(),
                'title' => $emission->getTitre(),
                'meta' => sprintf(
                    '%s • %d min • %s',
                    $emission->getDatepub()?->format('d/m/Y') ?? 'date inconnue',
                    $emission->getDuree() ?? 0,
                    $emission->getCategorie()?->getTitre() ?? 'catégorie inconnue'
                ),
                'durationMinutes' => $emission->getDuree(),
                'isAutoGenerated' => $emission->isAutoGenerated(),
            ];
        }

        return $this->json([
            'items' => $items,
            'extended' => $extended,
            'search' => $search,
            'categoryId' => $category->getId(),
            'categoryTitle' => $category->getTitre(),
            'isOverrideCategory' => $isOverrideCategory,
        ]);
    }

    #[Route('/special-candidates', name: 'special_candidates', methods: ['GET'])]
    public function specialCandidates(
        Request $request,
        CategoriesRepository $categoriesRepository,
        EmissionRepository $emissionRepository,
        ProgrammationRuleRepository $programmationRuleRepository
    ): JsonResponse {
        $categoryId = $request->query->getInt('categoryId');
        $showAll = filter_var($request->query->get('all', false), FILTER_VALIDATE_BOOLEAN);
        $limit = $showAll ? null : 20;

        if ($categoryId <= 0) {
            return $this->json([
                'items' => [],
                'total' => 0,
                'hasMore' => false,
            ], 400);
        }

        $category = $categoriesRepository->find($categoryId);

        if (!$category || !$category->isActive() || $category->isSoftDelete()) {
            return $this->json([
                'items' => [],
                'total' => 0,
                'hasMore' => false,
            ], 404);
        }

        $isRegularCategory = $programmationRuleRepository->hasActiveRuleForCategory($category);

        if ($isRegularCategory) {
            $rows = $emissionRepository->findSpecialCandidatesForRegularCategory($category, $limit);
            $total = $emissionRepository->countSpecialCandidatesForRegularCategory($category);
        } else {
            $rows = $emissionRepository->findSpecialCandidatesForNonRegularCategory($category, $limit);
            $total = $emissionRepository->countSpecialCandidatesForNonRegularCategory($category);
        }

        $items = array_map(static function (array $row): array {
            /** @var Emission $emission */
            $emission = $row['emission'];
            $playCount = isset($row['playCount']) ? (int) $row['playCount'] : 0;

            return [
                'id' => $emission->getId(),
                'title' => $emission->getTitre(),
                'meta' => sprintf(
                    '%s • %d min',
                    $emission->getDatepub()?->format('d/m/Y') ?? 'date inconnue',
                    $emission->getDuree() ?? 0
                ),
                'durationMinutes' => $emission->getDuree(),
                'playCount' => $playCount,
                'playLabel' => 0 === $playCount
                    ? 'Jamais diffusée'
                    : sprintf('Déjà diffusée %d fois', $playCount),
            ];
        }, $rows);

        return $this->json([
            'items' => $items,
            'total' => $total,
            'hasMore' => null !== $limit && $total > $limit,
        ]);
    }

    #[Route('/assign', name: 'assign', methods: ['POST'])]
    public function assign(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        EmissionRepository $emissionRepository,
        GridAssignmentService $gridAssignmentService
    ): JsonResponse {
        $slotId = $request->request->get('slotId');
        $emissionId = $request->request->get('emissionId');
        $startsAt = $request->request->get('startsAt');

        if (!$slotId || !$emissionId || !$startsAt) {
            return $this->json(['error' => 'Paramètres manquants'], 400);
        }

        $slot = $slotRepository->find($slotId);
        $emission = $emissionRepository->find($emissionId);

        if (!$slot instanceof ProgrammationRuleSlot || !$emission instanceof Emission) {
            return $this->json(['error' => 'Données invalides'], 404);
        }

        try {
            $selectedDate = new \DateTimeImmutable($startsAt);
        } catch (\Exception) {
            return $this->json(['error' => 'Date invalide'], 400);
        }

        try {
            $propagated = $gridAssignmentService->assign($slot, $emission, $selectedDate);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }

        return $this->json([
            'success' => true,
            'emissionTitle' => $emission->getTitre(),
            'propagated' => $propagated,
        ]);
    }

    #[Route('/create-live', name: 'create_live', methods: ['POST'])]
    public function createLive(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        EmissionRepository $emissionRepository,
        LiveEmissionCreator $liveCreator,
        GridAssignmentService $gridAssignmentService
    ): JsonResponse {
        $slotId = $request->request->get('slotId');
        $startsAt = $request->request->get('startsAt');

        if (!$slotId || !$startsAt) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json([
                'success' => false,
                'error' => 'Créneau invalide',
            ], 404);
        }

        try {
            $date = new \DateTimeImmutable($startsAt);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        $existingAutoGenerated = $emissionRepository->findAutoGeneratedForSlotAndStartsAt($slot, $date);

        if ($existingAutoGenerated instanceof Emission) {
            return $this->json([
                'success' => false,
                'error' => 'Une fiche de direct existe déjà pour ce créneau.',
            ], 409);
        }

        try {
            $emission = $liveCreator->createFromSlot($slot, $date);

            $propagated = $gridAssignmentService->assign($slot, $emission, $date);

            return $this->json([
                'success' => true,
                'emissionId' => $emission->getId(),
                'emissionTitle' => $emission->getTitre(),
                'propagated' => $propagated,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/remove', name: 'remove', methods: ['POST'])]
    public function remove(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        GridAssignmentService $gridAssignmentService
    ): JsonResponse {
        $slotId = $request->request->get('slotId');
        $startsAt = $request->request->get('startsAt');

        if (!$slotId || !$startsAt) {
            return $this->json(['error' => 'Paramètres manquants'], 400);
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json(['error' => 'Créneau invalide'], 404);
        }

        try {
            $date = new \DateTimeImmutable($startsAt);
        } catch (\Exception) {
            return $this->json(['error' => 'Date invalide'], 400);
        }

        try {
            $propagated = $gridAssignmentService->remove($slot, $date);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }

        return $this->json([
            'success' => true,
            'propagated' => $propagated,
        ]);
    }

    #[Route('/reschedule-week', name: 'reschedule_week', methods: ['POST'])]
    public function rescheduleWeek(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        GridSlotArbitrationRepository $gridSlotArbitrationRepository,
        EntityManagerInterface $em,
        ProgrammationGridBuilder $programmationGridBuilder
    ): JsonResponse {
        $slotId = $request->request->get('slotId');
        $startsAt = $request->request->get('startsAt');
        $direction = $request->request->get('direction');
        $rebroadcastStrategy = $request->request->get('rebroadcastStrategy', 'keep');

        if (!$slotId || !$startsAt || !\in_array($direction, ['previous', 'next'], true)) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres invalides',
            ], 400);
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json([
                'success' => false,
                'error' => 'Créneau invalide',
            ], 404);
        }

        try {
            $originalStartsAt = new \DateTimeImmutable($startsAt);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        if (!$this->occurrenceExistsForSlot($slot, $originalStartsAt, $programmationGridBuilder)) {
            return $this->json([
                'success' => false,
                'error' => 'Cette occurrence n’existe pas pour ce créneau.',
            ], 400);
        }

        $rule = $slot->getRule();

        if (null === $rule || null === $rule->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Règle introuvable pour ce créneau.',
            ], 400);
        }

        $action = 'previous' === $direction
            ? GridSlotArbitration::ACTION_RESCHEDULE_PREVIOUS_WEEK
            : GridSlotArbitration::ACTION_RESCHEDULE_NEXT_WEEK;

        $clickedWeekOffset = $slot->getWeekOffset() ?? 0;
        $anchorWeekStart = $this->getRadioWeekStart($originalStartsAt)
            ->modify(sprintf('-%d weeks', $clickedWeekOffset));

        $arbitrationGroupKey = sprintf(
            'rule_%d_reschedule_%s',
            $rule->getId(),
            $anchorWeekStart->format('Ymd_His')
        );

        $slotsToHandle = [$slot];

        if (
            (int) ($slot->getBroadcastRank() ?? 1) === 1
            && \in_array($rebroadcastStrategy, ['cancel', 'move'], true)
        ) {
            $slotsToHandle = $slotRepository->findActiveByRule($rule);
        }

        $changedCount = 0;

        foreach ($slotsToHandle as $slotToHandle) {
            if (!$slotToHandle instanceof ProgrammationRuleSlot) {
                continue;
            }

            $linkedStartsAt = $slotToHandle === $slot
                ? $originalStartsAt
                : $this->buildStartsAtForLinkedSlot($slotToHandle, $anchorWeekStart);

            if (!$this->occurrenceExistsForSlot($slotToHandle, $linkedStartsAt, $programmationGridBuilder)) {
                continue;
            }

            $duration = $slotToHandle->getDurationMinutes() ?? 15;
            if ($duration <= 0) {
                $duration = 15;
            }

            $linkedEndsAt = $linkedStartsAt->modify(sprintf('+%d minutes', $duration));

            $arbitration = $gridSlotArbitrationRepository->findOneActiveForOccurrence($slotToHandle, $linkedStartsAt)
                ?? new GridSlotArbitration();

            if ($slotToHandle !== $slot && 'cancel' === $rebroadcastStrategy) {
                $arbitration
                    ->setSlot($slotToHandle)
                    ->setOriginalStartsAt($linkedStartsAt)
                    ->setOriginalEndsAt($linkedEndsAt)
                    ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
                    ->setAction(GridSlotArbitration::ACTION_CANCEL)
                    ->setRescheduledStartsAt(null)
                    ->setRescheduledEndsAt(null)
                    ->setStatus(GridSlotArbitration::STATUS_RESOLVED)
                    ->setArbitrationGroupKey($arbitrationGroupKey);
            } else {
                $rescheduledStartsAt = 'previous' === $direction
                    ? $linkedStartsAt->modify('-7 days')
                    : $linkedStartsAt->modify('+7 days');

                $rescheduledEndsAt = $rescheduledStartsAt->modify(sprintf('+%d minutes', $duration));

                $arbitration
                    ->setSlot($slotToHandle)
                    ->setOriginalStartsAt($linkedStartsAt)
                    ->setOriginalEndsAt($linkedEndsAt)
                    ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
                    ->setAction($action)
                    ->setRescheduledStartsAt($rescheduledStartsAt)
                    ->setRescheduledEndsAt($rescheduledEndsAt)
                    ->setStatus(GridSlotArbitration::STATUS_RESOLVED)
                    ->setArbitrationGroupKey($arbitrationGroupKey);
            }

            $arbitration->markResolved();

            $em->persist($arbitration);
            $changedCount++;
        }

        $em->flush();

        $targetStartsAt = 'previous' === $direction
            ? $originalStartsAt->modify('-7 days')
            : $originalStartsAt->modify('+7 days');

        return $this->json([
            'success' => true,
            'changedCount' => $changedCount,
            'arbitrationGroupKey' => $arbitrationGroupKey,
            'targetWeekStart' => $this->getRadioWeekStart($targetStartsAt)->format('Y-m-d'),
        ]);
    }

    #[Route('/reschedule-custom', name: 'reschedule_custom', methods: ['POST'])]
    public function rescheduleCustom(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        GridSlotArbitrationRepository $gridSlotArbitrationRepository,
        EntityManagerInterface $em,
        ProgrammationGridBuilder $programmationGridBuilder
    ): JsonResponse {
        $slotId = $request->request->get('slotId');
        $startsAt = $request->request->get('startsAt');
        $newDate = $request->request->get('newDate');
        $newTime = $request->request->get('newTime');
        $rebroadcastStrategy = $request->request->get('rebroadcastStrategy', 'keep');
        $rebroadcastTargetsRaw = $request->request->get('rebroadcastTargets');

        if (!$slotId || !$startsAt || !$newDate || !$newTime) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        if (!\in_array($rebroadcastStrategy, ['keep', 'cancel', 'move', 'custom'], true)) {
            return $this->json([
                'success' => false,
                'error' => 'Stratégie de rediffusion invalide.',
            ], 400);
        }

        $rebroadcastTargets = [];

        if ('custom' === $rebroadcastStrategy) {
            if (!$rebroadcastTargetsRaw) {
                return $this->json([
                    'success' => false,
                    'error' => 'Cibles de rediffusion manquantes.',
                ], 400);
            }

            $decodedTargets = json_decode((string) $rebroadcastTargetsRaw, true);

            if (!\is_array($decodedTargets)) {
                return $this->json([
                    'success' => false,
                    'error' => 'Format des cibles de rediffusion invalide.',
                ], 400);
            }

            $rebroadcastTargets = $decodedTargets;
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json([
                'success' => false,
                'error' => 'Créneau invalide',
            ], 404);
        }

        try {
            $originalStartsAt = new \DateTimeImmutable($startsAt);
            $rescheduledStartsAt = new \DateTimeImmutable(sprintf('%s %s:00', $newDate, $newTime));
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        if (!$this->occurrenceExistsForSlot($slot, $originalStartsAt, $programmationGridBuilder)) {
            return $this->json([
                'success' => false,
                'error' => 'Cette occurrence n’existe pas pour ce créneau.',
            ], 400);
        }

        $minute = (int) $rescheduledStartsAt->format('i');

        if ($minute % 15 !== 0) {
            return $this->json([
                'success' => false,
                'error' => 'L’heure doit être alignée sur un quart d’heure.',
            ], 400);
        }

        $rule = $slot->getRule();

        if (null === $rule || null === $rule->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Règle introuvable pour ce créneau.',
            ], 400);
        }

        $clickedWeekOffset = $slot->getWeekOffset() ?? 0;
        $anchorWeekStart = $this->getRadioWeekStart($originalStartsAt)
            ->modify(sprintf('-%d weeks', $clickedWeekOffset));

        $arbitrationGroupKey = sprintf(
            'rule_%d_reschedule_%s',
            $rule->getId(),
            $anchorWeekStart->format('Ymd_His')
        );

        $slotsToHandle = [$slot];

        if (
            (int) ($slot->getBroadcastRank() ?? 1) === 1
            && \in_array($rebroadcastStrategy, ['cancel', 'move', 'custom'], true)
        ) {
            $slotsToHandle = $slotRepository->findActiveByRule($rule);
        }

        $deltaSeconds = $rescheduledStartsAt->getTimestamp() - $originalStartsAt->getTimestamp();
        $changedCount = 0;

        foreach ($slotsToHandle as $slotToHandle) {
            if (!$slotToHandle instanceof ProgrammationRuleSlot) {
                continue;
            }

            $linkedStartsAt = $slotToHandle === $slot
                ? $originalStartsAt
                : $this->buildStartsAtForLinkedSlot($slotToHandle, $anchorWeekStart);

            if (!$this->occurrenceExistsForSlot($slotToHandle, $linkedStartsAt, $programmationGridBuilder)) {
                continue;
            }

            $duration = $slotToHandle->getDurationMinutes() ?? 15;

            if ($duration <= 0) {
                $duration = 15;
            }

            $linkedEndsAt = $linkedStartsAt->modify(sprintf('+%d minutes', $duration));

            $arbitration = $gridSlotArbitrationRepository->findOneActiveForOccurrence($slotToHandle, $linkedStartsAt)
                ?? new GridSlotArbitration();

            if ($slotToHandle !== $slot && 'cancel' === $rebroadcastStrategy) {
                $arbitration
                    ->setSlot($slotToHandle)
                    ->setOriginalStartsAt($linkedStartsAt)
                    ->setOriginalEndsAt($linkedEndsAt)
                    ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
                    ->setAction(GridSlotArbitration::ACTION_CANCEL)
                    ->setRescheduledStartsAt(null)
                    ->setRescheduledEndsAt(null)
                    ->setStatus(GridSlotArbitration::STATUS_RESOLVED)
                    ->setArbitrationGroupKey($arbitrationGroupKey);
            } else {
                if ($slotToHandle === $slot) {
                    $targetStartsAt = $rescheduledStartsAt;
                } elseif ('custom' === $rebroadcastStrategy) {
                    $target = null;

                    foreach ($rebroadcastTargets as $candidate) {
                        if (!\is_array($candidate)) {
                            continue;
                        }

                        if ((int) ($candidate['slotId'] ?? 0) === (int) $slotToHandle->getId()) {
                            $target = $candidate;
                            break;
                        }
                    }

                    if (null === $target) {
                        continue;
                    }

                    $targetNewDate = $target['newDate'] ?? null;
                    $targetNewTime = $target['newTime'] ?? null;

                    if (!$targetNewDate || !$targetNewTime) {
                        continue;
                    }

                    try {
                        $targetStartsAt = new \DateTimeImmutable(sprintf('%s %s:00', $targetNewDate, $targetNewTime));
                    } catch (\Exception) {
                        continue;
                    }

                    $targetMinute = (int) $targetStartsAt->format('i');

                    if ($targetMinute % 15 !== 0) {
                        continue;
                    }
                } else {
                    $targetStartsAt = $linkedStartsAt->modify(sprintf('%+d seconds', $deltaSeconds));
                }

                $targetEndsAt = $targetStartsAt->modify(sprintf('+%d minutes', $duration));

                $arbitration
                    ->setSlot($slotToHandle)
                    ->setOriginalStartsAt($linkedStartsAt)
                    ->setOriginalEndsAt($linkedEndsAt)
                    ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
                    ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
                    ->setRescheduledStartsAt($targetStartsAt)
                    ->setRescheduledEndsAt($targetEndsAt)
                    ->setStatus(GridSlotArbitration::STATUS_RESOLVED)
                    ->setArbitrationGroupKey($arbitrationGroupKey);
            }

            $arbitration->markResolved();

            $em->persist($arbitration);
            $changedCount++;
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'changedCount' => $changedCount,
            'arbitrationGroupKey' => $arbitrationGroupKey,
            'rescheduledStartsAt' => $rescheduledStartsAt->format('Y-m-d H:i:s'),
            'targetWeekStart' => $this->getRadioWeekStart($rescheduledStartsAt)->format('Y-m-d'),
        ]);
    }

    #[Route('/cancel-occurrence', name: 'cancel_occurrence', methods: ['POST'])]
    public function cancelOccurrence(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        GridSlotArbitrationRepository $gridSlotArbitrationRepository,
        EntityManagerInterface $em,
        ProgrammationGridBuilder $programmationGridBuilder
    ): JsonResponse {
        $slotId = $request->request->get('slotId');
        $startsAt = $request->request->get('startsAt');
        $rebroadcastStrategy = $request->request->get('rebroadcastStrategy', 'keep');

        if (!$slotId || !$startsAt) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json([
                'success' => false,
                'error' => 'Créneau invalide',
            ], 404);
        }

        try {
            $originalStartsAt = new \DateTimeImmutable($startsAt);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        if (!$this->occurrenceExistsForSlot($slot, $originalStartsAt, $programmationGridBuilder)) {
            return $this->json([
                'success' => false,
                'error' => 'Cette occurrence n’existe pas pour ce créneau.',
            ], 400);
        }

        $rule = $slot->getRule();

        if (null === $rule || null === $rule->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Règle introuvable pour ce créneau.',
            ], 400);
        }

        $clickedWeekOffset = $slot->getWeekOffset() ?? 0;
        $anchorWeekStart = $this->getRadioWeekStart($originalStartsAt)
            ->modify(sprintf('-%d weeks', $clickedWeekOffset));

        $arbitrationGroupKey = sprintf(
            'rule_%d_cancel_%s',
            $rule->getId(),
            $anchorWeekStart->format('Ymd_His')
        );

        $slotsToCancel = [$slot];

        if (
            (int) ($slot->getBroadcastRank() ?? 1) === 1
            && \in_array($rebroadcastStrategy, ['cancel', 'move'], true)
        ) {
            $slotsToCancel = $slotRepository->findActiveByRule($rule);
        }

        $cancelledCount = 0;

        foreach ($slotsToCancel as $slotToCancel) {
            if (!$slotToCancel instanceof ProgrammationRuleSlot) {
                continue;
            }

            if ($slotToCancel->isDeleted() || !$slotToCancel->isActive()) {
                continue;
            }

            $linkedStartsAt = $slotToCancel === $slot
                ? $originalStartsAt
                : $this->buildStartsAtForLinkedSlot($slotToCancel, $anchorWeekStart);

            if (!$this->occurrenceExistsForSlot($slotToCancel, $linkedStartsAt, $programmationGridBuilder)) {
                continue;
            }

            $duration = $slotToCancel->getDurationMinutes() ?? 15;
            if ($duration <= 0) {
                $duration = 15;
            }

            $linkedEndsAt = $linkedStartsAt->modify(sprintf('+%d minutes', $duration));

            $arbitration = $gridSlotArbitrationRepository->findOneActiveForOccurrence($slotToCancel, $linkedStartsAt)
                ?? new GridSlotArbitration();

            $arbitration
                ->setSlot($slotToCancel)
                ->setOriginalStartsAt($linkedStartsAt)
                ->setOriginalEndsAt($linkedEndsAt)
                ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
                ->setAction(GridSlotArbitration::ACTION_CANCEL)
                ->setRescheduledStartsAt(null)
                ->setRescheduledEndsAt(null)
                ->setStatus(GridSlotArbitration::STATUS_RESOLVED)
                ->setArbitrationGroupKey($arbitrationGroupKey);

            $arbitration->markResolved();

            $em->persist($arbitration);
            $cancelledCount++;
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'cancelledCount' => $cancelledCount,
            'arbitrationGroupKey' => $arbitrationGroupKey,
        ]);
    }

    private function buildStartsAtForLinkedSlot(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $anchorWeekStart
    ): \DateTimeImmutable {
        $weekOffset = $slot->getWeekOffset() ?? 0;
        $dayIndex = $this->radioDayIndexFromDayOfWeek((int) $slot->getDayOfWeek());
        $startTime = $slot->getStartTime();

        $startsAt = $anchorWeekStart
            ->modify(sprintf('+%d weeks', $weekOffset))
            ->modify(sprintf('+%d days', $dayIndex));

        if ($startTime instanceof \DateTimeInterface) {
            $startsAt = $startsAt->setTime(
                (int) $startTime->format('H'),
                (int) $startTime->format('i'),
                0
            );
        }

        return $startsAt;
    }

    private function computeStartsAtFromAnchor(
        \DateTimeImmutable $anchorDate,
        ProgrammationRuleSlot $slot
    ): \DateTimeImmutable {
        $anchorWeekStart = $this->getRadioWeekStart($anchorDate);

        $targetDate = $anchorWeekStart
            ->modify(sprintf('+%d days', $this->radioDayIndexFromDayOfWeek($slot->getDayOfWeek())))
            ->modify(sprintf('+%d days', $slot->getWeekOffset() * 7));

        $startTime = $slot->getStartTime();

        if (null === $startTime) {
            return $targetDate->setTime(0, 0, 0);
        }

        return $targetDate->setTime(
            (int) $startTime->format('H'),
            (int) $startTime->format('i'),
            0
        );
    }

    private function occurrenceExistsForSlot(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $startsAt,
        ProgrammationGridBuilder $programmationGridBuilder
    ): bool {
        $weekStart = $this->getRadioWeekStart($startsAt);
        $weekEnd = $weekStart->modify('+7 days');

        $daySegments = $programmationGridBuilder->buildForWeek($weekStart, $weekEnd);

        foreach ($daySegments as $segments) {
            foreach ($segments as $segment) {
                if (
                    ($segment['slotId'] ?? null) === $slot->getId()
                    && ($segment['startsAt'] ?? null) === $startsAt->format('Y-m-d H:i:s')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getRadioWeekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
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

    private function radioDayIndexFromDayOfWeek(?int $dayOfWeek): int
    {
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

    #[Route('/goto', name: 'goto', methods: ['GET'])]
    public function gotoWeek(Request $request): Response
    {
        $date = $request->query->get('date');

        if (!$date) {
            return $this->redirectToRoute('admin.grille.index_current');
        }

        try {
            $selectedDate = new \DateTimeImmutable($date);
        } catch (\Exception) {
            return $this->redirectToRoute('admin.grille.index_current');
        }

        $startOfWeek = $this->getRadioWeekStart($selectedDate);

        return $this->redirectToRoute('admin.grille.index', [
            'startOfWeek' => $startOfWeek->format('Y-m-d'),
        ]);
    }

    #[Route('/clear-reschedule', name: 'clear_reschedule', methods: ['POST'])]
    public function clearReschedule(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        GridSlotArbitrationRepository $gridSlotArbitrationRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $slotId = $request->request->get('slotId');
        $originalStartsAt = $request->request->get('originalStartsAt');
        $restoreLinkedRebroadcasts = $request->request->getBoolean('restoreLinkedRebroadcasts', false);

        if (!$slotId || !$originalStartsAt) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json([
                'success' => false,
                'error' => 'Créneau invalide',
            ], 404);
        }

        try {
            $originalDate = new \DateTimeImmutable($originalStartsAt);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        $arbitration = $gridSlotArbitrationRepository->findOneActiveForOccurrence($slot, $originalDate);

        if (!$arbitration instanceof GridSlotArbitration) {
            return $this->json([
                'success' => false,
                'error' => 'Aucune exception à annuler.',
            ], 404);
        }

        $arbitrationsToRemove = [$arbitration];

        if ($restoreLinkedRebroadcasts) {
            $groupKey = $arbitration->getArbitrationGroupKey();

            if ($groupKey) {
                $arbitrationsToRemove = $gridSlotArbitrationRepository->findBy([
                    'arbitrationGroupKey' => $groupKey,
                ]);
            }
        }

        foreach ($arbitrationsToRemove as $item) {
            if ($item instanceof GridSlotArbitration) {
                $em->remove($item);
            }
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'restoredStartsAt' => $originalDate->format('Y-m-d H:i:s'),
            'restoredCount' => count($arbitrationsToRemove),
            'restoredLinkedRebroadcasts' => $restoreLinkedRebroadcasts,
            'targetWeekStart' => $this->getRadioWeekStart($originalDate)->format('Y-m-d'),
        ]);
    }

    private function resolveDurationMinutes(ProgrammationRuleSlot $slot, Emission $emission): int
    {
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

    #[Route('/restore-occurrence', name: 'restore_occurrence', methods: ['POST'])]
    public function restoreOccurrence(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        GridSlotArbitrationRepository $gridSlotArbitrationRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $slotId = $request->request->get('slotId');
        $originalStartsAt = $request->request->get('originalStartsAt');
        $restoreLinkedRebroadcasts = $request->request->getBoolean('restoreLinkedRebroadcasts', false);

        if (!$slotId || !$originalStartsAt) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json([
                'success' => false,
                'error' => 'Créneau invalide',
            ], 404);
        }

        try {
            $originalDate = new \DateTimeImmutable($originalStartsAt);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        $arbitration = $gridSlotArbitrationRepository->findOneActiveForOccurrence($slot, $originalDate);

        if (!$arbitration instanceof GridSlotArbitration) {
            return $this->json([
                'success' => false,
                'error' => 'Aucune exception à restaurer.',
            ], 404);
        }

        $arbitrationsToRestore = [$arbitration];

        if ($restoreLinkedRebroadcasts) {
            $groupKey = $arbitration->getArbitrationGroupKey();

            if ($groupKey) {
                $arbitrationsToRestore = $gridSlotArbitrationRepository->findBy([
                    'arbitrationGroupKey' => $groupKey,
                ]);
            }
        }

        foreach ($arbitrationsToRestore as $item) {
            if ($item instanceof GridSlotArbitration) {
                $em->remove($item);
            }
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'restoredStartsAt' => $originalDate->format('Y-m-d H:i:s'),
            'restoredCount' => count($arbitrationsToRestore),
            'restoredLinkedRebroadcasts' => $restoreLinkedRebroadcasts,
            'targetWeekStart' => $this->getRadioWeekStart($originalDate)->format('Y-m-d'),
        ]);
    }

    #[Route('/linked-rebroadcasts', name: 'linked_rebroadcasts', methods: ['GET'])]
    public function linkedRebroadcasts(
        Request $request,
        ProgrammationRuleSlotRepository $slotRepository,
        ProgrammationGridBuilder $programmationGridBuilder
    ): JsonResponse {
        $slotId = $request->query->get('slotId');
        $startsAt = $request->query->get('startsAt');

        if (!$slotId || !$startsAt) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres manquants',
            ], 400);
        }

        $slot = $slotRepository->find($slotId);

        if (!$slot instanceof ProgrammationRuleSlot || $slot->isDeleted() || !$slot->isActive()) {
            return $this->json([
                'success' => false,
                'error' => 'Créneau invalide',
            ], 404);
        }

        try {
            $originalStartsAt = new \DateTimeImmutable($startsAt);
        } catch (\Exception) {
            return $this->json([
                'success' => false,
                'error' => 'Date invalide',
            ], 400);
        }

        if ((int) ($slot->getBroadcastRank() ?? 1) !== 1) {
            return $this->json([
                'success' => true,
                'items' => [],
            ]);
        }

        if (!$this->occurrenceExistsForSlot($slot, $originalStartsAt, $programmationGridBuilder)) {
            return $this->json([
                'success' => false,
                'error' => 'Cette occurrence n’existe pas pour ce créneau.',
            ], 400);
        }

        $rule = $slot->getRule();

        if (null === $rule || null === $rule->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Règle introuvable pour ce créneau.',
            ], 400);
        }

        $clickedWeekOffset = $slot->getWeekOffset() ?? 0;

        $anchorWeekStart = $this->getRadioWeekStart($originalStartsAt)
            ->modify(sprintf('-%d weeks', $clickedWeekOffset));

        $items = [];

        foreach ($slotRepository->findActiveByRule($rule) as $linkedSlot) {
            if (!$linkedSlot instanceof ProgrammationRuleSlot) {
                continue;
            }

            $broadcastRank = (int) ($linkedSlot->getBroadcastRank() ?? 1);

            if ($broadcastRank <= 1) {
                continue;
            }

            $linkedStartsAt = $this->buildStartsAtForLinkedSlot(
                $linkedSlot,
                $anchorWeekStart
            );

            if (!$this->occurrenceExistsForSlot($linkedSlot, $linkedStartsAt, $programmationGridBuilder)) {
                continue;
            }

            $duration = $linkedSlot->getDurationMinutes() ?? 15;

            if ($duration <= 0) {
                $duration = 15;
            }

            $items[] = [
                'slotId' => $linkedSlot->getId(),
                'broadcastRank' => $broadcastRank,
                'startsAt' => $linkedStartsAt->format('Y-m-d H:i:s'),
                'duration' => $duration,
                'label' => sprintf('Rediffusion %d', $broadcastRank - 1),
            ];
        }

        return $this->json([
            'success' => true,
            'items' => $items,
        ]);
    }
}
