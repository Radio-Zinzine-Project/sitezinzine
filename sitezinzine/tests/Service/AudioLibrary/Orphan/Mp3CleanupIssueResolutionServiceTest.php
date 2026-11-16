<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupTransactionFailure;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueResolutionService;
use App\Service\AudioLibrary\Orphan\Mp3CleanupResolutionDecision;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/** SQL orchestration contract; mocks do not prove isolation, physical independence or server outcome. */
final class Mp3CleanupIssueResolutionServiceTest extends TestCase
{
    /** @dataProvider activeStatuses */
    public function testAllowedResolutionLocksReadsUpdatesAndCommits(string $status): void
    {
        $token = ['issue_id' => 17, 'last_event_id' => 42];
        $result = $this->scenario($status, 42, $token);

        self::assertSame('allowed', $result['result']);
        self::assertNull($result['error']);
        self::assertSame(['begin', 'issue', 'event', 'update', 'commit'], $result['steps']);
    }

    public static function activeStatuses(): array
    {
        return [['open'], ['investigating']];
    }

    /** @dataProvider refusals */
    public function testRefusalFinishesTransactionWithoutUpdatingAnything(?string $status, int|false $lastEvent, mixed $token, string $expected): void
    {
        $before = $token;
        $result = $this->scenario($status, $lastEvent, $token);

        self::assertSame($expected, $result['result']);
        self::assertNull($result['error']);
        self::assertSame($status === null ? ['begin', 'issue', 'commit'] : ['begin', 'issue', 'event', 'commit'], $result['steps']);
        self::assertSame($before, $token);
    }

    public static function refusals(): array
    {
        $token = ['issue_id' => 17, 'last_event_id' => 42];
        return [
            'new event' => ['open', 43, $token, 'stale_view'],
            'invalid token is not refreshed' => ['open', 42, null, 'invalid_token'],
            'no events' => ['open', false, $token, 'no_events'],
            'already resolved' => ['resolved', 42, $token, 'already_resolved'],
            'absent issue' => [null, false, $token, 'not_found'],
        ];
    }

    /** @dataProvider failureStages */
    public function testFailureBeforeCommitRollsBackAndPropagatesOriginal(string $stage): void
    {
        $original = new \RuntimeException('Simulated operation failure');
        $result = $this->scenario(failureStage: $stage, failure: $original);

        self::assertSame($original, $result['error']);
        self::assertNull($result['result']);
        self::assertSame($stage === 'event' ? ['begin', 'issue', 'event', 'rollback'] : ['begin', 'issue', 'event', 'update', 'rollback'], $result['steps']);
    }

    public static function failureStages(): array
    {
        return [['event'], ['update']];
    }

    public function testRollbackFailureKeepsBothCausesWithoutRetrying(): void
    {
        $original = new \RuntimeException('Update failed');
        $rollback = new \RuntimeException('Rollback failed');
        $result = $this->scenario(failureStage: 'update', failure: $original, rollbackFailure: $rollback);

        self::assertInstanceOf(Mp3CleanupTransactionFailure::class, $result['error']);
        self::assertSame($original, $result['error']->getPrevious());
        self::assertSame($rollback, $result['error']->getRollbackFailure());
        self::assertNull($result['result']);
        self::assertSame(['begin', 'issue', 'event', 'update', 'rollback'], $result['steps']);
    }

    /** @dataProvider commitLevels */
    public function testCommitFailureNeverConfirmsOrRepeatsResolution(bool $stillActive): void
    {
        $original = new \RuntimeException('Commit outcome unknown');
        $result = $this->scenario(failureStage: 'commit', failure: $original, activeAfterCommitFailure: $stillActive);

        self::assertSame($original, $result['error']);
        self::assertNull($result['result']);
        self::assertSame($stillActive ? ['begin', 'issue', 'event', 'update', 'commit', 'rollback'] : ['begin', 'issue', 'event', 'update', 'commit'], $result['steps']);
        // Neither this simulated state nor a defensive rollback proves durable server outcome.
    }

    public static function commitLevels(): array
    {
        return [['active' => true], ['active' => false]];
    }

    /** @dataProvider unexpectedRowCounts */
    public function testUpdateMustAffectExactlyOneRow(int $count): void
    {
        $result = $this->scenario(affectedRows: $count);

        self::assertInstanceOf(\UnexpectedValueException::class, $result['error']);
        self::assertNull($result['result']);
        self::assertSame(['begin', 'issue', 'event', 'update', 'rollback'], $result['steps']);
    }

    public static function unexpectedRowCounts(): array
    {
        return [[0], [2]];
    }

    public function testForeignTransactionIsRejectedWithoutTouchingIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        foreach (['beginTransaction', 'commit', 'rollBack', 'fetchAssociative', 'fetchOne', 'executeStatement', 'insert', 'delete', 'update'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $service = new Mp3CleanupIssueResolutionService($connection, new Mp3CleanupResolutionDecision());

        $this->expectException(\LogicException::class);
        $service->resolve(17, ['issue_id' => 17, 'last_event_id' => 42], new \DateTimeImmutable('2026-11-15T12:00:00+00:00'));
    }

    /** @dataProvider openingFailures */
    public function testOpeningFailureIsCleanedWithoutReadingOrRetrying(
        string $kind, int $localLevel, bool $connected, string $nativeState, array $expectedSteps,
    ): void {
        $opening = $this->openingError($kind);
        $result = $this->failedOpening($opening, $localLevel, $connected, $nativeState);

        self::assertSame($opening, $result['error']);
        self::assertNull($result['result']);
        self::assertSame($expectedSteps, $result['steps']);
        self::assertSame(0, $result['level']);
    }

    public static function openingFailures(): array
    {
        return [
            'zero DBAL level, healthy connection' => ['normal', 0, true, 'unused', ['begin']],
            'positive level, native active' => ['normal', 1, true, 'active', ['begin', 'inspect', 'rollback']],
            'positive level, native inactive' => ['normal', 1, true, 'inactive', ['begin', 'inspect', 'close']],
            'unsupported native driver' => ['normal', 1, true, 'unsupported', ['begin', 'inspect', 'close']],
            'native inspection throws' => ['normal', 1, true, 'throws', ['begin', 'inspect', 'close']],
            'connection lost, already closed' => ['lost', 0, false, 'unused', ['begin']],
            'connection lost, stale local state' => ['lost', 1, false, 'unused', ['begin', 'close']],
            'deadlock during opening is not retried' => ['deadlock', 1, true, 'active', ['begin', 'inspect', 'rollback']],
        ];
    }

    public function testOpeningAndDefensiveRollbackFailuresPreserveBothCauses(): void
    {
        $opening = new \RuntimeException('Opening failed');
        $rollback = new \RuntimeException('Defensive rollback failed');
        $result = $this->failedOpening($opening, 1, true, 'active', $rollback);

        self::assertInstanceOf(Mp3CleanupTransactionFailure::class, $result['error']);
        self::assertSame($opening, $result['error']->getPrevious());
        self::assertSame($rollback, $result['error']->getRollbackFailure());
        self::assertNull($result['result']);
        self::assertSame(['begin', 'inspect', 'rollback'], $result['steps']);
    }

    private function openingError(string $kind): \Throwable
    {
        if ($kind === 'normal') {
            return new \RuntimeException('Simulated opening failure');
        }
        $driver = new class('Simulated driver failure') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string { return 'HY000'; }
        };
        return $kind === 'lost'
            ? new \Doctrine\DBAL\Exception\ConnectionLost($driver, null)
            : new \Doctrine\DBAL\Exception\DeadlockException($driver, null);
    }

    private function failedOpening(
        \Throwable $opening, int $levelAfterFailure, bool $connected, string $nativeState,
        ?\Throwable $rollbackFailure = null,
    ): array {
        $steps = [];
        $level = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->method('isConnected')->willReturn($connected);
        $connection->expects(self::once())->method('beginTransaction')->willReturnCallback(
            static function () use (&$steps, &$level, $levelAfterFailure, $opening): void {
                $steps[] = 'begin'; $level = $levelAfterFailure;
                throw $opening;
            }
        );
        foreach (['fetchAssociative', 'fetchOne', 'fetchAllAssociative', 'executeStatement', 'insert', 'update', 'delete', 'commit'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        // getNativeConnection() can connect: it must not be called on a disconnected handle.
        $observe = $levelAfterFailure > 0 && $connected;
        $connection->expects($observe ? self::once() : self::never())->method('getNativeConnection')->willReturnCallback(
            function () use (&$steps, $nativeState): mixed {
                $steps[] = 'inspect';
                if ($nativeState === 'unsupported') { return new \stdClass(); }
                $native = $this->createMock(\PDO::class);
                $native->expects(self::once())->method('inTransaction')->willReturnCallback(
                    static function () use ($nativeState): bool {
                        if ($nativeState === 'throws') { throw new \RuntimeException('Native state unavailable'); }
                        return $nativeState === 'active';
                    }
                );
                return $native;
            }
        );
        $rollback = $observe && $nativeState === 'active';
        $connection->expects($rollback ? self::once() : self::never())->method('rollBack')->willReturnCallback(
            static function () use (&$steps, &$level, $rollbackFailure): void {
                $steps[] = 'rollback'; $level = 0;
                if ($rollbackFailure !== null) { throw $rollbackFailure; }
            }
        );
        $close = $levelAfterFailure > 0 && !$rollback;
        $connection->expects($close ? self::once() : self::never())->method('close')->willReturnCallback(
            static function () use (&$steps, &$level): void { $steps[] = 'close'; $level = 0; }
        );
        $service = new Mp3CleanupIssueResolutionService($connection, new Mp3CleanupResolutionDecision());
        $result = $error = null;
        try {
            $result = $service->resolve(17, ['issue_id' => 17, 'last_event_id' => 42], new \DateTimeImmutable('2026-11-15T12:00:00+00:00'));
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        return compact('result', 'error', 'steps', 'level');
    }

    private function scenario(
        ?string $status = 'open',
        int|false $lastEvent = 42,
        mixed $token = ['issue_id' => 17, 'last_event_id' => 42],
        ?string $failureStage = null,
        ?\Throwable $failure = null,
        ?\Throwable $rollbackFailure = null,
        bool $activeAfterCommitFailure = true,
        int $affectedRows = 1,
    ): array {
        $steps = [];
        $level = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->expects(self::once())->method('beginTransaction')->willReturnCallback(
            static function () use (&$steps, &$level): void { $steps[] = 'begin'; $level = 1; }
        );
        $connection->expects(self::once())->method('fetchAssociative')->willReturnCallback(
            function (string $sql, array $params) use (&$steps, $status): array|false {
                self::assertSame(['begin'], $steps);
                self::assertSame([17], $params);
                $sql = $this->sql($sql);
                self::assertStringContainsString('from mp3_cleanup_issue', $sql);
                self::assertStringContainsString('where id = ?', $sql);
                self::assertStringContainsString('for update', $sql);
                $steps[] = 'issue';
                return $status === null ? false : ['id' => 17, 'status' => $status, 'resolved_at' => $status === 'resolved' ? '2026-11-01 12:00:00.123456' : null];
            }
        );
        $connection->expects($status === null ? self::never() : self::once())->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params) use (&$steps, $lastEvent, $failureStage, $failure): int|false {
                self::assertSame(['begin', 'issue'], $steps);
                self::assertSame([17], $params);
                $sql = $this->sql($sql);
                foreach (['from mp3_cleanup_event', 'where issue_id = ?', 'order by id desc', 'limit 1', 'for update'] as $part) {
                    self::assertStringContainsString($part, $sql);
                }
                $steps[] = 'event';
                if ($failureStage === 'event') { throw $failure; }
                return $lastEvent;
            }
        );
        $allowed = $status !== null && $lastEvent !== false
            && $token === ['issue_id' => 17, 'last_event_id' => $lastEvent]
            && in_array($status, ['open', 'investigating'], true);
        $connection->expects($allowed && $failureStage !== 'event' ? self::once() : self::never())->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params) use (&$steps, $failureStage, $failure, $affectedRows): int {
                self::assertSame(['begin', 'issue', 'event'], $steps);
                $sql = $this->sql($sql);
                self::assertStringStartsWith('update mp3_cleanup_issue set ', $sql);
                self::assertStringContainsString('status = ?', $sql);
                self::assertStringContainsString('resolved_at = ?', $sql);
                self::assertMatchesRegularExpression('/where id = \? and status in \(\?,\s*\?\)/', $sql);
                self::assertSame(['resolved', '2026-11-14 19:32:03.123456', 17, 'open', 'investigating'], $params);
                $steps[] = 'update';
                if ($failureStage === 'update') { throw $failure; }
                return $affectedRows;
            }
        );
        $beforeCommitFailure = in_array($failureStage, ['event', 'update'], true) || ($allowed && $affectedRows !== 1);
        $connection->expects($beforeCommitFailure ? self::never() : self::once())->method('commit')->willReturnCallback(
            static function () use (&$steps, &$level, $failureStage, $failure, $activeAfterCommitFailure): void {
                $steps[] = 'commit';
                $level = $failureStage === 'commit' && $activeAfterCommitFailure ? 1 : 0;
                if ($failureStage === 'commit') { throw $failure; }
            }
        );
        $shouldRollback = $beforeCommitFailure || ($failureStage === 'commit' && $activeAfterCommitFailure);
        $connection->expects($shouldRollback ? self::once() : self::never())->method('rollBack')->willReturnCallback(
            static function () use (&$steps, &$level, $rollbackFailure): void {
                $steps[] = 'rollback'; $level = 0;
                if ($rollbackFailure !== null) { throw $rollbackFailure; }
            }
        );
        foreach (['insert', 'delete', 'update'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $service = new Mp3CleanupIssueResolutionService($connection, new Mp3CleanupResolutionDecision());
        $provided = new \DateTimeImmutable('2026-11-15 01:02:03.123456+05:30');
        $beforeToken = $token;
        $result = $error = null;
        try { $result = $service->resolve(17, $token, $provided); } catch (\Throwable $caught) { $error = $caught; }
        self::assertSame($beforeToken, $token);
        self::assertSame('2026-11-15 01:02:03.123456 +05:30', $provided->format('Y-m-d H:i:s.u P'));
        return compact('result', 'error', 'steps');
    }

    private function sql(string $sql): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($sql)));
    }
}
