<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupActiveIssueCollision;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Process\Process;

/** @group concurrency */
final class Mp3CleanupConcurrencyIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    private array $evidence = [];

    public function testReadCommittedInsertionCollisionRollsBackAndRetries(): void
    {
        $path = $this->newFixturePath();
        $a = $this->newConnection(Mp3CleanupCollisionConnection::class);
        $b = $this->newConnection(Mp3CleanupCollisionConnection::class);
        self::assertInstanceOf(Mp3CleanupCollisionConnection::class, $a);
        self::assertInstanceOf(Mp3CleanupCollisionConnection::class, $b);
        $this->evidence = ['sessions' => ['A' => $this->connectionId($a), 'B' => $this->connectionId($b)]];
        $isolationA = $a->fetchOne('SELECT @@SESSION.transaction_isolation');
        $isolationB = $b->fetchOne('SELECT @@SESSION.transaction_isolation');
        $adapterA = new Mp3CleanupIssueDbalAdapter($a);
        $adapterB = new Mp3CleanupIssueDbalAdapter($b);
        $findsA = 0;
        $findsB = 0;
        $createsA = 0;
        $idB = null;
        $collision = null;
        $primary = null;
        $anomalyB = new Mp3CleanupAnomaly('reference_check_failed', 42, $path, [
            'replacement_relative_path' => dirname($path) . '/replacement-b.mp3',
            'commit_confirmed' => true, 'reference_count' => null,
        ], ['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null]);
        $anomalyA = new Mp3CleanupAnomaly('obsolete_cleanup_failed', 43, $path, [
            'replacement_relative_path' => dirname($path) . '/replacement-a.mp3',
            'commit_confirmed' => true, 'reference_count' => 0,
        ], ['class' => \RuntimeException::class, 'code' => 8, 'sql_state' => null]);
        $a->useReadCommitted = $b->useReadCommitted = true;
        try {
            $writerB = new Mp3CleanupIssueWriter($b, new Mp3CleanupIssueGrouper(
                function (string $storage, string $relativePath) use ($adapterB, $b, &$findsB): array {
                    ++$findsB;
                    self::assertSame(1, $b->getTransactionNestingLevel());
                    $rows = $adapterB->findActiveIssues($storage, $relativePath);
                    self::assertSame([], $rows);
                    $this->evidence['b_initial_search_empty'] = true;
                    return $rows;
                },
                $adapterB->createIssue(...), $adapterB->appendEvent(...),
            ));
            $writerA = new Mp3CleanupIssueWriter($a, new Mp3CleanupIssueGrouper(
                function (string $storage, string $relativePath) use ($adapterA, $a, $b, $writerB, $anomalyB, &$findsA, &$idB): array {
                    ++$findsA;
                    self::assertSame(1, $a->getTransactionNestingLevel());
                    $rows = $adapterA->findActiveIssues($storage, $relativePath);
                    if ($findsA === 1) {
                        self::assertSame([], $rows);
                        $this->evidence['a_initial_search_empty'] = true;
                        $idB = $writerB->record($storage, $anomalyB);
                        self::assertSame(['begin', 'commit'], $b->transactions);
                        self::assertSame(0, $b->getTransactionNestingLevel());
                        self::assertFalse($b->getNativeConnection()->inTransaction());
                        // Visibility from A establishes that B's row and first event are committed.
                        self::assertSame($idB, (int) $a->fetchOne('SELECT id FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', [$storage, $relativePath]));
                        self::assertSame(1, (int) $a->fetchOne('SELECT COUNT(*) FROM mp3_cleanup_event WHERE issue_id = ?', [$idB]));
                        $this->evidence['b_committed_before_a_insert'] = true;
                    } else {
                        self::assertSame(2, $findsA);
                        self::assertSame(['begin', 'rollback', 'begin'], $a->transactions);
                        self::assertCount(1, $rows);
                        self::assertSame($idB, (int) $rows[0]['id']);
                        $this->evidence['a_retry_found_b_issue'] = true;
                    }
                    // Preserve A's original empty result despite B's completed transaction.
                    return $rows;
                },
                function (array $data) use ($adapterA, $a, $b, &$createsA, &$collision): int {
                    ++$createsA;
                    self::assertSame(['begin', 'commit'], $b->transactions);
                    try {
                        return $adapterA->createIssue($data);
                    } catch (Mp3CleanupActiveIssueCollision $error) {
                        $collision = $error;
                        self::assertInstanceOf(UniqueConstraintViolationException::class, $error->getPrevious());
                        self::assertSame($a->uniqueViolation, $error->getPrevious());
                        self::assertSame('23000', $a->uniqueViolation->getSQLState());
                        self::assertSame(1062, (int) $a->uniqueViolation->getPrevious()->getCode());
                        self::assertSame(1, $a->getTransactionNestingLevel());
                        self::assertSame(['begin'], $a->transactions);
                        $this->evidence['collision'] = ['class' => $error::class,
                            'previous_class' => $error->getPrevious()::class, 'sql_state' => '23000', 'driver_code' => 1062];
                        throw $error;
                    }
                },
                $adapterA->appendEvent(...),
            ));
            $idA = $writerA->record('emissionsMp3', $anomalyA);
            self::assertInstanceOf(Mp3CleanupActiveIssueCollision::class, $collision);
            self::assertSame(2, $findsA);
            self::assertSame(1, $findsB);
            self::assertSame(1, $createsA);
            self::assertSame(['begin', 'rollback', 'begin', 'commit'], $a->transactions);
            self::assertSame(['begin', 'commit'], $b->transactions);
            self::assertSame($idB, $idA);
            $issues = $b->fetchAllAssociative('SELECT id, status, active_marker FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', ['emissionsMp3', $path]);
            self::assertCount(1, $issues);
            self::assertSame($idA, (int) $issues[0]['id']);
            self::assertSame('open', $issues[0]['status']);
            self::assertSame(1, (int) $issues[0]['active_marker']);
            $events = $b->fetchAllAssociative('SELECT id, issue_id, reason, emission_id, context FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id', [$idA]);
            self::assertCount(2, $events);
            self::assertNotSame((int) $events[0]['id'], (int) $events[1]['id']);
            foreach ($events as $index => $event) {
                self::assertSame($idA, (int) $event['issue_id']);
                self::assertSame($index === 0 ? 'reference_check_failed' : 'obsolete_cleanup_failed', $event['reason']);
                self::assertSame(42 + $index, (int) $event['emission_id']);
                $context = json_decode($event['context'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($index === 0 ? null : 0, $context['reference_count']);
                self::assertSame(dirname($path) . ($index === 0 ? '/replacement-b.mp3' : '/replacement-a.mp3'), $context['replacement_relative_path']);
            }
            self::assertSame(0, $a->getTransactionNestingLevel());
            self::assertSame(0, $b->getTransactionNestingLevel());
            self::assertFalse($a->getNativeConnection()->inTransaction());
            self::assertFalse($b->getNativeConnection()->inTransaction());
            $this->evidence += ['issue_id' => $idA, 'active_issues' => count($issues), 'events' => count($events)];
        } catch (\Throwable $error) {
            $primary = $error;
            $this->evidence['parent_failure'] = ['class' => $error::class, 'code' => $error->getCode()];
            throw $error;
        } finally {
            $a->useReadCommitted = $b->useReadCommitted = false;
            $this->evidence['a_transactions'] = $a->transactions;
            $this->evidence['b_transactions'] = $b->transactions;
            // SET TRANSACTION affects only the next transaction, never the session default.
            $restored = [];
            try {
                foreach (['A' => [$a, $isolationA], 'B' => [$b, $isolationB]] as $name => [$connection, $initial]) {
                    $restored[$name] = false;
                    if ($connection->isConnected() && $connection->getTransactionNestingLevel() === 0) {
                        $restored[$name] = $connection->fetchOne('SELECT @@SESSION.transaction_isolation') === $initial;
                    }
                }
            } catch (\Throwable $isolationError) {
                $this->evidence['isolation_check_failure'] = ['class' => $isolationError::class, 'code' => $isolationError->getCode()];
                if ($primary === null) { throw $isolationError; }
            }
            $this->evidence['session_isolation_preserved'] = $restored;
        }
        self::assertSame(['A' => true, 'B' => true], $restored);
    }

    public function testTwoReportsShareOneIssueAfterAnObservedMySqlLockWait(): void
    {
        $this->runConcurrentOperation(false);
    }

    public function testConcurrentReportMakesResolutionTokenStale(): void
    {
        $this->runConcurrentOperation(true);
    }

    public function testResolutionBeforeConcurrentReportCreatesANewActiveIssue(): void
    {
        $path = $this->newFixturePath();
        $b = $this->newConnection();
        $b->executeStatement('SET SESSION innodb_lock_wait_timeout = 20');
        $sessionB = $this->connectionId($b);
        $threadB = (int) $b->fetchOne('SELECT PS_CURRENT_THREAD_ID()');
        $this->evidence = ['sessions' => ['B' => $sessionB], 'threads' => ['B' => $threadB], 'messages' => []];
        $adapter = new Mp3CleanupIssueDbalAdapter($b);
        $writer = new Mp3CleanupIssueWriter($b, new Mp3CleanupIssueGrouper(
            $adapter->findActiveIssues(...), $adapter->createIssue(...), $adapter->appendEvent(...),
        ));
        $oldId = $writer->record('emissionsMp3', new Mp3CleanupAnomaly('reference_check_failed', 42, $path, [
            'replacement_relative_path' => dirname($path) . '/replacement-a.mp3',
            'commit_confirmed' => true, 'reference_count' => null,
        ], ['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null]));
        $initialEventId = (int) $b->fetchOne('SELECT id FROM mp3_cleanup_event WHERE issue_id = ?', [$oldId]);
        self::assertGreaterThan(0, $initialEventId);
        $resolutionToken = ['issue_id' => $oldId, 'last_event_id' => $initialEventId];
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server);
        $worker = null;
        $channel = null;
        $primary = null;
        $token = bin2hex(random_bytes(16));
        try {
            $worker = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__, 3) . '/Support/Mp3CleanupConcurrencyWorker.php'], dirname(__DIR__, 4), ['APP_ENV' => 'test', 'XDEBUG_MODE' => 'off']);
            $worker->setInput(json_encode([
                'params' => $b->getParams(), 'path' => $path, 'token' => $token, 'operation' => 'resolve_first',
                'issue_id' => $oldId, 'resolution_token' => $resolutionToken, 'session_b' => $sessionB, 'thread_b' => $threadB,
                'address' => 'tcp://' . stream_socket_get_name($server, false),
            ], JSON_THROW_ON_ERROR));
            $worker->setTimeout(45);
            $worker->start();
            $channel = stream_socket_accept($server, 10);
            self::assertNotFalse($channel);
            stream_set_timeout($channel, 15);
            $ready = $this->receive($channel, $token, 'ready');
            self::assertSame('symfony_test', $ready['database']);
            self::assertSame(0, $ready['level']);
            $this->evidence['sessions']['A'] = $ready['session'];
            $this->evidence['threads']['A'] = $ready['thread'];
            $send = static function (string $event) use ($channel, $token): void {
                $line = json_encode(['token' => $token, 'event' => $event], JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($channel, $line) !== strlen($line) || !fflush($channel)) {
                    throw new \RuntimeException('Could not release concurrency barrier.');
                }
            };
            $send('run');
            $locked = $this->receive($channel, $token, 'resolution_locked');
            self::assertSame(1, $locked['level']);
            self::assertCount(3, array_unique([$sessionB, $ready['session'], $locked['session_c']]));
            $this->evidence['sessions']['C'] = $locked['session_c'];
            $this->evidence['transaction_a'] = $locked['transaction_a'];
            // The child now observes autonomously: this parent will block inside real SQL.
            $send('observe');
            $newId = $writer->record('emissionsMp3', new Mp3CleanupAnomaly('obsolete_cleanup_failed', 43, $path, [
                'replacement_relative_path' => dirname($path) . '/replacement-b.mp3',
                'commit_confirmed' => true, 'reference_count' => 0,
            ], ['class' => \RuntimeException::class, 'code' => 8, 'sql_state' => null]));
            $observed = $this->receive($channel, $token, 'lock_observed');
            $wait = $observed['strict_wait'];
            $this->evidence['strict_wait'] = $wait;
            $this->evidence['polls'] = $observed['polls'];
            self::assertSame($threadB, (int) $wait['REQUESTING_THREAD_ID']);
            self::assertSame($locked['transaction_a'], (string) $wait['BLOCKING_ENGINE_TRANSACTION_ID']);
            self::assertNotSame($locked['transaction_a'], (string) $wait['REQUESTING_ENGINE_TRANSACTION_ID']);
            self::assertSame('WAITING', $wait['requesting_status']);
            self::assertSame('GRANTED', $wait['blocking_status']);
            foreach (['requesting', 'blocking'] as $side) {
                self::assertSame('symfony_test', $wait[$side . '_schema']);
                self::assertSame('mp3_cleanup_issue', $wait[$side . '_table']);
            }
            $done = $this->receive($channel, $token, 'done');
            self::assertSame('allowed', $done['result']);
            self::assertSame($resolutionToken, $done['resolution_token']);
            self::assertSame($oldId, $done['issue_id']);
            self::assertSame($ready['session'], $done['session']);
            self::assertSame(0, $done['level']);
            $closed = $this->receive($channel, $token, 'closed');
            self::assertFalse($closed['connected']);
            self::assertFalse($closed['observer_connected']);
            self::assertSame(0, $closed['level']);
            self::assertSame(0, $closed['exit']);
            self::assertSame(0, $worker->wait());
            self::assertFalse($worker->isRunning());
            self::assertNotSame($oldId, $newId);
            $issues = $b->fetchAllAssociative('SELECT id, status, active_marker, resolved_at FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ? ORDER BY id', ['emissionsMp3', $path]);
            self::assertCount(2, $issues);
            self::assertSame($oldId, (int) $issues[0]['id']);
            self::assertSame('resolved', $issues[0]['status']);
            self::assertNull($issues[0]['active_marker']);
            self::assertSame('2026-11-15 12:00:00.123456', $issues[0]['resolved_at']);
            self::assertSame($newId, (int) $issues[1]['id']);
            self::assertSame('open', $issues[1]['status']);
            self::assertSame(1, (int) $issues[1]['active_marker']);
            self::assertNull($issues[1]['resolved_at']);
            self::assertCount(1, array_filter($issues, static fn (array $issue): bool => $issue['active_marker'] !== null));
            $events = $b->fetchAllAssociative('SELECT id, issue_id, reason, emission_id FROM mp3_cleanup_event WHERE issue_id IN (?, ?) ORDER BY id', [$oldId, $newId]);
            self::assertCount(2, $events);
            self::assertSame($initialEventId, (int) $events[0]['id']);
            self::assertSame($oldId, (int) $events[0]['issue_id']);
            self::assertSame('reference_check_failed', $events[0]['reason']);
            self::assertSame(42, (int) $events[0]['emission_id']);
            self::assertSame($newId, (int) $events[1]['issue_id']);
            self::assertGreaterThan($initialEventId, (int) $events[1]['id']);
            self::assertSame('obsolete_cleanup_failed', $events[1]['reason']);
            self::assertSame(43, (int) $events[1]['emission_id']);
            self::assertSame(0, $b->getTransactionNestingLevel());
            $this->evidence += ['resolution_result' => $done['result'], 'old_issue_id' => $oldId, 'new_issue_id' => $newId,
                'events' => $events, 'active_issues' => 1];
        } catch (\Throwable $error) {
            $primary = $error;
            $this->evidence['parent_failure'] = ['class' => $error::class, 'code' => $error->getCode()];
            // Recover buffered child diagnostics after an SQL failure, without waiting indefinitely.
            if (is_resource($channel)) {
                stream_set_blocking($channel, false);
                while (($line = fgets($channel, 8192)) !== false) {
                    $message = json_decode($line, true);
                    if (is_array($message) && ($message['token'] ?? null) === $token) {
                        unset($message['token']);
                        $this->evidence['messages'][] = $message;
                    }
                }
            }
            throw $error;
        } finally {
            try {
                if ($worker !== null && $worker->isRunning()) { $worker->stop(1); }
                if ($worker !== null && $worker->isStarted()) { $worker->wait(); }
            } catch (\Throwable $cleanupError) {
                $this->evidence['worker_cleanup_failure'] = ['class' => $cleanupError::class, 'code' => $cleanupError->getCode()];
                if ($primary === null) { throw $cleanupError; }
            } finally {
                $this->evidence['worker_running_after_cleanup'] = $worker?->isRunning() ?? false;
                $this->evidence['worker_exit'] = $worker?->getExitCode();
                if (is_resource($channel)) { fclose($channel); }
                fclose($server);
            }
        }
    }

    private function runConcurrentOperation(bool $resolve): void
    {
        $path = $this->newFixturePath();
        $a = $this->newConnection();
        $c = $this->newConnection();
        $a->executeStatement('SET SESSION innodb_lock_wait_timeout = 20');
        $sessionA = $this->connectionId($a);
        $sessionC = $this->connectionId($c);
        $threadA = (int) $a->fetchOne('SELECT PS_CURRENT_THREAD_ID()');
        $this->evidence = ['sessions' => ['A' => $sessionA, 'C' => $sessionC], 'threads' => ['A' => $threadA],
            'a_stage' => 'ready', 'b_stage' => 'not_started', 'polls' => 0, 'messages' => []];
        self::assertGreaterThan(0, $threadA);
        // Fail before starting a child if the required observation privilege is missing.
        $c->fetchOne('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*) FROM performance_schema.data_lock_waits');
        $c->fetchOne('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*) FROM performance_schema.data_locks');
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, 'Cannot create local concurrency IPC listener.');
        $worker = null;
        $channel = null;
        $primary = null;
        $token = bin2hex(random_bytes(16));
        $adapter = new Mp3CleanupIssueDbalAdapter($a);
        $initialIssueId = null;
        $initialEventId = null;
        try {
            if ($resolve) {
                $initialWriter = new Mp3CleanupIssueWriter($a, new Mp3CleanupIssueGrouper(
                    $adapter->findActiveIssues(...), $adapter->createIssue(...), $adapter->appendEvent(...),
                ));
                $initialIssueId = $initialWriter->record('emissionsMp3', new Mp3CleanupAnomaly('reference_check_failed', 42, $path, [
                    'replacement_relative_path' => dirname($path) . '/replacement-a.mp3',
                    'commit_confirmed' => true, 'reference_count' => null,
                ], ['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null]));
                $initialEventId = (int) $a->fetchOne('SELECT id FROM mp3_cleanup_event WHERE issue_id = ?', [$initialIssueId]);
                self::assertGreaterThan(0, $initialEventId);
            }
            $worker = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__, 3) . '/Support/Mp3CleanupConcurrencyWorker.php'], dirname(__DIR__, 4), ['APP_ENV' => 'test', 'XDEBUG_MODE' => 'off']);
            $worker->setInput(json_encode([
                'params' => $a->getParams(), 'path' => $path, 'token' => $token,
                'operation' => $resolve ? 'resolve' : 'report',
                'issue_id' => $initialIssueId,
                'resolution_token' => ['issue_id' => $initialIssueId, 'last_event_id' => $initialEventId],
                'address' => 'tcp://' . stream_socket_get_name($server, false),
            ], JSON_THROW_ON_ERROR));
            $worker->setTimeout(45);
            $worker->start();
            $channel = stream_socket_accept($server, 10);
            self::assertNotFalse($channel, 'Worker did not reach the IPC listener.');
            stream_set_timeout($channel, 15);
            $ready = $this->receive($channel, $token, 'ready');
            $this->evidence['sessions']['B'] = $ready['session'];
            $this->evidence['threads']['B'] = $ready['thread'];
            self::assertSame('symfony_test', $ready['database']);
            self::assertSame(0, $ready['level']);
            self::assertCount(3, array_unique([$sessionA, $ready['session'], $sessionC]));
            self::assertNotSame($threadA, $ready['thread']);

            $observed = null;
            $creations = 0;
            $pause = function () use ($a, $c, $worker, $channel, $token, $ready, $threadA, $resolve): void {
                self::assertSame(1, $a->getTransactionNestingLevel());
                $this->evidence['a_stage'] = $resolve ? 'event_inserted_before_commit' : 'issue_inserted_before_commit';
                $this->evidence['a_level_during_observation'] = $a->getTransactionNestingLevel();
                $locksA = $c->fetchAllAssociative(
                    'SELECT /*+ MAX_EXECUTION_TIME(1000) */ ENGINE_TRANSACTION_ID
                     FROM performance_schema.data_locks
                     WHERE ENGINE = ? AND THREAD_ID = ? AND OBJECT_SCHEMA = ? AND OBJECT_NAME = ?
                       AND LOCK_TYPE = ? AND LOCK_MODE = ? AND LOCK_STATUS = ?',
                    ['INNODB', $threadA, 'symfony_test', 'mp3_cleanup_issue', 'TABLE', 'IX', 'GRANTED'],
                    [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING,
                        ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
                );
                $this->evidence['a_table_locks'] = $locksA;
                self::assertCount(1, $locksA, 'Cannot uniquely identify transaction A from its granted table IX lock before releasing B.');
                $transactionA = (string) $locksA[0]['ENGINE_TRANSACTION_ID'];
                self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $transactionA);
                $this->evidence['transaction_a'] = $transactionA;
                $line = json_encode(['token' => $token, 'event' => 'run'], JSON_THROW_ON_ERROR) . "\n";
                self::assertSame(strlen($line), fwrite($channel, $line));
                fflush($channel);
                $this->receive($channel, $token, 'attempting');
                $beforeFind = $this->receive($channel, $token, $resolve ? 'before_resolution' : 'before_find');
                self::assertSame($resolve ? 0 : 1, $beforeFind['level']);
                $deadline = microtime(true) + 10;
                do {
                    $worker->checkTimeout();
                    ++$this->evidence['polls'];
                    $observed = $c->fetchAssociative(
                        'SELECT /*+ MAX_EXECUTION_TIME(1000) */ w.REQUESTING_THREAD_ID, w.BLOCKING_THREAD_ID,
                                w.REQUESTING_ENGINE_TRANSACTION_ID, w.BLOCKING_ENGINE_TRANSACTION_ID,
                                r.LOCK_STATUS AS requesting_status, b.LOCK_STATUS AS blocking_status,
                                r.OBJECT_SCHEMA AS requesting_schema, r.OBJECT_NAME AS requesting_table,
                                b.OBJECT_SCHEMA AS blocking_schema, b.OBJECT_NAME AS blocking_table
                         FROM performance_schema.data_lock_waits w
                         JOIN performance_schema.data_locks r
                           ON r.ENGINE = w.ENGINE AND r.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                         JOIN performance_schema.data_locks b
                           ON b.ENGINE = w.ENGINE AND b.ENGINE_LOCK_ID = w.BLOCKING_ENGINE_LOCK_ID
                         WHERE w.ENGINE = ? AND w.REQUESTING_THREAD_ID = ?
                           AND w.BLOCKING_ENGINE_TRANSACTION_ID = ?
                           AND r.ENGINE_TRANSACTION_ID = w.REQUESTING_ENGINE_TRANSACTION_ID
                           AND b.ENGINE_TRANSACTION_ID = w.BLOCKING_ENGINE_TRANSACTION_ID
                           AND EXISTS (
                               SELECT 1 FROM performance_schema.data_locks owner_b
                               WHERE owner_b.ENGINE = w.ENGINE AND owner_b.THREAD_ID = ?
                                 AND owner_b.ENGINE_TRANSACTION_ID = w.REQUESTING_ENGINE_TRANSACTION_ID
                                 AND owner_b.OBJECT_SCHEMA = ? AND owner_b.OBJECT_NAME = ?
                                 AND owner_b.LOCK_TYPE = ? AND owner_b.LOCK_MODE = ? AND owner_b.LOCK_STATUS = ?
                           )
                           AND r.OBJECT_SCHEMA = ? AND r.OBJECT_NAME = ? AND r.LOCK_STATUS = ?
                           AND b.OBJECT_SCHEMA = ? AND b.OBJECT_NAME = ? AND b.LOCK_STATUS = ? LIMIT 1',
                        ['INNODB', $ready['thread'], $transactionA, $ready['thread'],
                            'symfony_test', 'mp3_cleanup_issue', 'TABLE', 'IX', 'GRANTED',
                            'symfony_test', 'mp3_cleanup_issue', 'WAITING',
                            'symfony_test', 'mp3_cleanup_issue', 'GRANTED'],
                        [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER,
                            ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                            ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                            ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
                    );
                    if ($observed !== false) { break; }
                    // IPC readiness only detects early failure and paces polling; never proves a lock.
                    $read = [$channel]; $write = $except = [];
                    $available = stream_select($read, $write, $except, 0, 10000);
                    if ($available === false) { throw new \RuntimeException('IPC polling failed.'); }
                    if ($available > 0) {
                        $this->receive($channel, $token, 'terminal_before_lock');
                        self::fail('Worker advanced before a matching MySQL lock wait was observed.');
                    }
                } while (microtime(true) < $deadline);
                $this->evidence['strict_wait'] = $observed;
                if ($observed === false) {
                    try {
                        $rows = $c->fetchAllAssociative(
                            'SELECT /*+ MAX_EXECUTION_TIME(1000) */ ENGINE, REQUESTING_THREAD_ID, BLOCKING_THREAD_ID,
                             REQUESTING_ENGINE_TRANSACTION_ID, BLOCKING_ENGINE_TRANSACTION_ID
                             FROM performance_schema.data_lock_waits LIMIT 21',
                        );
                        $this->evidence['wait_sample'] = array_slice($rows, 0, 20);
                        $this->evidence['wait_sample_truncated'] = count($rows) > 20;
                        $this->evidence['observation'] = $rows === [] ? 'no_visible_waits' : 'visible_waits_without_strict_match';
                    } catch (\Throwable $diagnosticError) {
                        $this->evidence['diagnostic_failure'] = ['class' => $diagnosticError::class, 'code' => $diagnosticError->getCode()];
                    }
                } else {
                    $this->evidence['observation'] = 'strict_b_to_a_wait';
                }
                self::assertIsArray($observed, 'No B-to-A MySQL lock wait was observed before the deadline.');
                self::assertSame($ready['thread'], (int) $observed['REQUESTING_THREAD_ID']);
                self::assertSame($transactionA, (string) $observed['BLOCKING_ENGINE_TRANSACTION_ID']);
                self::assertNotSame($transactionA, (string) $observed['REQUESTING_ENGINE_TRANSACTION_ID']);
                self::assertSame('WAITING', $observed['requesting_status']);
                self::assertSame('GRANTED', $observed['blocking_status']);
                self::assertSame('symfony_test', $observed['requesting_schema']);
                self::assertSame('symfony_test', $observed['blocking_schema']);
                self::assertSame('mp3_cleanup_issue', $observed['requesting_table']);
                self::assertSame('mp3_cleanup_issue', $observed['blocking_table']);
                // Returning releases A only after the real B-to-A lock wait was observed.
            };
            $writer = new Mp3CleanupIssueWriter($a, new Mp3CleanupIssueGrouper(
                $adapter->findActiveIssues(...),
                function (array $data) use ($adapter, $pause, $resolve, &$creations): int {
                    $id = $adapter->createIssue($data);
                    ++$creations;
                    if (!$resolve) { $pause(); }
                    return $id;
                },
                function (int $issueId, Mp3CleanupAnomaly $anomaly) use ($adapter, $pause, $resolve): void {
                    $adapter->appendEvent($issueId, $anomaly);
                    if ($resolve) { $pause(); }
                },
            ));
            $id = $writer->record('emissionsMp3', new Mp3CleanupAnomaly($resolve ? 'obsolete_cleanup_failed' : 'reference_check_failed', $resolve ? 43 : 42, $path, [
                'replacement_relative_path' => dirname($path) . ($resolve ? '/replacement-b.mp3' : '/replacement-a.mp3'),
                'commit_confirmed' => true, 'reference_count' => $resolve ? 0 : null,
            ], ['class' => \RuntimeException::class, 'code' => $resolve ? 8 : 7, 'sql_state' => null]));
            $this->evidence['a_stage'] = 'committed';
            // A retry may emit another before_find/after_find pair. Never mistake it for completion.
            do {
                $done = $this->receive($channel, $token, null);
                self::assertContains($done['event'], ['before_find', 'after_find', 'done']);
            } while ($done['event'] !== 'done');
            $closed = $this->receive($channel, $token, 'closed');
            self::assertFalse($closed['connected']);
            self::assertSame(0, $closed['level']);
            self::assertSame(0, $closed['exit']);
            self::assertSame(0, $worker->wait(), 'Worker must exit normally after closing its DBAL session.');
            self::assertFalse($worker->isRunning());
            self::assertSame($id, $done['issue_id']);
            self::assertSame($ready['session'], $done['session']);
            self::assertSame(0, $done['level']);
            self::assertSame($resolve ? 0 : 1, $creations);
            if ($resolve) {
                self::assertSame($initialIssueId, $id);
                self::assertSame('stale_view', $done['result']);
                self::assertSame(['issue_id' => $initialIssueId, 'last_event_id' => $initialEventId], $done['resolution_token']);
                $this->evidence['resolution_result'] = $done['result'];
            }
            $issues = $c->fetchAllAssociative(
                'SELECT id, status, active_marker, resolved_at FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', ['emissionsMp3', $path],
            );
            self::assertCount(1, $issues);
            self::assertSame($id, (int) $issues[0]['id']);
            self::assertSame('open', $issues[0]['status']);
            self::assertSame(1, (int) $issues[0]['active_marker']);
            if ($resolve) { self::assertNull($issues[0]['resolved_at']); }
            $events = $c->fetchAllAssociative(
                'SELECT id, reason, emission_id, obsolete_relative_path, context, diagnostic FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id', [$id],
            );
            self::assertCount(2, $events);
            self::assertNotSame((int) $events[0]['id'], (int) $events[1]['id']);
            if ($resolve) {
                self::assertSame($initialEventId, (int) $events[0]['id']);
                self::assertGreaterThan($initialEventId, (int) $events[1]['id']);
            }
            foreach ($events as $index => $event) {
                self::assertSame($index === 0 ? 'reference_check_failed' : 'obsolete_cleanup_failed', $event['reason']);
                self::assertSame(42 + $index, (int) $event['emission_id']);
                self::assertSame($path, $event['obsolete_relative_path']);
                $context = json_decode($event['context'], true, 512, JSON_THROW_ON_ERROR);
                self::assertCount(3, $context);
                self::assertTrue($context['commit_confirmed']);
                self::assertSame($index === 0 ? null : 0, $context['reference_count']);
                self::assertSame(dirname($path) . ($index === 0 ? '/replacement-a.mp3' : '/replacement-b.mp3'), $context['replacement_relative_path']);
                $diagnostic = json_decode($event['diagnostic'], true, 512, JSON_THROW_ON_ERROR);
                self::assertCount(3, $diagnostic);
                self::assertSame(\RuntimeException::class, $diagnostic['class']);
                self::assertSame(7 + $index, $diagnostic['code']);
                self::assertNull($diagnostic['sql_state']);
            }
            self::assertSame(0, $a->getTransactionNestingLevel());
            self::assertSame(0, $c->getTransactionNestingLevel());
            $this->evidence['active_issues'] = count($issues);
            $this->evidence['events'] = count($events);
        } catch (\Throwable $error) {
            $primary = $error;
            $this->evidence['parent_failure'] = ['class' => $error::class, 'code' => $error->getCode()];
            throw $error;
        } finally {
            try {
                $this->evidence['worker_running_before_cleanup'] = $worker?->isRunning() ?? false;
                if ($worker !== null && $worker->isRunning()) { $worker->stop(1); }
                if ($worker !== null && $worker->isStarted()) { $worker->wait(); }
            } catch (\Throwable $cleanupError) {
                $this->evidence['worker_cleanup_failure'] = ['class' => $cleanupError::class, 'code' => $cleanupError->getCode()];
                if ($primary === null) { throw $cleanupError; }
            } finally {
                $this->evidence['worker_running_after_cleanup'] = $worker?->isRunning() ?? false;
                $this->evidence['worker_exit'] = $worker?->getExitCode();
                if (is_resource($channel)) { fclose($channel); }
                fclose($server);
            }
        }
    }

    private function receive($channel, string $token, ?string $event): array
    {
        $line = fgets($channel, 8192);
        self::assertNotFalse($line, 'IPC deadline expired or child connection closed.');
        $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($token, $message['token']);
        unset($message['token']);
        $this->evidence['messages'][] = $message;
        $this->evidence['b_stage'] = $message['event'] === 'error' ? ($message['stage'] ?? 'unknown') : $message['event'];
        if ($message['event'] === 'error') { $this->evidence['child_failure'] = $message; }
        if ($event !== null) {
            self::assertSame($event, $message['event'], 'Unexpected child IPC event: ' . json_encode($message, JSON_THROW_ON_ERROR));
        }
        return $message;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
            $this->evidence['parent_cleanup_completed'] = true;
        } catch (\Throwable $error) {
            $this->evidence['parent_cleanup_failure'] = ['class' => $error::class, 'code' => $error->getCode()];
            throw $error;
        } finally {
            // Optional run artifact includes failures too; never SQL text, paths or credentials.
            if (($report = getenv('MP3_CLEANUP_CONCURRENCY_EVIDENCE')) !== false && $report !== '') {
                try {
                    if (file_put_contents($report, json_encode($this->evidence, JSON_THROW_ON_ERROR)) === false) {
                        throw new \RuntimeException('Could not save concurrency evidence.');
                    }
                } catch (\Throwable $reportError) {
                    fwrite(STDERR, 'Concurrency evidence unavailable: ' . $reportError::class . PHP_EOL);
                }
            }
            $this->evidence = [];
        }
    }
}

/** Local observation only: all SQL and transaction operations execute through DBAL. */
final class Mp3CleanupCollisionConnection extends Connection
{
    public bool $useReadCommitted = false;
    public array $transactions = [];
    public ?UniqueConstraintViolationException $uniqueViolation = null;

    public function beginTransaction(): void
    {
        if ($this->useReadCommitted) {
            $this->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        try {
            parent::beginTransaction();
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
        $this->transactions[] = 'begin';
    }

    public function rollBack(): void
    {
        parent::rollBack();
        $this->transactions[] = 'rollback';
    }

    public function commit(): void
    {
        parent::commit();
        $this->transactions[] = 'commit';
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        try {
            return parent::insert($table, $data, $types);
        } catch (UniqueConstraintViolationException $error) {
            if ($table === 'mp3_cleanup_issue') { $this->uniqueViolation = $error; }
            throw $error;
        }
    }
}
