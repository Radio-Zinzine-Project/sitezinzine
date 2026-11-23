<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\Mp3StorageLock;
use App\Service\Mp3StoragePath;
use Doctrine\DBAL\Connection;

final readonly class Mp3DeferredCleanupPreconditions
{
    public function __construct(
        private Mp3StorageLock $lock,
        private Mp3CleanupResolutionDecision $freshness,
        private string $libraryDir,
    ) {}

    /**
     * Supply a dedicated connection to the writing server and a resolver
     * covering every known public prefix for this library, including legacy aliases.
     * A favorable observation expires when the lock is released and says nothing
     * about the identity of the file.
     */
    public function check(Connection $db, int $issueId, mixed $token, AudioLocalUrlResolver $localUrls): Mp3DeferredCleanupDecision
    {
        if ($issueId <= 0 || $db->getTransactionNestingLevel() !== 0) {
            return Mp3DeferredCleanupDecision::Indeterminate;
        }

        $entered = false;
        try {
            return $this->lock->synchronized(function () use ($db, $issueId, $token, $localUrls, &$entered): Mp3DeferredCleanupDecision {
                $entered = true;
                try {
                    $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

                    return $db->transactional(fn (Connection $connection): Mp3DeferredCleanupDecision =>
                        $this->inspectLocked($connection, $issueId, $token, $localUrls));
                } catch (\Throwable $error) {
                    // Discard pending transaction settings or a connection with uncertain state.
                    $db->close();
                    throw $error;
                }
            });
        } catch (\Throwable) {
            return $entered ? Mp3DeferredCleanupDecision::Indeterminate : Mp3DeferredCleanupDecision::LockUnavailable;
        }
    }

    /** Internal reuse under the same audio lock and a caller-owned transaction. */
    public function inspectLocked(
        Connection $db,
        int $issueId,
        mixed $token,
        AudioLocalUrlResolver $localUrls,
        ?string &$verifiedPath = null,
        bool $lockIssue = false,
    ): Mp3DeferredCleanupDecision {
        $verifiedPath = null;
        if (!$this->lock->isHeldByCurrentProcess()) {
            return Mp3DeferredCleanupDecision::LockUnavailable;
        }

        if ($lockIssue) {
            if ($db->getTransactionNestingLevel() !== 1) {
                return Mp3DeferredCleanupDecision::Indeterminate;
            }

            $issue = $db->fetchAssociative(
                'SELECT id, status, storage, obsolete_relative_path FROM mp3_cleanup_issue WHERE id = ? FOR UPDATE',
                [$issueId],
            );
            if ($issue !== false) {
                $issue['last_event_id'] = $db->fetchOne(
                    'SELECT MAX(id) FROM mp3_cleanup_event WHERE issue_id = ?', [$issueId],
                );
            }
        } else {
            $issue = $db->fetchAssociative(
                'SELECT i.id, i.status, i.storage, i.obsolete_relative_path,
                        (SELECT MAX(e.id) FROM mp3_cleanup_event e WHERE e.issue_id = i.id) AS last_event_id
                 FROM mp3_cleanup_issue i WHERE i.id = ?',
                [$issueId],
            );
        }
        if ($issue === false) {
            return Mp3DeferredCleanupDecision::NotFound;
        }
        $lastEventId = self::positiveId($issue['last_event_id'] ?? null);
        if (self::positiveId($issue['id'] ?? null) !== $issueId || !is_string($issue['status'] ?? null)
            || ($issue['storage'] ?? null) !== 'emissionsMp3' || $lastEventId === null) {
            return Mp3DeferredCleanupDecision::Indeterminate;
        }

        $decision = $this->freshness->decide($issueId, $issue['status'], $lastEventId, $token);
        if ($decision !== 'allowed') {
            return match ($decision) {
                'already_resolved' => Mp3DeferredCleanupDecision::AlreadyResolved,
                'stale_view' => Mp3DeferredCleanupDecision::StaleView,
                default => Mp3DeferredCleanupDecision::Indeterminate,
            };
        }

        $path = $issue['obsolete_relative_path'] ?? null;
        if (!is_string($path)) {
            return Mp3DeferredCleanupDecision::Indeterminate;
        }
        try {
            $resolvedPath = Mp3StoragePath::resolve($this->libraryDir, $path);
            $entry = @lstat($resolvedPath);
            if ($entry !== false && ($entry['mode'] & 0170000) !== 0100000) {
                return Mp3DeferredCleanupDecision::InvalidPath;
            }
        } catch (\RuntimeException) {
            return Mp3DeferredCleanupDecision::InvalidPath;
        }

        $result = Mp3CleanupReferences::inspect($db, $path, $localUrls);
        if ($result !== Mp3DeferredCleanupDecision::PreconditionsVerified) {
            return $result;
        }

        $verifiedPath = $path;
        return Mp3DeferredCleanupDecision::PreconditionsVerified;
    }

    private static function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && (!is_string($value) || !ctype_digit($value))) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

}
