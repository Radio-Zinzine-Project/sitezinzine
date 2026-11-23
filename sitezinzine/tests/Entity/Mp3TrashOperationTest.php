<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3TrashPhase as Phase;
use PHPUnit\Framework\TestCase;

final class Mp3TrashOperationTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const ATTEMPT = 'abcdef0123456789abcdef0123456789';

    private function operation(): Mp3TrashOperation
    {
        return new Mp3TrashOperation(self::ID, 42, 'ABC/2026/Émission.mp3', 'https://example.test/Audio.mp3', 17, str_repeat('a', 64), 7, $this->timeAt(0));
    }

    private function timeAt(int $minute): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('2026-11-19T09:%02d:00+01:00', $minute));
    }

    public function testInitialStateAndOriginalIdentity(): void
    {
        $operation = $this->operation();
        self::assertSame(Phase::SUPPRESSION_PREPAREE, $operation->phase());
        self::assertSame(self::ID, $operation->id());
        self::assertSame(42, $operation->emissionId());
        self::assertSame('ABC/2026/Émission.mp3', $operation->originalThumbnailMp3());
        self::assertSame('https://example.test/Audio.mp3', $operation->originalUrl());
        self::assertSame(self::ID . '.mp3', $operation->trashRelativePath());
        self::assertSame(17, $operation->size());
        self::assertSame(str_repeat('a', 64), $operation->sha256());
        self::assertSame(7, $operation->authorId());
        self::assertSame('2026-11-19T08:00:00+00:00', $operation->updatedAt()->format(DATE_ATOM));
    }

    public function testTransitionsAndIdempotentReplays(): void
    {
        $operation = $this->operation();
        $operation->confirmDisassociation($this->timeAt(1));
        self::assertSame(Phase::RETRAIT_PUBLIC_EN_ATTENTE, $operation->phase());
        $operation->confirmDisassociation($this->timeAt(2));
        self::assertEquals($this->timeAt(1), $operation->updatedAt());
        $operation->confirmPublicRemoval($this->timeAt(3));
        self::assertSame(Phase::EN_CORBEILLE, $operation->phase());
        $operation->prepareRestoration(self::ATTEMPT, 9, $this->timeAt(4));
        self::assertSame(Phase::RESTAURATION_PREPAREE, $operation->phase());
        $operation->prepareRestoration(self::ATTEMPT, 9, $this->timeAt(5));
        self::assertCount(1, $operation->restorationAttempts());
        self::assertEquals($this->timeAt(4), $operation->updatedAt());
        $operation->confirmAssociation(self::ATTEMPT, $this->timeAt(6));
        self::assertSame(Phase::RETRAIT_PRIVE_EN_ATTENTE, $operation->phase());
        $operation->confirmPrivateRemoval(self::ATTEMPT, $this->timeAt(7));
        $operation->confirmPrivateRemoval(self::ATTEMPT, $this->timeAt(8));
        self::assertSame(Phase::RESTAUREE, $operation->phase());
        self::assertEquals($this->timeAt(7), $operation->updatedAt());
        self::assertSame(self::ID, $operation->id());
        self::assertSame(42, $operation->emissionId());
        self::assertSame('ABC/2026/Émission.mp3', $operation->originalThumbnailMp3());
        self::assertSame('https://example.test/Audio.mp3', $operation->originalUrl());
        self::assertSame(9, $operation->restorationAttempts()[self::ATTEMPT]['actor_id']);
    }

    public function testForbiddenTransitionDoesNotMutateTheJournal(): void
    {
        $operation = $this->operation();
        $before = clone $operation;
        try {
            $operation->confirmPublicRemoval($this->timeAt(1));
            self::fail('Transition must be rejected.');
        } catch (\DomainException) {
            self::assertEquals($before, $operation);
        }
    }

    public function testBlockingPreservesPhaseAndDiagnosticAfterResume(): void
    {
        $operation = $this->operation();
        $operation->confirmDisassociation($this->timeAt(1));
        $operation->block(Phase::RETRAIT_PUBLIC_EN_ATTENTE, 'file_missing', $this->timeAt(2));
        $operation->block(Phase::RETRAIT_PUBLIC_EN_ATTENTE, 'file_missing', $this->timeAt(3));
        self::assertEquals($this->timeAt(2), $operation->updatedAt());
        try {
            $operation->confirmPublicRemoval($this->timeAt(4));
            self::fail('Blocked operation must be rejected.');
        } catch (\DomainException) {
            self::assertSame(Phase::RETRAIT_PUBLIC_EN_ATTENTE, $operation->phase());
        }
        $operation->resume(Phase::RETRAIT_PUBLIC_EN_ATTENTE, $this->timeAt(5));
        $operation->resume(Phase::RETRAIT_PUBLIC_EN_ATTENTE, $this->timeAt(6));
        self::assertFalse($operation->isBlocked());
        self::assertSame('file_missing', $operation->lastDiagnosticCode());
        self::assertEquals($this->timeAt(5), $operation->updatedAt());
        $operation->confirmPublicRemoval($this->timeAt(7));
        self::assertSame(Phase::EN_CORBEILLE, $operation->phase());
        self::assertSame('file_missing', $operation->lastDiagnosticCode());
    }

    public function testRepeatedBlockingDecisionHasADistinctVersionEvenWithTheSameClock(): void
    {
        $operation = $this->operation();
        $at = $this->timeAt(1);
        $phase = Phase::SUPPRESSION_PREPAREE;
        $operation->block($phase, 'public_file_missing', $at);
        $version = $operation->blockVersion();
        $operation->resume($phase, $at);
        self::assertNull($operation->blockVersion());
        $operation->block($phase, 'public_file_missing', $at);
        self::assertNotSame($version, $operation->blockVersion());
        self::assertEquals($at->modify('+1 microsecond'), $operation->lastDiagnosticAt());
    }

    public function testCompletedDeletionCannotBeNewlyBlocked(): void
    {
        $operation = $this->operation();
        $operation->confirmDisassociation($this->timeAt(1));
        $operation->confirmPublicRemoval($this->timeAt(2));
        $this->expectException(\DomainException::class);
        $operation->block(Phase::EN_CORBEILLE, 'private_file_missing', $this->timeAt(3));
    }

    public function testCancelledRestorationKeepsItsHistoryAndCannotBeReused(): void
    {
        $operation = $this->operation();
        $operation->confirmDisassociation($this->timeAt(1));
        $operation->confirmPublicRemoval($this->timeAt(2));
        $operation->prepareRestoration(self::ATTEMPT, 9, $this->timeAt(3));
        $operation->cancelRestoration(self::ATTEMPT, $this->timeAt(4));
        $operation->cancelRestoration(self::ATTEMPT, $this->timeAt(5));
        self::assertSame(Phase::EN_CORBEILLE, $operation->phase());
        self::assertSame('cancelled', $operation->restorationAttempts()[self::ATTEMPT]['outcome']);
        self::assertEquals($this->timeAt(4), $operation->updatedAt());
        $this->expectException(\DomainException::class);
        $operation->prepareRestoration(self::ATTEMPT, 9, $this->timeAt(6));
    }

    public function testPreparedDeletionCanBeCancelled(): void
    {
        $operation = $this->operation();
        $operation->cancelDeletion($this->timeAt(1));
        $operation->cancelDeletion($this->timeAt(2));
        self::assertSame(Phase::ANNULEE, $operation->phase());
        self::assertEquals($this->timeAt(1), $operation->updatedAt());
        $this->expectException(\DomainException::class);
        $operation->confirmDisassociation($this->timeAt(3));
    }
}
