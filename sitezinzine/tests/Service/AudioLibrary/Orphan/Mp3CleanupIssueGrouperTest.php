<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use PHPUnit\Framework\TestCase;

/** In-memory persistence doubles describe grouping, not database atomicity or concurrency. */
final class Mp3CleanupIssueGrouperTest extends TestCase
{
    private array $issues = [];
    private array $events = [];

    public function testFirstAnomalyCreatesOneOpenIssueAndOneEvent(): void
    {
        $grouper = $this->grouper();
        $anomaly = $this->anomaly();

        $id = $grouper->record('sources', $anomaly);

        self::assertCount(1, $this->issues);
        self::assertSame([
            'id' => $id, 'storage' => 'sources', 'relative_path' => 'ABC/2026/old.mp3', 'status' => 'open',
        ], $this->issues[$id]);
        self::assertSame([['issue_id' => $id, 'anomaly' => $anomaly]], $this->events);
        self::assertSame('reference_check_failed', $this->events[0]['anomaly']->reason);
        self::assertNull($this->events[0]['anomaly']->context['reference_count']);
    }

    public function testSecondAnomalyReusesOpenIssueAndAddsAnEvent(): void
    {
        $grouper = $this->grouper();
        $first = $this->anomaly();
        $second = $this->anomaly(emissionId: 43, reason: 'obsolete_cleanup_failed');
        $id = $grouper->record('sources', $first);
        $before = $this->issues[$id];

        self::assertSame($id, $grouper->record('sources', $second));

        self::assertSame([$id => $before], $this->issues);
        self::assertSame([
            ['issue_id' => $id, 'anomaly' => $first],
            ['issue_id' => $id, 'anomaly' => $second],
        ], $this->events);
        self::assertSame(0, $this->events[1]['anomaly']->context['reference_count']);
    }

    public function testInvestigatingIssueIsReusedWithoutChangingItsStatus(): void
    {
        $grouper = $this->grouper();
        $this->issues[10] = $this->issue(10, 'investigating');
        $before = $this->issues;
        $anomaly = $this->anomaly();

        self::assertSame(10, $grouper->record('sources', $anomaly));

        self::assertSame($before, $this->issues);
        self::assertSame([['issue_id' => 10, 'anomaly' => $anomaly]], $this->events);
    }

    public function testNewAnomalyAfterResolutionCreatesANewIssueWithoutReopeningTheOldOne(): void
    {
        $grouper = $this->grouper();
        $this->issues[10] = $this->issue(10, 'resolved');
        $resolved = $this->issues[10];
        $oldEvent = ['issue_id' => 10, 'anomaly' => $this->anomaly()];
        $this->events[] = $oldEvent;
        $anomaly = $this->anomaly(reason: 'obsolete_cleanup_failed');

        $id = $grouper->record('sources', $anomaly);

        self::assertNotSame(10, $id);
        self::assertCount(2, $this->issues);
        self::assertSame($resolved, $this->issues[10]);
        self::assertSame('open', $this->issues[$id]['status']);
        self::assertSame([$oldEvent, ['issue_id' => $id, 'anomaly' => $anomaly]], $this->events);
    }

    public function testSameRelativePathInDifferentStoragesCreatesDistinctIssues(): void
    {
        $grouper = $this->grouper();
        $anomaly = $this->anomaly();
        $firstId = $grouper->record('storage-a', $anomaly);
        $secondId = $grouper->record('storage-b', $anomaly);

        self::assertNotSame($firstId, $secondId);
        self::assertCount(2, $this->issues);
        self::assertSame('storage-a', $this->issues[$firstId]['storage']);
        self::assertSame('storage-b', $this->issues[$secondId]['storage']);
        self::assertSame([$firstId, $secondId], array_column($this->events, 'issue_id'));
    }

    public function testSameBasenameInDifferentRelativePathsCreatesDistinctIssues(): void
    {
        $grouper = $this->grouper();
        $firstId = $grouper->record('sources', $this->anomaly());
        $secondId = $grouper->record('sources', $this->anomaly(path: 'DEF/2026/old.mp3'));

        self::assertNotSame($firstId, $secondId);
        self::assertCount(2, $this->issues);
        self::assertSame('ABC/2026/old.mp3', $this->issues[$firstId]['relative_path']);
        self::assertSame('DEF/2026/old.mp3', $this->issues[$secondId]['relative_path']);
        self::assertSame([$firstId, $secondId], array_column($this->events, 'issue_id'));
    }

    public function testResolvedHistoryAndActiveIssueEventsArePreservedWhenAppending(): void
    {
        $grouper = $this->grouper();
        $this->issues[10] = $this->issue(10, 'resolved');
        $this->issues[11] = $this->issue(11, 'open');
        $before = $this->issues;
        $history = [
            ['issue_id' => 10, 'anomaly' => $this->anomaly()],
            ['issue_id' => 11, 'anomaly' => $this->anomaly(reason: 'obsolete_cleanup_failed')],
        ];
        $this->events = $history;
        $anomaly = $this->anomaly();

        self::assertSame(11, $grouper->record('sources', $anomaly));

        self::assertSame($before, $this->issues);
        self::assertSame([...$history, ['issue_id' => 11, 'anomaly' => $anomaly]], $this->events);
    }

    public function testMultipleActiveIssuesAreRejectedBeforeAnyWrite(): void
    {
        $grouper = $this->grouper();
        foreach ([['open', 'open'], ['open', 'investigating'], ['investigating', 'investigating']] as [$first, $second]) {
            $this->issues = [10 => $this->issue(10, $first), 11 => $this->issue(11, $second)];
            $this->events = [['issue_id' => 10, 'anomaly' => $this->anomaly()]];
            $issuesBefore = $this->issues;
            $eventsBefore = $this->events;

            try {
                $grouper->record('sources', $this->anomaly());
                self::fail('Multiple active issues must be reported explicitly, never selected arbitrarily.');
            } catch (\DomainException $error) {
                self::assertNotSame('', $error->getMessage());
            }

            self::assertSame($issuesBefore, $this->issues);
            self::assertSame($eventsBefore, $this->events);
        }
    }

    private function anomaly(string $path = 'ABC/2026/old.mp3', int $emissionId = 42, string $reason = 'reference_check_failed'): Mp3CleanupAnomaly
    {
        return new Mp3CleanupAnomaly($reason, $emissionId, $path, [
            'replacement_relative_path' => 'ABC/2026/new.mp3',
            'commit_confirmed' => true,
            'reference_count' => $reason === 'reference_check_failed' ? null : 0,
        ], ['class' => \RuntimeException::class, 'code' => 0, 'sql_state' => null]);
    }

    private function issue(int $id, string $status): array
    {
        return ['id' => $id, 'storage' => 'sources', 'relative_path' => 'ABC/2026/old.mp3', 'status' => $status];
    }

    private function grouper(): Mp3CleanupIssueGrouper
    {
        return new Mp3CleanupIssueGrouper(
            // Read snapshots; exclude resolved issues without deleting their history.
            function (string $storage, string $path): array {
                return array_values(array_filter($this->issues, static fn (array $issue): bool =>
                    $issue['storage'] === $storage && $issue['relative_path'] === $path
                    && in_array($issue['status'], ['open', 'investigating'], true)
                ));
            },
            function (array $data): int {
                $id = $this->issues === [] ? 1 : max(array_keys($this->issues)) + 1;
                $this->issues[$id] = ['id' => $id] + $data;
                return $id;
            },
            function (int $issueId, Mp3CleanupAnomaly $anomaly): void {
                $this->events[] = ['issue_id' => $issueId, 'anomaly' => $anomaly];
            },
        );
    }
}
