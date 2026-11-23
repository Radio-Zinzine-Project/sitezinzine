<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class Mp3TrashHistory
{
    private const PAGE_SIZE = 25;

    public function __construct(private Connection $db) {}

    public function read(string $operationId, ?string $before, ?string $after): ?array
    {
        if ($this->db->fetchOne('SELECT id FROM mp3_trash_operation WHERE id = ?', [$operationId]) !== $operationId) {
            return null;
        }

        $sql = <<<'SQL'
            SELECT a.id, a.request_id, a.actor_id, a.attempt_id, a.action, a.expected_phase,
                   a.observed_phase, a.event_type, a.result_code, a.recorded_at,
                   COALESCE(NULLIF(u.pseudo, ''), u.username) AS actor_name
            FROM mp3_administrative_event a
            LEFT JOIN `user` u ON u.id = a.actor_id
            WHERE a.operation_id = :operation
            SQL;
        $params = ['operation' => $operationId];
        $types = ['operation' => ParameterType::STRING];
        if ($before !== null || $after !== null) {
            $sql .= $after === null ? ' AND a.id < CAST(:cursor AS UNSIGNED)' : ' AND a.id > CAST(:cursor AS UNSIGNED)';
            $params['cursor'] = $after ?? $before;
            $types['cursor'] = ParameterType::STRING;
        }
        $sql .= $after === null ? ' ORDER BY a.id DESC LIMIT :limit' : ' ORDER BY a.id ASC LIMIT :limit';
        $params['limit'] = self::PAGE_SIZE + 1;
        $types['limit'] = ParameterType::INTEGER;
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        if ($after !== null) {
            $rows = array_reverse($rows);
        }
        $first = isset($rows[0]) ? (string) $rows[0]['id'] : null;
        $last = $rows !== [] ? (string) $rows[array_key_last($rows)]['id'] : null;
        $utc = new \DateTimeZone('UTC');
        foreach ($rows as &$row) {
            $row['recorded_at'] = new \DateTimeImmutable($row['recorded_at'], $utc);
        }
        unset($row);

        return [
            'rows' => $rows,
            'newer' => ($after !== null ? $hasMore : $before !== null) ? $first : null,
            'older' => ($after !== null || $hasMore) ? $last : null,
        ];
    }
}
