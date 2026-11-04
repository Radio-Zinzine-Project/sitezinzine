<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DiffusionDraft;
use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use App\Repository\GridSlotArbitrationRepository;
use App\Service\GridConflictDetector;
use App\Service\GridOccurrenceProjectionService;
use App\Service\GridPlacementConflictService;
use App\Service\ProgrammationGridBuilder;
use PHPUnit\Framework\TestCase;

class GridPlacementConflictServiceTest extends TestCase
{
    public function testFindBlockingDraftOverlapsKeepsNonRegularDraft(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $draft = new DiffusionDraft();
        $draft->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL);
        $draft->setSchedule($startsAt, 60);

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->expects($this->once())
            ->method('findOverlappingDrafts')
            ->with($startsAt, $endsAt, null)
            ->willReturn([$draft]);

        $arbitrationRepository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $arbitrationRepository
            ->expects($this->never())
            ->method('findOneBy');

        $service = $this->createService(
            $draftRepository,
            $arbitrationRepository
        );

        $result = $service->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt
        );

        $this->assertCount(1, $result);
        $this->assertSame($draft, $result[0]);
    }

    public function testFindBlockingDraftOverlapsKeepsRegularDraftWithoutArbitration(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(123);

        $draft = new DiffusionDraft();
        $draft->setDraftType(DiffusionDraft::TYPE_REGULAR);
        $draft->setSlot($slot);
        $draft->setSchedule($startsAt, 60);

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->expects($this->once())
            ->method('findOverlappingDrafts')
            ->with($startsAt, $endsAt, null)
            ->willReturn([$draft]);

        $arbitrationRepository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $arbitrationRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with([
                'slot' => $slot,
                'originalStartsAt' => $startsAt,
            ])
            ->willReturn(null);

        $service = $this->createService(
            $draftRepository,
            $arbitrationRepository
        );

        $result = $service->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt
        );

        $this->assertCount(1, $result);
        $this->assertSame($draft, $result[0]);
    }

    public function testFindBlockingDraftOverlapsIgnoresCancelledRegularDraft(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(123);

        $draft = new DiffusionDraft();
        $draft->setDraftType(DiffusionDraft::TYPE_REGULAR);
        $draft->setSlot($slot);
        $draft->setSchedule($startsAt, 60);

        $arbitration = $this->createMock(
            GridSlotArbitration::class
        );

        $arbitration
            ->method('isCancelAction')
            ->willReturn(true);

        $arbitration
            ->method('isRescheduleAction')
            ->willReturn(false);

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $arbitrationRepository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $arbitrationRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn($arbitration);

        $service = $this->createService(
            $draftRepository,
            $arbitrationRepository
        );

        $result = $service->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt
        );

        $this->assertSame([], $result);
    }

    public function testFindBlockingDraftOverlapsIgnoresRescheduledRegularDraft(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(123);

        $draft = new DiffusionDraft();
        $draft->setDraftType(DiffusionDraft::TYPE_REGULAR);
        $draft->setSlot($slot);
        $draft->setSchedule($startsAt, 60);

        $arbitration = $this->createMock(
            GridSlotArbitration::class
        );

        $arbitration
            ->method('isCancelAction')
            ->willReturn(false);

        $arbitration
            ->method('isRescheduleAction')
            ->willReturn(true);

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $arbitrationRepository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $arbitrationRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn($arbitration);

        $service = $this->createService(
            $draftRepository,
            $arbitrationRepository
        );

        $result = $service->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt
        );

        $this->assertSame([], $result);
    }

    public function testFindBlockingDraftOverlapsKeepsRegularDraftWhenArbitrationDoesNotCancelOrReschedule(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(123);

        $draft = new DiffusionDraft();
        $draft->setDraftType(DiffusionDraft::TYPE_REGULAR);
        $draft->setSlot($slot);
        $draft->setSchedule($startsAt, 60);

        $arbitration = $this->createMock(
            GridSlotArbitration::class
        );

        $arbitration
            ->method('isCancelAction')
            ->willReturn(false);

        $arbitration
            ->method('isRescheduleAction')
            ->willReturn(false);

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $arbitrationRepository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $arbitrationRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with([
                'slot' => $slot,
                'originalStartsAt' => $startsAt,
            ])
            ->willReturn($arbitration);

        $service = $this->createService(
            $draftRepository,
            $arbitrationRepository
        );

        $result = $service->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt
        );

        $this->assertCount(1, $result);
        $this->assertSame($draft, $result[0]);
    }

    public function testFindBlockingDraftOverlapsPassesExcludedDraftIdToRepository(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->expects($this->once())
            ->method('findOverlappingDrafts')
            ->with($startsAt, $endsAt, 42)
            ->willReturn([]);

        $service = $this->createService($draftRepository);

        $result = $service->findBlockingDraftOverlaps(
            $startsAt,
            $endsAt,
            42
        );

        $this->assertSame([], $result);
    }

    public function testFindBlockingRegularOverlapsUsesProjectedRadioWeek(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-22 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-22 15:00:00');

        $weekStart = new \DateTimeImmutable('2026-10-20 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-27 00:00:00');

        $builtSegments = [
            [
                [
                    'startsAt' => '2026-10-22 14:00:00',
                    'endsAt' => '2026-10-22 15:00:00',
                ],
            ],
        ];

        $projectedSegments = [
            [
                [
                    'startsAt' => '2026-10-22 14:00:00',
                    'endsAt' => '2026-10-22 15:00:00',
                    'isBlocking' => true,
                ],
            ],
        ];

        $expectedOverlaps = [
            [
                'startsAt' => '2026-10-22 14:00:00',
                'endsAt' => '2026-10-22 15:00:00',
                'isBlocking' => true,
            ],
        ];

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $arbitrationRepository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $gridBuilder = $this->createMock(
            ProgrammationGridBuilder::class
        );

        $gridBuilder
            ->expects($this->once())
            ->method('buildForWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn($builtSegments);

        $projectionService = $this->createMock(
            GridOccurrenceProjectionService::class
        );

        $projectionService
            ->expects($this->once())
            ->method('applyForWeek')
            ->with($builtSegments, $weekStart, $weekEnd)
            ->willReturn($projectedSegments);

        $conflictDetector = $this->createMock(
            GridConflictDetector::class
        );

        $conflictDetector
            ->expects($this->once())
            ->method('findBlockingOverlapsForRange')
            ->with(
                $projectedSegments,
                $startsAt,
                $endsAt
            )
            ->willReturn($expectedOverlaps);

        $service = new GridPlacementConflictService(
            $draftRepository,
            $arbitrationRepository,
            $gridBuilder,
            $projectionService,
            $conflictDetector
        );

        $result = $service->findBlockingRegularOverlaps(
            $startsAt,
            $endsAt
        );

        $this->assertSame($expectedOverlaps, $result);
    }

    public function testHasBlockingDraftOverlapReturnsTrueWhenBlockingDraftExists(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $draft = new DiffusionDraft();
        $draft->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL);
        $draft->setSchedule($startsAt, 60);

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $service = $this->createService($draftRepository);

        $this->assertTrue(
            $service->hasBlockingDraftOverlap(
                $startsAt,
                $endsAt
            )
        );
    }

    public function testHasBlockingDraftOverlapReturnsFalseWithoutBlockingDraft(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([]);

        $service = $this->createService($draftRepository);

        $this->assertFalse(
            $service->hasBlockingDraftOverlap(
                $startsAt,
                $endsAt
            )
        );
    }

    public function testHasBlockingOverlapReturnsTrueImmediatelyWhenRegularOverlapExists(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $weekStart = new \DateTimeImmutable('2026-10-20 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-27 00:00:00');

        $builtSegments = [
            [
                [
                    'startsAt' => '2026-10-20 14:00:00',
                    'endsAt' => '2026-10-20 15:00:00',
                ],
            ],
        ];

        $projectedSegments = [
            [
                [
                    'startsAt' => '2026-10-20 14:00:00',
                    'endsAt' => '2026-10-20 15:00:00',
                    'isBlocking' => true,
                ],
            ],
        ];

        $regularOverlaps = [
            [
                'startsAt' => '2026-10-20 14:00:00',
                'endsAt' => '2026-10-20 15:00:00',
                'isBlocking' => true,
            ],
        ];

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->expects($this->never())
            ->method('findOverlappingDrafts');

        $gridBuilder = $this->createMock(
            ProgrammationGridBuilder::class
        );

        $gridBuilder
            ->expects($this->once())
            ->method('buildForWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn($builtSegments);

        $projectionService = $this->createMock(
            GridOccurrenceProjectionService::class
        );

        $projectionService
            ->expects($this->once())
            ->method('applyForWeek')
            ->with(
                $builtSegments,
                $weekStart,
                $weekEnd
            )
            ->willReturn($projectedSegments);

        $conflictDetector = $this->createMock(
            GridConflictDetector::class
        );

        $conflictDetector
            ->expects($this->once())
            ->method('findBlockingOverlapsForRange')
            ->with(
                $projectedSegments,
                $startsAt,
                $endsAt
            )
            ->willReturn($regularOverlaps);

        $service = $this->createService(
            $draftRepository,
            null,
            $gridBuilder,
            $projectionService,
            $conflictDetector
        );

        self::assertTrue(
            $service->hasBlockingOverlap(
                $startsAt,
                $endsAt,
                42
            )
        );
    }

    public function testHasBlockingOverlapFallsBackToDraftOverlapWhenNoRegularOverlapExists(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-20 14:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-20 15:00:00');

        $weekStart = new \DateTimeImmutable('2026-10-20 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-27 00:00:00');

        $builtSegments = [[]];
        $projectedSegments = [[]];

        $blockingDraft = new DiffusionDraft();

        $blockingDraft
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setSchedule($startsAt, 60);

        $draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $draftRepository
            ->expects($this->once())
            ->method('findOverlappingDrafts')
            ->with(
                $startsAt,
                $endsAt,
                42
            )
            ->willReturn([$blockingDraft]);

        $gridBuilder = $this->createMock(
            ProgrammationGridBuilder::class
        );

        $gridBuilder
            ->expects($this->once())
            ->method('buildForWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn($builtSegments);

        $projectionService = $this->createMock(
            GridOccurrenceProjectionService::class
        );

        $projectionService
            ->expects($this->once())
            ->method('applyForWeek')
            ->with(
                $builtSegments,
                $weekStart,
                $weekEnd
            )
            ->willReturn($projectedSegments);

        $conflictDetector = $this->createMock(
            GridConflictDetector::class
        );

        $conflictDetector
            ->expects($this->once())
            ->method('findBlockingOverlapsForRange')
            ->with(
                $projectedSegments,
                $startsAt,
                $endsAt
            )
            ->willReturn([]);

        $service = $this->createService(
            $draftRepository,
            null,
            $gridBuilder,
            $projectionService,
            $conflictDetector
        );

        self::assertTrue(
            $service->hasBlockingOverlap(
                $startsAt,
                $endsAt,
                42
            )
        );
    }

    private function createService(
        ?DiffusionDraftRepository $draftRepository = null,
        ?GridSlotArbitrationRepository $arbitrationRepository = null,
        ?ProgrammationGridBuilder $gridBuilder = null,
        ?GridOccurrenceProjectionService $projectionService = null,
        ?GridConflictDetector $conflictDetector = null
    ): GridPlacementConflictService {
        return new GridPlacementConflictService(
            $draftRepository
                ?? $this->createMock(DiffusionDraftRepository::class),
            $arbitrationRepository
                ?? $this->createMock(GridSlotArbitrationRepository::class),
            $gridBuilder
                ?? $this->createMock(ProgrammationGridBuilder::class),
            $projectionService
                ?? $this->createMock(GridOccurrenceProjectionService::class),
            $conflictDetector
                ?? $this->createMock(GridConflictDetector::class)
        );
    }
}
