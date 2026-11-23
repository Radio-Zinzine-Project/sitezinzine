<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use Doctrine\DBAL\Connection;

/** Shared reference checks. The caller owns the audio lock and the SQL read context. */
final class Mp3CleanupReferences
{
    public static function inspect(Connection $db, string $path, AudioLocalUrlResolver $localUrls): Mp3DeferredCleanupDecision
    {
        // An emission keeps its audio references even after a logical deletion.
        $reference = self::hasRows($db->fetchOne('SELECT COUNT(*) FROM emission WHERE thumbnail_mp3 = ?', [$path]));
        if ($reference) {
            return Mp3DeferredCleanupDecision::ReferenceActive;
        }
        $reservation = self::hasRows($db->fetchOne(
            'SELECT COUNT(*) FROM mp3_trash_operation WHERE active_marker = 1 AND original_thumbnail_mp3 = ?', [$path],
        ));
        if ($reservation) {
            return Mp3DeferredCleanupDecision::ReservationActive;
        }

        return self::inspectUrls($db, $path, $localUrls);
    }

    /** Checks URL-only references, including references on logically deleted emissions. */
    public static function inspectUrls(Connection $db, string $path, AudioLocalUrlResolver $localUrls, ?int $excludedEmission = null): Mp3DeferredCleanupDecision
    {
        $sql = 'SELECT url FROM emission WHERE url IS NOT NULL AND url <> ?';
        $params = [''];
        if ($excludedEmission !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludedEmission;
        }
        $ambiguous = false;
        foreach ($db->iterateColumn($sql, $params) as $url) {
            if (!is_string($url)) {
                return Mp3DeferredCleanupDecision::Indeterminate;
            }
            $resolved = $localUrls->resolve($url);
            if ($resolved['status'] === 'local' && $resolved['relative_path'] === $path) {
                return Mp3DeferredCleanupDecision::ReferenceActive;
            }
            $ambiguous = $ambiguous || $resolved['status'] === 'uncertain';
        }

        if ($ambiguous) {
            return Mp3DeferredCleanupDecision::Indeterminate;
        }

        return Mp3DeferredCleanupDecision::PreconditionsVerified;
    }

    private static function hasRows(mixed $count): bool
    {
        if ((!is_int($count) || $count < 0) && (!is_string($count) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $count) !== 1)) {
            throw new \UnexpectedValueException('Invalid MP3 reference or reservation count.');
        }

        return $count !== 0 && $count !== '0';
    }
}
