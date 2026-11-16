<?php

declare(strict_types=1);

// One test-owned PHP process, autoload only: no kernel, ORM or raw DATABASE_URL.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueResolutionService;
use App\Service\AudioLibrary\Orphan\Mp3CleanupResolutionDecision;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Test-only interception after a successful, unchanged locking read. */
final class Mp3CleanupResolutionBarrierConnection extends Connection
{
    private ?Closure $afterIssueLock = null;
    private ?int $expectedIssueId = null;

    public function armIssueLock(int $issueId, Closure $callback): void
    {
        $this->expectedIssueId = $issueId;
        $this->afterIssueLock = $callback;
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $row = parent::fetchAssociative($query, $params, $types);
        if ($this->afterIssueLock !== null
            && $query === 'SELECT id, status, resolved_at FROM mp3_cleanup_issue WHERE id = ? FOR UPDATE'
            && $params === [$this->expectedIssueId]) {
            if ($row === false || (int) $row['id'] !== $this->expectedIssueId || $this->getTransactionNestingLevel() !== 1) {
                throw new LogicException('Expected a real locked issue in one owned resolution transaction.');
            }
            $callback = $this->afterIssueLock;
            $this->afterIssueLock = null;
            $callback();
        }
        return $row;
    }
}

$connection = null;
$observer = null;
$socket = null;
$exit = 0;
$send = null;
$stage = 'initializing';
try {
    $config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $params = $config['params'];
    if (($params['dbname'] ?? null) !== 'symfony_test' || ($params['driver'] ?? null) !== 'pdo_mysql'
        || isset($params['pdo']) || isset($params['primary']) || isset($params['replica'])
        || !empty($params['persistent']) || !empty($params['driverOptions'][PDO::ATTR_PERSISTENT])) {
        throw new LogicException('Refusing unsafe concurrency worker connection parameters.');
    }
    if (!str_starts_with($config['address'], 'tcp://127.0.0.1:') || !str_starts_with($config['path'], '__test_mp3_cleanup__/')) {
        throw new LogicException('Expected local IPC and a synthetic fixture path.');
    }
    $socket = stream_socket_client($config['address'], $errno, $error, 10);
    if ($socket === false) { throw new RuntimeException('Worker IPC connection failed.'); }
    stream_set_timeout($socket, 15);
    $send = static function (string $event, array $data = []) use ($socket, $config, &$stage): void {
        if ($event !== 'error' && $event !== 'closed') { $stage = $event; }
        $line = json_encode(['token' => $config['token'], 'event' => $event] + $data, JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($socket, $line) !== strlen($line)) { throw new RuntimeException('Incomplete worker IPC write.'); }
        fflush($socket);
    };
    $resolutionFirst = ($config['operation'] ?? 'report') === 'resolve_first';
    if ($resolutionFirst) { $params['wrapperClass'] = Mp3CleanupResolutionBarrierConnection::class; }
    $connection = DriverManager::getConnection($params);
    if ($connection->fetchOne('SELECT DATABASE()') !== 'symfony_test') {
        throw new LogicException('Worker connected outside symfony_test.');
    }
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 20');
    $session = (int) $connection->fetchOne('SELECT CONNECTION_ID()');
    $thread = (int) $connection->fetchOne('SELECT PS_CURRENT_THREAD_ID()');
    if ($thread <= 0) { throw new RuntimeException('Performance Schema thread identity unavailable.'); }
    $send('ready', ['session' => $session, 'thread' => $thread, 'database' => 'symfony_test', 'level' => $connection->getTransactionNestingLevel()]);
    $line = fgets($socket, 4096);
    if ($line === false) { throw new RuntimeException('Worker barrier expired or closed.'); }
    $command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (($command['token'] ?? null) !== $config['token'] || ($command['event'] ?? null) !== 'run') {
        throw new RuntimeException('Invalid worker barrier command.');
    }
    if ($resolutionFirst) {
        $observerParams = $params;
        unset($observerParams['wrapperClass']);
        $observer = DriverManager::getConnection($observerParams);
        if ($observer->fetchOne('SELECT DATABASE()') !== 'symfony_test') {
            throw new LogicException('Observer connected outside symfony_test.');
        }
        $sessionC = (int) $observer->fetchOne('SELECT CONNECTION_ID()');
        if (count(array_unique([$session, $sessionC, $config['session_b']])) !== 3) {
            throw new LogicException('Expected three distinct MySQL sessions.');
        }
        $connection->armIssueLock($config['issue_id'], static function () use ($connection, $observer, $send, $thread, $sessionC, $config, $socket): void {
            $locks = $observer->fetchAllAssociative(
                'SELECT /*+ MAX_EXECUTION_TIME(1000) */ ENGINE_TRANSACTION_ID FROM performance_schema.data_locks
                 WHERE ENGINE = ? AND THREAD_ID = ? AND OBJECT_SCHEMA = ? AND OBJECT_NAME = ?
                   AND LOCK_TYPE = ? AND LOCK_MODE = ? AND LOCK_STATUS = ?',
                ['INNODB', $thread, 'symfony_test', 'mp3_cleanup_issue', 'TABLE', 'IX', 'GRANTED'],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING,
                    ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
            if (count($locks) !== 1) { throw new LogicException('Cannot uniquely identify resolution transaction A.'); }
            $transactionA = (string) $locks[0]['ENGINE_TRANSACTION_ID'];
            $send('resolution_locked', ['transaction_a' => $transactionA, 'session_c' => $sessionC,
                'level' => $connection->getTransactionNestingLevel()]);
            $line = fgets($socket, 4096);
            if ($line === false) { throw new RuntimeException('Observation barrier expired.'); }
            $command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (($command['token'] ?? null) !== $config['token'] || ($command['event'] ?? null) !== 'observe') {
                throw new RuntimeException('Invalid observation barrier.');
            }
            $deadline = microtime(true) + 10;
            $polls = 0;
            do {
                ++$polls;
                $observed = $observer->fetchAssociative(
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
                    ['INNODB', $config['thread_b'], $transactionA, $config['thread_b'],
                        'symfony_test', 'mp3_cleanup_issue', 'TABLE', 'IX', 'GRANTED',
                        'symfony_test', 'mp3_cleanup_issue', 'WAITING',
                        'symfony_test', 'mp3_cleanup_issue', 'GRANTED'],
                    [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER,
                        ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                        ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                        ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
                );
                if ($observed !== false) {
                    $send('lock_observed', ['transaction_a' => $transactionA, 'strict_wait' => $observed, 'polls' => $polls]);
                    return;
                }
                // IPC only paces observation and detects parent failure; it is not lock evidence.
                $read = [$socket]; $write = $except = [];
                $available = stream_select($read, $write, $except, 0, 10000);
                if ($available === false || $available > 0) { throw new RuntimeException('Parent observation channel interrupted.'); }
            } while (microtime(true) < $deadline);
            $sample = $observer->fetchAllAssociative(
                'SELECT /*+ MAX_EXECUTION_TIME(1000) */ REQUESTING_THREAD_ID, BLOCKING_THREAD_ID,
                 REQUESTING_ENGINE_TRANSACTION_ID, BLOCKING_ENGINE_TRANSACTION_ID
                 FROM performance_schema.data_lock_waits LIMIT 21',
            );
            $send('observation_failed', ['transaction_a' => $transactionA, 'polls' => $polls, 'wait_sample' => $sample]);
            throw new RuntimeException('No B-to-resolution-A lock wait observed before deadline.');
        });
        $result = (new Mp3CleanupIssueResolutionService($connection, new Mp3CleanupResolutionDecision()))->resolve(
            $config['issue_id'], $config['resolution_token'], new DateTimeImmutable('2026-11-15 12:00:00.123456', new DateTimeZone('UTC')),
        );
        $send('done', ['issue_id' => $config['issue_id'], 'result' => $result,
            'resolution_token' => $config['resolution_token'], 'level' => $connection->getTransactionNestingLevel(),
            'session' => (int) $connection->fetchOne('SELECT CONNECTION_ID()')]);
    } elseif (($config['operation'] ?? 'report') === 'resolve') {
        $send('attempting');
        $send('before_resolution', ['level' => $connection->getTransactionNestingLevel()]);
        $result = (new Mp3CleanupIssueResolutionService($connection, new Mp3CleanupResolutionDecision()))->resolve(
            $config['issue_id'], $config['resolution_token'], new DateTimeImmutable('2026-11-15 12:00:00.123456', new DateTimeZone('UTC')),
        );
        $send('done', ['issue_id' => $config['issue_id'], 'result' => $result,
            'resolution_token' => $config['resolution_token'], 'level' => $connection->getTransactionNestingLevel(),
            'session' => (int) $connection->fetchOne('SELECT CONNECTION_ID()')]);
    } else {
        $adapter = new Mp3CleanupIssueDbalAdapter($connection);
        $writer = new Mp3CleanupIssueWriter($connection, new Mp3CleanupIssueGrouper(
            static function (string $storage, string $path) use ($adapter, $connection, $send): array {
                $level = $connection->getTransactionNestingLevel();
                if ($level !== 1) { throw new LogicException('Expected one owned worker transaction before active lookup.'); }
                $send('before_find', ['level' => $level]);
                $rows = $adapter->findActiveIssues($storage, $path);
                $send('after_find', ['level' => $connection->getTransactionNestingLevel()]);
                return $rows;
            },
            $adapter->createIssue(...), $adapter->appendEvent(...),
        ));
        $anomaly = new Mp3CleanupAnomaly('obsolete_cleanup_failed', 43, $config['path'], [
            'replacement_relative_path' => dirname($config['path']) . '/replacement-b.mp3',
            'commit_confirmed' => true, 'reference_count' => 0,
        ], ['class' => RuntimeException::class, 'code' => 8, 'sql_state' => null]);
        $send('attempting');
        $id = $writer->record('emissionsMp3', $anomaly);
        $send('done', ['issue_id' => $id, 'level' => $connection->getTransactionNestingLevel(),
            'session' => (int) $connection->fetchOne('SELECT CONNECTION_ID()')]);
    }
} catch (Throwable $failure) {
    $exit = 1;
    if ($send !== null) {
        try {
            $send('error', ['stage' => $stage, 'class' => $failure::class, 'code' => $failure->getCode(),
                'driver_code' => $failure instanceof \Doctrine\DBAL\Exception\DriverException ? $failure->getPrevious()?->getCode() : null,
                'sql_state' => $failure instanceof \Doctrine\DBAL\Exception\DriverException ? $failure->getSQLState() : null,
                'level' => $connection?->getTransactionNestingLevel()]);
        } catch (Throwable) {}
    }
} finally {
    if ($connection !== null) {
        try {
            while ($connection->isConnected() && ($level = $connection->getTransactionNestingLevel()) > 0) {
                $connection->rollBack();
                if ($connection->getTransactionNestingLevel() >= $level) { throw new RuntimeException('Worker rollback made no progress.'); }
            }
        } catch (Throwable) { $exit = 1; }
        finally { $connection->close(); }
    }
    if ($observer !== null) {
        try { $observer->close(); } catch (Throwable) { $exit = 1; }
    }
    if ($send !== null) {
        try { $send('closed', ['exit' => $exit, 'connected' => $connection?->isConnected() ?? false,
            'level' => $connection?->getTransactionNestingLevel() ?? 0,
            'observer_connected' => $observer?->isConnected() ?? false]); } catch (Throwable) {}
    }
    if (is_resource($socket)) { fclose($socket); }
}
exit($exit);
