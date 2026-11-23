<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Exception\Mp3AdministrativeRequestUncertain;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\Mp3DiskIo;
use App\Service\Mp3StorageLock;
use App\Service\Mp3StoragePath;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Internal only. Physical removal requires a confirmed durable request. */
final readonly class Mp3DeferredCleanup
{
    public function __construct(
        private Mp3StorageLock $lock,
        private Mp3DeferredCleanupPreconditions $preconditions,
        private Mp3CleanupIdentityVerifier $identity,
        private Mp3DiskIo $disk,
        private string $libraryDir,
        private Mp3DeferredCleanupAttemptJournal $journal,
    ) {}

    /** Use an exclusively dedicated connection to the writing server and all local URL prefixes. */
    public function remove(
        Connection $db,
        int $issueId,
        mixed $token,
        AudioLocalUrlResolver $localUrls,
        string $requestId,
        ?int $actorId,
    ): Mp3DeferredCleanupResult {
        if (!is_array($token) || ($token['issue_id'] ?? null) !== $issueId
            || !is_int($token['last_event_id'] ?? null)) {
            return Mp3DeferredCleanupResult::RequestNotRecorded;
        }

        try {
            $this->journal->reserve($db, $requestId, $issueId, $token['last_event_id'], $actorId, new \DateTimeImmutable());
        } catch (UniqueConstraintViolationException) {
            return Mp3DeferredCleanupResult::DuplicateOrActiveRequest;
        } catch (Mp3AdministrativeRequestUncertain) {
            $db->close();
            return Mp3DeferredCleanupResult::RequestUncertain;
        } catch (\Throwable) {
            return Mp3DeferredCleanupResult::RequestNotRecorded;
        }

        $result = $this->underLock($db, $issueId, $token, $localUrls);
        try {
            $this->journal->finish($db, $requestId, $result, new \DateTimeImmutable());
        } catch (\Throwable) {
            $db->close();
            return Mp3DeferredCleanupResult::OutcomeUncertain;
        }

        return $result;
    }

    /**
     * Read recorded facts and optionally observe current storage. It does not
     * infer the outcome of an interrupted request from that observation.
     */
    public function reconcile(Connection $db, string $requestId, AudioLocalUrlResolver $localUrls): ?array
    {
        $attempt = $this->journal->find($db, $requestId);
        if ($attempt === null) {
            return null;
        }
        $observation = null;
        $needsReconciliation = $attempt['state'] === 'pending'
            || $attempt['result_code'] === Mp3DeferredCleanupResult::Indeterminate->value;
        if ($needsReconciliation) {
            $issueId = (int) $attempt['issue_id'];
            $token = ['issue_id' => $issueId, 'last_event_id' => (int) $attempt['expected_event_id']];
            $result = $this->underLock($db, $issueId, $token, $localUrls, observe: true);
            $observation = $result === Mp3DeferredCleanupResult::AlreadyAbsent ? 'absent_now' : $result->value;
        }

        return ['attempt' => $attempt, 'needs_reconciliation' => $needsReconciliation, 'observation' => $observation];
    }

    private function underLock(Connection $db, int $issueId, array $token, AudioLocalUrlResolver $localUrls,
        bool $observe = false): Mp3DeferredCleanupResult
    {
        if ($issueId <= 0 || $db->getTransactionNestingLevel() !== 0) {
            return Mp3DeferredCleanupResult::Indeterminate;
        }

        $entered = false;
        try {
            return $this->lock->synchronized(function () use ($db, $issueId, $token, $localUrls, $observe, &$entered): Mp3DeferredCleanupResult {
                $entered = true;
                try {
                    // The incident row is held through the filesystem operation, as the
                    // signal writer and resolution service also serialize on that row.
                    // No DML is performed. READ WRITE permits the locking read in MySQL.
                    $db->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ WRITE');

                    return $db->transactional(fn (Connection $session): Mp3DeferredCleanupResult =>
                        $this->removeLocked($session, $issueId, $token, $localUrls, $observe));
                } catch (\Throwable $error) {
                    $db->close();
                    throw $error;
                }
            }, allowCreation: !$observe);
        } catch (\Throwable) {
            return $entered ? Mp3DeferredCleanupResult::Indeterminate : Mp3DeferredCleanupResult::LockUnavailable;
        }
    }

    private function removeLocked(Connection $db, int $issueId, array $token, AudioLocalUrlResolver $localUrls,
        bool $observe): Mp3DeferredCleanupResult
    {
        $precondition = $this->preconditions->inspectLocked($db, $issueId, $token, $localUrls, $relative, lockIssue: true);
        if ($precondition !== Mp3DeferredCleanupDecision::PreconditionsVerified) {
            return Mp3DeferredCleanupResult::from($precondition->value);
        }

        $observed = $this->identity->check($relative, self::historicalProofs($db, $issueId));
        if ($observed !== Mp3CleanupIdentityDecision::Confirmed) {
            return self::withoutRemoval($observed);
        }

        if ($observe) {
            return Mp3DeferredCleanupResult::IdentityPresent;
        }

        $precondition = $this->preconditions->inspectLocked($db, $issueId, $token, $localUrls, $rechecked, lockIssue: true);
        if ($precondition !== Mp3DeferredCleanupDecision::PreconditionsVerified) {
            return Mp3DeferredCleanupResult::from($precondition->value);
        }
        if ($relative !== $rechecked) {
            return Mp3DeferredCleanupResult::Indeterminate;
        }

        $target = Mp3StoragePath::resolve($this->libraryDir, $relative);
        $observed = $this->identity->check($relative, self::historicalProofs($db, $issueId));
        if ($observed !== Mp3CleanupIdentityDecision::Confirmed) {
            return self::withoutRemoval($observed);
        }
        // This comparison and the unlink are only serialized for writers that use our lock.
        if (Mp3StoragePath::resolve($this->libraryDir, $relative) !== $target) {
            return Mp3DeferredCleanupResult::Indeterminate;
        }
        $removed = $this->disk->unlink($target);
        $after = $this->identity->check($relative, self::historicalProofs($db, $issueId));
        if ($removed && $after === Mp3CleanupIdentityDecision::Absent) {
            return Mp3DeferredCleanupResult::Removed;
        }
        if (!$removed && $after === Mp3CleanupIdentityDecision::Confirmed) {
            return Mp3DeferredCleanupResult::RemovalRefused;
        }

        return Mp3DeferredCleanupResult::Indeterminate;
    }

    /** @return iterable<Mp3CleanupHistoricalIdentity> */
    public static function historicalProofs(Connection $db, int $issueId): iterable
    {
        foreach ($db->iterateColumn(
            "SELECT JSON_EXTRACT(context, '$.historical_identity') FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id",
            [$issueId],
        ) as $evidence) {
            if ($evidence === null) {
                yield Mp3CleanupHistoricalIdentity::restore(null);
                continue;
            }
            if (!is_string($evidence)) {
                throw new \UnexpectedValueException('Invalid MP3 cleanup evidence result.');
            }

            yield Mp3CleanupHistoricalIdentity::restore(json_decode($evidence, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    private static function withoutRemoval(Mp3CleanupIdentityDecision $observed): Mp3DeferredCleanupResult
    {
        return match ($observed) {
            Mp3CleanupIdentityDecision::Absent => Mp3DeferredCleanupResult::AlreadyAbsent,
            Mp3CleanupIdentityDecision::UnsafePath => Mp3DeferredCleanupResult::InvalidPath,
            default => Mp3DeferredCleanupResult::from($observed->value),
        };
    }
}
