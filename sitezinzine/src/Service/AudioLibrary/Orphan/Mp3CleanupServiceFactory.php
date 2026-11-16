<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/** Composition only: no ORM access, eager physical connection or global configuration. */
final class Mp3CleanupServiceFactory
{
    public static function createConnection(Connection $businessConnection): Connection
    {
        $params = $businessConnection->getParams();
        $options = $params['driverOptions'] ?? [];
        if (($params['driver'] ?? null) !== 'pdo_mysql'
            || !is_string($params['dbname'] ?? null) || $params['dbname'] === ''
            || array_key_exists('pdo', $params)
            || isset($params['primary']) || isset($params['replica'])
            || isset($params['driverClass']) || isset($params['wrapperClass']) || isset($params['url'])
            || !empty($params['persistent']) || !empty($options[\PDO::ATTR_PERSISTENT])
            || (array_key_exists(\PDO::ATTR_AUTOCOMMIT, $options) && !$options[\PDO::ATTR_AUTOCOMMIT])) {
            throw new \LogicException('MP3 cleanup requires resolved parameters for a direct, nonpersistent PDO MySQL connection with autocommit enabled.');
        }

        // A new DBAL configuration and driver are constructed; the business session is never accessed.
        return DriverManager::getConnection($params);
    }

    /**
     * Providers must return the writer and its exclusively dedicated, shared DBAL connection.
     *
     * @param \Closure():Mp3CleanupIssueWriter $writerProvider
     * @param \Closure():Connection $connectionProvider
     * @return \Closure(Mp3CleanupAnomaly):void
     */
    public static function createRecipient(\Closure $writerProvider, \Closure $connectionProvider): \Closure
    {
        $running = false;

        return static function (Mp3CleanupAnomaly $anomaly) use ($writerProvider, $connectionProvider, &$running): void {
            if ($running) {
                throw new \LogicException('An MP3 cleanup recipient cannot be entered recursively.');
            }

            $running = true;
            $connection = null;
            $ownsConnection = false;
            $primary = null;
            try {
                $connection = $connectionProvider();
                if ($connection->getTransactionNestingLevel() !== 0) {
                    throw new \LogicException('MP3 cleanup cannot take ownership of an already active transaction.');
                }
                $ownsConnection = true;
                $writer = $writerProvider();
                $writer->record('emissionsMp3', $anomaly);
            } catch (\Throwable $error) {
                $primary = $error;
                throw $error;
            } finally {
                try {
                    if ($ownsConnection) {
                        try {
                            // Also abandon residual native state after a rollback failure or uncertain commit.
                            $connection->close();
                        } catch (\Throwable $closeFailure) {
                            if ($primary === null) {
                                throw $closeFailure;
                            }
                            // Preserve the initial error for the reporter's existing sanitized fallback.
                        }
                    }
                } finally {
                    $running = false;
                }
            }
        };
    }
}
