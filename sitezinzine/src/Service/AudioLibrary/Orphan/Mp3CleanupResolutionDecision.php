<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

/** Pure decision over a current snapshot; never executes the resolution. */
final class Mp3CleanupResolutionDecision
{
    public function decide(int $issueId, string $status, ?int $lastEventId, mixed $token): string
    {
        if (!is_array($token)
            || !isset($token['issue_id'], $token['last_event_id'])
            || !is_int($token['issue_id']) || $token['issue_id'] <= 0
            || !is_int($token['last_event_id']) || $token['last_event_id'] <= 0
            || $token['issue_id'] !== $issueId) {
            return 'invalid_token';
        }

        if ($lastEventId === null) {
            return 'no_events';
        }

        if ($lastEventId !== $token['last_event_id']) {
            return 'stale_view';
        }

        if ($status === 'resolved') {
            return 'already_resolved';
        }

        if ($status === 'open' || $status === 'investigating') {
            return 'allowed';
        }

        throw new \DomainException('Unknown MP3 cleanup issue status.');
    }
}
