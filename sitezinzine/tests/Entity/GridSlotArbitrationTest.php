<?php

namespace App\Tests\Entity;

use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRuleSlot;
use PHPUnit\Framework\TestCase;

class GridSlotArbitrationTest extends TestCase
{
    public function testInitialValues(): void
    {
        $before = new \DateTimeImmutable();

        $arbitration = new GridSlotArbitration();

        $after = new \DateTimeImmutable();

        $this->assertNull($arbitration->getId());
        $this->assertNull($arbitration->getSlot());
        $this->assertNull($arbitration->getOriginalStartsAt());
        $this->assertNull($arbitration->getOriginalEndsAt());
        $this->assertNull($arbitration->getRescheduledStartsAt());
        $this->assertNull($arbitration->getRescheduledEndsAt());
        $this->assertNull($arbitration->getNotes());
        $this->assertNull($arbitration->getResolvedAt());
        $this->assertNull($arbitration->getArbitrationGroupKey());

        $this->assertSame(
            GridSlotArbitration::TYPE_RULE_OVERLAP,
            $arbitration->getType()
        );

        $this->assertSame(
            GridSlotArbitration::STATUS_PENDING,
            $arbitration->getStatus()
        );

        $this->assertSame(
            GridSlotArbitration::ACTION_KEEP,
            $arbitration->getAction()
        );

        $this->assertFalse($arbitration->isRescheduleAction());
        $this->assertFalse($arbitration->isCancelAction());
        $this->assertTrue($arbitration->isActiveDecision());

        $this->assertGreaterThanOrEqual(
            $before,
            $arbitration->getCreatedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $arbitration->getCreatedAt()
        );

        $this->assertSame(
            $arbitration->getCreatedAt(),
            $arbitration->getUpdatedAt()
        );
    }

    public function testBasicGettersAndSetters(): void
    {
        $arbitration = new GridSlotArbitration();
        $slot = new ProgrammationRuleSlot();

        $startsAt = new \DateTimeImmutable(
            '2026-09-06 14:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-06 15:00:00'
        );

        $rescheduledStartsAt = new \DateTimeImmutable(
            '2026-09-13 16:00:00'
        );

        $rescheduledEndsAt = new \DateTimeImmutable(
            '2026-09-13 17:00:00'
        );

        $result = $arbitration
            ->setSlot($slot)
            ->setOriginalStartsAt($startsAt)
            ->setOriginalEndsAt($endsAt)
            ->setType(GridSlotArbitration::TYPE_MANUAL_OVERRIDE)
            ->setStatus(GridSlotArbitration::STATUS_RESOLVED)
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt($rescheduledStartsAt)
            ->setRescheduledEndsAt($rescheduledEndsAt)
            ->setNotes('Déplacement manuel')
            ->setArbitrationGroupKey('group_42');

        $this->assertSame($arbitration, $result);
        $this->assertSame($slot, $arbitration->getSlot());
        $this->assertSame($startsAt, $arbitration->getOriginalStartsAt());
        $this->assertSame($endsAt, $arbitration->getOriginalEndsAt());

        $this->assertSame(
            GridSlotArbitration::TYPE_MANUAL_OVERRIDE,
            $arbitration->getType()
        );

        $this->assertSame(
            GridSlotArbitration::STATUS_RESOLVED,
            $arbitration->getStatus()
        );

        $this->assertSame(
            GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM,
            $arbitration->getAction()
        );

        $this->assertSame(
            $rescheduledStartsAt,
            $arbitration->getRescheduledStartsAt()
        );

        $this->assertSame(
            $rescheduledEndsAt,
            $arbitration->getRescheduledEndsAt()
        );

        $this->assertSame(
            'Déplacement manuel',
            $arbitration->getNotes()
        );

        $this->assertSame(
            'group_42',
            $arbitration->getArbitrationGroupKey()
        );
    }

    public function testNullableFieldsCanBeCleared(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration
            ->setSlot(new ProgrammationRuleSlot())
            ->setRescheduledStartsAt(
                new \DateTimeImmutable('2026-09-06 14:00:00')
            )
            ->setRescheduledEndsAt(
                new \DateTimeImmutable('2026-09-06 15:00:00')
            )
            ->setNotes('Test')
            ->setArbitrationGroupKey('group_42');

        $arbitration
            ->setSlot(null)
            ->setRescheduledStartsAt(null)
            ->setRescheduledEndsAt(null)
            ->setNotes(null)
            ->setArbitrationGroupKey(null);

        $this->assertNull($arbitration->getSlot());
        $this->assertNull($arbitration->getRescheduledStartsAt());
        $this->assertNull($arbitration->getRescheduledEndsAt());
        $this->assertNull($arbitration->getNotes());
        $this->assertNull($arbitration->getArbitrationGroupKey());
    }

    public function testAllTypesCanBeSet(): void
    {
        $arbitration = new GridSlotArbitration();

        $types = [
            GridSlotArbitration::TYPE_RULE_OVERLAP,
            GridSlotArbitration::TYPE_MANUAL_OVERRIDE,
            GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT,
        ];

        foreach ($types as $type) {
            $result = $arbitration->setType($type);

            $this->assertSame($arbitration, $result);
            $this->assertSame($type, $arbitration->getType());
        }
    }

    public function testAllStatusesCanBeSet(): void
    {
        $arbitration = new GridSlotArbitration();

        $statuses = [
            GridSlotArbitration::STATUS_PENDING,
            GridSlotArbitration::STATUS_RESOLVED,
            GridSlotArbitration::STATUS_CANCELLED,
        ];

        foreach ($statuses as $status) {
            $result = $arbitration->setStatus($status);

            $this->assertSame($arbitration, $result);
            $this->assertSame($status, $arbitration->getStatus());
        }
    }

    public function testAllActionsCanBeSet(): void
    {
        $arbitration = new GridSlotArbitration();

        $actions = [
            GridSlotArbitration::ACTION_KEEP,
            GridSlotArbitration::ACTION_REPLACE,
            GridSlotArbitration::ACTION_CANCEL,
            GridSlotArbitration::ACTION_RESCHEDULE_PREVIOUS_WEEK,
            GridSlotArbitration::ACTION_RESCHEDULE_NEXT_WEEK,
            GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM,
        ];

        foreach ($actions as $action) {
            $result = $arbitration->setAction($action);

            $this->assertSame($arbitration, $result);
            $this->assertSame($action, $arbitration->getAction());
        }
    }

    public function testPreviousWeekIsRescheduleAction(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->setAction(
            GridSlotArbitration::ACTION_RESCHEDULE_PREVIOUS_WEEK
        );

        $this->assertTrue($arbitration->isRescheduleAction());
        $this->assertFalse($arbitration->isCancelAction());
    }

    public function testNextWeekIsRescheduleAction(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->setAction(
            GridSlotArbitration::ACTION_RESCHEDULE_NEXT_WEEK
        );

        $this->assertTrue($arbitration->isRescheduleAction());
        $this->assertFalse($arbitration->isCancelAction());
    }

    public function testCustomIsRescheduleAction(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->setAction(
            GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM
        );

        $this->assertTrue($arbitration->isRescheduleAction());
        $this->assertFalse($arbitration->isCancelAction());
    }

    public function testKeepIsNotRescheduleAction(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->setAction(
            GridSlotArbitration::ACTION_KEEP
        );

        $this->assertFalse($arbitration->isRescheduleAction());
    }

    public function testReplaceIsNotRescheduleAction(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->setAction(
            GridSlotArbitration::ACTION_REPLACE
        );

        $this->assertFalse($arbitration->isRescheduleAction());
    }

    public function testCancelAction(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->setAction(
            GridSlotArbitration::ACTION_CANCEL
        );

        $this->assertTrue($arbitration->isCancelAction());
        $this->assertFalse($arbitration->isRescheduleAction());
    }

    public function testMarkResolved(): void
    {
        $arbitration = new GridSlotArbitration();

        $before = new \DateTimeImmutable();

        $arbitration->markResolved();

        $after = new \DateTimeImmutable();

        $this->assertSame(
            GridSlotArbitration::STATUS_RESOLVED,
            $arbitration->getStatus()
        );

        $this->assertNotNull($arbitration->getResolvedAt());

        $this->assertGreaterThanOrEqual(
            $before,
            $arbitration->getResolvedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $arbitration->getResolvedAt()
        );

        $this->assertTrue($arbitration->isActiveDecision());
    }

    public function testMarkPendingClearsResolvedAt(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->markResolved();

        $this->assertNotNull($arbitration->getResolvedAt());

        $arbitration->markPending();

        $this->assertSame(
            GridSlotArbitration::STATUS_PENDING,
            $arbitration->getStatus()
        );

        $this->assertNull($arbitration->getResolvedAt());
        $this->assertTrue($arbitration->isActiveDecision());
    }

    public function testMarkCancelledClearsResolvedAt(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->markResolved();

        $this->assertNotNull($arbitration->getResolvedAt());

        $arbitration->markCancelled();

        $this->assertSame(
            GridSlotArbitration::STATUS_CANCELLED,
            $arbitration->getStatus()
        );

        $this->assertNull($arbitration->getResolvedAt());
        $this->assertFalse($arbitration->isActiveDecision());
    }

    public function testPendingAndResolvedAreActiveDecisions(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->setStatus(
            GridSlotArbitration::STATUS_PENDING
        );

        $this->assertTrue($arbitration->isActiveDecision());

        $arbitration->setStatus(
            GridSlotArbitration::STATUS_RESOLVED
        );

        $this->assertTrue($arbitration->isActiveDecision());
    }

    public function testCancelledIsNotActiveDecision(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->setStatus(
            GridSlotArbitration::STATUS_CANCELLED
        );

        $this->assertFalse($arbitration->isActiveDecision());
    }

    public function testTouchUpdatesUpdatedAt(): void
    {
        $arbitration = new GridSlotArbitration();

        $initialUpdatedAt = $arbitration->getUpdatedAt();

        usleep(1000);

        $arbitration->touch();

        $this->assertGreaterThan(
            $initialUpdatedAt,
            $arbitration->getUpdatedAt()
        );
    }

    public function testMarkResolvedTouchesUpdatedAt(): void
    {
        $arbitration = new GridSlotArbitration();

        $initialUpdatedAt = $arbitration->getUpdatedAt();

        usleep(1000);

        $arbitration->markResolved();

        $this->assertGreaterThan(
            $initialUpdatedAt,
            $arbitration->getUpdatedAt()
        );
    }

    public function testMarkPendingTouchesUpdatedAt(): void
    {
        $arbitration = new GridSlotArbitration();

        $arbitration->markResolved();

        $initialUpdatedAt = $arbitration->getUpdatedAt();

        usleep(1000);

        $arbitration->markPending();

        $this->assertGreaterThan(
            $initialUpdatedAt,
            $arbitration->getUpdatedAt()
        );
    }

    public function testMarkCancelledTouchesUpdatedAt(): void
    {
        $arbitration = new GridSlotArbitration();

        $initialUpdatedAt = $arbitration->getUpdatedAt();

        usleep(1000);

        $arbitration->markCancelled();

        $this->assertGreaterThan(
            $initialUpdatedAt,
            $arbitration->getUpdatedAt()
        );
    }
}