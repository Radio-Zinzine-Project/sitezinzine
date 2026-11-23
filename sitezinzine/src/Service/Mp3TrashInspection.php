<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3TrashPhase as Phase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/** Advisory inspection. SQL and disk are observed separately without a storage lock. */
final readonly class Mp3TrashInspection
{
    public function __construct(private Mp3TrashDisk $disk, private string $publicBaseUrl) {}

    /** Use the writing server on a fresh connection outside a transaction. */
    public function inspect(Connection $db, string $operationId): ?array
    {
        $privatePath = Mp3TrashOperation::trashRelativePathFor($operationId);
        if ($db->isTransactionActive()) {
            throw new \LogicException('L’inspection MP3 exige une lecture hors transaction.');
        }
        try {
            $row = $db->fetchAssociative(<<<'SQL'
                SELECT j.id, j.emission_id, j.phase, j.blocked, j.last_diagnostic_code, j.last_diagnostic_at,
                       j.original_thumbnail_mp3, j.original_url, j.size_bytes, j.sha256, j.last_attempt_id, j.restoration_attempts,
                       e.id AS current_id, e.deleted_at, e.thumbnail_mp3 AS current_path, e.url AS current_url,
                       (SELECT COUNT(*) FROM emission c
                        WHERE c.id <> j.emission_id AND (c.thumbnail_mp3 = j.original_thumbnail_mp3
                            OR c.url IN (j.original_url COLLATE utf8mb4_0900_as_ci,
                                CONCAT(?, j.original_thumbnail_mp3) COLLATE utf8mb4_0900_as_ci))) AS reference_count,
                       (SELECT COUNT(*) FROM mp3_trash_operation r
                        WHERE r.id <> j.id AND r.active_marker = 1
                          AND (r.emission_id = j.emission_id OR r.original_thumbnail_mp3 = j.original_thumbnail_mp3)) AS reservation_count
                FROM mp3_trash_operation j
                LEFT JOIN emission e ON e.id = j.emission_id
                WHERE j.id = ?
                SQL, [rtrim($this->publicBaseUrl, '/') . '/', $operationId]);
        } catch (Exception) {
            return $this->unavailable($operationId, 'sql_unavailable');
        }
        if ($row === false) {
            return null;
        }
        if ($row['id'] !== $operationId) {
            return $this->unavailable($operationId, 'journal_identity_mismatch');
        }

        $attempts = json_decode($row['restoration_attempts'], true);
        $attemptId = $row['last_attempt_id'];
        $attempt = $attemptId === null || !is_array($attempts) ? null : ($attempts[$attemptId] ?? null);
        $journal = [
            'id' => $operationId, 'phase' => $row['phase'], 'blocked' => (bool) $row['blocked'],
            'last_diagnostic_code' => $row['last_diagnostic_code'], 'last_diagnostic_at' => $row['last_diagnostic_at'],
            'block_version' => (bool) $row['blocked'] ? $row['last_diagnostic_at'] : null,
            'emission_id' => (int) $row['emission_id'], 'original_thumbnail_mp3' => $row['original_thumbnail_mp3'],
            'original_url' => $row['original_url'], 'private_path' => $privatePath,
            'size' => (int) $row['size_bytes'], 'sha256' => $row['sha256'],
            'last_attempt_id' => $attemptId, 'last_attempt' => is_array($attempt) ? $attempt : null,
        ];
        $emission = [
            'exists' => $row['current_id'] !== null, 'deleted_at' => $row['deleted_at'],
            'thumbnail_mp3' => $row['current_path'], 'url' => $row['current_url'],
            'reference_count' => (int) $row['reference_count'], 'reservation_count' => (int) $row['reservation_count'],
        ];
        $disk = $this->disk->inspect($journal['original_thumbnail_mp3'], $privatePath, $journal['size'], $journal['sha256']);
        [$status, $reason] = $this->diagnose($journal, $emission, $disk);

        return ['operation_id' => $operationId, 'journal' => $journal, 'emission' => $emission, 'disk' => $disk,
            'diagnostic' => ['status' => $status, 'reason' => $reason]];
    }

    /** @return array{string, string} */
    private function diagnose(array $journal, array $emission, array $disk): array
    {
        $phase = Phase::tryFrom($journal['phase']);
        if ($phase === null) {
            return ['indeterminate', 'unknown_phase'];
        }
        if ($phase === Phase::RESTAUREE || $phase === Phase::ANNULEE) {
            return ['completed', 'terminal_phase'];
        }
        if ($journal['blocked']) {
            return ['conflict', 'journal_blocked'];
        }
        $private = $disk['private'];
        if ($phase === Phase::EN_CORBEILLE) {
            return match (true) {
                $private['matches_journal'] === true => ['completed', 'verified_in_trash'],
                $this->invalidEntry($private) => ['conflict', 'unsafe_or_changed_private_name'],
                default => ['indeterminate', 'private_copy_unconfirmed'],
            };
        }
        if (!$emission['exists'] || $emission['deleted_at'] !== null) {
            return ['conflict', 'emission_unavailable'];
        }
        if ($emission['reference_count'] > 0 || $emission['reservation_count'] > 0) {
            return ['conflict', 'concurrent_reference_or_reservation'];
        }
        $original = $emission['thumbnail_mp3'] === $journal['original_thumbnail_mp3']
            && $emission['url'] === $journal['original_url'];
        $sqlMatches = match ($phase) {
            Phase::SUPPRESSION_PREPAREE, Phase::RETRAIT_PRIVE_EN_ATTENTE => $original,
            Phase::RETRAIT_PUBLIC_EN_ATTENTE => $emission['thumbnail_mp3'] === null && $emission['url'] === null,
            Phase::RESTAURATION_PREPAREE => in_array($emission['thumbnail_mp3'], [null, ''], true)
                && in_array($emission['url'], [null, ''], true),
        };
        if (!$sqlMatches) {
            return ['conflict', 'audio_association_changed'];
        }
        if (in_array($phase, [Phase::RESTAURATION_PREPAREE, Phase::RETRAIT_PRIVE_EN_ATTENTE], true)
            && ($journal['last_attempt']['outcome'] ?? null) !== 'pending') {
            return ['indeterminate', 'attempt_unconfirmed'];
        }
        $public = $disk['public'];
        if ($disk['collision'] === true || $this->invalidEntry($public) || $this->invalidEntry($private)) {
            return ['conflict', 'collision_or_changed_content'];
        }
        if ($public['error'] !== null || $private['error'] !== null || $disk['pair_error'] !== null) {
            return ['indeterminate', 'disk_identity_unconfirmed'];
        }
        [$required, $second] = match ($phase) {
            Phase::SUPPRESSION_PREPAREE, Phase::RETRAIT_PRIVE_EN_ATTENTE => [$public, $private],
            Phase::RESTAURATION_PREPAREE, Phase::RETRAIT_PUBLIC_EN_ATTENTE => [$private, $public],
        };
        if ($required['matches_journal'] !== true) {
            return ['indeterminate', 'required_copy_unconfirmed'];
        }
        if ($second['present'] === false && in_array($phase, [Phase::SUPPRESSION_PREPAREE, Phase::RESTAURATION_PREPAREE], true)) {
            return ['intermediate_coherent', 'before_second_link'];
        }

        return ['potentially_resumable', $second['present'] === false ? 'removal_already_observed' : 'verified_pair'];
    }

    private function invalidEntry(array $entry): bool
    {
        return in_array($entry['type'], ['symlink', 'directory', 'special'], true) || $entry['matches_journal'] === false;
    }

    private function unavailable(string $id, string $reason): array
    {
        return ['operation_id' => $id, 'journal' => null, 'emission' => null, 'disk' => null,
            'diagnostic' => ['status' => 'indeterminate', 'reason' => $reason]];
    }
}
