<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary;

use App\Enum\Mp3TrashPhase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class Mp3TrashList
{
    public const PAGE_SIZE = 25;

    public function __construct(private Connection $db) {}

    public function read(int $page, ?Mp3TrashPhase $phase, ?bool $blocked): array
    {
        $sql = <<<'SQL'
            SELECT j.id, j.emission_id, j.created_at, j.phase, j.blocked, j.last_diagnostic_code,
                   e.id AS current_emission_id, e.titre AS emission_title, e.deleted_at
            FROM mp3_trash_operation j
            LEFT JOIN emission e ON e.id = j.emission_id
            SQL;
        $filters = [];
        $params = [];
        $types = [];
        if ($phase !== null) {
            $filters[] = 'j.phase = :phase';
            $params['phase'] = $phase->value;
            $types['phase'] = ParameterType::STRING;
        }
        if ($blocked !== null) {
            $filters[] = 'j.blocked = :blocked';
            $params['blocked'] = (int) $blocked;
            $types['blocked'] = ParameterType::INTEGER;
        }
        if ($filters !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $filters);
        }
        $sql .= ' ORDER BY j.created_at DESC, j.id DESC LIMIT :limit OFFSET :offset';
        $params['limit'] = self::PAGE_SIZE + 1;
        $params['offset'] = ($page - 1) * self::PAGE_SIZE;
        $types['limit'] = $types['offset'] = ParameterType::INTEGER;
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        $hasNext = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $utc = new \DateTimeZone('UTC');
        foreach ($rows as &$row) {
            $row['created_at'] = new \DateTimeImmutable($row['created_at'], $utc);
        }
        unset($row);

        return ['rows' => $rows, 'has_next' => $hasNext];
    }
}
