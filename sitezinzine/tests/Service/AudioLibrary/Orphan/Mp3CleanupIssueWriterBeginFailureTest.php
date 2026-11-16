<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupTransactionFailure;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\TestCase;

/** Native PDO observations are simulated: neither local state nor a mock proves server state. */
final class Mp3CleanupIssueWriterBeginFailureTest extends TestCase
{
    public function testBeginFailureWithZeroLocalLevelOnlyPropagatesOriginalError(): void
    {
        $error = new \RuntimeException('Begin rejected');
        $result = $this->runFailure($error, 0, true, 'unobserved');

        self::assertSame($error, $result['error']);
        self::assertSame(['begin'], $result['steps']);
        self::assertNull($result['id']);
    }

    public function testPositiveLocalLevelAndNativeActiveTransactionAllowOneDefensiveRollback(): void
    {
        $error = new \RuntimeException('Begin outcome uncertain');
        $result = $this->runFailure($error, 1, true, 'active');

        self::assertSame($error, $result['error']);
        self::assertSame(['begin', 'observe native', 'rollback'], $result['steps']);
        self::assertSame(0, $result['level']);
        self::assertNull($result['id']);
    }

    public function testPositiveLocalLevelWithoutNativeTransactionClosesInsteadOfRollingBackBlindly(): void
    {
        $error = new \RuntimeException('Driver rejected begin');
        $result = $this->runFailure($error, 1, true, 'inactive');

        self::assertSame($error, $result['error']);
        self::assertSame(['begin', 'observe native', 'close'], $result['steps']);
        self::assertSame(0, $result['level']);
        self::assertNull($result['id']);
    }

    public function testJustifiedRollbackFailurePreservesBeginAndRollbackErrors(): void
    {
        $error = new \RuntimeException('Begin uncertain');
        $rollback = new \RuntimeException('Rollback rejected');
        $result = $this->runFailure($error, 1, true, 'active', $rollback);

        self::assertInstanceOf(Mp3CleanupTransactionFailure::class, $result['error']);
        self::assertSame($error, $result['error']->getPrevious());
        self::assertSame($rollback, $result['error']->getRollbackFailure());
        self::assertSame(['begin', 'observe native', 'rollback'], $result['steps']);
        self::assertNull($result['id']);
    }

    /** @dataProvider lostConnectionLevels */
    public function testLostConnectionNeverReconnectsOrRetriesBegin(int $localLevel): void
    {
        $driver = new class('Connection lost') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string { return 'HY000'; }
        };
        $error = new ConnectionLost($driver, null);
        $result = $this->runFailure($error, $localLevel, false, 'unobserved');

        self::assertSame($error, $result['error']);
        self::assertSame($localLevel === 0 ? ['begin'] : ['begin', 'close'], $result['steps']);
        self::assertSame(0, $result['level']);
        self::assertNull($result['id']);
    }

    public static function lostConnectionLevels(): array
    {
        // Zero models DBAL's normal ConnectionLost conversion; one is a defensive stale-local-state case.
        return ['DBAL already closed' => [0], 'stale local counter' => [1]];
    }

    /** @dataProvider unavailableNativeStates */
    public function testUnobservableNativeStateClosesDedicatedConnectionAndPreservesBeginError(string $nativeState): void
    {
        $error = new \RuntimeException('Begin failed');
        $result = $this->runFailure($error, 1, true, $nativeState);

        self::assertSame($error, $result['error']);
        self::assertSame(['begin', 'observe native', 'close'], $result['steps']);
        self::assertSame(0, $result['level']);
        self::assertNull($result['id']);
    }

    public static function unavailableNativeStates(): array
    {
        return ['inspection fails' => ['throws'], 'native type unsupported' => ['unsupported']];
    }

    public function testRetryableErrorDuringBeginIsNeverTreatedAsRetryableWork(): void
    {
        $driver = new class('Deadlock during begin', 1213) extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string { return '40001'; }
        };
        $error = new DeadlockException($driver, null);
        $result = $this->runFailure($error, 1, true, 'active');

        self::assertSame($error, $result['error']);
        self::assertSame(['begin', 'observe native', 'rollback'], $result['steps']);
        self::assertNull($result['id']);
    }

    private function runFailure(
        \Throwable $openingError,
        int $levelAfterFailure,
        bool $connected,
        string $nativeState,
        ?\Throwable $rollbackError = null,
    ): array {
        $steps = [];
        $level = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$level): bool { return $level > 0; });
        $connection->method('isConnected')->willReturn($connected);
        $connection->expects(self::once())->method('beginTransaction')->willReturnCallback(
            static function () use (&$steps, &$level, $levelAfterFailure, $openingError): void {
                $steps[] = 'begin';
                $level = $levelAfterFailure;
                throw $openingError;
            }
        );
        $connection->expects(self::never())->method('commit');
        $observe = $levelAfterFailure > 0 && $connected;
        $connection->expects($observe ? self::once() : self::never())->method('getNativeConnection')->willReturnCallback(
            function () use (&$steps, $nativeState): mixed {
                $steps[] = 'observe native';
                if ($nativeState === 'unsupported') {
                    return new \stdClass();
                }
                $native = $this->createMock(\PDO::class);
                $native->expects(self::once())->method('inTransaction')->willReturnCallback(
                    static function () use ($nativeState): bool {
                        if ($nativeState === 'throws') {
                            throw new \RuntimeException('Native inspection unavailable');
                        }
                        return $nativeState === 'active';
                    }
                );
                return $native;
            }
        );
        $rollback = $observe && $nativeState === 'active';
        $connection->expects($rollback ? self::once() : self::never())->method('rollBack')->willReturnCallback(
            static function () use (&$steps, &$level, $rollbackError): void {
                $steps[] = 'rollback';
                $level = 0;
                if ($rollbackError !== null) {
                    throw $rollbackError;
                }
            }
        );
        $close = $levelAfterFailure > 0 && !$rollback;
        $connection->expects($close ? self::once() : self::never())->method('close')->willReturnCallback(
            static function () use (&$steps, &$level): void { $steps[] = 'close'; $level = 0; }
        );
        $grouper = new Mp3CleanupIssueGrouper(
            function (): array { self::fail('Grouping must not start after begin failed.'); },
            function (): int { self::fail('No issue may be created after begin failed.'); },
            function (): void { self::fail('No event may be inserted after begin failed.'); },
        );
        $writer = new Mp3CleanupIssueWriter($connection, $grouper);
        $anomaly = new Mp3CleanupAnomaly('reference_check_failed', 42, 'TST/2026/old.mp3', [
            'replacement_relative_path' => 'TST/2026/new.mp3', 'commit_confirmed' => true, 'reference_count' => null,
        ], ['class' => \RuntimeException::class, 'code' => 0, 'sql_state' => null]);
        $id = $error = null;
        try { $id = $writer->record('emissionsMp3', $anomaly); } catch (\Throwable $caught) { $error = $caught; }

        return compact('id', 'error', 'steps', 'level');
    }
}
