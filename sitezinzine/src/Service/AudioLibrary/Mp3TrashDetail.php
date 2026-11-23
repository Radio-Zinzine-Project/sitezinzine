<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary;

use App\Service\Mp3TrashInspection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

class Mp3TrashDetail
{
    /** @param \Closure(): Connection $connect */
    public function __construct(private readonly Mp3TrashInspection $inspection, private readonly \Closure $connect) {}

    public function read(string $operationId): ?array
    {
        $db = ($this->connect)();
        try {
            $inspection = $this->inspection->inspect($db, $operationId);
            if ($inspection === null) {
                return null;
            }
            $openedAt = null;
            // The inspection contract does not expose the immutable opening date.
            if ($inspection['journal'] !== null) {
                try {
                    $value = $db->fetchOne('SELECT created_at FROM mp3_trash_operation WHERE id = ?', [$operationId]);
                    $openedAt = is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
                } catch (Exception) {
                    // Keep the observations already obtained and leave the date unknown.
                }
            }

            return ['inspection' => $inspection, 'opened_at' => $openedAt];
        } finally {
            $db->close();
        }
    }
}
