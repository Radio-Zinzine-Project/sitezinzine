<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;

final class Mp3CleanupTransactionIsolationIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    public function testCommittedAnomalySurvivesRollbackOfAnotherSession(): void
    {
        $path = $this->newFixturePath();
        $a = $this->newConnection();
        $b = $this->newConnection();
        $c = $this->newConnection();
        $sessions = ['A' => $this->connectionId($a), 'B' => $this->connectionId($b), 'C' => $this->connectionId($c)];
        self::assertCount(3, array_unique($sessions), 'Distinct MySQL sessions: ' . json_encode($sessions, JSON_THROW_ON_ERROR));

        $a->beginTransaction();
        self::assertSame(1, $a->getTransactionNestingLevel());
        self::assertSame(0, $b->getTransactionNestingLevel());
        $adapter = new Mp3CleanupIssueDbalAdapter($b);
        $writer = new Mp3CleanupIssueWriter($b, new Mp3CleanupIssueGrouper(
            $adapter->findActiveIssues(...), $adapter->createIssue(...), $adapter->appendEvent(...),
        ));
        $anomaly = new Mp3CleanupAnomaly('reference_check_failed', 42, $path, [
            'replacement_relative_path' => dirname($path) . '/replacement.mp3',
            'commit_confirmed' => true, 'reference_count' => null,
        ], ['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null]);

        $id = $writer->record('emissionsMp3', $anomaly);
        self::assertGreaterThan(0, $id);
        self::assertSame(0, $b->getTransactionNestingLevel());
        self::assertSame(1, $a->getTransactionNestingLevel(), 'The writer must not commit the business session.');
        $a->rollBack();

        $issues = $c->fetchAllAssociative(
            'SELECT id, status, active_marker FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?',
            ['emissionsMp3', $path],
        );
        self::assertCount(1, $issues);
        self::assertSame($id, (int) $issues[0]['id']);
        self::assertSame('open', $issues[0]['status']);
        self::assertSame(1, (int) $issues[0]['active_marker']);
        $events = $c->fetchAllAssociative(
            'SELECT issue_id, reason, emission_id, obsolete_relative_path, context FROM mp3_cleanup_event WHERE issue_id = ?', [$id],
        );
        self::assertCount(1, $events);
        self::assertSame($id, (int) $events[0]['issue_id']);
        self::assertSame($anomaly->reason, $events[0]['reason']);
        self::assertSame(42, (int) $events[0]['emission_id']);
        self::assertSame($path, $events[0]['obsolete_relative_path']);
        $context = json_decode($events[0]['context'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($context['commit_confirmed']);
        self::assertNull($context['reference_count']);
        foreach (['A' => $a, 'B' => $b, 'C' => $c] as $role => $connection) {
            self::assertSame($sessions[$role], $this->connectionId($connection), 'No session was reconnected during the scenario.');
            self::assertSame(0, $connection->getTransactionNestingLevel());
            self::assertFalse($connection->isTransactionActive());
        }
    }
}
