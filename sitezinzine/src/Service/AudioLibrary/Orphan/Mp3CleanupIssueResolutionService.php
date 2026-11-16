<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupTransactionFailure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;

/** Owns a short resolution transaction on an exclusively dedicated connection; never retries. */
final readonly class Mp3CleanupIssueResolutionService
{
    public function __construct(
        private Connection $connection,
        private Mp3CleanupResolutionDecision $decision,
    ) {}

    public function resolve(int $issueId, mixed $token, \DateTimeImmutable $resolvedAt): string
    {
        if ($this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('MP3 cleanup resolution requires a connection without an active transaction.');
        }

        try {
            $this->connection->beginTransaction();
        } catch (\Throwable $openingError) {
            $this->cleanUpFailedBegin($openingError);
            throw $openingError;
        }

        try {
            $issue = $this->connection->fetchAssociative(
                'SELECT id, status, resolved_at FROM mp3_cleanup_issue WHERE id = ? FOR UPDATE',
                [$issueId],
            );

            $result = 'not_found';
            if ($issue !== false) {
                $lastEventId = $this->connection->fetchOne(
                    'SELECT id FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE',
                    [$issueId],
                );
                $result = $this->decision->decide(
                    $issueId, $issue['status'], $lastEventId === false ? null : (int) $lastEventId, $token,
                );

                if ($result === 'allowed') {
                    $affected = $this->connection->executeStatement(
                        'UPDATE mp3_cleanup_issue SET status = ?, resolved_at = ? WHERE id = ? AND status IN (?, ?)',
                        [
                            'resolved',
                            $resolvedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                            $issueId, 'open', 'investigating',
                        ],
                    );

                    if ((int) $affected !== 1) {
                        throw new \UnexpectedValueException('MP3 cleanup resolution must update exactly one issue.');
                    }
                }
            }

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
        if ($this->connection->getTransactionNestingLevel() === 0) {
            return;
        }

        $nativeActive = false;
        if (!$openingError instanceof ConnectionLost && $this->connection->isConnected()) {
            try {
                $native = $this->connection->getNativeConnection();
                // Local PDO state permits defensive cleanup, not certainty about the server.
                $nativeActive = $native instanceof \PDO && $native->inTransaction();
            } catch (\Throwable) {
                // Preserve the opening error when native state cannot be inspected.
            }
        }

        if (!$nativeActive) {
            $this->connection->close();
            return;
        }

        try {
            $this->connection->rollBack();
        } catch (\Throwable $rollbackError) {
            throw new Mp3CleanupTransactionFailure($openingError, $rollbackError);
        }
    }
}
