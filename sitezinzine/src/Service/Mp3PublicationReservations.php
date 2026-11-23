<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class Mp3PublicationReservations
{
    public static function hasActiveForEmission(Connection $connection, int $emissionId): bool
    {
        return $connection->fetchOne(
            'SELECT id FROM mp3_trash_operation WHERE emission_id = ? AND active_marker = 1 FOR SHARE',
            [$emissionId],
        ) !== false;
    }

    /**
     * Read once on the writing connection, in the transaction covered by the
     * storage lock. FOR SHARE sees current rows even inside an outer snapshot.
     *
     * @return array<string, true>
     */
    public static function read(Connection $connection): array
    {
        return array_fill_keys($connection->fetchFirstColumn(
            'SELECT original_thumbnail_mp3 FROM mp3_trash_operation WHERE active_marker = 1 FOR SHARE'
        ), true);
    }
}
