<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Mp3AdministrativeEventType as Type;
use App\Enum\Mp3TrashPhase as Phase;
use App\Service\Mp3AdministrativeJournal;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class Mp3AdministrativeJournalTest extends TestCase
{
    private const REQUEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OPERATION = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testRecordsTheInitialRequestInItsOwnTransaction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $db->expects(self::once())->method('transactional')->willReturnCallback(fn (callable $work) => $work($db));
        $db->expects(self::once())->method('insert')->with('mp3_administrative_event', self::callback(
            static fn (array $row): bool => $row['request_id'] === self::REQUEST
                && $row['operation_id'] === self::OPERATION && $row['actor_id'] === 42
                && $row['event_type'] === 'request' && $row['recorded_at'] === '2026-11-19 11:00:00.123456',
        ));
        (new Mp3AdministrativeJournal())->recordRequest($db, self::REQUEST, self::OPERATION, 42,
            'resume_deletion', Phase::SUPPRESSION_PREPAREE, null, new \DateTimeImmutable('2026-11-19T12:00:00.123456+01:00'));
    }

    public function testRejectsAnInvalidAdministrativeActorBeforeAnyWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $db->expects(self::never())->method('transactional');
        $this->expectException(\InvalidArgumentException::class);
        (new Mp3AdministrativeJournal())->recordRequest($db, self::REQUEST, self::OPERATION, 0,
            'resume_deletion', Phase::SUPPRESSION_PREPAREE, null, new \DateTimeImmutable());
    }

    public function testRejectsAConfirmationWithoutAnExistingTransaction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('insert');
        $this->expectException(\LogicException::class);
        (new Mp3AdministrativeJournal())->recordInTransaction($db, self::REQUEST, Type::TRANSITION_CONFIRMED,
            Phase::EN_CORBEILLE, 'completed', new \DateTimeImmutable());
    }

    public function testRejectsAnIndependentWriteUsingAnActiveConnection(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('isTransactionActive')->willReturn(true);
        $db->expects(self::never())->method('transactional');
        $this->expectException(\LogicException::class);
        (new Mp3AdministrativeJournal())->recordOutcome($db, self::REQUEST, Type::REFUSAL,
            null, 'lock_unavailable', new \DateTimeImmutable());
    }

    public function testRequiresAKnownPhaseForAConfirmedTransition(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('isTransactionActive')->willReturn(true);
        $db->expects(self::never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        (new Mp3AdministrativeJournal())->recordInTransaction($db, self::REQUEST, Type::TRANSITION_CONFIRMED,
            null, 'completed', new \DateTimeImmutable());
    }
}
