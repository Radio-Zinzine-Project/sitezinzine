<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class Mp3CleanupReporter
{
    /** @param \Closure(Mp3CleanupAnomaly):void $recipient */
    public function __construct(
        private \Closure $recipient,
        private LoggerInterface $fallbackLogger = new NullLogger(),
    ) {}

    /** Called after commit when the cleanup guard could not establish zero references and reservations. */
    public function reportReferenceCheckFailure(
        int $emissionId,
        string $obsoleteRelativePath,
        string $replacementRelativePath,
        \Throwable $error,
        ?Mp3CleanupHistoricalIdentity $historicalIdentity = null,
    ): void {
        $this->report('reference_check_failed', $emissionId, $obsoleteRelativePath, $replacementRelativePath, null, $error, $historicalIdentity);
    }

    /** Called after commit only when zero references were established before cleanup failed. */
    public function reportObsoleteCleanupFailure(
        int $emissionId,
        string $obsoleteRelativePath,
        string $replacementRelativePath,
        \Throwable $error,
        ?Mp3CleanupHistoricalIdentity $historicalIdentity = null,
    ): void {
        $this->report('obsolete_cleanup_failed', $emissionId, $obsoleteRelativePath, $replacementRelativePath, 0, $error, $historicalIdentity);
    }

    private function report(
        string $reason,
        int $emissionId,
        string $obsoleteRelativePath,
        string $replacementRelativePath,
        ?int $referenceCount,
        \Throwable $error,
        ?Mp3CleanupHistoricalIdentity $historicalIdentity = null,
    ): void {
        $anomaly = new Mp3CleanupAnomaly(
            $reason,
            $emissionId,
            $obsoleteRelativePath,
            [
                'replacement_relative_path' => $replacementRelativePath,
                'commit_confirmed' => true,
                'reference_count' => $referenceCount,
            ],
            $this->diagnostic($error),
            $historicalIdentity,
        );

        try {
            ($this->recipient)($anomaly);
        } catch (\Throwable $recipientFailure) {
            try {
                // Only allowlisted scalar metadata: no paths, raw messages or exception objects.
                $this->fallbackLogger->error('MP3 post-commit cleanup reporting failed.', [
                    'reason' => $reason,
                    'emission_id' => $emissionId,
                    'commit_confirmed' => true,
                    'reference_count' => $referenceCount,
                    'diagnostic' => $anomaly->diagnostic,
                    'recipient_failure' => $this->diagnostic($recipientFailure),
                ]);
            } catch (\Throwable) {
                // A reporting outage cannot invalidate an already committed replacement.
            }
        }
    }

    /** @return array{class:class-string<\Throwable>, code:int|string, sql_state:?string} */
    private function diagnostic(\Throwable $error): array
    {
        return [
            'class' => $error::class,
            'code' => $error->getCode(),
            'sql_state' => $error instanceof \PDOException ? ($error->errorInfo[0] ?? null) : null,
        ];
    }
}
