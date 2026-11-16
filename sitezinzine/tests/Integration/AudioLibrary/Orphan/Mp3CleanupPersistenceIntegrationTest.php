<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

final class Mp3CleanupPersistenceIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    public function testFirstReportCommitsOneActiveIssueAndItsEvent(): void
    {
        $path = $this->newFixturePath();
        $connection = $this->newConnection();
        $anomaly = $this->anomaly($path);
        $id = $this->writer($connection)->record('emissionsMp3', $anomaly);

        self::assertGreaterThan(0, $id);
        self::assertSame(0, $connection->getTransactionNestingLevel());
        $issue = $connection->fetchAssociative(
            'SELECT id, storage, obsolete_relative_path, status, active_marker FROM mp3_cleanup_issue WHERE id = ?', [$id],
        );
        self::assertIsArray($issue);
        self::assertSame('emissionsMp3', $issue['storage']);
        self::assertSame($path, $issue['obsolete_relative_path']);
        self::assertSame('open', $issue['status']);
        self::assertSame(1, (int) $issue['active_marker']);
        $events = $this->events($connection, $id);
        self::assertCount(1, $events);
        $this->assertEvent($events[0], $id, $anomaly);
    }

    public function testSecondReportAddsAnEventToTheSameActiveIssue(): void
    {
        $path = $this->newFixturePath();
        $connection = $this->newConnection();
        $writer = $this->writer($connection);
        $first = $this->anomaly($path);
        $second = $this->anomaly($path, 'obsolete_cleanup_failed', 0);
        $id = $writer->record('emissionsMp3', $first);
        $original = $this->events($connection, $id);
        self::assertCount(1, $original);
        self::assertSame($id, $writer->record('emissionsMp3', $second));
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', ['emissionsMp3', $path],
        ));
        self::assertSame('open', $connection->fetchOne('SELECT status FROM mp3_cleanup_issue WHERE id = ?', [$id]));
        $events = $this->events($connection, $id);
        self::assertCount(2, $events);
        self::assertSame($original[0], $events[0], 'The first historical event must remain unchanged.');
        self::assertNotSame((int) $events[0]['id'], (int) $events[1]['id']);
        $this->assertEvent($events[0], $id, $first);
        $this->assertEvent($events[1], $id, $second);
    }

    public function testRejectedEventInsertRollsBackTheNewIssueAsWell(): void
    {
        $path = $this->newFixturePath();
        $connection = $this->newConnection();
        // Short enough for the column, but rejected specifically by the existing reason CHECK.
        // The real adapter creates the issue before attempting this event INSERT.
        try {
            $this->writer($connection)->record('emissionsMp3', $this->anomaly($path, 'invalid_test_reason'));
            self::fail('The event INSERT must fail without returning a successful issue identifier.');
        } catch (DriverException $error) {
            self::assertStringContainsString('chk_mp3_cleanup_event_reason', $error->getMessage());
            self::assertSame(3819, (int) $error->getPrevious()->getCode());
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', ['emissionsMp3', $path],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM mp3_cleanup_event WHERE obsolete_relative_path = ?', [$path],
        ));
    }

    private function writer(Connection $connection): Mp3CleanupIssueWriter
    {
        $adapter = new Mp3CleanupIssueDbalAdapter($connection);
        $grouper = new Mp3CleanupIssueGrouper(
            $adapter->findActiveIssues(...), $adapter->createIssue(...), $adapter->appendEvent(...),
        );
        return new Mp3CleanupIssueWriter($connection, $grouper);
    }

    private function anomaly(string $path, string $reason = 'reference_check_failed', ?int $references = null): Mp3CleanupAnomaly
    {
        return new Mp3CleanupAnomaly($reason, 42, $path, [
            'replacement_relative_path' => dirname($path) . '/replacement.mp3',
            'commit_confirmed' => true, 'reference_count' => $references,
        ], ['class' => \RuntimeException::class, 'code' => 7, 'sql_state' => null]);
    }

    private function events(Connection $connection, int $issueId): array
    {
        return $connection->fetchAllAssociative(
            'SELECT id, issue_id, reason, emission_id, obsolete_relative_path, context, diagnostic
             FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id', [$issueId],
        );
    }

    private function assertEvent(array $event, int $issueId, Mp3CleanupAnomaly $anomaly): void
    {
        self::assertSame($issueId, (int) $event['issue_id']);
        self::assertSame($anomaly->reason, $event['reason']);
        self::assertSame($anomaly->emissionId, (int) $event['emission_id']);
        self::assertSame($anomaly->obsoleteRelativePath, $event['obsolete_relative_path']);
        // MySQL JSON may reorder object keys; values and null-versus-zero must remain exact.
        $context = json_decode($event['context'], true, 512, JSON_THROW_ON_ERROR);
        $diagnostic = json_decode($event['diagnostic'], true, 512, JSON_THROW_ON_ERROR);
        ksort($context);
        ksort($diagnostic);
        $expectedContext = $anomaly->context;
        $expectedDiagnostic = $anomaly->diagnostic;
        ksort($expectedContext);
        ksort($expectedDiagnostic);
        self::assertSame($expectedContext, $context);
        self::assertSame($expectedDiagnostic, $diagnostic);
    }
}
