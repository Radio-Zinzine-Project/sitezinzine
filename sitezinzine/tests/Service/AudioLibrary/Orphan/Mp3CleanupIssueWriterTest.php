<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/** Transaction orchestration only: mocks do not prove physical isolation or concurrent uniqueness. */
final class Mp3CleanupIssueWriterTest extends TestCase
{
    public function testNewIssueAndEventAreWrittenBetweenBeginAndSingleCommit(): void
    {
        $steps = [];
        $connection = $this->connection($steps);
        $anomaly = $this->anomaly();
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use (&$steps, $anomaly): array {
                self::assertSame(['begin'], $steps);
                self::assertSame(['emissionsMp3', $anomaly->obsoleteRelativePath], $params);
                $steps[] = 'find';
                return [];
            }
        );
        $connection->expects(self::exactly(2))->method('insert')->willReturnCallback(
            function (string $table, array $data) use (&$steps, $anomaly): int {
                if ($table === 'issue') {
                    self::assertSame(['begin', 'find'], $steps);
                    self::assertSame([
                        'storage' => 'emissionsMp3', 'relative_path' => $anomaly->obsoleteRelativePath, 'status' => 'open',
                    ], $data);
                    $steps[] = 'create';
                } else {
                    self::assertSame('event', $table);
                    self::assertSame(['begin', 'find', 'create'], $steps);
                    self::assertSame(['issue_id' => 17, 'anomaly' => $anomaly], $data);
                    $steps[] = 'event';
                }
                return 1;
            }
        );
        $connection->expects(self::once())->method('lastInsertId')->willReturn('17');

        $id = $this->writer($connection)->record('emissionsMp3', $anomaly);

        self::assertSame(17, $id);
        self::assertSame(['begin', 'find', 'create', 'event', 'commit'], $steps);
    }

    /** @dataProvider activeStatuses */
    public function testExistingIssueIsReusedWithoutCreation(string $status): void
    {
        $steps = [];
        $connection = $this->connection($steps);
        $anomaly = $this->anomaly();
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            function () use (&$steps, $status, $anomaly): array {
                self::assertSame(['begin'], $steps);
                $steps[] = 'find';
                return [['id' => 23, 'storage' => 'emissionsMp3', 'relative_path' => $anomaly->obsoleteRelativePath, 'status' => $status]];
            }
        );
        $connection->expects(self::once())->method('insert')->with('event', ['issue_id' => 23, 'anomaly' => $anomaly])
            ->willReturnCallback(function () use (&$steps): int {
                self::assertSame(['begin', 'find'], $steps);
                $steps[] = 'event';
                return 1;
            });
        $connection->expects(self::never())->method('lastInsertId');

        self::assertSame(23, $this->writer($connection)->record('emissionsMp3', $anomaly));
        self::assertSame(['begin', 'find', 'event', 'commit'], $steps);
    }

    public static function activeStatuses(): array
    {
        return [['open'], ['investigating']];
    }

    /** @dataProvider failedWrites */
    public function testWriteFailureRollsBackAndPropagatesOriginalError(string $failureAt): void
    {
        $steps = [];
        $connection = $this->connection($steps, succeeds: false);
        $failure = new \RuntimeException('Simulated write failure');
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            function () use (&$steps): array {
                self::assertSame(['begin'], $steps);
                $steps[] = 'find';
                return [];
            }
        );
        $connection->expects(self::exactly($failureAt === 'create' ? 1 : 2))->method('insert')->willReturnCallback(
            function (string $table) use (&$steps, $failureAt, $failure): int {
                $operation = $table === 'issue' ? 'create' : 'event';
                self::assertSame($operation === 'create' ? ['begin', 'find'] : ['begin', 'find', 'create'], $steps);
                $steps[] = $operation;
                if ($operation === $failureAt) {
                    throw $failure;
                }
                return 1;
            }
        );
        $connection->expects($failureAt === 'create' ? self::never() : self::once())->method('lastInsertId')->willReturn('17');

        try {
            $this->writer($connection)->record('emissionsMp3', $this->anomaly());
            self::fail('A failed write must not return an issue identifier.');
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }

        self::assertSame(
            $failureAt === 'create' ? ['begin', 'find', 'create', 'rollback'] : ['begin', 'find', 'create', 'event', 'rollback'],
            $steps
        );
    }

    public static function failedWrites(): array
    {
        return [['create'], ['event']];
    }

    /** @dataProvider doubleFailurePhases */
    public function testRollbackFailurePreservesBothCausesWithoutAnotherAttempt(string $phase): void
    {
        $primary = new \RuntimeException('Initial ' . $phase . ' failure');
        $rollback = new \RuntimeException('Rollback failure');
        $connection = $this->failingTransaction($phase, $primary, true, $rollback);
        $writer = $this->writer($connection);
        $caught = null;

        try {
            $writer->record('emissionsMp3', $this->anomaly());
        } catch (\Throwable $error) {
            $caught = $error;
        }

        self::assertNotNull($caught, 'A double transaction failure must never return successfully.');
        self::assertSame(\App\Exception\Mp3CleanupTransactionFailure::class, $caught::class);
        self::assertSame($primary, $caught->getPrevious());
        self::assertSame($rollback, $caught->getRollbackFailure());
    }

    public static function doubleFailurePhases(): array
    {
        return ['write then rollback' => ['write'], 'commit then rollback' => ['commit']];
    }

    /** @dataProvider failedCommitStates */
    public function testCommitFailureNeverReturnsOrRepeatsGrouping(bool $active, bool $lost): void
    {
        $failure = $lost
            ? new \Doctrine\DBAL\Exception\ConnectionLost(
                new class('Connection lost during commit') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                    public function getSQLState(): ?string { return 'HY000'; }
                },
                null,
            )
            : new \RuntimeException('Commit failed; outcome is not confirmed');
        $connection = $this->failingTransaction('commit', $failure, $active);
        $writer = $this->writer($connection);
        $caught = null;

        try {
            $writer->record('emissionsMp3', $this->anomaly());
        } catch (\Throwable $error) {
            $caught = $error;
        }

        // Even a successful defensive rollback does not turn a failed commit into success.
        self::assertSame($failure, $caught);
    }

    public static function failedCommitStates(): array
    {
        return [
            'transaction remains active' => [true, false],
            'DBAL nesting level already zero' => [false, false],
            'connection lost and closed by DBAL' => [false, true],
        ];
    }

    private function failingTransaction(
        string $phase,
        \Throwable $primary,
        bool $activeAfterFailure,
        ?\Throwable $rollbackFailure = null,
    ): Connection {
        $connection = $this->createMock(Connection::class);
        $level = 0;
        $connection->method('getTransactionNestingLevel')->willReturnCallback(
            static function () use (&$level): int { return $level; }
        );
        $connection->expects(self::once())->method('beginTransaction')->willReturnCallback(
            static function () use (&$level): void { $level = 1; }
        );
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            function () use (&$level): array {
                self::assertSame(1, $level);
                return [['id' => 23, 'storage' => 'emissionsMp3', 'relative_path' => $this->anomaly()->obsoleteRelativePath, 'status' => 'open']];
            }
        );
        $connection->expects(self::once())->method('insert')->with('event', ['issue_id' => 23, 'anomaly' => $this->anomaly()])
            ->willReturnCallback(function () use (&$level, $phase, $primary): int {
                self::assertSame(1, $level);
                if ($phase === 'write') {
                    throw $primary;
                }
                return 1;
            });
        $connection->expects(self::never())->method('lastInsertId');
        $connection->expects($phase === 'commit' ? self::once() : self::never())->method('commit')
            ->willReturnCallback(static function () use (&$level, $activeAfterFailure, $primary): void {
                $level = $activeAfterFailure ? 1 : 0;
                throw $primary;
            });
        $connection->expects($activeAfterFailure ? self::once() : self::never())->method('rollBack')
            ->willReturnCallback(function () use (&$level, $rollbackFailure): void {
                self::assertSame(1, $level);
                // DBAL sets its nesting level to zero before calling the driver's rollback.
                $level = 0;
                if ($rollbackFailure !== null) {
                    throw $rollbackFailure;
                }
            });

        return $connection;
    }

    public function testForeignTransactionIsRejectedWithoutTouchingIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        foreach (['beginTransaction', 'commit', 'rollBack', 'fetchAllAssociative', 'insert', 'lastInsertId'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $writer = $this->writer($connection);

        $this->expectException(\LogicException::class);
        $writer->record('emissionsMp3', $this->anomaly());
    }

    private function connection(array &$steps, bool $succeeds = true): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(
            static function () use (&$steps): int { return $steps === [] ? 0 : 1; }
        );
        $connection->expects(self::once())->method('beginTransaction')->willReturnCallback(
            static function () use (&$steps): void { $steps[] = 'begin'; }
        );
        $connection->expects($succeeds ? self::once() : self::never())->method('commit')->willReturnCallback(
            static function () use (&$steps): void { $steps[] = 'commit'; }
        );
        $connection->expects($succeeds ? self::never() : self::once())->method('rollBack')->willReturnCallback(
            static function () use (&$steps): void { $steps[] = 'rollback'; }
        );
        return $connection;
    }

    private function writer(Connection $connection): Mp3CleanupIssueWriter
    {
        // SQL and payloads here are test-double commands, not a proposed physical schema.
        $grouper = new Mp3CleanupIssueGrouper(
            static fn (string $storage, string $path): array => $connection->fetchAllAssociative('find active issues', [$storage, $path]),
            static function (array $data) use ($connection): int {
                $connection->insert('issue', $data);
                return (int) $connection->lastInsertId();
            },
            static function (int $id, Mp3CleanupAnomaly $anomaly) use ($connection): void {
                $connection->insert('event', ['issue_id' => $id, 'anomaly' => $anomaly]);
            },
        );
        return new Mp3CleanupIssueWriter($connection, $grouper);
    }

    private function anomaly(): Mp3CleanupAnomaly
    {
        return new Mp3CleanupAnomaly('reference_check_failed', 42, 'TST/2026/Ancien Épisode.mp3', [
            'replacement_relative_path' => 'TST/2026/new.mp3', 'commit_confirmed' => true, 'reference_count' => null,
        ], ['class' => \RuntimeException::class, 'code' => 0, 'sql_state' => null]);
    }
}
