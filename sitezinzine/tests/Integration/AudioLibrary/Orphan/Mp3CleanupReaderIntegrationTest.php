<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueReader;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class Mp3CleanupReaderIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    public function testInitialDetailUsesOneRealRepeatableReadSnapshot(): void
    {
        $path = $this->newFixturePath();
        $writer = $this->newConnection();
        [$id, $ids] = $this->fixture($writer, $path, 2);
        $persistedIssue = $writer->fetchAssociative(
            'SELECT id, status, storage, obsolete_relative_path, created_at, resolved_at FROM mp3_cleanup_issue WHERE id = ?', [$id],
        );
        $persistedIssue['id'] = (int) $persistedIssue['id'];
        $inserted = null;
        $calls = 0;
        // A local observer extends real DBAL, without mocking SQL, transactions or returned rows.
        // Its own physical connection is always closed below, including assertion failures.
        $params = $writer->getParams();
        $params['wrapperClass'] = Mp3CleanupReaderObservedConnection::class;
        /** @var Mp3CleanupReaderObservedConnection $observed */
        $observed = DriverManager::getConnection($params);
        $observed->afterIssueRead = function () use ($writer, $id, $path, &$inserted, &$calls): void {
            ++$calls;
            $inserted = $this->append($writer, $id, $path);
        };
        try {
            self::assertSame('symfony_test', $observed->getParams()['dbname']);
            self::assertSame('symfony_test', $observed->fetchOne('SELECT DATABASE()'));
            self::assertNotSame($this->connectionId($writer), (int) $observed->fetchOne('SELECT CONNECTION_ID()'));
            // A READ COMMITTED session makes the test sensitive to the reader's one-shot RR preparation.
            $observed->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $reader = new Mp3CleanupIssueReader($observed);
            $detail = $reader->readInitialDetail($id);
            self::assertSame(1, $calls);
            self::assertGreaterThan(max($ids), $inserted);
            self::assertSame($persistedIssue, $detail['issue']);
            self::assertSame(array_reverse($ids), array_column($detail['events'], 'id'));
            self::assertSame(['issue_id' => $id, 'last_event_id' => max($ids)], $detail['token']);
            self::assertSame(0, $observed->getTransactionNestingLevel());
            self::assertSame('READ-COMMITTED', $observed->fetchOne('SELECT @@SESSION.transaction_isolation'));
            self::assertSame(3, (int) $writer->fetchOne('SELECT COUNT(*) FROM mp3_cleanup_event WHERE issue_id = ?', [$id]));
            $fresh = $reader->readInitialDetail($id);
            self::assertSame($inserted, $fresh['token']['last_event_id']);
            self::assertSame(array_merge([$inserted], array_reverse($ids)), array_column($fresh['events'], 'id'));
        } finally {
            try {
                if ($observed->getTransactionNestingLevel() > 0) { $observed->rollBack(); }
            } finally {
                $observed->close();
            }
        }
    }

    public function testPaginationKeepsItsHighWaterMarkWhileNewConsultationSeesNewEvents(): void
    {
        $path = $this->newFixturePath();
        $writer = $this->newConnection();
        $connection = $this->newConnection();
        [$id, $ids] = $this->fixture($writer, $path, 21);
        $reader = new Mp3CleanupIssueReader($connection);
        $first = $reader->readInitialDetail($id);
        self::assertCount(20, $first['events']);
        self::assertSame(array_reverse(array_slice($ids, 1)), array_column($first['events'], 'id'));
        self::assertSame(['issue_id' => $id, 'last_event_id' => $ids[20]], $first['token']);
        self::assertSame(['has_more' => true, 'next_before_id' => $ids[1]], $first['pagination']);

        $newId = $this->append($writer, $id, $path);
        self::assertGreaterThan($first['token']['last_event_id'], $newId);
        $next = $reader->readNextEventsPage($id, $first['token']['last_event_id'], $first['pagination']['next_before_id']);
        self::assertSame([$ids[0]], array_column($next['events'], 'id'));
        self::assertSame($first['token'], $next['token']);
        self::assertSame(['has_more' => false, 'next_before_id' => null], $next['pagination']);
        $allViewed = array_merge(array_column($first['events'], 'id'), array_column($next['events'], 'id'));
        self::assertSame(array_reverse($ids), $allViewed);
        self::assertNotContains($newId, $allViewed);
        $fresh = $reader->readInitialDetail($id);
        self::assertSame($newId, $fresh['token']['last_event_id']);
        self::assertSame($newId, $fresh['events'][0]['id']);
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, $writer->getTransactionNestingLevel());
    }

    public function testRealJsonAndScalarProjectionsPreserveAllowedValuesAndFilterExtraKeys(): void
    {
        $path = $this->newFixturePath();
        $connection = $this->newConnection();
        [$id, $ids] = $this->fixture($connection, $path, 2);
        // Extra keys are synthetic corruption of test rows only, to exercise the reader boundary.
        $connection->executeStatement(
            "UPDATE mp3_cleanup_event SET context = JSON_SET(context, '$.secret', 'hidden-context'),
             diagnostic = JSON_SET(diagnostic, '$.message', 'hidden-message', '$.trace', 'hidden-trace') WHERE issue_id = ?", [$id],
        );
        $rows = $connection->fetchAllAssociative(
            'SELECT id, occurred_at, reason, emission_id, obsolete_relative_path, context, diagnostic FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id DESC', [$id],
        );
        $detail = (new Mp3CleanupIssueReader($connection))->readInitialDetail($id);
        self::assertSame(array_reverse($ids), array_column($detail['events'], 'id'));
        foreach ($detail['events'] as $index => $event) {
            $row = $rows[$index];
            self::assertSame(['id', 'occurred_at', 'reason', 'emission_id', 'obsolete_relative_path', 'context', 'diagnostic'], array_keys($event));
            self::assertSame((int) $row['id'], $event['id']);
            self::assertSame((int) $row['emission_id'], $event['emission_id']);
            self::assertSame($row['occurred_at'], $event['occurred_at']);
            self::assertIsString($event['occurred_at']);
            self::assertSame($row['reason'], $event['reason']);
            self::assertSame($path, $event['obsolete_relative_path']);
            self::assertSame([
                'replacement_relative_path' => dirname($path) . '/replacement.mp3',
                'commit_confirmed' => true, 'reference_count' => $index === 0 ? 0 : null,
            ], $event['context']);
            self::assertSame(['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null], $event['diagnostic']);
        }
        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        foreach (['hidden-context', 'hidden-message', 'hidden-trace'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    private function fixture(Connection $connection, string $path, int $count): array
    {
        $adapter = new Mp3CleanupIssueDbalAdapter($connection);
        $connection->beginTransaction();
        try {
            $id = $adapter->createIssue(['storage' => 'emissionsMp3', 'relative_path' => $path, 'status' => 'open']);
            $ids = [];
            for ($i = 0; $i < $count; ++$i) {
                $adapter->appendEvent($id, $this->anomaly($path, $i % 2 === 0 ? null : 0));
                $ids[] = (int) $connection->lastInsertId();
            }
            $connection->commit();
            return [$id, $ids];
        } catch (\Throwable $error) {
            if ($connection->getTransactionNestingLevel() > 0) { $connection->rollBack(); }
            throw $error;
        }
    }

    private function append(Connection $connection, int $id, string $path): int
    {
        $adapter = new Mp3CleanupIssueDbalAdapter($connection);
        $connection->beginTransaction();
        try {
            $active = $adapter->findActiveIssues('emissionsMp3', $path);
            self::assertCount(1, $active);
            self::assertSame($id, (int) $active[0]['id']);
            $adapter->appendEvent($id, $this->anomaly($path, 0));
            $eventId = (int) $connection->lastInsertId();
            $connection->commit();
            return $eventId;
        } catch (\Throwable $error) {
            if ($connection->getTransactionNestingLevel() > 0) { $connection->rollBack(); }
            throw $error;
        }
    }

    private function anomaly(string $path, ?int $references): Mp3CleanupAnomaly
    {
        return new Mp3CleanupAnomaly($references === null ? 'reference_check_failed' : 'obsolete_cleanup_failed', 42, $path, [
            'replacement_relative_path' => dirname($path) . '/replacement.mp3',
            'commit_confirmed' => true, 'reference_count' => $references,
        ], ['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null]);
    }
}

/** Test-local scheduling hook; all SQL and transaction handling remain real DBAL operations. */
final class Mp3CleanupReaderObservedConnection extends Connection
{
    public ?\Closure $afterIssueRead = null;

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $row = parent::fetchAssociative($query, $params, $types);
        if (str_contains($query, 'FROM mp3_cleanup_issue') && $this->afterIssueRead !== null) {
            $hook = $this->afterIssueRead;
            $this->afterIssueRead = null;
            ($hook)();
        }
        return $row;
    }
}
