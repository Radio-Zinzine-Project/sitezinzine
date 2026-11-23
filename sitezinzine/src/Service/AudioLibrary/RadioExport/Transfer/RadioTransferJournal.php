<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Transfer;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

final readonly class RadioTransferJournal
{
    public function __construct(private Connection $connection, private ClockInterface $clock) {}

    /** Returns a prior declaration only if all client-visible request parameters match. */
    public function replay(array $request): ?array
    {
        $this->requirePrimaryAutocommit();
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM radio_transfer_confirmation WHERE request_id = ?', [$request['request_id']]
        );
        if ($row === false) {
            return null;
        }
        foreach ($request as $key => $value) {
            // DBAL may return numeric columns as strings.
            if ((string) $row[$key] !== (string) $value) {
                throw new \DomainException('This transfer request identifier already belongs to another declaration.');
            }
        }

        return $row;
    }

    /** Persists a declaration whose artifact has been verified by RadioTransferConfirmation. */
    public function append(array $request, string $originAttemptId, int $size): array
    {
        $this->requirePrimaryAutocommit();
        try {
            $this->connection->insert('radio_transfer_confirmation', $request + [
                'origin_attempt_id' => $originAttemptId, 'artifact_size' => $size,
                'confirmed_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ]);
        } catch (UniqueConstraintViolationException $error) {
            // A concurrent writer may have committed the same request since the initial lookup.
            return $this->replay($request) ?? throw $error;
        }

        return $this->replay($request) ?? throw new \RuntimeException('The committed transfer declaration could not be reloaded.');
    }

    /** Newest event ID first. Pass the last ID seen to fetch an older page. */
    public function history(string $destination, string $filename, int $limit = 50, ?int $beforeId = null): array
    {
        if ($limit < 1 || $limit > 100 || ($beforeId !== null && $beforeId < 1)) {
            throw new \InvalidArgumentException('Invalid radio transfer history page.');
        }
        $this->requirePrimaryAutocommit();
        $query = $this->connection->createQueryBuilder()->select('*')->from('radio_transfer_confirmation')
            ->where('destination_key = :destination AND filename = :filename')
            ->setParameter('destination', $destination)->setParameter('filename', $filename)
            ->orderBy('id', 'DESC')->setMaxResults($limit);
        if ($beforeId !== null) {
            $query->andWhere('id < :before')->setParameter('before', $beforeId);
        }

        return $query->executeQuery()->fetchAllAssociative();
    }

    /** One latest declaration per requested exact filename, for the given destination. */
    public function latestForFiles(string $destination, array $filenames): array
    {
        if ($filenames === []) {
            return [];
        }
        $this->requirePrimaryAutocommit();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ranked.* FROM (
                 SELECT event.*, ROW_NUMBER() OVER (PARTITION BY filename ORDER BY id DESC) AS event_rank
                 FROM radio_transfer_confirmation event WHERE destination_key = ? AND filename IN (?)
             ) ranked WHERE ranked.event_rank = 1',
            [$destination, array_values(array_unique($filenames))],
            [ParameterType::STRING, ArrayParameterType::STRING]
        );
        foreach ($rows as &$row) {
            unset($row['event_rank']);
        }
        unset($row);

        return $rows;
    }

    /** @return list<string> */
    public function weeksBefore(string $cutoff): array
    {
        $this->requirePrimaryAutocommit();

        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT week_start FROM radio_transfer_confirmation WHERE week_start < ?', [$cutoff]
        );
    }

    /** Called only by the expiry service under the corresponding week lock. */
    public function purgeExpiredWeek(string $week, string $cutoff): int
    {
        if ($week >= $cutoff) {
            throw new \LogicException('Cannot purge current or future confirmations.');
        }
        $this->requirePrimaryAutocommit();

        return (int) $this->connection->executeStatement('DELETE FROM radio_transfer_confirmation WHERE week_start = ?', [$week]);
    }

    private function requirePrimaryAutocommit(): void
    {
        if ($this->connection->isTransactionActive() || !$this->connection->isAutoCommit()) {
            throw new \LogicException('Transfer confirmations require autocommit outside a caller transaction.');
        }
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
    }
}
