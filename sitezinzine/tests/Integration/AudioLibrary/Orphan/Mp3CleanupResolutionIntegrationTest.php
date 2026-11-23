<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Tests\Support\Mp3CleanupClosureFixture;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;

final class Mp3CleanupResolutionIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    public function testValidTokenResolvesIssueAndPreservesItsHistory(): void
    {
        $path = $this->newFixturePath();
        $connection = $this->newConnection();
        $this->requireClosureSchema($connection);
        $id = $this->writer($connection)->record('emissionsMp3', $this->anomaly($path));
        $events = $this->events($connection, $id);
        self::assertCount(1, $events);
        $token = $this->token($id, $events);

        self::assertSame('allowed', Mp3CleanupClosureFixture::resolve($connection, $path, $id, $token, $this->resolvedAt()));
        $issue = $this->issue($connection, $id);
        self::assertSame('resolved', $issue['status']);
        $observer = $this->newConnection();
        $trace = $observer->fetchAssociative('SELECT actor_id, proof_type, request_id, reason FROM mp3_cleanup_issue_closure WHERE issue_id = ?', [$id]);
        self::assertSame([19, 'currently_absent', null, 'Synthetic closure'],
            [(int) $trace['actor_id'], $trace['proof_type'], $trace['request_id'], $trace['reason']]);
        self::assertNull($issue['active_marker']);
        self::assertSame('2026-11-15 07:04:56.123456', $issue['resolved_at']);
        self::assertSame($events, $this->events($connection, $id));
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testStaleTokenRefusesResolutionWithoutChangingIssueOrEvents(): void
    {
        $path = $this->newFixturePath();
        $connection = $this->newConnection();
        $this->requireClosureSchema($connection);
        $writer = $this->writer($connection);
        $id = $writer->record('emissionsMp3', $this->anomaly($path));
        $initialEvents = $this->events($connection, $id);
        self::assertCount(1, $initialEvents);
        $token = $this->token($id, $initialEvents);
        self::assertSame($id, $writer->record('emissionsMp3', $this->anomaly($path)));
        $beforeIssue = $this->issue($connection, $id);
        $beforeEvents = $this->events($connection, $id);
        self::assertCount(2, $beforeEvents);
        self::assertGreaterThan($token['last_event_id'], (int) $beforeEvents[1]['id']);

        self::assertSame('stale_view', Mp3CleanupClosureFixture::resolve($connection, $path, $id, $token, $this->resolvedAt()));
        self::assertSame($beforeIssue, $this->issue($connection, $id));
        self::assertSame('open', $beforeIssue['status']);
        self::assertSame(1, (int) $beforeIssue['active_marker']);
        self::assertNull($beforeIssue['resolved_at']);
        self::assertSame($beforeEvents, $this->events($connection, $id));
        self::assertSame($initialEvents[0], $beforeEvents[0]);
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testReportAfterResolutionCreatesANewActiveIssueWithoutAlteringHistory(): void
    {
        $path = $this->newFixturePath();
        $connection = $this->newConnection();
        $this->requireClosureSchema($connection);
        $writer = $this->writer($connection);
        $oldId = $writer->record('emissionsMp3', $this->anomaly($path));
        $history = $this->events($connection, $oldId);
        self::assertCount(1, $history);
        self::assertSame('allowed', Mp3CleanupClosureFixture::resolve($connection, $path, $oldId, $this->token($oldId, $history), $this->resolvedAt()));
        $resolved = $this->issue($connection, $oldId);
        self::assertSame('resolved', $resolved['status']);
        self::assertNull($resolved['active_marker']);

        $newId = $writer->record('emissionsMp3', $this->anomaly($path));
        self::assertNotSame($oldId, $newId);
        self::assertSame($resolved, $this->issue($connection, $oldId));
        self::assertSame($history, $this->events($connection, $oldId));
        $active = $this->issue($connection, $newId);
        self::assertSame('open', $active['status']);
        self::assertSame(1, (int) $active['active_marker']);
        self::assertNull($active['resolved_at']);
        self::assertCount(1, $this->events($connection, $newId));
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', ['emissionsMp3', $path],
        ));
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testTraceRollsBackWhenFinalStateCannotBeUpdated(): void
    {
        $db = $this->newConnection(Mp3CleanupClosureFailingUpdateConnection::class);
        $this->requireClosureSchema($db);
        $path = $this->newFixturePath();
        $id = $this->writer($db)->record('emissionsMp3', $this->anomaly($path));
        $observer = $this->newConnection();
        $events = $this->events($db, $id);

        self::assertSame('indeterminate', Mp3CleanupClosureFixture::resolve(
            $db, $path, $id, $this->token($id, $events), $this->resolvedAt(),
        ));
        self::assertTrue($db->reachedUpdate);
        self::assertSame('open', $this->issue($observer, $id)['status']);
        self::assertSame(0, (int) $observer->fetchOne('SELECT COUNT(*) FROM mp3_cleanup_issue_closure WHERE issue_id = ?', [$id]));
        self::assertSame($events, $this->events($observer, $id));
    }

    private function writer(Connection $connection): Mp3CleanupIssueWriter
    {
        $adapter = new Mp3CleanupIssueDbalAdapter($connection);
        return new Mp3CleanupIssueWriter($connection, new Mp3CleanupIssueGrouper(
            $adapter->findActiveIssues(...), $adapter->createIssue(...), $adapter->appendEvent(...),
        ));
    }

    private function anomaly(string $path): Mp3CleanupAnomaly
    {
        return new Mp3CleanupAnomaly('reference_check_failed', 42, $path, [
            'replacement_relative_path' => dirname($path) . '/replacement.mp3',
            'commit_confirmed' => true, 'reference_count' => null,
        ], ['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null]);
    }

    private function issue(Connection $connection, int $id): array
    {
        $issue = $connection->fetchAssociative(
            'SELECT id, storage, obsolete_relative_path, status, active_marker, created_at, resolved_at FROM mp3_cleanup_issue WHERE id = ?', [$id],
        );
        self::assertIsArray($issue);
        return $issue;
    }

    private function events(Connection $connection, int $id): array
    {
        return $connection->fetchAllAssociative(
            'SELECT id, issue_id, occurred_at, reason, emission_id, obsolete_relative_path, context, diagnostic
             FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id', [$id],
        );
    }

    private function token(int $id, array $events): array
    {
        return ['issue_id' => $id, 'last_event_id' => (int) $events[count($events) - 1]['id']];
    }

    private function resolvedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-15 12:34:56.123456+05:30');
    }
}

final class Mp3CleanupClosureFailingUpdateConnection extends Connection
{
    public bool $reachedUpdate = false;

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_starts_with($sql, "UPDATE mp3_cleanup_issue SET status = 'resolved'")) {
            $this->reachedUpdate = (int) $this->fetchOne(
                'SELECT COUNT(*) FROM mp3_cleanup_issue_closure WHERE issue_id = ?', [$params[1]],
            ) === 1;
            throw new \RuntimeException('Synthetic failure after inserting the administrative trace.');
        }

        return parent::executeStatement($sql, $params, $types);
    }
}
