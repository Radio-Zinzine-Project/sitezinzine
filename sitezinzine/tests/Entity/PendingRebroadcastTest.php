<?php

namespace App\Tests\Entity;

use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use PHPUnit\Framework\TestCase;

class PendingRebroadcastTest extends TestCase
{
    public function testNewPendingRebroadcastHasCreationDate(): void
    {
        $beforeCreation = new \DateTimeImmutable();

        $pendingRebroadcast = new PendingRebroadcast();

        $afterCreation = new \DateTimeImmutable();

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $pendingRebroadcast->getCreatedAt()
        );

        self::assertGreaterThanOrEqual(
            $beforeCreation,
            $pendingRebroadcast->getCreatedAt()
        );

        self::assertLessThanOrEqual(
            $afterCreation,
            $pendingRebroadcast->getCreatedAt()
        );
    }

    public function testEmissionCanBeAssigned(): void
    {
        $emission = new Emission();
        $pendingRebroadcast = new PendingRebroadcast();

        $result = $pendingRebroadcast->setEmission($emission);

        self::assertSame($emission, $pendingRebroadcast->getEmission());
        self::assertSame($pendingRebroadcast, $result);
    }

    public function testAssignmentGroupKeyCanBeAssigned(): void
    {
        $pendingRebroadcast = new PendingRebroadcast();

        $result = $pendingRebroadcast->setAssignmentGroupKey(
            'rule_70_origin_20260918_2000'
        );

        self::assertSame(
            'rule_70_origin_20260918_2000',
            $pendingRebroadcast->getAssignmentGroupKey()
        );

        self::assertSame($pendingRebroadcast, $result);
    }

    public function testNewPendingRebroadcastIsPendingByDefault(): void
    {
        $pending = new PendingRebroadcast();

        self::assertSame(
            PendingRebroadcast::STATUS_PENDING,
            $pending->getStatus()
        );

        self::assertTrue($pending->isPending());
        self::assertFalse($pending->isDismissed());
    }

    public function testPendingRebroadcastCanBeDismissed(): void
    {
        $pending = new PendingRebroadcast();

        $result = $pending->dismiss();

        self::assertSame($pending, $result);

        self::assertSame(
            PendingRebroadcast::STATUS_DISMISSED,
            $pending->getStatus()
        );

        self::assertFalse($pending->isPending());
        self::assertTrue($pending->isDismissed());
    }

    public function testDismissedPendingRebroadcastCanBeRestored(): void
    {
        $pending = new PendingRebroadcast();

        $pending->dismiss();

        $result = $pending->restore();

        self::assertSame($pending, $result);

        self::assertSame(
            PendingRebroadcast::STATUS_PENDING,
            $pending->getStatus()
        );

        self::assertTrue($pending->isPending());
        self::assertFalse($pending->isDismissed());
    }

    public function testDismissingPendingRebroadcastTwiceIsIdempotent(): void
    {
        $pending = new PendingRebroadcast();

        $pending->dismiss();
        $pending->dismiss();

        self::assertSame(
            PendingRebroadcast::STATUS_DISMISSED,
            $pending->getStatus()
        );

        self::assertTrue($pending->isDismissed());
    }
}
