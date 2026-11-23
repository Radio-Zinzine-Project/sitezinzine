<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupTransactionFailure;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;

/** Short snapshot reads on an exclusively dedicated connection; never retries. */
final readonly class Mp3CleanupIssueReader
{
    private const PAGE_SIZE = 20;

    public function __construct(private Connection $connection) {}

    public function readInitialDetail(int $issueId): ?array
    {
        return $this->readSnapshot(fn (): ?array => $this->readProjection($issueId));
    }

    public function readNextEventsPage(int $issueId, int $lastEventId, int $beforeId): array
    {
        if ($issueId <= 0 || $lastEventId <= 0 || $beforeId <= 0 || $beforeId > $lastEventId) {
            throw new \InvalidArgumentException('MP3 cleanup pagination requires positive identifiers and a cursor not above the consultation event.');
        }

        return $this->readSnapshot(function () use ($issueId, $lastEventId, $beforeId): array {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, occurred_at, reason, emission_id, obsolete_relative_path, context, diagnostic
                 FROM mp3_cleanup_event WHERE issue_id = ? AND id <= ? AND id < ? ORDER BY id DESC LIMIT 21',
                [$issueId, $lastEventId, $beforeId],
            );
            $hasMore = count($rows) > self::PAGE_SIZE;
            $events = array_map($this->projectEvent(...), array_slice($rows, 0, self::PAGE_SIZE));

            return [
                'events' => $events,
                'token' => ['issue_id' => $issueId, 'last_event_id' => $lastEventId],
                'pagination' => [
                    'has_more' => $hasMore,
                    'next_before_id' => $hasMore ? $events[count($events) - 1]['id'] : null,
                ],
            ];
        });
    }

    /** Shared only by this reader's initial and subsequent projections. */
    private function readSnapshot(\Closure $read): ?array
    {
        if ($this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('MP3 cleanup reading requires a connection without an active transaction.');
        }

        // Applies only to the next transaction, without changing the session default.
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        try {
            $this->connection->beginTransaction();
        } catch (\Throwable $openingError) {
            $this->cleanUpFailedBegin($openingError);
            throw $openingError;
        }

        try {
            $result = $read();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $error) {
            if ($this->connection->getTransactionNestingLevel() > 0) {
                try {
                    $this->connection->rollBack();
                } catch (\Throwable $rollbackFailure) {
                    throw new Mp3CleanupTransactionFailure($error, $rollbackFailure);
                }
            }

            throw $error;
        }
    }

    private function cleanUpFailedBegin(\Throwable $openingError): void
    {
        try {
            $nativeActive = false;
            if ($this->connection->getTransactionNestingLevel() > 0
                && !$openingError instanceof ConnectionLost && $this->connection->isConnected()) {
                try {
                    $native = $this->connection->getNativeConnection();
                    // Local PDO state permits cleanup, not certainty about server state.
                    $nativeActive = $native instanceof \PDO && $native->inTransaction();
                } catch (\Throwable) {
                    // Do not reconnect or replace the opening error when inspection fails.
                }
            }

            if ($nativeActive) {
                try {
                    $this->connection->rollBack();
                } catch (\Throwable $rollbackFailure) {
                    throw new Mp3CleanupTransactionFailure($openingError, $rollbackFailure);
                }
            }
        } finally {
            // Also discard a possibly pending next-transaction isolation preparation.
            $this->connection->close();
        }
    }

    private function readProjection(int $issueId): ?array
    {
        $issue = $this->connection->fetchAssociative(
            'SELECT id, status, storage, obsolete_relative_path, created_at, resolved_at
             FROM mp3_cleanup_issue WHERE id = ?',
            [$issueId],
        );
        if ($issue === false) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, occurred_at, reason, emission_id, obsolete_relative_path, context, diagnostic
             FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id DESC LIMIT 21',
            [$issueId],
        );
        $hasMore = count($rows) > self::PAGE_SIZE;
        $events = array_map($this->projectEvent(...), array_slice($rows, 0, self::PAGE_SIZE));

        return [
            'issue' => [
                'id' => (int) $issue['id'],
                'status' => $issue['status'],
                'storage' => $issue['storage'],
                'obsolete_relative_path' => $issue['obsolete_relative_path'],
                'created_at' => $issue['created_at'],
                'resolved_at' => $issue['resolved_at'],
            ],
            'events' => $events,
            'token' => $events === [] ? null : [
                'issue_id' => (int) $issue['id'],
                'last_event_id' => $events[0]['id'],
            ],
            'pagination' => [
                'has_more' => $hasMore,
                'next_before_id' => $hasMore ? $events[count($events) - 1]['id'] : null,
            ],
        ];
    }

    private function projectEvent(array $row): array
    {
        $context = json_decode($row['context'], true, 512, JSON_THROW_ON_ERROR);
        $diagnostic = json_decode($row['diagnostic'], true, 512, JSON_THROW_ON_ERROR);

        $event = [
            'id' => (int) $row['id'],
            'occurred_at' => $row['occurred_at'],
            'reason' => $row['reason'],
            'emission_id' => (int) $row['emission_id'],
            'obsolete_relative_path' => $row['obsolete_relative_path'],
            'context' => [
                'replacement_relative_path' => $context['replacement_relative_path'],
                'commit_confirmed' => $context['commit_confirmed'],
                'reference_count' => $context['reference_count'],
            ],
            'diagnostic' => [
                'class' => $diagnostic['class'],
                'code' => $diagnostic['code'],
                'sql_state' => $diagnostic['sql_state'],
            ],
        ];
        if (array_key_exists('historical_identity', $context)) {
            $event['historical_identity'] = Mp3CleanupHistoricalIdentity::restore($context['historical_identity'])->toArray();
        }

        return $event;
    }
}
