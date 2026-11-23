<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Enum\Mp3TrashPhase;

/** A display check only. The restore service revalidates the operation under lock. */
final class TrashRestoreAvailability
{
    public static function canPrepare(?array $inspection): bool
    {
        $journal = $inspection['journal'] ?? null;
        $emission = $inspection['emission'] ?? null;
        if ($journal === null || ($journal['phase'] ?? null) !== Mp3TrashPhase::EN_CORBEILLE->value
            || ($journal['blocked'] ?? null) !== false || !array_key_exists('last_attempt_id', $journal)
            || ($journal['emission_id'] ?? 0) <= 0 || ($inspection['diagnostic']['status'] ?? null) !== 'completed'
            || ($emission['exists'] ?? null) !== true || !array_key_exists('deleted_at', $emission)
            || $emission['deleted_at'] !== null || !array_key_exists('thumbnail_mp3', $emission)
            || !array_key_exists('url', $emission)) {
            return false;
        }
        $lastAttempt = $journal['last_attempt_id'];

        return ($lastAttempt === null || (is_string($lastAttempt) && preg_match('/\A[a-f0-9]{32}\z/', $lastAttempt) === 1))
            && in_array($emission['thumbnail_mp3'], [null, ''], true) && in_array($emission['url'], [null, ''], true)
            && ($inspection['disk']['public']['present'] ?? null) === false
            && ($inspection['disk']['private']['matches_journal'] ?? null) === true;
    }
}
