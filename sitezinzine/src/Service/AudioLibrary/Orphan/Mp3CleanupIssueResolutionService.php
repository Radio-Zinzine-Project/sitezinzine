<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\Mp3StorageLock;
use Doctrine\DBAL\Connection;

final readonly class Mp3CleanupIssueResolutionService
{
    public function __construct(
        private Mp3StorageLock $lock,
        private Mp3DeferredCleanupPreconditions $preconditions,
        private Mp3CleanupIdentityVerifier $identity,
    ) {}

    public function resolve(Connection $db, int $issueId, array $token, AudioLocalUrlResolver $urls, int $actorId,
        string $reason, \DateTimeImmutable $resolvedAt): string
    {
        $reason = trim($reason);
        if ($actorId < 1 || $actorId > 2_147_483_647 || $reason === '' || !mb_check_encoding($reason, 'UTF-8')
            || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            return 'invalid_reason';
        }
        if ($db->getTransactionNestingLevel() !== 0) {
            return 'indeterminate';
        }

        $entered = false;
        try {
            return $this->lock->synchronized(function () use ($db, $issueId, $token, $urls, $actorId, $reason, $resolvedAt, &$entered): string {
                $entered = true;
                try {
                    // The locking lookup must also protect the empty unique-key range against a concurrent reservation.
                    $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ WRITE');

                    return $db->transactional(fn (Connection $session): string =>
                        $this->resolveLocked($session, $issueId, $token, $urls, $actorId, $reason, $resolvedAt));
                } catch (\Throwable $error) {
                    $db->close();
                    throw $error;
                }
            });
        } catch (\Throwable) {
            return $entered ? 'indeterminate' : 'lock_unavailable';
        }
    }

    private function resolveLocked(Connection $db, int $issueId, array $token, AudioLocalUrlResolver $urls,
        int $actorId, string $reason, \DateTimeImmutable $resolvedAt): string
    {
        $decision = $this->preconditions->inspectLocked($db, $issueId, $token, $urls, $relative, lockIssue: true);
        if ($decision !== Mp3DeferredCleanupDecision::PreconditionsVerified) {
            return $decision->value;
        }

        if ($db->fetchOne(
            'SELECT request_id FROM mp3_deferred_cleanup_attempt FORCE INDEX (uniq_mp3_deferred_cleanup_active) WHERE issue_id = ? AND active_marker = 1 FOR UPDATE',
            [$issueId],
        ) !== false) {
            return 'active_attempt';
        }

        $observation = $this->identity->checkAbsenceForClosure($relative, Mp3DeferredCleanup::historicalProofs($db, $issueId));
        if ($observation !== Mp3CleanupIdentityDecision::Absent) {
            return $observation->value;
        }

        $attempt = $db->fetchOne(
            "SELECT request_id FROM mp3_deferred_cleanup_attempt WHERE issue_id = ? AND expected_event_id = ?
             AND state = 'finished' AND result_code = 'removed' ORDER BY requested_at DESC, request_id DESC LIMIT 1",
            [$issueId, $token['last_event_id']],
        );
        $at = $resolvedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        if ((int) $db->insert('mp3_cleanup_issue_closure', [
            'issue_id' => $issueId,
            'actor_id' => $actorId,
            'closed_at' => $at,
            'reason' => $reason,
            'proof_type' => $attempt === false ? 'currently_absent' : 'confirmed_removal',
            'request_id' => $attempt === false ? null : $attempt,
            'expected_event_id' => $token['last_event_id'],
        ]) !== 1 || (int) $db->executeStatement(
            "UPDATE mp3_cleanup_issue SET status = 'resolved', resolved_at = ? WHERE id = ? AND status IN ('open', 'investigating')",
            [$at, $issueId],
        ) !== 1) {
            throw new \UnexpectedValueException('Both the MP3 incident and its closure trace must be persisted.');
        }

        return 'allowed';
    }
}
