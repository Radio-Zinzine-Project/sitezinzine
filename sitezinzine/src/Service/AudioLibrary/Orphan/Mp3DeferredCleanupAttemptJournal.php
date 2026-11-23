<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Exception\Mp3AdministrativeRequestUncertain;
use Doctrine\DBAL\Connection;

class Mp3DeferredCleanupAttemptJournal
{
    public function reserve(Connection $db, string $requestId, int $issueId, int $eventId,
        ?int $actorId, \DateTimeImmutable $at): void
    {
        self::requireIdle($db, $requestId);
        if ($issueId < 1 || $issueId > 2_147_483_647 || $eventId < 1
            || ($actorId !== null && ($actorId < 1 || $actorId > 2_147_483_647))) {
            throw new \InvalidArgumentException('Invalid deferred MP3 cleanup request context.');
        }

        $inserted = false;
        try {
            $db->transactional(static function (Connection $connection) use ($requestId, $issueId, $eventId, $actorId, $at, &$inserted): void {
                $count = (int) $connection->insert('mp3_deferred_cleanup_attempt', [
                    'request_id' => $requestId,
                    'issue_id' => $issueId,
                    'expected_event_id' => $eventId,
                    'actor_id' => $actorId,
                    'requested_at' => self::utc($at),
                    'state' => 'pending',
                ]);
                if ($count !== 1) {
                    throw new \UnexpectedValueException('Deferred MP3 cleanup request was not inserted.');
                }
                $inserted = true;
            });
        } catch (\Throwable $error) {
            if ($inserted) {
                throw new Mp3AdministrativeRequestUncertain('The deferred MP3 cleanup request commit was not confirmed.', 0, $error);
            }
            throw $error;
        }
    }

    public function finish(Connection $db, string $requestId, Mp3DeferredCleanupResult $result, \DateTimeImmutable $at): void
    {
        self::requireIdle($db, $requestId);
        $db->transactional(static function (Connection $connection) use ($requestId, $result, $at): void {
            $changed = $connection->executeStatement(
                "UPDATE mp3_deferred_cleanup_attempt SET state = 'finished', result_code = ?, finished_at = ?
                 WHERE request_id = ? AND state = 'pending'",
                [$result->value, self::utc($at), $requestId],
            );
            if ((int) $changed !== 1) {
                throw new \UnexpectedValueException('Deferred MP3 cleanup outcome must update exactly one pending request.');
            }
        });
    }

    public function find(Connection $db, string $requestId): ?array
    {
        self::requireIdle($db, $requestId);
        $row = $db->fetchAssociative(
            'SELECT request_id, issue_id, expected_event_id, actor_id, requested_at, state, result_code, finished_at
             FROM mp3_deferred_cleanup_attempt WHERE request_id = ?',
            [$requestId],
        );

        return $row === false ? null : $row;
    }

    public function recentForIssue(Connection $db, int $issueId): array
    {
        return $db->fetchAllAssociative(
            'SELECT request_id, requested_at, state, result_code FROM mp3_deferred_cleanup_attempt
             WHERE issue_id = ? ORDER BY requested_at DESC, request_id DESC LIMIT 20',
            [$issueId],
        );
    }

    public function activeForIssue(Connection $db, int $issueId): ?string
    {
        $id = $db->fetchOne(
            'SELECT request_id FROM mp3_deferred_cleanup_attempt WHERE issue_id = ? AND active_marker = 1',
            [$issueId],
        );

        return $id === false ? null : $id;
    }

    private static function requireIdle(Connection $db, string $requestId): void
    {
        if ($db->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Deferred MP3 cleanup journaling requires an idle writing connection.');
        }
        if (preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1) {
            throw new \InvalidArgumentException('Invalid deferred MP3 cleanup request identifier.');
        }
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
