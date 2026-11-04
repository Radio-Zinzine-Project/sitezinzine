<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DiffusionDraft;
use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use App\Repository\GridSlotArbitrationRepository;
use App\Service\GridDraftOverlapService;
use PHPUnit\Framework\TestCase;

final class GridDraftOverlapServiceTest extends TestCase
{
    private DiffusionDraftRepository $draftRepository;
    private GridSlotArbitrationRepository $arbitrationRepository;
    private GridDraftOverlapService $service;

    protected function setUp(): void
    {
        $this->draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $this->arbitrationRepository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $this->service = new GridDraftOverlapService(
            $this->draftRepository,
            $this->arbitrationRepository
        );
    }

    public function testNonRegularDraftIsBlockingWithoutCheckingArbitration(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->expects(self::once())
            ->method('getDraftType')
            ->willReturn(DiffusionDraft::TYPE_MANUAL_SPECIAL);

        $this->draftRepository
            ->expects(self::once())
            ->method('findOverlappingDrafts')
            ->with($startsAt, $endsAt, null)
            ->willReturn([$draft]);

        $this->arbitrationRepository
            ->expects(self::never())
            ->method('findOneBy');

        $result = $this->service->findBlockingOverlaps(
            $startsAt,
            $endsAt
        );

        self::assertSame([$draft], $result);
    }

    public function testRegularDraftWithoutSlotIsBlocking(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getDraftType')
            ->willReturn(DiffusionDraft::TYPE_REGULAR);

        $draft
            ->method('getSlot')
            ->willReturn(null);

        $this->draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $this->arbitrationRepository
            ->expects(self::never())
            ->method('findOneBy');

        $result = $this->service->findBlockingOverlaps(
            $startsAt,
            $endsAt
        );

        self::assertSame([$draft], $result);
    }

    public function testRegularDraftWithoutPersistedSlotIsBlocking(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(null);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getDraftType')
            ->willReturn(DiffusionDraft::TYPE_REGULAR);

        $draft
            ->method('getSlot')
            ->willReturn($slot);

        $this->draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $this->arbitrationRepository
            ->expects(self::never())
            ->method('findOneBy');

        $result = $this->service->findBlockingOverlaps(
            $startsAt,
            $endsAt
        );

        self::assertSame([$draft], $result);
    }

    public function testRegularDraftWithoutArbitrationIsBlocking(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');
        $draftStartsAt = new \DateTimeImmutable('2026-10-07 17:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(57);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getDraftType')
            ->willReturn(DiffusionDraft::TYPE_REGULAR);

        $draft
            ->method('getSlot')
            ->willReturn($slot);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn($draftStartsAt);

        $this->draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $this->arbitrationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with([
                'slot' => $slot,
                'originalStartsAt' => $draftStartsAt,
            ])
            ->willReturn(null);

        $result = $this->service->findBlockingOverlaps(
            $startsAt,
            $endsAt
        );

        self::assertSame([$draft], $result);
    }

    public function testCancelledRegularDraftIsNotBlocking(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');
        $draftStartsAt = new \DateTimeImmutable('2026-10-07 17:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(100);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getDraftType')
            ->willReturn(DiffusionDraft::TYPE_REGULAR);

        $draft
            ->method('getSlot')
            ->willReturn($slot);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn($draftStartsAt);

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt($draftStartsAt)
            ->setOriginalEndsAt($endsAt)
            ->setAction(GridSlotArbitration::ACTION_CANCEL);

        $this->draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $this->arbitrationRepository
            ->method('findOneBy')
            ->willReturn($arbitration);

        $result = $this->service->findBlockingOverlaps(
            $startsAt,
            $endsAt
        );

        self::assertSame([], $result);
    }

    public function testRescheduledRegularDraftIsNotBlocking(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');
        $draftStartsAt = new \DateTimeImmutable('2026-10-07 17:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(100);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getDraftType')
            ->willReturn(DiffusionDraft::TYPE_REGULAR);

        $draft
            ->method('getSlot')
            ->willReturn($slot);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn($draftStartsAt);

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt($draftStartsAt)
            ->setOriginalEndsAt($endsAt)
            ->setAction(
                GridSlotArbitration::ACTION_RESCHEDULE_PREVIOUS_WEEK
            );

        $this->draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $this->arbitrationRepository
            ->method('findOneBy')
            ->willReturn($arbitration);

        $result = $this->service->findBlockingOverlaps(
            $startsAt,
            $endsAt
        );

        self::assertSame([], $result);
    }

    public function testKeepArbitrationLeavesRegularDraftBlocking(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');
        $draftStartsAt = new \DateTimeImmutable('2026-10-07 17:00:00');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(100);

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getDraftType')
            ->willReturn(DiffusionDraft::TYPE_REGULAR);

        $draft
            ->method('getSlot')
            ->willReturn($slot);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn($draftStartsAt);

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt($draftStartsAt)
            ->setOriginalEndsAt($endsAt)
            ->setAction(GridSlotArbitration::ACTION_KEEP);

        $this->draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        $this->arbitrationRepository
            ->method('findOneBy')
            ->willReturn($arbitration);

        $result = $this->service->findBlockingOverlaps(
            $startsAt,
            $endsAt
        );

        self::assertSame([$draft], $result);
    }

    public function testExcludeDraftIdIsForwardedToRepository(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');

        $this->draftRepository
            ->expects(self::once())
            ->method('findOverlappingDrafts')
            ->with(
                $startsAt,
                $endsAt,
                665
            )
            ->willReturn([]);

        $result = $this->service->findBlockingOverlaps(
            $startsAt,
            $endsAt,
            665
        );

        self::assertSame([], $result);
    }

    public function testHasBlockingOverlapReturnsFalseWhenNoBlockingDraftExists(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');

        $this->draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([]);

        self::assertFalse(
            $this->service->hasBlockingOverlap(
                $startsAt,
                $endsAt
            )
        );
    }

    public function testHasBlockingOverlapReturnsTrueWhenBlockingDraftExists(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-07 17:00:00');
        $endsAt = new \DateTimeImmutable('2026-10-07 18:00:00');

        $draft = $this->createMock(DiffusionDraft::class);

        $draft
            ->method('getDraftType')
            ->willReturn(DiffusionDraft::TYPE_MANUAL_SPECIAL);

        $this->draftRepository
            ->method('findOverlappingDrafts')
            ->willReturn([$draft]);

        self::assertTrue(
            $this->service->hasBlockingOverlap(
                $startsAt,
                $endsAt
            )
        );
    }
}