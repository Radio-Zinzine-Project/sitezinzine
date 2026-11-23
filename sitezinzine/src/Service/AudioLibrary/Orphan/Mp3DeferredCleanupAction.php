<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Application entry point for the administrative screen. */
class Mp3DeferredCleanupAction
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Mp3DeferredCleanupAttemptJournal $journal,
        private readonly Mp3DeferredCleanup $cleanup,
        private readonly Mp3CleanupIssueResolutionService $resolution,
        #[Autowire('%app.mp3_deferred_cleanup.local_url_prefixes%')]
        private readonly string $localUrlPrefixes,
        #[Autowire('%app.mp3_public_base_url%')]
        private readonly string $canonicalUrl,
    ) {}

    public function read(int $issueId, ?string $requestId): array
    {
        $db = Mp3CleanupServiceFactory::createConnection($this->connection);
        try {
            $attempts = $this->journal->recentForIssue($db, $issueId);
            $active = $this->journal->activeForIssue($db, $issueId);
            $diagnostic = null;
            $resolver = $this->resolver();
            if ($requestId !== null) {
                $attempt = $this->journal->find($db, $requestId);
                if ($attempt === null || (int) $attempt['issue_id'] !== $issueId) {
                    return ['unknown' => true];
                }
                if ($resolver !== null) {
                    $diagnostic = $this->cleanup->reconcile($db, $requestId, $resolver);
                } else {
                    $diagnostic = ['observation' => $attempt['state'] === 'pending'
                        || $attempt['result_code'] === Mp3DeferredCleanupResult::Indeterminate->value ? 'indeterminate' : null];
                }
            }

            return ['attempts' => $attempts, 'active' => $active, 'diagnostic' => $diagnostic, 'configured' => $resolver !== null];
        } finally {
            $db->close();
        }
    }

    public function remove(int $issueId, int $eventId, string $requestId, int $actorId): Mp3DeferredCleanupResult
    {
        $resolver = $this->resolver();
        if ($resolver === null) {
            return Mp3DeferredCleanupResult::RequestNotRecorded;
        }

        $db = Mp3CleanupServiceFactory::createConnection($this->connection);
        try {
            return $this->cleanup->remove($db, $issueId, ['issue_id' => $issueId, 'last_event_id' => $eventId],
                $resolver, $requestId, $actorId);
        } finally {
            $db->close();
        }
    }

    public function closeIncident(int $issueId, int $eventId, int $actorId, string $reason): string
    {
        $resolver = $this->resolver();
        if ($resolver === null) {
            return 'indeterminate';
        }

        $db = Mp3CleanupServiceFactory::createConnection($this->connection);
        try {
            return $this->resolution->resolve($db, $issueId, ['issue_id' => $issueId, 'last_event_id' => $eventId],
                $resolver, $actorId, $reason, new \DateTimeImmutable());
        } finally {
            $db->close();
        }
    }

    private function resolver(): ?AudioLocalUrlResolver
    {
        return AudioLocalUrlResolver::fromConfiguredLibrary($this->canonicalUrl, $this->localUrlPrefixes);
    }
}
