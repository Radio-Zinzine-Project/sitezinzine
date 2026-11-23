<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Exception\Mp3AdministrativeRequestUncertain;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupAttemptJournal;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupResult;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class Mp3DeferredCleanupAttemptJournalTest extends TestCase
{
    private const REQUEST_ID = '0123456789abcdef0123456789abcdef';

    public function testConsultationIsBoundedAndFindsActiveAttemptIndependently(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAllAssociative')->with(
            self::logicalAnd(self::stringContains('ORDER BY requested_at DESC, request_id DESC LIMIT 20'),
                self::stringContains('WHERE issue_id = ?')),
            [17],
        )->willReturn([]);
        $db->expects(self::once())->method('fetchOne')
            ->with(self::stringContains('WHERE issue_id = ? AND active_marker = 1'), [17])
            ->willReturn(self::REQUEST_ID);
        foreach (['executeStatement', 'insert', 'update', 'delete', 'beginTransaction'] as $method) {
            $db->expects(self::never())->method($method);
        }

        $journal = new Mp3DeferredCleanupAttemptJournal();
        self::assertSame([], $journal->recentForIssue($db, 17));
        self::assertSame(self::REQUEST_ID, $journal->activeForIssue($db, 17));
    }

    public function testReservationIsReturnedOnlyAfterCommit(): void
    {
        $db = $this->createMock(Connection::class);
        $steps = [];
        $db->expects(self::once())->method('transactional')->willReturnCallback(
            static function (\Closure $work) use ($db, &$steps): void {
                $steps[] = 'begin';
                $work($db);
                $steps[] = 'commit acknowledged';
            },
        );
        $db->expects(self::once())->method('insert')->with('mp3_deferred_cleanup_attempt', [
            'request_id' => self::REQUEST_ID,
            'issue_id' => 17,
            'expected_event_id' => 42,
            'actor_id' => 51,
            'requested_at' => '2026-11-20 11:02:03.123456',
            'state' => 'pending',
        ])->willReturnCallback(static function () use (&$steps): int {
            $steps[] = 'insert';
            return 1;
        });

        (new Mp3DeferredCleanupAttemptJournal())->reserve(
            $db, self::REQUEST_ID, 17, 42, 51, new \DateTimeImmutable('2026-11-20T12:02:03.123456+01:00'),
        );
        self::assertSame(['begin', 'insert', 'commit acknowledged'], $steps);
    }

    public function testUnconfirmedInitialCommitIsDistinguishedFromAnInsertFailure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('insert')->willReturn(1);
        $failure = new \RuntimeException('Commit acknowledgement lost');
        $db->expects(self::once())->method('transactional')->willReturnCallback(
            static function (\Closure $work) use ($db, $failure): never {
                $work($db);
                throw $failure;
            },
        );
        try {
            (new Mp3DeferredCleanupAttemptJournal())->reserve(
                $db, self::REQUEST_ID, 17, 42, null, new \DateTimeImmutable(),
            );
            self::fail('An uncertain request cannot authorize removal.');
        } catch (Mp3AdministrativeRequestUncertain $error) {
            self::assertSame($failure, $error->getPrevious());
        }
    }

    public function testOutcomeWritesOnlyPendingRequestAndRefusesAnOverwrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('transactional')->willReturnCallback(
            static fn (\Closure $work) => $work($db),
        );
        $db->expects(self::once())->method('executeStatement')->with(
            self::logicalAnd(self::stringContains("state = 'pending'"), self::stringContains('request_id = ?')),
            ['removed', '2026-11-20 11:02:03.000000', self::REQUEST_ID],
        )->willReturn(0);
        $this->expectException(\UnexpectedValueException::class);
        (new Mp3DeferredCleanupAttemptJournal())->finish(
            $db, self::REQUEST_ID, Mp3DeferredCleanupResult::Removed, new \DateTimeImmutable('2026-11-20T11:02:03Z'),
        );
    }
}
