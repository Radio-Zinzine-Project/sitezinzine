<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupTransactionFailure;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

/** Retry orchestration only; neither constraint identification nor real concurrency is proved here. */
final class Mp3CleanupIssueWriterRetryTest extends TestCase
{
    public function testVerifiedActiveIssueCollisionStartsANewTransactionAndFindsTheWinner(): void
    {
        $signal = 'App\\Exception\\Mp3CleanupActiveIssueCollision';
        self::assertTrue(class_exists($signal), 'A verified active-issue collision requires an explicit domain signal.');
        // The future SQL boundary must verify the exact active file before emitting this signal.
        $failure = new $signal($this->dbalError(UniqueConstraintViolationException::class));
        $result = $this->runScenario([$failure], 'create');

        self::assertNull($result['error']);
        self::assertSame(72, $result['id']);
        self::assertSame(['begin', 'find', 'create', 'rollback', 'begin', 'find', 'event', 'commit'], $result['steps']);
        self::assertSame(1, $result['commits']);
    }

    /** @dataProvider retryableWriteErrors */
    public function testRetryableWriteErrorRestartsTheEntireGrouping(string $errorClass): void
    {
        $result = $this->runScenario([$this->dbalError($errorClass)], 'event');

        self::assertNull($result['error']);
        self::assertSame(72, $result['id']);
        self::assertSame(['begin', 'find', 'event', 'rollback', 'begin', 'find', 'event', 'commit'], $result['steps']);
        self::assertSame([71, 72], $result['eventIssues'], 'The second event must use the newly queried issue, not stale state.');
        self::assertSame(1, $result['commits']);
    }

    public static function retryableWriteErrors(): array
    {
        return ['deadlock' => [DeadlockException::class], 'lock timeout' => [LockWaitTimeoutException::class]];
    }

    public function testThreeTotalAttemptsAreTheHardLimitAndTheLastErrorIsPreserved(): void
    {
        $failures = [$this->dbalError(DeadlockException::class), $this->dbalError(DeadlockException::class), $this->dbalError(DeadlockException::class)];
        $result = $this->runScenario($failures, 'event');

        self::assertSame($failures[2], $result['error']);
        self::assertNull($result['id']);
        self::assertSame(array_merge(...array_fill(0, 3, ['begin', 'find', 'event', 'rollback'])), $result['steps']);
        self::assertSame(0, $result['commits']);
    }

    public function testConfiguredSingleAttemptDisablesRetries(): void
    {
        $failure = $this->dbalError(DeadlockException::class);
        $result = $this->runScenario([$failure], 'event', maxAttempts: 1);

        self::assertSame($failure, $result['error']);
        self::assertSame(['begin', 'find', 'event', 'rollback'], $result['steps']);
        self::assertNull($result['id']);
    }

    /** @dataProvider permanentErrors */
    public function testUnclassifiedUniqueViolationOrPermanentErrorIsNeverRetried(string $errorClass, string $phase): void
    {
        $failure = $this->dbalError($errorClass);
        $result = $this->runScenario([$failure], $phase);

        self::assertSame($failure, $result['error']);
        self::assertSame(['begin', 'find', $phase, 'rollback'], $result['steps']);
        self::assertNull($result['id']);
        self::assertSame(0, $result['commits']);
    }

    public static function permanentErrors(): array
    {
        return [
            'unclassified issue unique violation' => [UniqueConstraintViolationException::class, 'create'],
            'event unique violation' => [UniqueConstraintViolationException::class, 'event'],
            'permanent driver error' => [DriverException::class, 'event'],
        ];
    }

    public function testRollbackFailurePreventsRetryAndPreservesBothCauses(): void
    {
        $failure = $this->dbalError(DeadlockException::class);
        $rollback = new \RuntimeException('Simulated rollback failure');
        $result = $this->runScenario([$failure], 'event', rollbackFailure: $rollback);

        self::assertInstanceOf(Mp3CleanupTransactionFailure::class, $result['error']);
        self::assertSame($failure, $result['error']->getPrevious());
        self::assertSame($rollback, $result['error']->getRollbackFailure());
        self::assertSame(['begin', 'find', 'event', 'rollback'], $result['steps']);
        self::assertNull($result['id']);
    }

    /** @dataProvider commitStates */
    public function testEvenRetryableCommitErrorNeverRestartsGrouping(bool $active): void
    {
        $failure = $this->dbalError(DeadlockException::class);
        $result = $this->runScenario([$failure], 'commit', activeAfterCommitFailure: $active);

        self::assertSame($failure, $result['error']);
        self::assertNull($result['id']);
        self::assertSame($active ? ['begin', 'find', 'event', 'commit', 'rollback'] : ['begin', 'find', 'event', 'commit'], $result['steps']);
        self::assertSame(0, $result['commits']);
    }

    public static function commitStates(): array
    {
        return ['still active' => [true], 'already ended' => [false]];
    }

    public function testTimeoutWithUnusableConnectionAfterRollbackIsNotRetried(): void
    {
        $failure = $this->dbalError(LockWaitTimeoutException::class);
        $result = $this->runScenario([$failure], 'event', usableAfterRollback: false);

        self::assertSame($failure, $result['error']);
        self::assertSame(['begin', 'find', 'event', 'rollback'], $result['steps']);
        self::assertNull($result['id']);
    }

    private function runScenario(
        array $failures,
        string $phase,
        int $maxAttempts = 3,
        ?\Throwable $rollbackFailure = null,
        bool $activeAfterCommitFailure = true,
        bool $usableAfterRollback = true,
    ): array {
        $steps = $eventIssues = [];
        $attempt = $level = $commits = 0;
        $connected = true;
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->method('isConnected')->willReturnCallback(static function () use (&$connected): bool { return $connected; });
        $connection->method('beginTransaction')->willReturnCallback(function () use (&$steps, &$attempt, &$level, &$connected, $maxAttempts): void {
            self::assertSame(0, $level);
            self::assertTrue($connected);
            self::assertLessThan($maxAttempts, $attempt, 'Retry limit exceeded.');
            ++$attempt;
            $level = 1;
            $steps[] = 'begin';
        });
        $connection->method('commit')->willReturnCallback(static function () use (&$steps, &$level, &$commits, &$attempt, $phase, $failures, $activeAfterCommitFailure): void {
            $steps[] = 'commit';
            if ($phase === 'commit' && isset($failures[$attempt - 1])) {
                $level = $activeAfterCommitFailure ? 1 : 0;
                throw $failures[$attempt - 1];
            }
            $level = 0;
            ++$commits;
        });
        $connection->method('rollBack')->willReturnCallback(function () use (&$steps, &$level, &$connected, $rollbackFailure, $usableAfterRollback): void {
            self::assertSame(1, $level, 'No second blind rollback.');
            $steps[] = 'rollback';
            $level = 0;
            $connected = $usableAfterRollback;
            if ($rollbackFailure !== null) {
                throw $rollbackFailure;
            }
        });
        $anomaly = new Mp3CleanupAnomaly('reference_check_failed', 42, 'TST/2026/Old.mp3', [
            'replacement_relative_path' => 'TST/2026/new.mp3', 'commit_confirmed' => true, 'reference_count' => null,
        ], ['class' => \RuntimeException::class, 'code' => 0, 'sql_state' => null]);
        $grouper = new Mp3CleanupIssueGrouper(
            function (string $storage, string $path) use (&$steps, &$level, &$attempt, $phase, $anomaly): array {
                self::assertSame(1, $level);
                self::assertSame('emissionsMp3', $storage);
                self::assertSame($anomaly->obsoleteRelativePath, $path);
                $steps[] = 'find';
                return $phase === 'create' && $attempt === 1 ? [] : [
                    ['id' => 70 + $attempt, 'storage' => $storage, 'relative_path' => $path, 'status' => 'open'],
                ];
            },
            function (array $data) use (&$steps, &$level, &$attempt, $failures): int {
                self::assertSame(1, $level);
                self::assertSame('open', $data['status']);
                $steps[] = 'create';
                throw $failures[$attempt - 1];
            },
            function (int $id, Mp3CleanupAnomaly $event) use (&$steps, &$level, &$attempt, &$eventIssues, $anomaly, $phase, $failures): void {
                self::assertSame(1, $level);
                self::assertSame($anomaly, $event);
                $steps[] = 'event';
                $eventIssues[] = $id;
                if ($phase === 'event' && isset($failures[$attempt - 1])) {
                    throw $failures[$attempt - 1];
                }
            },
        );
        $writer = new Mp3CleanupIssueWriter($connection, $grouper, $maxAttempts);
        $id = $error = null;
        try {
            $id = $writer->record('emissionsMp3', $anomaly);
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        return compact('id', 'error', 'steps', 'eventIssues', 'commits');
    }

    /** Build real DBAL exception types without a driver connection or raw-message classification. */
    private function dbalError(string $class): DriverException
    {
        $driver = new class('Opaque driver diagnostic') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string { return 'HY000'; }
        };
        return new $class($driver, null);
    }
}
