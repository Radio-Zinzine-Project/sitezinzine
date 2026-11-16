<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupActiveIssueCollision;
use App\Exception\Mp3CleanupTransactionFailure;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;

final readonly class Mp3CleanupIssueWriter
{
    /** Exclusively dedicated connection: callbacks must share it; defensive cleanup may close it. */
    public function __construct(
        private Connection $connection,
        private Mp3CleanupIssueGrouper $grouper,
        private int $maxAttempts = 3,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('At least one MP3 cleanup transaction attempt is required.');
        }
    }

    public function record(string $storage, Mp3CleanupAnomaly $anomaly): int
    {
        if ($this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('The MP3 cleanup writer requires a connection without an active transaction.');
        }

        for ($attempt = 1; ; ++$attempt) {
            try {
                $this->connection->beginTransaction();
            } catch (\Throwable $openingError) {
                $this->cleanUpFailedBegin($openingError);
                throw $openingError;
            }
            $commitAttempted = false;

            try {
                $id = $this->grouper->record($storage, $anomaly);
                $commitAttempted = true;
                $this->connection->commit();

                return $id;
            } catch (\Throwable $error) {
                $rollbackSucceeded = false;
                if ($this->connection->getTransactionNestingLevel() > 0) {
                    try {
                        $this->connection->rollBack();
                        $rollbackSucceeded = true;
                    } catch (\Throwable $rollbackFailure) {
                        throw new Mp3CleanupTransactionFailure($error, $rollbackFailure);
                    }
                }

                if ($commitAttempted || !$rollbackSucceeded || $attempt >= $this->maxAttempts
                    || !($error instanceof Mp3CleanupActiveIssueCollision
                        || $error instanceof DeadlockException
                        || $error instanceof LockWaitTimeoutException)
                    || !$this->connection->isConnected()
                    || $this->connection->getTransactionNestingLevel() !== 0) {
                    throw $error;
                }
            }
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
                // A local PDO observation permits defensive cleanup, not a claim about server outcome.
                $nativeActive = $native instanceof \PDO && $native->inTransaction();
            } catch (\Throwable) {
                // Inaccessible native state must not mask the opening error or trigger reconnection.
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
