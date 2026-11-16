<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueReader;
use App\Exception\Mp3CleanupTransactionFailure;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/** Snapshot orchestration is simulated; MySQL snapshot semantics require integration coverage. */
final class Mp3CleanupIssueReaderTest extends TestCase
{
    public function testAbsentIssueReturnsNullWithoutLoadingHistoryOrInventingAToken(): void
    {
        $reader = $this->reader(false, []);

        self::assertNull($reader->readInitialDetail(17));
    }

    public function testIssueWithoutEventsHasNoResolutionTokenOrNextPage(): void
    {
        $result = $this->reader($this->issue(), [])->readInitialDetail(17);

        self::assertSame($this->issueProjection(), $result['issue']);
        self::assertSame([], $result['events']);
        self::assertNull($result['token']);
        self::assertSame(['has_more' => false, 'next_before_id' => null], $result['pagination']);
    }

    public function testSmallHistoryReturnsAllEventsAndUsesTheHighestIdForItsToken(): void
    {
        $result = $this->reader($this->issue(), [$this->event(91), $this->event(42)])->readInitialDetail(17);

        self::assertSame(['issue', 'events', 'token', 'pagination'], array_keys($result));
        self::assertSame($this->issueProjection(), $result['issue']);
        self::assertSame([$this->eventProjection(91), $this->eventProjection(42)], $result['events']);
        self::assertSame(['issue_id' => 17, 'last_event_id' => 91], $result['token']);
        self::assertSame(['has_more' => false, 'next_before_id' => null], $result['pagination']);
    }

    public function testExactlyTwentyEventsDoesNotInventANextPage(): void
    {
        $rows = array_map(fn (int $id): array => $this->event($id), range(120, 101));
        $result = $this->reader($this->issue(), $rows)->readInitialDetail(17);

        self::assertCount(20, $result['events']);
        self::assertSame(range(120, 101), array_column($result['events'], 'id'));
        self::assertSame(['issue_id' => 17, 'last_event_id' => 120], $result['token']);
        self::assertSame(['has_more' => false, 'next_before_id' => null], $result['pagination']);
    }

    public function testLookaheadIsHiddenAndPaginationCursorDoesNotReplaceTheConsultationToken(): void
    {
        $rows = array_map(fn (int $id): array => $this->event($id), range(120, 100));
        $result = $this->reader($this->issue(), $rows)->readInitialDetail(17);
        $ids = array_column($result['events'], 'id');

        self::assertCount(20, $result['events']);
        self::assertSame(range(120, 101), $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertNotContains(100, $ids);
        self::assertSame(['issue_id' => 17, 'last_event_id' => 120], $result['token']);
        self::assertSame(['has_more' => true, 'next_before_id' => 101], $result['pagination']);
    }

    /** @dataProvider referenceCounts */
    public function testProjectionFiltersJsonAndPreservesUnknownVersusZeroReferences(?int $references): void
    {
        $result = $this->reader($this->issue(), [$this->event(91, $references)])->readInitialDetail(17);

        self::assertSame($this->eventProjection(91, $references), $result['events'][0]);
        self::assertIsArray($result['events'][0]['context']);
        self::assertIsArray($result['events'][0]['diagnostic']);
        self::assertSame($references, $result['events'][0]['context']['reference_count']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['raw-message', 'private-trace', 'private-context', 'private-column'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public static function referenceCounts(): array
    {
        return ['unknown' => [null], 'zero established' => [0]];
    }

    public function testResolvedIssueStillExposesItsHistoricalEventsAndOriginalResolutionDate(): void
    {
        $issue = $this->issue();
        $issue['status'] = 'resolved';
        $issue['resolved_at'] = '2026-11-15 12:00:00.000007';
        $result = $this->reader($issue, [$this->event(91)])->readInitialDetail(17);

        self::assertSame('resolved', $result['issue']['status']);
        self::assertSame($issue['resolved_at'], $result['issue']['resolved_at']);
        self::assertSame([$this->eventProjection(91)], $result['events']);
        // Token describes the viewed history; it never overrides the resolved-status decision.
        self::assertSame(['issue_id' => 17, 'last_event_id' => 91], $result['token']);
    }

    /** @dataProvider snapshotCases */
    public function testInitialReadsFinishTheirOwnRepeatableReadTransaction(string $case): void
    {
        $run = $this->snapshotRun($case);
        self::assertNull($run['error']);
        self::assertSame($case === 'absent'
            ? ['prepare', 'begin', 'issue', 'commit']
            : ['prepare', 'begin', 'issue', 'events', 'commit'], $run['steps']);
        self::assertSame(0, $run['level']);
        if ($case === 'absent') {
            self::assertNull($run['result']);
        } else {
            self::assertSame($case === 'empty' ? null : ['issue_id' => 17, 'last_event_id' => 91], $run['result']['token']);
        }
    }

    public static function snapshotCases(): array
    {
        return ['history' => ['history'], 'absent' => ['absent'], 'empty' => ['empty']];
    }

    /** @dataProvider failureCases */
    public function testSnapshotFailuresNeverReturnAPartialResult(string $case, array $expected): void
    {
        $initial = new \RuntimeException('Initial failure');
        if (in_array($case, ['begin_lost', 'begin_deadlock'], true)) {
            $driver = new class('Driver failure') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                public function getSQLState(): ?string { return 'HY000'; }
            };
            $initial = $case === 'begin_lost'
                ? new \Doctrine\DBAL\Exception\ConnectionLost($driver, null)
                : new \Doctrine\DBAL\Exception\DeadlockException($driver, null);
        }
        $rollback = str_contains($case, 'double') ? new \RuntimeException('Rollback failure') : null;
        $run = $this->snapshotRun($case, $initial, $rollback);
        self::assertNull($run['result']);
        if ($rollback !== null) {
            self::assertInstanceOf(Mp3CleanupTransactionFailure::class, $run['error']);
            self::assertSame($initial, $run['error']->getPrevious());
            self::assertSame($rollback, $run['error']->getRollbackFailure());
        } else {
            self::assertSame($initial, $run['error']);
        }
        self::assertSame($expected, $run['steps']);
    }

    public static function failureCases(): array
    {
        return [
            'issue read' => ['issue_failure', ['prepare', 'begin', 'issue', 'rollback']],
            'events read' => ['events_failure', ['prepare', 'begin', 'issue', 'events', 'rollback']],
            'double read failure' => ['events_double', ['prepare', 'begin', 'issue', 'events', 'rollback']],
            'commit still active' => ['commit_active', ['prepare', 'begin', 'issue', 'events', 'commit', 'rollback']],
            'commit already ended' => ['commit_ended', ['prepare', 'begin', 'issue', 'events', 'commit']],
            'double commit failure' => ['commit_double', ['prepare', 'begin', 'issue', 'events', 'commit', 'rollback']],
            'isolation unavailable' => ['prepare_failure', ['prepare']],
            // A pending next-transaction characteristic must not leak into another operation.
            'begin zero level' => ['begin_zero', ['prepare', 'begin', 'close']],
            'begin native active' => ['begin_active', ['prepare', 'begin', 'native', 'rollback', 'close']],
            'begin native inactive' => ['begin_inactive', ['prepare', 'begin', 'native', 'close']],
            'begin inspection impossible' => ['begin_unknown', ['prepare', 'begin', 'native', 'close']],
            'begin unsupported driver' => ['begin_unsupported', ['prepare', 'begin', 'native', 'close']],
            'begin connection lost' => ['begin_lost', ['prepare', 'begin', 'close']],
            'begin double failure' => ['begin_double', ['prepare', 'begin', 'native', 'rollback', 'close']],
            'begin deadlock never retried' => ['begin_deadlock', ['prepare', 'begin', 'native', 'rollback', 'close']],
        ];
    }

    public function testForeignTransactionIsRejectedWithoutChangingItsIsolationOrOwnership(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        foreach (['executeStatement', 'setTransactionIsolation', 'beginTransaction', 'commit', 'rollBack', 'close', 'fetchAssociative', 'fetchAllAssociative'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $this->expectException(\LogicException::class);
        (new Mp3CleanupIssueReader($connection))->readInitialDetail(17);
    }

    private function snapshotRun(string $case, ?\Throwable $initial = null, ?\Throwable $rollbackError = null): array
    {
        $steps = [];
        $level = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$level): bool { return $level > 0; });
        // Never use SET SESSION, nor trust DBAL's cached default as proof of actual isolation.
        $connection->expects(self::never())->method('setTransactionIsolation');
        foreach (['insert', 'update', 'delete'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$steps, &$level, $case, $initial): int {
            self::assertSame([], $steps);
            self::assertSame(0, $level);
            self::assertSame('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $sql);
            self::assertSame([], $params);
            $steps[] = 'prepare';
            if ($case === 'prepare_failure') { throw $initial; }
            return 0;
        });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$steps, &$level, $case, $initial): void {
            self::assertSame(['prepare'], $steps);
            $steps[] = 'begin';
            $level = $case === 'begin_zero' ? 0 : 1;
            if (str_starts_with($case, 'begin_')) { throw $initial; }
        });
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params) use (&$steps, &$level, $case, $initial): array|false {
            self::assertSame(['prepare', 'begin'], $steps, 'Issue SELECT must follow snapshot preparation and begin.');
            self::assertSame(1, $level);
            self::assertSame([17], $params);
            $this->assertReadQuery($sql, 'mp3_cleanup_issue', ['id', 'status']);
            $steps[] = 'issue';
            if ($case === 'issue_failure') { throw $initial; }
            return $case === 'absent' ? false : $this->issue();
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params) use (&$steps, &$level, $case, $initial): array {
            self::assertSame(['prepare', 'begin', 'issue'], $steps);
            self::assertSame(1, $level);
            self::assertSame([17], $params);
            $this->assertReadQuery($sql, 'mp3_cleanup_event', ['id', 'context', 'diagnostic']);
            $steps[] = 'events';
            if (str_starts_with($case, 'events_')) { throw $initial; }
            return $case === 'empty' ? [] : [$this->event(91)];
        });
        $connection->method('commit')->willReturnCallback(static function () use (&$steps, &$level, $case, $initial): void {
            self::assertSame(1, $level);
            $steps[] = 'commit';
            $level = $case === 'commit_ended' ? 0 : 1;
            if (str_starts_with($case, 'commit_')) { throw $initial; }
            $level = 0;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$steps, &$level, $rollbackError): void {
            self::assertSame(1, $level);
            $steps[] = 'rollback';
            if ($rollbackError !== null) { throw $rollbackError; }
            $level = 0;
        });
        $connection->method('isConnected')->willReturn($case !== 'begin_lost');
        $connection->expects($case === 'begin_lost' ? self::never() : self::any())->method('getNativeConnection')->willReturnCallback(function () use (&$steps, $case): mixed {
            $steps[] = 'native';
            if ($case === 'begin_unknown') { throw new \RuntimeException('Inspection unavailable'); }
            if ($case === 'begin_unsupported') { return new \stdClass(); }
            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(in_array($case, ['begin_active', 'begin_double', 'begin_deadlock'], true));
            return $pdo;
        });
        $connection->method('close')->willReturnCallback(static function () use (&$steps, &$level): void { $steps[] = 'close'; $level = 0; });
        $result = $error = null;
        try { $result = (new Mp3CleanupIssueReader($connection))->readInitialDetail(17); }
        catch (\Throwable $caught) { $error = $caught; }
        return compact('result', 'error', 'steps', 'level');
    }

    /** @dataProvider nextPageCases */
    public function testNextPageIsBoundedAndKeepsTheOriginalConsultationToken(int $count): void
    {
        $rows = $count === 0 ? [] : array_map(fn (int $id): array => $this->event($id), range(100, 101 - $count));
        // These rows model newly appended events and another issue, outside the requested page.
        $source = array_merge([$this->event(130), $this->event(121), $this->event(101)], $rows);
        $foreign = $this->event(99);
        $foreign['issue_id'] = 18;
        $source[] = $foreign;
        $run = $this->nextPageRun($source);

        self::assertNull($run['error']);
        self::assertSame(['prepare', 'begin', 'events', 'commit'], $run['steps']);
        self::assertSame(0, $run['level']);
        self::assertSame(['events', 'token', 'pagination'], array_keys($run['result']));
        self::assertSame(['issue_id' => 17, 'last_event_id' => 120], $run['result']['token']);
        $visible = min(20, $count);
        $expected = $visible === 0 ? [] : array_map(fn (int $id): array => $this->eventProjection($id), range(100, 101 - $visible));
        self::assertSame($expected, $run['result']['events']);
        self::assertSame(['has_more' => $count > 20, 'next_before_id' => $count > 20 ? 81 : null], $run['result']['pagination']);
        $ids = array_column($run['result']['events'], 'id');
        self::assertSame($ids, array_values(array_unique($ids)));
        foreach ([130, 121, 101] as $excluded) { self::assertNotContains($excluded, $ids); }
    }

    public static function nextPageCases(): array
    {
        return ['empty' => [0], 'last short page' => [2], 'exactly twenty' => [20], 'lookahead' => [21]];
    }

    /** @dataProvider referenceCounts */
    public function testNextPageReusesTheFilteredProjectionAndPreservesReferenceCounts(?int $references): void
    {
        $run = $this->nextPageRun([$this->event(91, $references)]);
        self::assertNull($run['error']);
        self::assertSame([$this->eventProjection(91, $references)], $run['result']['events']);
        self::assertSame($references, $run['result']['events'][0]['context']['reference_count']);
        $encoded = json_encode($run['result'], JSON_THROW_ON_ERROR);
        foreach (['raw-message', 'private-trace', 'private-context', 'private-column'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    /** @dataProvider invalidNextPageParameters */
    public function testNextPageRejectsInvalidParametersBeforeAnyDatabaseOperation(int $issueId, int $lastEventId, int $beforeId): void
    {
        $connection = $this->createMock(Connection::class);
        foreach (['executeStatement', 'beginTransaction', 'commit', 'rollBack', 'close', 'fetchAssociative', 'fetchAllAssociative', 'fetchOne'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $this->expectException(\InvalidArgumentException::class);
        (new Mp3CleanupIssueReader($connection))->readNextEventsPage($issueId, $lastEventId, $beforeId);
    }

    public static function invalidNextPageParameters(): array
    {
        return ['zero issue' => [0, 120, 101], 'negative issue' => [-1, 120, 101],
            'zero high water' => [17, 0, 1], 'negative high water' => [17, -1, 1],
            'zero cursor' => [17, 120, 0], 'negative cursor' => [17, 120, -1],
            'cursor above high water' => [17, 120, 121]];
    }

    public function testNextPageAcceptsACursorEqualToTheHighWaterMark(): void
    {
        $run = $this->nextPageRun([$this->event(120), $this->event(119)], 'success', 120);
        self::assertNull($run['error']);
        self::assertSame([$this->eventProjection(119)], $run['result']['events']);
        self::assertSame(['issue_id' => 17, 'last_event_id' => 120], $run['result']['token']);
    }

    public function testNextPageRefusesToTouchAForeignTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        foreach (['executeStatement', 'setTransactionIsolation', 'beginTransaction', 'commit', 'rollBack', 'close', 'fetchAssociative', 'fetchAllAssociative', 'fetchOne'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $this->expectException(\LogicException::class);
        (new Mp3CleanupIssueReader($connection))->readNextEventsPage(17, 120, 101);
    }

    /** @dataProvider nextPageFailures */
    public function testNextPageFailureNeverReturnsPartialDataOrRetries(string $case, array $expected): void
    {
        $run = $this->nextPageRun([$this->event(91)], $case);
        // An absent method remains a genuine RED error, not a simulated persistence failure.
        if ($run['error'] instanceof \Error) { throw $run['error']; }
        self::assertNull($run['result']);
        if (str_contains($case, 'double')) {
            self::assertInstanceOf(Mp3CleanupTransactionFailure::class, $run['error']);
            self::assertSame($run['initial'], $run['error']->getPrevious());
            self::assertSame($run['rollbackError'], $run['error']->getRollbackFailure());
        } elseif ($case === 'json_failure') {
            self::assertInstanceOf(\JsonException::class, $run['error']);
            self::assertSame($run['initial']->getCode(), $run['error']->getCode());
        } else {
            self::assertSame($run['initial'], $run['error']);
        }
        self::assertSame($expected, $run['steps']);
    }

    public static function nextPageFailures(): array
    {
        return [
            'SQL error' => ['read_failure', ['prepare', 'begin', 'events', 'rollback']],
            'rollback error' => ['read_double', ['prepare', 'begin', 'events', 'rollback']],
            'invalid JSON' => ['json_failure', ['prepare', 'begin', 'events', 'rollback']],
            'commit active' => ['commit_active', ['prepare', 'begin', 'events', 'commit', 'rollback']],
            'commit ended' => ['commit_ended', ['prepare', 'begin', 'events', 'commit']],
            'commit and rollback fail' => ['commit_double', ['prepare', 'begin', 'events', 'commit', 'rollback']],
            'isolation failure' => ['prepare_failure', ['prepare']],
            'begin zero' => ['begin_zero', ['prepare', 'begin', 'close']],
            'begin native active' => ['begin_active', ['prepare', 'begin', 'native', 'rollback', 'close']],
            'begin native inactive' => ['begin_inactive', ['prepare', 'begin', 'native', 'close']],
            'begin native unavailable' => ['begin_unknown', ['prepare', 'begin', 'native', 'close']],
            'begin connection lost' => ['begin_lost', ['prepare', 'begin', 'close']],
            'begin rollback failure' => ['begin_double', ['prepare', 'begin', 'native', 'rollback', 'close']],
        ];
    }

    private function nextPageRun(array $source, string $case = 'success', int $beforeId = 101): array
    {
        $steps = [];
        $level = 0;
        $initial = new \RuntimeException('Read failed');
        if ($case === 'json_failure') {
            $source[0]['context'] = '{';
            try { json_decode('{', true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException $caught) { $initial = $caught; }
        }
        if ($case === 'begin_lost') {
            $driver = new class('Lost') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                public function getSQLState(): ?string { return 'HY000'; }
            };
            $initial = new \Doctrine\DBAL\Exception\ConnectionLost($driver, null);
        }
        $rollbackError = new \RuntimeException('Rollback failed');
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$level): bool { return $level > 0; });
        foreach (['insert', 'update', 'delete', 'setTransactionIsolation', 'fetchAssociative', 'fetchOne'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$steps, $case, $initial): int {
            self::assertSame([], $steps);
            self::assertSame('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $sql);
            self::assertSame([], $params);
            $steps[] = 'prepare';
            if ($case === 'prepare_failure') { throw $initial; }
            return 0;
        });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$steps, &$level, $case, $initial): void {
            self::assertSame(['prepare'], $steps);
            $steps[] = 'begin';
            $level = $case === 'begin_zero' ? 0 : 1;
            if (str_starts_with($case, 'begin_')) { throw $initial; }
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params) use (&$steps, &$level, $source, $beforeId, $case, $initial): array {
            self::assertSame(['prepare', 'begin'], $steps);
            self::assertSame(1, $level);
            $this->assertReadQuery($sql, 'mp3_cleanup_event', ['id', 'occurred_at', 'reason', 'emission_id', 'obsolete_relative_path', 'context', 'diagnostic']);
            $sql = $this->sql($sql);
            self::assertMatchesRegularExpression('/where\s+issue_id = \?\s+and\s+id <= \?\s+and\s+id < \?\s+order by id desc\s+limit (21|\?)(?:\s|$)/', $sql);
            self::assertSame(preg_match('/limit \?/', $sql) ? [17, 120, $beforeId, 21] : [17, 120, $beforeId], $params);
            $steps[] = 'events';
            if (str_starts_with($case, 'read_')) { throw $initial; }
            // SQL semantics are simulated only after checking all predicates and parameters.
            $rows = array_values(array_filter($source, static fn (array $row): bool => ($row['issue_id'] ?? 17) === 17 && (int) $row['id'] <= 120 && (int) $row['id'] < $beforeId));
            usort($rows, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
            return array_slice($rows, 0, 21);
        });
        $connection->method('commit')->willReturnCallback(static function () use (&$steps, &$level, $case, $initial): void {
            self::assertSame(['prepare', 'begin', 'events'], $steps);
            $steps[] = 'commit';
            $level = $case === 'commit_ended' ? 0 : 1;
            if (str_starts_with($case, 'commit_')) { throw $initial; }
            $level = 0;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$steps, &$level, $case, $rollbackError): void {
            self::assertSame(1, $level);
            $steps[] = 'rollback';
            if (str_contains($case, 'double')) { throw $rollbackError; }
            $level = 0;
        });
        $connection->method('isConnected')->willReturn($case !== 'begin_lost');
        $connection->expects($case === 'begin_lost' ? self::never() : self::any())->method('getNativeConnection')->willReturnCallback(function () use (&$steps, $case): \PDO {
            $steps[] = 'native';
            if ($case === 'begin_unknown') { throw new \RuntimeException('Native unavailable'); }
            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(in_array($case, ['begin_active', 'begin_double'], true));
            return $pdo;
        });
        $connection->method('close')->willReturnCallback(static function () use (&$steps, &$level): void { $steps[] = 'close'; $level = 0; });
        $result = $error = null;
        try { $result = (new Mp3CleanupIssueReader($connection))->readNextEventsPage(17, 120, $beforeId); }
        catch (\Throwable $caught) {
            if ($caught instanceof \Error) { throw $caught; }
            $error = $caught;
        }
        return compact('result', 'error', 'steps', 'level', 'initial', 'rollbackError');
    }

    private function reader(array|false $issue, array $events): Mp3CleanupIssueReader
    {
        $connection = $this->createMock(Connection::class);
        foreach (['insert', 'update', 'delete'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        // Permit only next-transaction preparation; projection assertions remain unchanged.
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []): int {
            self::assertSame('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $sql);
            self::assertSame([], $params);
            return 0;
        });
        $connection->expects(self::once())->method('fetchAssociative')->willReturnCallback(
            function (string $sql, array $params) use ($issue): array|false {
                self::assertSame([17], $params);
                $this->assertReadQuery($sql, 'mp3_cleanup_issue', ['id', 'status', 'storage', 'obsolete_relative_path', 'created_at', 'resolved_at']);
                self::assertStringContainsString('where id = ?', $this->sql($sql));
                return $issue;
            }
        );
        $connection->expects($issue === false ? self::never() : self::once())->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use ($events): array {
                $this->assertReadQuery($sql, 'mp3_cleanup_event', ['id', 'occurred_at', 'reason', 'emission_id', 'obsolete_relative_path', 'context', 'diagnostic']);
                $sql = $this->sql($sql);
                self::assertStringContainsString('where issue_id = ?', $sql);
                self::assertStringContainsString('order by id desc', $sql);
                self::assertSame(17, $params[0]);
                if (preg_match('/limit\s+\?/', $sql)) {
                    self::assertSame(21, $params[count($params) - 1]);
                } else {
                    self::assertMatchesRegularExpression('/limit\s+21\b/', $sql);
                }
                return $events;
            }
        );
        return new Mp3CleanupIssueReader($connection);
    }

    private function assertReadQuery(string $sql, string $table, array $columns): void
    {
        $sql = $this->sql($sql);
        self::assertStringStartsWith('select ', $sql);
        self::assertStringContainsString('from ' . $table, $sql);
        self::assertStringNotContainsString('*', $sql);
        self::assertStringNotContainsString('for update', $sql);
        self::assertStringNotContainsString('for share', $sql);
        self::assertStringNotContainsString('lock in share mode', $sql);
        $selection = explode(' from ', $sql, 2)[0];
        foreach ($columns as $column) {
            self::assertMatchesRegularExpression('/\b' . $column . '\b/', $selection);
        }
    }

    private function sql(string $sql): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($sql)));
    }

    private function issue(): array
    {
        return ['id' => '17', 'status' => 'open', 'storage' => 'emissionsMp3',
            'obsolete_relative_path' => 'TST/2026/Old.mp3', 'created_at' => '2026-11-15 10:00:00.123456',
            'resolved_at' => null, 'internal' => 'private-column'];
    }

    private function issueProjection(): array
    {
        return ['id' => 17, 'status' => 'open', 'storage' => 'emissionsMp3',
            'obsolete_relative_path' => 'TST/2026/Old.mp3', 'created_at' => '2026-11-15 10:00:00.123456',
            'resolved_at' => null];
    }

    private function event(int $id, ?int $references = null): array
    {
        return ['id' => (string) $id, 'occurred_at' => '2026-11-15 10:00:00.000007',
            'reason' => $references === null ? 'reference_check_failed' : 'obsolete_cleanup_failed',
            'emission_id' => '42', 'obsolete_relative_path' => 'TST/2026/Old.mp3',
            'context' => json_encode(['replacement_relative_path' => 'TST/2026/New.mp3', 'commit_confirmed' => true,
                'reference_count' => $references, 'secret' => 'private-context'], JSON_THROW_ON_ERROR),
            'diagnostic' => json_encode(['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null,
                'message' => 'raw-message', 'trace' => 'private-trace'], JSON_THROW_ON_ERROR),
            'internal' => 'private-column'];
    }

    private function eventProjection(int $id, ?int $references = null): array
    {
        return ['id' => $id, 'occurred_at' => '2026-11-15 10:00:00.000007',
            'reason' => $references === null ? 'reference_check_failed' : 'obsolete_cleanup_failed',
            'emission_id' => 42, 'obsolete_relative_path' => 'TST/2026/Old.mp3',
            'context' => ['replacement_relative_path' => 'TST/2026/New.mp3', 'commit_confirmed' => true, 'reference_count' => $references],
            'diagnostic' => ['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null]];
    }
}
