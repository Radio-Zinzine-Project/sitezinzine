<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

use App\Exception\Mp3CleanupTransactionFailure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MySQLPlatform;

/** Collects SQL references only, using one exclusively dedicated MySQL connection. */
final readonly class AudioInventoryReferenceCollector
{
    private AudioInventoryReferenceReader $reader;

    public function __construct(private Connection $connection, AudioLocalUrlResolver $urlResolver)
    {
        // Composition guarantees that page reads use this transaction's connection.
        $this->reader = new AudioInventoryReferenceReader($connection, $urlResolver);
    }

    /**
     * The synchronous consumer receives one provisional page at a time. Return
     * true to acknowledge it, false to stop (including on the terminal page).
     * It must not retain all pages, interact with a user, change schema or touch
     * this connection. Consumer effects are not undone by a SQL rollback.
     * Only the returned summary can establish successful SQL completion.
     *
     * An InnoDB check and one next-transaction REPEATABLE READ preparation precede
     * all pages. No concurrent DDL is supported. The snapshot begins with the
     * first ordinary emission SELECT, not at the start of the inventory process.
     * Neither SQL completion nor temporal consistency proves disk availability.
     * The dedicated connection is closed after ownership is accepted, including
     * on failure; an initially foreign transaction is never touched or closed.
     *
     * @param \Closure(array):bool $consumePage
     * @return array{status:'completed'|'interrupted', references_complete:bool, temporal_consistency:string, pages_read:int, association_count:int, uncertainty_count:int, next_after_id:int}
     */
    public function collect(\Closure $consumePage, int $pageSize = 500): array
    {
        if ($pageSize < 1 || $pageSize > 500) {
            throw new \InvalidArgumentException('Expected a page size between 1 and 500.');
        }
        if ($this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Reference collection cannot use a foreign transaction.');
        }
        if (!$this->connection->isAutoCommit()) {
            throw new \LogicException('Reference collection requires autocommit enabled before taking ownership.');
        }

        $primary = null;
        try {
            if (!$this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
                throw new \LogicException('Reference snapshot collection requires MySQL.');
            }
            $engine = $this->connection->fetchOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName',
                ['tableName' => 'emission'], ['tableName' => ParameterType::STRING],
            );
            if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
                throw new \LogicException('The emission table must use InnoDB for a consistent reference snapshot.');
            }
            // Next transaction only; never changes the session isolation default.
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            try {
                $this->connection->beginTransaction();
            } catch (\Throwable $openingError) {
                $this->cleanUpFailedBegin($openingError);
                throw $openingError;
            }

            $rollbackAttempted = false;
            try {
                $cursor = $pages = $associations = $uncertainties = 0;
                do {
                    $this->assertOwnedTransaction();
                    $page = $this->reader->readPage($cursor, $pageSize);
                    if (!$page['exhausted'] && $page['next_after_id'] <= $cursor) {
                        throw new \UnexpectedValueException('A nonterminal reference page must advance the cursor.');
                    }
                    ++$pages;
                    $associations += count($page['associations']);
                    $uncertainties += count($page['uncertainties']);
                    $cursor = $page['next_after_id'];
                    $continue = $consumePage($page);
                    if (!is_bool($continue)) {
                        throw new \UnexpectedValueException('The page consumer must explicitly return true or false.');
                    }
                    $this->assertOwnedTransaction();
                    if (!$continue) {
                        $rollbackAttempted = true;
                        $this->connection->rollBack();
                        return $this->summary('interrupted', $pages, $associations, $uncertainties, $cursor);
                    }
                } while (!$page['exhausted']);

                $this->connection->commit();
                return $this->summary('completed', $pages, $associations, $uncertainties, $cursor);
            } catch (\Throwable $error) {
                if (!$rollbackAttempted && $this->connection->isConnected()
                    && $this->connection->getTransactionNestingLevel() > 0) {
                    try {
                        $this->connection->rollBack();
                    } catch (\Throwable $rollbackFailure) {
                        throw new Mp3CleanupTransactionFailure($error, $rollbackFailure);
                    }
                }
                throw $error;
            }
        } catch (\Throwable $error) {
            $primary = $error;
            throw $error;
        } finally {
            try {
                // Also discards pending isolation preparation and uncertain native state.
                $this->connection->close();
            } catch (\Throwable $closeFailure) {
                if ($primary === null) {
                    throw $closeFailure;
                }
                // Keep the primary error (and any wrapped rollback error) intact.
            }
        }
    }

    private function assertOwnedTransaction(): void
    {
        if (!$this->connection->isConnected() || $this->connection->getTransactionNestingLevel() !== 1) {
            throw new \LogicException('The dedicated reference transaction is no longer intact.');
        }
    }

    private function cleanUpFailedBegin(\Throwable $openingError): void
    {
        $nativeActive = false;
        if ($this->connection->getTransactionNestingLevel() > 0
            && !$openingError instanceof ConnectionLost && $this->connection->isConnected()) {
            try {
                $native = $this->connection->getNativeConnection();
                // PDO reports local state, not independently verified server state.
                $nativeActive = $native instanceof \PDO && $native->inTransaction();
            } catch (\Throwable) {
                // Do not reconnect or replace the original opening failure.
            }
        }
        if ($nativeActive) {
            try {
                $this->connection->rollBack();
            } catch (\Throwable $rollbackFailure) {
                throw new Mp3CleanupTransactionFailure($openingError, $rollbackFailure);
            }
        }
    }

    private function summary(string $status, int $pages, int $associations, int $uncertainties, int $cursor): array
    {
        return [
            'status' => $status,
            'references_complete' => $status === 'completed' && $uncertainties === 0,
            'temporal_consistency' => 'repeatable_read',
            'pages_read' => $pages,
            'association_count' => $associations,
            'uncertainty_count' => $uncertainties,
            'next_after_id' => $cursor,
        ];
    }
}
