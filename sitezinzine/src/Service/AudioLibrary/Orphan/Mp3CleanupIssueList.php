<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class Mp3CleanupIssueList
{
    public const PAGE_SIZE = 25;

    public function __construct(private Connection $db) {}

    public function read(int $page): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, storage, obsolete_relative_path, status, created_at FROM mp3_cleanup_issue
             ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['limit' => self::PAGE_SIZE + 1, 'offset' => ($page - 1) * self::PAGE_SIZE],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
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
