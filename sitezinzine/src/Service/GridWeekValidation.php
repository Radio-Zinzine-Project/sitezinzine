<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;

final readonly class GridWeekValidation
{
    public function __construct(private Connection $connection) {}

    /** @param callable(\DateTimeImmutable): array $change Existing grid operation on this connection. */
    public function changeManually(\DateTimeImmutable $date, bool $validated, callable $change): array
    {
        $week = self::week($date);
        $key = $week->format('Y-m-d');

        return $this->connection->transactional(function () use ($week, $key, $validated, $change): array {
            // InnoDB serializes manual commands for the same week, including its first validation.
            $this->connection->executeStatement(
                'INSERT INTO grid_week_validation (week_start, validated, manual_revision) VALUES (?, 0, 0)
                    ON DUPLICATE KEY UPDATE week_start = week_start',
                [$key]
            );

            $result = $change($week);
            $this->connection->executeStatement(
                'UPDATE grid_week_validation SET manual_revision = manual_revision + 1, validated = ?
                    WHERE week_start = ? AND validated <> ?',
                [(int) $validated, $key, (int) $validated]
            );

            return $result;
        });
    }

    /**
     * Reads both values from one SQL snapshot, without ORM identity-map reuse.
     *
     * @return array{validated: bool, revision: string}
     */
    public function read(\DateTimeImmutable $date): array
    {
        $state = $this->snapshot($date);

        return ['validated' => $state['validated'], 'revision' => $state['revision']];
    }

    /** Reads proof, revision and export rows together from the primary, outside any caller transaction. */
    public function captureForExport(\DateTimeImmutable $date): array
    {
        if ($this->connection->isTransactionActive() || !$this->connection->isAutoCommit()) {
            throw new \LogicException('Radio export requires an autocommit connection outside any transaction.');
        }
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }

        return $this->snapshot($date);
    }

    private function snapshot(\DateTimeImmutable $date): array
    {
        $week = self::week($date);
        $key = $week->format('Y-m-d');
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT v.validated, v.manual_revision,
                   d.id AS diffusion_id, d.horaire_diffusion, e.id AS emission_id,
                   e.titre AS emission_title, c.slug AS category_code, e.thumbnail_mp3, e.url,
                   e.is_live, (e.deleted_at IS NOT NULL) AS deleted
              FROM (SELECT ? AS week_start) w
              LEFT JOIN grid_week_validation v ON v.week_start = w.week_start
              LEFT JOIN diffusion d ON d.horaire_diffusion >= ? AND d.horaire_diffusion < ?
                                   AND d.publication_status = ?
              LEFT JOIN emission e ON e.id = d.emission_id
              LEFT JOIN categories c ON c.id = e.categorie_id
             ORDER BY d.id
            SQL, [
            $key,
            $week->format('Y-m-d H:i:s'),
            $week->modify('+7 days')->format('Y-m-d H:i:s'),
            \App\Entity\Diffusion::STATUS_PUBLISHED,
        ]);

        // A content revision deliberately ignores intermediate unpublish/republish writes,
        // unpublished drafts, editorial fields and timestamps unrelated to the export.
        $validated = (bool) $rows[0]['validated'];
        $manualRevision = (string) ($rows[0]['manual_revision'] ?? '0');
        $occurrences = [];
        foreach ($rows as $row) {
            if ($row['diffusion_id'] !== null) {
                unset($row['validated'], $row['manual_revision']);
                $occurrences[] = $row;
            }
        }

        return [
            'weekStart' => $week,
            'rows' => $occurrences,
            'validated' => $validated,
            'revision' => hash('sha256', serialize([$key, $manualRevision, $occurrences])),
        ];
    }

    public function isCurrent(\DateTimeImmutable $date, string $revision): bool
    {
        $state = $this->read($date);

        return $state['validated'] && hash_equals($state['revision'], $revision);
    }

    private static function week(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return RadioWeek::start($date->setTimezone(new \DateTimeZone('Europe/Paris')));
    }
}
