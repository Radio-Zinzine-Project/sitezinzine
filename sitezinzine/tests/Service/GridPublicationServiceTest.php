<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\GridSlotArbitrationRepository;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Service\GridPublicationService;
use App\Service\PendingRebroadcastPopulatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class GridPublicationServiceTest extends TestCase
{
    private DiffusionDraftRepository&MockObject $draftRepository;
    private DiffusionRepository&MockObject $diffusionRepository;
    private EntityManagerInterface&MockObject $entityManager;
    /** @var PendingRebroadcastPopulatorInterface&MockObject */
    private PendingRebroadcastPopulatorInterface $pendingRebroadcastService;
    private GridPublicationService $service;
    /** @var ClockInterface&MockObject */
    private ClockInterface $clock;
    private GridSlotArbitrationRepository&MockObject $arbitrationRepository;
    /**
     * @var GridSlotArbitration[]
     */
    private array $relevantArbitrations = [];

    protected function setUp(): void
    {
        $this->draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $this->diffusionRepository = $this->createMock(
            DiffusionRepository::class
        );

        $this->arbitrationRepository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $this->entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $this->pendingRebroadcastService = $this->createMock(
            PendingRebroadcastPopulatorInterface::class
        );

        $this->clock = $this->createMock(
            ClockInterface::class
        );

        $this->clock
            ->method('now')
            ->willReturn(
                new \DateTimeImmutable(
                    '2026-09-29 12:00:00',
                    new \DateTimeZone('UTC')
                )
            );

        $this->relevantArbitrations = [];

        $this->arbitrationRepository
            ->method('findRelevantForWeek')
            ->willReturnCallback(
                fn(): array => $this->relevantArbitrations
            );

        $this->service = new GridPublicationService(
            $this->draftRepository,
            $this->diffusionRepository,
            $this->arbitrationRepository,
            $this->entityManager,
            $this->pendingRebroadcastService,
            $this->clock
        );
    }

    public function testPreviewNormalizesDateToRadioWeek(): void
    {
        /*
         * Jeudi 10 septembre 2026 appartient à la semaine radio :
         * mardi 8 -> mardi 15.
         */
        $expectedStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $expectedEnd = new \DateTimeImmutable(
            '2026-09-15 00:00:00'
        );

        $this->draftRepository
            ->expects(self::once())
            ->method('findPublishableByWeek')
            ->with($expectedStart, $expectedEnd)
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findByWeek')
            ->with($expectedStart, $expectedEnd)
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->with([], $expectedEnd)
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication(
            new \DateTimeImmutable('2026-09-10 15:42:00')
        );

        self::assertEquals(
            $expectedStart,
            $preview['weekStart']
        );

        self::assertEquals(
            $expectedEnd,
            $preview['weekEnd']
        );

        self::assertSame(0, $preview['publishableDraftCount']);
        self::assertSame(0, $preview['createCount']);
        self::assertSame(0, $preview['updateCount']);
        self::assertSame(0, $preview['conflictCount']);
        self::assertSame(0, $preview['futureDraftCount']);

        self::assertFalse($preview['hasBlockingConflicts']);
        self::assertFalse($preview['canPublish']);
    }

    public function testPreviewCreatesNewDiffusionWhenNoExistingDiffusionExists(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $weekEnd = new \DateTimeImmutable(
            '2026-09-15 00:00:00'
        );

        $draft = $this->createDraft(
            id: 10,
            startsAt: '2026-09-09 10:00:00',
            assignmentGroupKey: 'rule_5_origin_20260909_1000'
        );

        $this->draftRepository
            ->expects(self::once())
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->with(
                ['rule_5_origin_20260909_1000'],
                $weekEnd
            )
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication(
            $weekStart
        );

        self::assertSame(1, $preview['publishableDraftCount']);
        self::assertSame(1, $preview['createCount']);
        self::assertSame(0, $preview['updateCount']);
        self::assertSame(0, $preview['conflictCount']);

        self::assertTrue($preview['canPublish']);

        self::assertCount(1, $preview['items']);

        $item = $preview['items'][0];

        self::assertSame($draft, $item['draft']);
        self::assertSame('create', $item['action']);
        self::assertNull($item['targetDiffusion']);
        self::assertFalse($item['hasConflict']);

        self::assertSame(
            '2026-09-09 10:00:00',
            $item['startsAt']->format('Y-m-d H:i:s')
        );
    }

    public function testPreviewUpdatesPublishedDiffusionAlreadyLinkedToDraft(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $diffusion = $this->createDiffusionMock(
            100,
            '2026-09-09 10:00:00'
        );

        $draft = $this->createDraft(
            id: 10,
            startsAt: '2026-09-09 10:00:00',
            publishedDiffusion: $diffusion
        );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([$diffusion]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication(
            $weekStart
        );

        self::assertSame(0, $preview['createCount']);
        self::assertSame(1, $preview['updateCount']);
        self::assertSame(0, $preview['conflictCount']);

        $item = $preview['items'][0];

        self::assertSame('update', $item['action']);
        self::assertSame(
            $diffusion,
            $item['targetDiffusion']
        );
        self::assertFalse($item['hasConflict']);
    }

    public function testPreviewReusesSingleUnclaimedDiffusionAtSameTime(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $oldDiffusion = $this->createDiffusionMock(
            50,
            '2026-09-09 10:00:00'
        );

        $draft = $this->createDraft(
            id: 10,
            startsAt: '2026-09-09 10:00:00'
        );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([$oldDiffusion]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication(
            $weekStart
        );

        self::assertSame(0, $preview['createCount']);
        self::assertSame(1, $preview['updateCount']);

        $item = $preview['items'][0];

        self::assertSame('update', $item['action']);
        self::assertSame(
            $oldDiffusion,
            $item['targetDiffusion']
        );
    }

    public function testPreviewCreatesNewDiffusionWhenSeveralOldDiffusionsExistAtSameTime(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $firstOld = $this->createDiffusionMock(
            50,
            '2026-09-09 10:00:00'
        );

        $secondOld = $this->createDiffusionMock(
            51,
            '2026-09-09 10:00:00'
        );

        $draft = $this->createDraft(
            id: 10,
            startsAt: '2026-09-09 10:00:00'
        );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([
                $firstOld,
                $secondOld,
            ]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication(
            $weekStart
        );

        self::assertSame(1, $preview['createCount']);
        self::assertSame(0, $preview['updateCount']);

        $item = $preview['items'][0];

        self::assertSame('create', $item['action']);
        self::assertNull($item['targetDiffusion']);
    }

    public function testPreviewDetectsDiffusionReservedByAnotherDraft(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $diffusion = $this->createDiffusionMock(
            100,
            '2026-09-09 10:00:00'
        );

        /*
         * Premier draft : possède déjà la Diffusion.
         */
        $ownerDraft = $this->createDraft(
            id: 1,
            startsAt: '2026-09-09 10:00:00',
            publishedDiffusion: $diffusion
        );

        /*
         * Deuxième draft : essaie de publier au même horaire,
         * mais n'a aucune Diffusion liée.
         */
        $otherDraft = $this->createDraft(
            id: 2,
            startsAt: '2026-09-09 10:00:00'
        );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([
                $ownerDraft,
                $otherDraft,
            ]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([$diffusion]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication(
            $weekStart
        );

        self::assertSame(1, $preview['conflictCount']);
        self::assertTrue($preview['hasBlockingConflicts']);
        self::assertFalse($preview['canPublish']);

        self::assertCount(1, $preview['conflicts']);

        self::assertSame(
            'diffusion_reserved_by_other_draft',
            $preview['conflicts'][0]['type']
        );

        self::assertFalse(
            $preview['items'][0]['hasConflict']
        );

        self::assertTrue(
            $preview['items'][1]['hasConflict']
        );
    }

    public function testPreviewReturnsFutureDraftsForAssignmentGroups(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $weekEnd = new \DateTimeImmutable(
            '2026-09-15 00:00:00'
        );

        $firstDraft = $this->createDraft(
            id: 1,
            startsAt: '2026-09-09 10:00:00',
            assignmentGroupKey: 'group_a'
        );

        $secondDraft = $this->createDraft(
            id: 2,
            startsAt: '2026-09-10 10:00:00',
            assignmentGroupKey: 'group_a'
        );

        $futureDraft = $this->createMock(
            DiffusionDraft::class
        );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([
                $firstDraft,
                $secondDraft,
            ]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        /*
         * Vérifie aussi array_unique() sur les clés de groupe.
         */
        $this->draftRepository
            ->expects(self::once())
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->with(
                ['group_a'],
                $weekEnd
            )
            ->willReturn([$futureDraft]);

        $preview = $this->service->previewWeekPublication(
            $weekStart
        );

        self::assertSame(1, $preview['futureDraftCount']);

        self::assertSame(
            [$futureDraft],
            $preview['futureDraftsLeft']
        );
    }

    public function testPublishWeekRejectsWeekWithNoPublishableDraft(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Aucun draft publiable n’a été trouvé pour cette semaine.'
        );

        $this->service->publishWeek($weekStart);
    }

    public function testPublishWeekRejectsBlockingConflict(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $diffusion = $this->createDiffusionMock(
            100,
            '2026-09-09 10:00:00'
        );

        $firstDraft = $this->createDraft(
            id: 1,
            startsAt: '2026-09-09 10:00:00',
            publishedDiffusion: $diffusion
        );

        $secondDraft = $this->createDraft(
            id: 2,
            startsAt: '2026-09-09 10:00:00'
        );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([
                $firstDraft,
                $secondDraft,
            ]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([$diffusion]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'La validation est bloquée par un ou plusieurs conflits.'
        );

        $this->service->publishWeek($weekStart);
    }

    private function createDraft(
        int $id,
        string $startsAt,
        ?string $assignmentGroupKey = null,
        ?Diffusion $publishedDiffusion = null,
        ?ProgrammationRuleSlot $slot = null
    ): DiffusionDraft&MockObject {
        $draft = $this->createMock(
            DiffusionDraft::class
        );

        $draft
            ->method('getId')
            ->willReturn($id);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable($startsAt)
            );

        $draft
            ->method('getAssignmentGroupKey')
            ->willReturn($assignmentGroupKey);

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn($publishedDiffusion);

        $draft
            ->method('getSlot')
            ->willReturn($slot);

        return $draft;
    }

    private function createDiffusionMock(
        int $id,
        string $startsAt
    ): Diffusion&MockObject {
        $diffusion = $this->createMock(
            Diffusion::class
        );

        $diffusion
            ->method('getId')
            ->willReturn($id);

        $diffusion
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTime($startsAt)
            );

        return $diffusion;
    }

    public function testPublishWeekCreatesNewDiffusionFromDraft(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $emission = $this->createMock(Emission::class);

        $emission
            ->method('getId')
            ->willReturn(500);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(10);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 10:00:00')
            );

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $draft
            ->method('getAssignmentGroupKey')
            ->willReturn('group_1');

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(1);

        $draft
            ->method('getEffectiveDurationMinutes')
            ->willReturn(60);

        $draft
            ->method('getEndsAt')
            ->willReturn(null);

        $draft
            ->expects(self::once())
            ->method('markAsPublished')
            ->with(
                self::isInstanceOf(Diffusion::class),
                self::isInstanceOf(\DateTimeImmutable::class)
            );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $persisted = [];

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(
                static function ($entity) use (&$persisted): void {
                    $persisted[] = $entity;
                }
            );

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service->publishWeek($weekStart);

        self::assertTrue($result['published']);
        self::assertSame(1, $result['createdCount']);
        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['publishedDraftCount']);

        self::assertCount(1, $persisted);
        self::assertInstanceOf(Diffusion::class, $persisted[0]);

        /** @var Diffusion $diffusion */
        $diffusion = $persisted[0];

        self::assertSame($emission, $diffusion->getEmission());
        self::assertSame(1, $diffusion->getNombreDiffusion());

        self::assertSame(
            'group_1',
            $diffusion->getAssignmentGroupKey()
        );

        self::assertSame(
            '2026-09-09 10:00:00',
            $diffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            60,
            $diffusion->getDurationMinutes()
        );

        self::assertSame(
            '2026-09-09 11:00:00',
            $diffusion
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );
    }


    public function testPublishWeekUpdatesExistingDiffusion(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $emission = $this->createMock(Emission::class);

        $emission
            ->method('getId')
            ->willReturn(501);

        $diffusion = new Diffusion();

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(11);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 12:00:00')
            );

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn($diffusion);

        $draft
            ->method('getAssignmentGroupKey')
            ->willReturn('group_2');

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(2);

        $draft
            ->method('getEffectiveDurationMinutes')
            ->willReturn(30);

        $draft
            ->method('getEndsAt')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 12:30:00')
            );

        $draft
            ->expects(self::once())
            ->method('markAsPublished')
            ->with(
                $diffusion,
                self::isInstanceOf(\DateTimeImmutable::class)
            );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([$diffusion]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service->publishWeek($weekStart);

        self::assertSame(0, $result['createdCount']);
        self::assertSame(1, $result['updatedCount']);

        self::assertSame(
            $diffusion,
            $result['updated'][0]
        );

        self::assertSame($emission, $diffusion->getEmission());
        self::assertSame(2, $diffusion->getNombreDiffusion());

        self::assertSame(
            '2026-09-09 12:00:00',
            $diffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-09-09 12:30:00',
            $diffusion
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );
    }


    public function testPublishWeekAcceptsDraftWithoutDuration(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $emission = $this->createMock(Emission::class);

        $emission
            ->method('getId')
            ->willReturn(502);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(12);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 14:00:00')
            );

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(1);

        $draft
            ->method('getEffectiveDurationMinutes')
            ->willReturn(null);

        $draft
            ->method('getEndsAt')
            ->willReturn(null);

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

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
            ->expects(self::exactly(2))
            ->method('flush');

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->service->publishWeek($weekStart);

        self::assertInstanceOf(Diffusion::class, $persisted);

        self::assertSame(
            '2026-09-09 14:00:00',
            $persisted
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertNull($persisted->getDurationMinutes());
        self::assertNull($persisted->getEndsAt());
    }


    public function testPublishWeekNormalizesInvalidDurationToNull(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $emission = $this->createMock(Emission::class);

        $emission
            ->method('getId')
            ->willReturn(503);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(13);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 15:00:00')
            );

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(1);

        $draft
            ->method('getEffectiveDurationMinutes')
            ->willReturn(0);

        $draft
            ->method('getEndsAt')
            ->willReturn(null);

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

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
            ->expects(self::exactly(2))
            ->method('flush');

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->service->publishWeek($weekStart);

        self::assertInstanceOf(Diffusion::class, $persisted);

        self::assertNull(
            $persisted->getDurationMinutes()
        );

        self::assertNull(
            $persisted->getEndsAt()
        );
    }


    public function testPublishWeekRejectsDraftWithoutValidEmission(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(14);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 16:00:00')
            );

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $draft
            ->method('getEmission')
            ->willReturn(null);

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'ne possède pas d’émission valide'
        );

        $this->service->publishWeek($weekStart);
    }


    public function testPublishWeekRejectsInvalidNombreDiffusion(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $emission = $this->createMock(Emission::class);

        $emission
            ->method('getId')
            ->willReturn(504);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(15);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 17:00:00')
            );

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(0);

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'possède un nombreDiffusion invalide'
        );

        $this->service->publishWeek($weekStart);
    }

    public function testPreviewDetectsDraftWithoutValidSchedule(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $weekEnd = new \DateTimeImmutable(
            '2026-09-15 00:00:00'
        );

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(99);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(null);

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->with([], $weekEnd)
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication(
            $weekStart
        );

        self::assertSame(1, $preview['publishableDraftCount']);
        self::assertSame(0, $preview['createCount']);
        self::assertSame(0, $preview['updateCount']);

        self::assertSame(1, $preview['conflictCount']);
        self::assertTrue($preview['hasBlockingConflicts']);
        self::assertFalse($preview['canPublish']);

        self::assertCount(1, $preview['conflicts']);

        $conflict = $preview['conflicts'][0];

        self::assertSame('invalid_draft', $conflict['type']);
        self::assertSame(
            'Le draft ne possède pas d’horaire valide.',
            $conflict['message']
        );
        self::assertSame($draft, $conflict['draft']);
        self::assertSame([], $conflict['existingDiffusions']);

        self::assertCount(1, $preview['items']);

        $item = $preview['items'][0];

        self::assertSame($draft, $item['draft']);
        self::assertNull($item['action']);
        self::assertNull($item['targetDiffusion']);
        self::assertNull($item['startsAt']);
        self::assertTrue($item['hasConflict']);
        self::assertSame([$conflict], $item['conflicts']);
    }

    public function testPreviewDetectsConflictBetweenTwoPublishedDraftsAtSameTime(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $firstDiffusion = $this->createDiffusionMock(
            100,
            '2026-09-09 10:00:00'
        );

        $secondDiffusion = $this->createDiffusionMock(
            101,
            '2026-09-09 10:00:00'
        );

        /*
     * Ancienne Diffusion au même horaire,
     * mais non réservée par un Draft.
     * Elle doit simplement être ignorée.
     */
        $unclaimedDiffusion = $this->createDiffusionMock(
            102,
            '2026-09-09 10:00:00'
        );

        $firstDraft = $this->createDraft(
            id: 1,
            startsAt: '2026-09-09 10:00:00',
            publishedDiffusion: $firstDiffusion
        );

        $secondDraft = $this->createDraft(
            id: 2,
            startsAt: '2026-09-09 10:00:00',
            publishedDiffusion: $secondDiffusion
        );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([
                $firstDraft,
                $secondDraft,
            ]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([
                $firstDiffusion,
                $secondDiffusion,
                $unclaimedDiffusion,
            ]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication(
            $weekStart
        );

        self::assertSame(2, $preview['publishableDraftCount']);
        self::assertSame(2, $preview['updateCount']);
        self::assertSame(2, $preview['conflictCount']);

        self::assertTrue($preview['hasBlockingConflicts']);
        self::assertFalse($preview['canPublish']);

        self::assertTrue(
            $preview['items'][0]['hasConflict']
        );

        self::assertTrue(
            $preview['items'][1]['hasConflict']
        );

        self::assertSame(
            'diffusion_reserved_by_other_draft',
            $preview['items'][0]['conflicts'][0]['type']
        );

        self::assertSame(
            'diffusion_reserved_by_other_draft',
            $preview['items'][1]['conflicts'][0]['type']
        );

        self::assertSame(
            [$secondDiffusion],
            $preview['items'][0]['conflicts'][0]['existingDiffusions']
        );

        self::assertSame(
            [$firstDiffusion],
            $preview['items'][1]['conflicts'][0]['existingDiffusions']
        );
    }

    public function testPublishWeekSortsDraftsByStartTime(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $emission = $this->createMock(Emission::class);
        $emission->method('getId')->willReturn(600);

        $laterDraft = $this->createMock(DiffusionDraft::class);
        $laterDraft->method('getId')->willReturn(20);
        $laterDraft->method('getHoraireDiffusion')->willReturn(
            new \DateTimeImmutable('2026-09-09 15:00:00')
        );
        $laterDraft->method('getPublishedDiffusion')->willReturn(null);
        $laterDraft->method('getEmission')->willReturn($emission);
        $laterDraft->method('getNombreDiffusion')->willReturn(1);
        $laterDraft->method('getEffectiveDurationMinutes')->willReturn(30);
        $laterDraft->method('getEndsAt')->willReturn(null);

        $earlierDraft = $this->createMock(DiffusionDraft::class);
        $earlierDraft->method('getId')->willReturn(10);
        $earlierDraft->method('getHoraireDiffusion')->willReturn(
            new \DateTimeImmutable('2026-09-09 10:00:00')
        );
        $earlierDraft->method('getPublishedDiffusion')->willReturn(null);
        $earlierDraft->method('getEmission')->willReturn($emission);
        $earlierDraft->method('getNombreDiffusion')->willReturn(1);
        $earlierDraft->method('getEffectiveDurationMinutes')->willReturn(30);
        $earlierDraft->method('getEndsAt')->willReturn(null);

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([
                $laterDraft,
                $earlierDraft,
            ]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('persist');

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $result = $this->service->publishWeek($weekStart);

        self::assertSame(
            [
                $earlierDraft,
                $laterDraft,
            ],
            $result['publishedDrafts']
        );
    }

    public function testPublishWeekSortsSameStartTimeByDraftId(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $emission = $this->createMock(Emission::class);
        $emission->method('getId')->willReturn(601);

        $secondDraft = $this->createMock(DiffusionDraft::class);
        $secondDraft->method('getId')->willReturn(20);
        $secondDraft->method('getHoraireDiffusion')->willReturn(
            new \DateTimeImmutable('2026-09-09 10:00:00')
        );
        $secondDraft->method('getPublishedDiffusion')->willReturn(null);
        $secondDraft->method('getEmission')->willReturn($emission);
        $secondDraft->method('getNombreDiffusion')->willReturn(2);
        $secondDraft->method('getEffectiveDurationMinutes')->willReturn(30);
        $secondDraft->method('getEndsAt')->willReturn(null);

        $firstDraft = $this->createMock(DiffusionDraft::class);
        $firstDraft->method('getId')->willReturn(10);
        $firstDraft->method('getHoraireDiffusion')->willReturn(
            new \DateTimeImmutable('2026-09-09 10:00:00')
        );
        $firstDraft->method('getPublishedDiffusion')->willReturn(null);
        $firstDraft->method('getEmission')->willReturn($emission);
        $firstDraft->method('getNombreDiffusion')->willReturn(1);
        $firstDraft->method('getEffectiveDurationMinutes')->willReturn(30);
        $firstDraft->method('getEndsAt')->willReturn(null);

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([
                $secondDraft,
                $firstDraft,
            ]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('persist');

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $result = $this->service->publishWeek($weekStart);

        self::assertSame(
            [
                $firstDraft,
                $secondDraft,
            ],
            $result['publishedDrafts']
        );
    }

    public function testPublishWeekPopulatesPendingRebroadcastPoolWithPublishedDiffusions(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-22 00:00:00',
            new \DateTimeZone('UTC')
        );

        $emission = $this->createMock(Emission::class);

        $emission
            ->method('getId')
            ->willReturn(600);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(20);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable(
                    '2026-09-23 10:00:00',
                    new \DateTimeZone('UTC')
                )
            );

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $draft
            ->method('getAssignmentGroupKey')
            ->willReturn('group_600');

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(1);

        $draft
            ->method('getEffectiveDurationMinutes')
            ->willReturn(60);

        $draft
            ->method('getEndsAt')
            ->willReturn(null);

        $draft
            ->expects(self::once())
            ->method('markAsPublished')
            ->with(
                self::isInstanceOf(Diffusion::class),
                self::isInstanceOf(\DateTimeImmutable::class)
            );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->pendingRebroadcastService
            ->expects(self::once())
            ->method('populateFromPublishedDiffusions')
            ->with(
                self::callback(
                    static function (array $diffusions) use ($emission): bool {
                        if (1 !== count($diffusions)) {
                            return false;
                        }

                        $diffusion = $diffusions[0];

                        return $diffusion instanceof Diffusion
                            && $diffusion->getEmission() === $emission
                            && 'group_600' === $diffusion->getAssignmentGroupKey()
                            && $diffusion->isPublished();
                    }
                ),
                self::callback(
                    static function (\DateTimeInterface $before): bool {
                        return '2026-10-06 00:00:00'
                            === $before->format('Y-m-d H:i:s');
                    }
                )
            )
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Diffusion::class));

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service->publishWeek($weekStart);

        self::assertTrue($result['published']);
        self::assertSame(1, $result['createdCount']);
        self::assertSame(1, $result['publishedDraftCount']);
    }

    public function testPublishWeekFlushesDiffusionsBeforePopulatingPendingRebroadcastPool(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-09-22 00:00:00',
            new \DateTimeZone('UTC')
        );

        $emission = $this->createMock(Emission::class);
        $emission
            ->method('getId')
            ->willReturn(600);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getId')
            ->willReturn(20);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable(
                    '2026-09-23 10:00:00',
                    new \DateTimeZone('UTC')
                )
            );

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $draft
            ->method('getAssignmentGroupKey')
            ->willReturn('group_600');

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(1);

        $draft
            ->method('getEffectiveDurationMinutes')
            ->willReturn(60);

        $draft
            ->method('getEndsAt')
            ->willReturn(null);

        $draft
            ->expects(self::once())
            ->method('markAsPublished')
            ->with(
                self::isInstanceOf(Diffusion::class),
                self::isInstanceOf(\DateTimeImmutable::class)
            );

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Diffusion::class));

        /*
     * On enregistre explicitement l'ordre des opérations critiques.
     *
     * L'ordre attendu est :
     *
     * 1. flush des Diffusion/DiffusionDraft ;
     * 2. alimentation du parc ;
     * 3. flush des éventuels PendingRebroadcast.
     */
        $operations = [];

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush')
            ->willReturnCallback(
                static function () use (&$operations): void {
                    $operations[] = 'flush';
                }
            );

        $this->pendingRebroadcastService
            ->expects(self::once())
            ->method('populateFromPublishedDiffusions')
            ->with(
                self::callback(
                    static function (array $diffusions): bool {
                        return 1 === count($diffusions)
                            && $diffusions[0] instanceof Diffusion
                            && $diffusions[0]->isPublished();
                    }
                ),
                self::isInstanceOf(\DateTimeInterface::class)
            )
            ->willReturnCallback(
                static function (
                    array $diffusions,
                    \DateTimeInterface $before
                ) use (&$operations): array {
                    $operations[] = 'populate';

                    return [];
                }
            );

        $this->service->publishWeek($weekStart);

        self::assertSame(
            [
                'flush',
                'populate',
                'flush',
            ],
            $operations
        );
    }

    public function testPreviewIgnoresRescheduledOriginWhenDestinationIsOutsideWeek(): void
    {
        $weekStart = new \DateTimeImmutable('2026-10-06 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-13 00:00:00');

        $firstSlot = $this->createMock(ProgrammationRuleSlot::class);
        $firstSlot
            ->method('getId')
            ->willReturn(57);

        $rescheduledSlot = $this->createMock(ProgrammationRuleSlot::class);
        $rescheduledSlot
            ->method('getId')
            ->willReturn(100);

        $firstDiffusion = $this->createDiffusionMock(
            1867219,
            '2026-10-07 17:00:00'
        );

        $rescheduledDiffusion = $this->createDiffusionMock(
            1867220,
            '2026-10-07 17:00:00'
        );

        $firstDraft = $this->createDraft(
            id: 665,
            startsAt: '2026-10-07 17:00:00',
            assignmentGroupKey: 'rule_25_origin_20261002_1230',
            publishedDiffusion: $firstDiffusion,
            slot: $firstSlot
        );

        $rescheduledDraft = $this->createDraft(
            id: 702,
            startsAt: '2026-10-07 17:00:00',
            assignmentGroupKey: 'rule_43_origin_20261007_1700',
            publishedDiffusion: $rescheduledDiffusion,
            slot: $rescheduledSlot
        );

        $arbitration = new GridSlotArbitration();
        $arbitration
            ->setSlot($rescheduledSlot)
            ->setOriginalStartsAt(
                new \DateTimeImmutable('2026-10-07 17:00:00')
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-10-07 18:00:00')
            )
            ->setAction(
                GridSlotArbitration::ACTION_RESCHEDULE_PREVIOUS_WEEK
            )
            ->setRescheduledStartsAt(
                new \DateTimeImmutable('2026-09-30 17:00:00')
            )
            ->setRescheduledEndsAt(
                new \DateTimeImmutable('2026-09-30 18:00:00')
            );

        $arbitration->markResolved();

        $this->relevantArbitrations = [$arbitration];

        $this->draftRepository
            ->expects(self::once())
            ->method('findPublishableByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([
                $firstDraft,
                $rescheduledDraft,
            ]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([
                $firstDiffusion,
                $rescheduledDiffusion,
            ]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication($weekStart);

        self::assertSame(1, $preview['publishableDraftCount']);
        self::assertSame(0, $preview['createCount']);
        self::assertSame(1, $preview['updateCount']);
        self::assertSame(0, $preview['conflictCount']);

        self::assertFalse($preview['hasBlockingConflicts']);
        self::assertTrue($preview['canPublish']);

        self::assertCount(1, $preview['items']);

        $item = $preview['items'][0];

        self::assertSame($firstDraft, $item['draft']);
        self::assertSame('update', $item['action']);
        self::assertSame($firstDiffusion, $item['targetDiffusion']);

        self::assertSame(
            '2026-10-07 17:00:00',
            $item['startsAt']->format('Y-m-d H:i:s')
        );
    }

    public function testPreviewIgnoresCancelledOccurrence(): void
    {
        $weekStart = new \DateTimeImmutable('2026-10-06 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-13 00:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);
        $slot
            ->method('getId')
            ->willReturn(100);

        $draft = $this->createDraft(
            id: 702,
            startsAt: '2026-10-07 17:00:00',
            assignmentGroupKey: 'rule_43_origin_20261007_1700',
            slot: $slot
        );

        $arbitration = new GridSlotArbitration();
        $arbitration
            ->setSlot($slot)
            ->setOriginalStartsAt(
                new \DateTimeImmutable('2026-10-07 17:00:00')
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-10-07 18:00:00')
            )
            ->setAction(GridSlotArbitration::ACTION_CANCEL);

        $arbitration->markResolved();

        $this->relevantArbitrations = [$arbitration];

        $this->draftRepository
            ->expects(self::once())
            ->method('findPublishableByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->with([], $weekEnd)
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication($weekStart);

        self::assertSame(0, $preview['publishableDraftCount']);
        self::assertSame(0, $preview['createCount']);
        self::assertSame(0, $preview['updateCount']);
        self::assertSame(0, $preview['conflictCount']);

        self::assertFalse($preview['hasBlockingConflicts']);
        self::assertFalse($preview['canPublish']);

        self::assertSame([], $preview['items']);
    }

    public function testPreviewUsesRescheduledTimeWhenOccurrenceMovesInsideSameWeek(): void
    {
        $weekStart = new \DateTimeImmutable('2026-10-06 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-13 00:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);
        $slot
            ->method('getId')
            ->willReturn(100);

        $draft = $this->createDraft(
            id: 702,
            startsAt: '2026-10-07 10:00:00',
            assignmentGroupKey: 'rule_43_origin_20261007_1000',
            slot: $slot
        );

        $arbitration = new GridSlotArbitration();
        $arbitration
            ->setSlot($slot)
            ->setOriginalStartsAt(
                new \DateTimeImmutable('2026-10-07 10:00:00')
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-10-07 11:00:00')
            )
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt(
                new \DateTimeImmutable('2026-10-07 14:00:00')
            )
            ->setRescheduledEndsAt(
                new \DateTimeImmutable('2026-10-07 15:00:00')
            );

        $arbitration->markResolved();

        $this->relevantArbitrations = [$arbitration];

        $this->draftRepository
            ->expects(self::once())
            ->method('findPublishableByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication($weekStart);

        self::assertSame(1, $preview['publishableDraftCount']);
        self::assertSame(1, $preview['createCount']);
        self::assertSame(0, $preview['updateCount']);
        self::assertSame(0, $preview['conflictCount']);

        self::assertTrue($preview['canPublish']);

        self::assertCount(1, $preview['items']);

        $item = $preview['items'][0];

        self::assertSame($draft, $item['draft']);
        self::assertSame('create', $item['action']);

        self::assertSame(
            '2026-10-07 14:00:00',
            $item['startsAt']->format('Y-m-d H:i:s')
        );
    }

    public function testPreviewIncludesOccurrenceRescheduledIntoWeekFromPreviousWeek(): void
    {
        $weekStart = new \DateTimeImmutable('2026-10-06 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-13 00:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);
        $slot
            ->method('getId')
            ->willReturn(100);

        $originalStartsAt = new \DateTimeImmutable(
            '2026-09-30 17:00:00'
        );

        $draft = $this->createDraft(
            id: 702,
            startsAt: '2026-09-30 17:00:00',
            assignmentGroupKey: 'rule_43_origin_20260930_1700',
            slot: $slot
        );

        $arbitration = new GridSlotArbitration();
        $arbitration
            ->setSlot($slot)
            ->setOriginalStartsAt($originalStartsAt)
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-09-30 18:00:00')
            )
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_NEXT_WEEK)
            ->setRescheduledStartsAt(
                new \DateTimeImmutable('2026-10-07 17:00:00')
            )
            ->setRescheduledEndsAt(
                new \DateTimeImmutable('2026-10-07 18:00:00')
            );

        $arbitration->markResolved();

        $this->relevantArbitrations = [$arbitration];

        $this->draftRepository
            ->expects(self::once())
            ->method('findPublishableByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findOneActiveDraftBySlotAndHoraire')
            ->with($slot, $originalStartsAt)
            ->willReturn($draft);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication($weekStart);

        self::assertSame(1, $preview['publishableDraftCount']);
        self::assertSame(1, $preview['createCount']);
        self::assertSame(0, $preview['updateCount']);
        self::assertSame(0, $preview['conflictCount']);

        self::assertTrue($preview['canPublish']);

        self::assertCount(1, $preview['items']);

        $item = $preview['items'][0];

        self::assertSame($draft, $item['draft']);
        self::assertSame('create', $item['action']);

        self::assertSame(
            '2026-10-07 17:00:00',
            $item['startsAt']->format('Y-m-d H:i:s')
        );
    }

    public function testPublishWeekUsesEffectiveRescheduledTimeFromPreview(): void
    {
        $weekStart = new \DateTimeImmutable(
            '2026-10-06 00:00:00'
        );

        $originalStartsAt = new \DateTimeImmutable(
            '2026-10-07 10:00:00'
        );

        $originalEndsAt = new \DateTimeImmutable(
            '2026-10-07 11:00:00'
        );

        $rescheduledStartsAt = new \DateTimeImmutable(
            '2026-10-07 14:00:00'
        );

        $rescheduledEndsAt = new \DateTimeImmutable(
            '2026-10-07 15:00:00'
        );

        $emission = $this->createMock(Emission::class);

        $emission
            ->method('getId')
            ->willReturn(700);

        $slot = $this->createMock(
            ProgrammationRuleSlot::class
        );

        $slot
            ->method('getId')
            ->willReturn(100);

        $draft = $this->createMock(
            DiffusionDraft::class
        );

        $draft
            ->method('getId')
            ->willReturn(702);

        /*
     * Le Draft reste volontairement à son horaire d'origine.
     *
     * C'est l'arbitrage qui définit son horaire effectif de publication.
     */
        $draft
            ->method('getHoraireDiffusion')
            ->willReturn($originalStartsAt);

        $draft
            ->method('getSlot')
            ->willReturn($slot);

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn(null);

        $draft
            ->method('getAssignmentGroupKey')
            ->willReturn(
                'rule_43_origin_20261007_1000'
            );

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(1);

        $draft
            ->method('getEffectiveDurationMinutes')
            ->willReturn(60);

        $draft
            ->method('getEndsAt')
            ->willReturn($originalEndsAt);

        $draft
            ->expects(self::once())
            ->method('markAsPublished')
            ->with(
                self::isInstanceOf(Diffusion::class),
                self::isInstanceOf(\DateTimeImmutable::class)
            );

        $arbitration = new GridSlotArbitration();

        $arbitration
            ->setSlot($slot)
            ->setOriginalStartsAt($originalStartsAt)
            ->setOriginalEndsAt($originalEndsAt)
            ->setAction(
                GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM
            )
            ->setRescheduledStartsAt($rescheduledStartsAt)
            ->setRescheduledEndsAt($rescheduledEndsAt);

        $arbitration->markResolved();

        $this->relevantArbitrations = [$arbitration];

        $this->draftRepository
            ->method('findPublishableByWeek')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

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
            ->expects(self::exactly(2))
            ->method('flush');

        $this->entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service->publishWeek(
            $weekStart
        );

        self::assertTrue($result['published']);
        self::assertSame(1, $result['createdCount']);
        self::assertSame(1, $result['publishedDraftCount']);

        self::assertInstanceOf(
            Diffusion::class,
            $persisted
        );

        /*
     * Point essentiel du test :
     *
     * la Diffusion doit être publiée à l'horaire EFFECTIF
     * défini par l'arbitrage, et non à l'horaire conservé
     * dans DiffusionDraft.
     */
        self::assertSame(
            '2026-10-07 14:00:00',
            $persisted
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-10-07 15:00:00',
            $persisted
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testPreviewAllowsReusingTimeFreedByMovedLinkedDiffusion(): void
    {
        $weekStart = new \DateTimeImmutable('2026-10-20 00:00:00');

        /*
     * Ancienne diffusion publiée à 17 h.
     * Après dévalidation, son draft a été déplacé à 14 h.
     */
        $diffusion = $this->createDiffusionMock(
            1867404,
            '2026-10-26 17:00:00'
        );

        $movedDraft = $this->createDraft(
            id: 929,
            startsAt: '2026-10-26 14:00:00',
            publishedDiffusion: $diffusion
        );

        /*
     * Nouvelle émission affectée au créneau libéré de 17 h.
     * Elle ne possède pas encore de diffusion publiée.
     */
        $newDraft = $this->createDraft(
            id: 1003,
            startsAt: '2026-10-26 17:00:00'
        );

        $this->draftRepository
            ->expects(self::once())
            ->method('findPublishableByWeek')
            ->willReturn([$movedDraft, $newDraft]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findByWeek')
            ->willReturn([$diffusion]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findFutureDraftsByAssignmentGroupKeys')
            ->willReturn([]);

        $preview = $this->service->previewWeekPublication($weekStart);

        self::assertSame(2, $preview['publishableDraftCount']);
        self::assertSame(0, $preview['conflictCount']);
        self::assertFalse($preview['hasBlockingConflicts']);
        self::assertTrue($preview['canPublish']);

        self::assertSame(1, $preview['updateCount']);
        self::assertSame(1, $preview['createCount']);

        $itemsByDraftId = [];

        foreach ($preview['items'] as $item) {
            $itemsByDraftId[$item['draft']->getId()] = $item;
        }

        self::assertCount(2, $itemsByDraftId);

        // L'ancienne diffusion conserve son identité et passe à 14 h.
        self::assertSame('update', $itemsByDraftId[929]['action']);
        self::assertSame(
            $diffusion,
            $itemsByDraftId[929]['targetDiffusion']
        );
        self::assertSame(
            '2026-10-26 14:00:00',
            $itemsByDraftId[929]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertFalse($itemsByDraftId[929]['hasConflict']);

        // La nouvelle émission obtient sa propre diffusion à 17 h.
        self::assertSame('create', $itemsByDraftId[1003]['action']);
        self::assertNull($itemsByDraftId[1003]['targetDiffusion']);
        self::assertSame(
            '2026-10-26 17:00:00',
            $itemsByDraftId[1003]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertFalse($itemsByDraftId[1003]['hasConflict']);
    }
}
