<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\ProgrammationRuleSlotRepository;
use App\Service\GridOccurrenceProjectionService;
use App\Service\GridRebroadcastCoverageService;
use App\Service\ProgrammationGridBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GridRebroadcastCoverageServiceTest extends TestCase
{
    private ProgrammationGridBuilder&MockObject $gridBuilder;
    private GridOccurrenceProjectionService&MockObject $projectionService;
    private ProgrammationRuleSlotRepository&MockObject $slotRepository;
    private DiffusionRepository&MockObject $diffusionRepository;
    private DiffusionDraftRepository&MockObject $draftRepository;
    private EntityManagerInterface&MockObject $entityManager;

    private GridRebroadcastCoverageService $service;

    protected function setUp(): void
    {
        $this->gridBuilder = $this->createMock(
            ProgrammationGridBuilder::class
        );

        $this->projectionService = $this->createMock(
            GridOccurrenceProjectionService::class
        );

        $this->slotRepository = $this->createMock(
            ProgrammationRuleSlotRepository::class
        );

        $this->diffusionRepository = $this->createMock(
            DiffusionRepository::class
        );

        $this->draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $this->entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $this->service = new GridRebroadcastCoverageService(
            $this->gridBuilder,
            $this->projectionService,
            $this->slotRepository,
            $this->diffusionRepository,
            $this->draftRepository,
            $this->entityManager
        );
    }

    public function testPreviewNormalizesRadioWeekAndIgnoresFirstBroadcast(): void
    {
        $expectedStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $expectedEnd = new \DateTimeImmutable(
            '2026-09-15 00:00:00'
        );

        $segments = [
            [
                [
                    'slotId' => 1,
                    'broadcastRank' => 1,
                    'startsAt' => '2026-09-08 10:00:00',
                ],
            ],
        ];

        $this->gridBuilder
            ->expects(self::once())
            ->method('buildForWeek')
            ->with($expectedStart, $expectedEnd)
            ->willReturn($segments);

        $this->projectionService
            ->expects(self::once())
            ->method('applyForWeek')
            ->with(
                $segments,
                $expectedStart,
                $expectedEnd
            )
            ->willReturn($segments);

        $this->slotRepository
            ->expects(self::never())
            ->method('find');

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-10 14:30:00')
        );

        self::assertEquals(
            $expectedStart,
            $result['weekStart']
        );

        self::assertEquals(
            $expectedEnd,
            $result['weekEnd']
        );

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['fillableCount']);
    }

    public function testPreviewMarksCancelledRebroadcastAsArbitrated(): void
    {
        $slot = $this->createSlot(10, 2);

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'isCancelled' => true,
        ]);

        $this->prepareSingleSegment($segment, $slot);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(1, $result['total']);

        self::assertSame(
            1,
            $result['counts'][GridRebroadcastCoverageService::STATUS_ARBITRATED]
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_ARBITRATED,
            $result['items'][0]['status']
        );

        self::assertTrue(
            $result['items'][0]['isCancelled']
        );
    }

    public function testPreviewMarksMissingFirstBroadcastLinkAsSourceNotFound(): void
    {
        $slot = $this->createSlot(10, 2);

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => null,
        ]);

        $this->prepareSingleSegment($segment, $slot);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_SOURCE_NOT_FOUND,
            $result['items'][0]['status']
        );
    }

    public function testPreviewMarksSourceInsideCurrentWeekAsPending(): void
    {
        $slot = $this->createSlot(10, 2);

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-08 10:00:00',
        ]);

        $this->prepareSingleSegment($segment, $slot);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findAllAtHoraire')
            ->willReturn([]);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_PENDING_SOURCE,
            $result['items'][0]['status']
        );
    }

    public function testPreviewMarksMissingHistoricalSourceAsSourceNotFound(): void
    {
        $slot = $this->createSlot(10, 2);

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $this->prepareSingleSegment($segment, $slot);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findAllAtHoraire')
            ->willReturn([]);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_SOURCE_NOT_FOUND,
            $result['items'][0]['status']
        );
    }

    public function testPreviewMarksSeveralSourcesAsAmbiguous(): void
    {
        $slot = $this->createSlot(10, 2);

        $firstDiffusion = $this->createDiffusion(
            100,
            $this->createEmission(1, 'Émission A')
        );

        $secondDiffusion = $this->createDiffusion(
            101,
            $this->createEmission(2, 'Émission B')
        );

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $this->prepareSingleSegment($segment, $slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([
                $firstDiffusion,
                $secondDiffusion,
            ]);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        $item = $result['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_AMBIGUOUS,
            $item['status']
        );

        self::assertSame(
            [100, 101],
            $item['ambiguousSourceDiffusionIds']
        );
    }

    public function testPreviewMarksRebroadcastWithoutDraftAsMissing(): void
    {
        $slot = $this->createSlot(10, 2);

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $sourceDiffusion = $this->createDiffusion(
            100,
            $emission
        );

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $this->prepareSingleSegment($segment, $slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn(null);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(1, $result['fillableCount']);

        $item = $result['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_MISSING,
            $item['status']
        );

        self::assertSame(100, $item['sourceDiffusionId']);
        self::assertSame(1, $item['expectedEmissionId']);
        self::assertSame(
            'Émission source',
            $item['expectedEmissionTitle']
        );
    }

    public function testPreviewMarksSameEmissionAsNormal(): void
    {
        $slot = $this->createSlot(10, 2);

        $emission = $this->createEmission(
            1,
            'Même émission'
        );

        $sourceDiffusion = $this->createDiffusion(
            100,
            $emission
        );

        $existingDraft = $this->createDraft(
            200,
            $emission
        );

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $this->prepareSingleSegment($segment, $slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn($existingDraft);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        $item = $result['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_NORMAL,
            $item['status']
        );

        self::assertSame(200, $item['draftId']);
        self::assertSame(1, $item['programmedEmissionId']);
    }

    public function testPreviewMarksDifferentEmissionAsOverride(): void
    {
        $slot = $this->createSlot(10, 2);

        $sourceEmission = $this->createEmission(
            1,
            'Émission source'
        );

        $otherEmission = $this->createEmission(
            2,
            'Autre émission'
        );

        $sourceDiffusion = $this->createDiffusion(
            100,
            $sourceEmission
        );

        $existingDraft = $this->createDraft(
            200,
            $otherEmission
        );

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $this->prepareSingleSegment($segment, $slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn($existingDraft);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        $item = $result['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_OVERRIDE,
            $item['status']
        );

        self::assertSame(1, $item['expectedEmissionId']);
        self::assertSame(2, $item['programmedEmissionId']);
    }

    public function testPreviewSortsItemsByTargetStartTime(): void
    {
        $firstSlot = $this->createSlot(10, 2);
        $secondSlot = $this->createSlot(11, 2);

        $later = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'startsAt' => '2026-09-10 18:00:00',
            'originalStartsAt' => '2026-09-10 18:00:00',
            'isCancelled' => true,
        ]);

        $earlier = $this->createSegment([
            'slotId' => 11,
            'broadcastRank' => 2,
            'startsAt' => '2026-09-09 09:00:00',
            'originalStartsAt' => '2026-09-09 09:00:00',
            'isCancelled' => true,
        ]);

        $segments = [
            [$later, $earlier],
        ];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturnCallback(
                static function ($id) use ($firstSlot, $secondSlot) {
                    return match ((int) $id) {
                        10 => $firstSlot,
                        11 => $secondSlot,
                        default => null,
                    };
                }
            );

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            '2026-09-09 09:00:00',
            $result['items'][0]['targetStartsAt']
        );

        self::assertSame(
            '2026-09-10 18:00:00',
            $result['items'][1]['targetStartsAt']
        );
    }

    private function prepareSingleSegment(
        array $segment,
        ProgrammationRuleSlot $slot
    ): void {
        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);
    }

    private function createSegment(
        array $overrides = []
    ): array {
        return array_replace(
            [
                'slotId' => 10,
                'broadcastRank' => 2,
                'startsAt' => '2026-09-10 14:00:00',
                'originalStartsAt' => '2026-09-10 14:00:00',
                'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
                'isCancelled' => false,
                'isRescheduledOrigin' => false,
                'isProjectedOverride' => false,
                'isBlocking' => true,
                'projectionType' => null,
            ],
            $overrides
        );
    }

    private function createSlot(
        int $id,
        int $broadcastRank
    ): ProgrammationRuleSlot&MockObject {
        $rule = $this->createMock(
            ProgrammationRule::class
        );

        $rule
            ->method('getId')
            ->willReturn(5);

        $rule
            ->method('getRuleNumber')
            ->willReturn(1);

        $rule
            ->method('getDisplayName')
            ->willReturn('Règle test');

        $slot = $this->createMock(
            ProgrammationRuleSlot::class
        );

        $slot
            ->method('getId')
            ->willReturn($id);

        $slot
            ->method('getBroadcastRank')
            ->willReturn($broadcastRank);

        $slot
            ->method('getRule')
            ->willReturn($rule);

        $slot
            ->method('isActive')
            ->willReturn(true);

        $slot
            ->method('isDeleted')
            ->willReturn(false);

        return $slot;
    }

    private function createEmission(
        int $id,
        string $title
    ): Emission&MockObject {
        $emission = $this->createMock(
            Emission::class
        );

        $emission
            ->method('getId')
            ->willReturn($id);

        $emission
            ->method('getTitre')
            ->willReturn($title);

        return $emission;
    }

    private function createDiffusion(
        int $id,
        Emission $emission
    ): Diffusion&MockObject {
        $diffusion = $this->createMock(
            Diffusion::class
        );

        $diffusion
            ->method('getId')
            ->willReturn($id);

        $diffusion
            ->method('getEmission')
            ->willReturn($emission);

        return $diffusion;
    }

    private function createDraft(
        int $id,
        Emission $emission
    ): DiffusionDraft&MockObject {
        $draft = $this->createMock(
            DiffusionDraft::class
        );

        $draft
            ->method('getId')
            ->willReturn($id);

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        return $draft;
    }

    public function testFillCreatesMissingRebroadcastDraft(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $rule = $this->createMock(
            ProgrammationRule::class
        );

        $rule
            ->method('getId')
            ->willReturn(5);

        $slot = $this->createMock(
            ProgrammationRuleSlot::class
        );

        $slot
            ->method('getId')
            ->willReturn(10);

        $slot
            ->method('getBroadcastRank')
            ->willReturn(2);

        $slot
            ->method('getDurationMinutes')
            ->willReturn(45);

        $slot
            ->method('getRule')
            ->willReturn($rule);

        $slot
            ->method('isActive')
            ->willReturn(true);

        $slot
            ->method('isDeleted')
            ->willReturn(false);

        $sourceDiffusion = $this->createMock(
            Diffusion::class
        );

        $sourceDiffusion
            ->method('getId')
            ->willReturn(100);

        $sourceDiffusion
            ->method('getEmission')
            ->willReturn($emission);

        $sourceDiffusion
            ->method('getAssignmentGroupKey')
            ->willReturn(null);

        $sourceDiffusion
            ->expects(self::once())
            ->method('setAssignmentGroupKey')
            ->with('rule_5_origin_20260901_1000')
            ->willReturnSelf();

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'startsAt' => '2026-09-10 14:00:00',
            'originalStartsAt' => '2026-09-10 14:00:00',
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturnOnConsecutiveCalls(
                null,
                null
            );

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn($sourceDiffusion);

        $persisted = null;

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(
                static function ($entity) use (&$persisted): void {
                    $persisted = $entity;
                }
            );

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(1, $result['createdCount']);
        self::assertSame(
            1,
            $result['updatedSourceDiffusionCount']
        );
        self::assertSame(
            0,
            $result['updatedExistingDraftCount']
        );
        self::assertSame(0, $result['skippedCount']);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $persisted
        );

        self::assertSame(
            $emission,
            $persisted->getEmission()
        );

        self::assertSame(
            $slot,
            $persisted->getSlot()
        );

        self::assertSame(
            2,
            $persisted->getNombreDiffusion()
        );

        self::assertSame(
            DiffusionDraft::TYPE_REGULAR,
            $persisted->getDraftType()
        );

        self::assertSame(
            'rule_5_origin_20260901_1000',
            $persisted->getAssignmentGroupKey()
        );

        self::assertSame(
            '2026-09-10 14:00:00',
            $persisted
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            45,
            $persisted->getDurationMinutes()
        );
    }

    public function testFillDoesNothingWhenSourceAndDraftAlreadyHaveSameGroupKey(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $slot = $this->createSlot(10, 2);

        $sourceDiffusion = $this->createMock(
            Diffusion::class
        );

        $sourceDiffusion
            ->method('getId')
            ->willReturn(100);

        $sourceDiffusion
            ->method('getEmission')
            ->willReturn($emission);

        $sourceDiffusion
            ->method('getAssignmentGroupKey')
            ->willReturn('group_same');

        $existingDraft = $this->createMock(
            DiffusionDraft::class
        );

        $existingDraft
            ->method('getId')
            ->willReturn(200);

        $existingDraft
            ->method('getEmission')
            ->willReturn($emission);

        $existingDraft
            ->method('getAssignmentGroupKey')
            ->willReturn('group_same');

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn($existingDraft);

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn($sourceDiffusion);

        $this->draftRepository
            ->method('find')
            ->with(200)
            ->willReturn($existingDraft);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(0, $result['createdCount']);
        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );
        self::assertSame(
            0,
            $result['updatedExistingDraftCount']
        );
        self::assertSame(0, $result['skippedCount']);
    }

    public function testFillSkipsWhenSourceAndDraftHaveDifferentGroupKeys(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $slot = $this->createSlot(10, 2);

        $sourceDiffusion = $this->createMock(
            Diffusion::class
        );

        $sourceDiffusion
            ->method('getId')
            ->willReturn(100);

        $sourceDiffusion
            ->method('getEmission')
            ->willReturn($emission);

        $sourceDiffusion
            ->method('getAssignmentGroupKey')
            ->willReturn('source_group');

        $existingDraft = $this->createMock(
            DiffusionDraft::class
        );

        $existingDraft
            ->method('getId')
            ->willReturn(200);

        $existingDraft
            ->method('getEmission')
            ->willReturn($emission);

        $existingDraft
            ->method('getAssignmentGroupKey')
            ->willReturn('draft_group');

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn($existingDraft);

        $this->diffusionRepository
            ->method('find')
            ->willReturn($sourceDiffusion);

        $this->draftRepository
            ->method('find')
            ->willReturn($existingDraft);

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(1, $result['skippedCount']);

        self::assertSame(
            'assignment_group_key_mismatch',
            $result['skipped'][0]['reason']
        );

        self::assertSame(
            'source_group',
            $result['skipped'][0]['sourceAssignmentGroupKey']
        );

        self::assertSame(
            'draft_group',
            $result['skipped'][0]['draftAssignmentGroupKey']
        );
    }

    public function testFillSkipsMissingDraftWhenItAppearsBeforeWrite(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $slot = $this->createSlot(10, 2);

        $sourceDiffusion = $this->createMock(
            Diffusion::class
        );

        $sourceDiffusion
            ->method('getId')
            ->willReturn(100);

        $sourceDiffusion
            ->method('getEmission')
            ->willReturn($emission);

        $existingDraft = $this->createMock(
            DiffusionDraft::class
        );

        $existingDraft
            ->method('getId')
            ->willReturn(999);

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        /*
     * Premier appel : preview => aucun draft, donc STATUS_MISSING.
     * Second appel : écriture => un draft vient d'apparaître.
     */
        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturnOnConsecutiveCalls(
                null,
                $existingDraft
            );

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn($sourceDiffusion);

        $this->slotRepository
            ->method('find')
            ->with(10)
            ->willReturn($slot);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(0, $result['createdCount']);
        self::assertSame(1, $result['skippedCount']);

        self::assertSame(
            'draft_already_exists',
            $result['skipped'][0]['reason']
        );

        self::assertSame(
            999,
            $result['skipped'][0]['draftId']
        );
    }

    public function testFillUsesFifteenMinutesWhenSlotDurationIsInvalid(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $rule = $this->createMock(
            ProgrammationRule::class
        );

        $rule
            ->method('getId')
            ->willReturn(5);

        $slot = $this->createMock(
            ProgrammationRuleSlot::class
        );

        $slot
            ->method('getId')
            ->willReturn(10);

        $slot
            ->method('getBroadcastRank')
            ->willReturn(2);

        $slot
            ->method('getDurationMinutes')
            ->willReturn(0);

        $slot
            ->method('getRule')
            ->willReturn($rule);

        $slot
            ->method('isActive')
            ->willReturn(true);

        $slot
            ->method('isDeleted')
            ->willReturn(false);

        $sourceDiffusion = $this->createMock(
            Diffusion::class
        );

        $sourceDiffusion
            ->method('getId')
            ->willReturn(100);

        $sourceDiffusion
            ->method('getEmission')
            ->willReturn($emission);

        $sourceDiffusion
            ->method('getAssignmentGroupKey')
            ->willReturn('existing_group');

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturnOnConsecutiveCalls(
                null,
                null
            );

        $this->diffusionRepository
            ->method('find')
            ->willReturn($sourceDiffusion);

        $persisted = null;

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(
                static function ($draft) use (&$persisted): void {
                    $persisted = $draft;
                }
            );

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(1, $result['createdCount']);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $persisted
        );

        self::assertSame(
            15,
            $persisted->getDurationMinutes()
        );

        self::assertSame(
            'existing_group',
            $persisted->getAssignmentGroupKey()
        );
    }

    public function testFillSkipsWhenMissingSlotCannotBeReloaded(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(1, 'Émission source');
        $slot = $this->createSlot(10, 2);
        $sourceDiffusion = $this->createDiffusion(100, $emission);

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        /*
     * Preview : le slot existe.
     * Écriture : il n'existe plus.
     */
        $this->slotRepository
            ->method('find')
            ->willReturnOnConsecutiveCalls(
                $slot,
                null
            );

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn(null);

        $this->diffusionRepository
            ->method('find')
            ->willReturn($sourceDiffusion);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->executeTransactionCallback();

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(0, $result['createdCount']);
        self::assertSame(1, $result['skippedCount']);
        self::assertSame(
            'entity_not_found',
            $result['skipped'][0]['reason']
        );
    }

    public function testFillSkipsWhenSourceEmissionCannotBeReloaded(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(1, 'Émission source');
        $slot = $this->createSlot(10, 2);

        $previewSource = $this->createDiffusion(
            100,
            $emission
        );

        $reloadedSource = $this->createMock(
            Diffusion::class
        );

        $reloadedSource
            ->method('getId')
            ->willReturn(100);

        $reloadedSource
            ->method('getEmission')
            ->willReturn(null);

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$previewSource]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn(null);

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn($reloadedSource);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->executeTransactionCallback();

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(0, $result['createdCount']);
        self::assertSame(1, $result['skippedCount']);
        self::assertSame(
            'source_emission_not_found',
            $result['skipped'][0]['reason']
        );
    }

    public function testFillSkipsWhenAssignmentGroupCannotBeBuiltWithoutRule(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $slot = $this->createMock(
            ProgrammationRuleSlot::class
        );

        $slot
            ->method('getId')
            ->willReturn(10);

        $slot
            ->method('getBroadcastRank')
            ->willReturn(2);

        $slot
            ->method('getDurationMinutes')
            ->willReturn(30);

        $slot
            ->method('getRule')
            ->willReturn(null);

        $slot
            ->method('isActive')
            ->willReturn(true);

        $slot
            ->method('isDeleted')
            ->willReturn(false);

        $sourceDiffusion = $this->createMock(
            Diffusion::class
        );

        $sourceDiffusion
            ->method('getId')
            ->willReturn(100);

        $sourceDiffusion
            ->method('getEmission')
            ->willReturn($emission);

        $sourceDiffusion
            ->method('getAssignmentGroupKey')
            ->willReturn(null);

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturnOnConsecutiveCalls(
                null,
                null
            );

        $this->diffusionRepository
            ->method('find')
            ->willReturn($sourceDiffusion);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->executeTransactionCallback();

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(0, $result['createdCount']);
        self::assertSame(1, $result['skippedCount']);
        self::assertSame(
            'rule_not_found',
            $result['skipped'][0]['reason']
        );
    }

    private function executeTransactionCallback(): void
    {
        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );
    }

    public function testFillCopiesSourceGroupKeyToLegacyDraftWithoutGroupKey(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $slot = $this->createSlot(10, 2);

        $sourceDiffusion = $this->createMock(
            Diffusion::class
        );

        $sourceDiffusion
            ->method('getId')
            ->willReturn(100);

        $sourceDiffusion
            ->method('getEmission')
            ->willReturn($emission);

        $sourceDiffusion
            ->method('getAssignmentGroupKey')
            ->willReturn('source_group');

        $existingDraft = $this->createMock(
            DiffusionDraft::class
        );

        $existingDraft
            ->method('getId')
            ->willReturn(200);

        $existingDraft
            ->method('getEmission')
            ->willReturn($emission);

        $existingDraft
            ->method('getAssignmentGroupKey')
            ->willReturn(null);

        $existingDraft
            ->expects(self::once())
            ->method('setAssignmentGroupKey')
            ->with('source_group')
            ->willReturnSelf();

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn($existingDraft);

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn($sourceDiffusion);

        $this->draftRepository
            ->method('find')
            ->with(200)
            ->willReturn($existingDraft);

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(0, $result['createdCount']);

        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );

        self::assertSame(
            1,
            $result['updatedExistingDraftCount']
        );

        self::assertSame(0, $result['skippedCount']);
    }

    public function testFillCopiesDraftGroupKeyToLegacySourceWithoutGroupKey(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');

        $emission = $this->createEmission(
            1,
            'Émission source'
        );

        $slot = $this->createSlot(10, 2);

        $sourceDiffusion = $this->createMock(
            Diffusion::class
        );

        $sourceDiffusion
            ->method('getId')
            ->willReturn(100);

        $sourceDiffusion
            ->method('getEmission')
            ->willReturn($emission);

        $sourceDiffusion
            ->method('getAssignmentGroupKey')
            ->willReturn(null);

        $sourceDiffusion
            ->expects(self::once())
            ->method('setAssignmentGroupKey')
            ->with('draft_group')
            ->willReturnSelf();

        $existingDraft = $this->createMock(
            DiffusionDraft::class
        );

        $existingDraft
            ->method('getId')
            ->willReturn(200);

        $existingDraft
            ->method('getEmission')
            ->willReturn($emission);

        $existingDraft
            ->method('getAssignmentGroupKey')
            ->willReturn('draft_group');

        $segment = $this->createSegment([
            'slotId' => 10,
            'broadcastRank' => 2,
            'firstBroadcastStartsAt' => '2026-09-01 10:00:00',
        ]);

        $segments = [[$segment]];

        $this->gridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->slotRepository
            ->method('find')
            ->willReturn($slot);

        $this->diffusionRepository
            ->method('findAllAtHoraire')
            ->willReturn([$sourceDiffusion]);

        $this->draftRepository
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->willReturn($existingDraft);

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn($sourceDiffusion);

        $this->draftRepository
            ->method('find')
            ->with(200)
            ->willReturn($existingDraft);

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service
            ->fillMissingRebroadcastsForWeek($weekStart);

        self::assertSame(0, $result['createdCount']);

        self::assertSame(
            1,
            $result['updatedSourceDiffusionCount']
        );

        self::assertSame(
            0,
            $result['updatedExistingDraftCount']
        );

        self::assertSame(0, $result['skippedCount']);
    }
}
