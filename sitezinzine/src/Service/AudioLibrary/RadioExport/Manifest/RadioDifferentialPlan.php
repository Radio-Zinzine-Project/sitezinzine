<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Manifest;

use App\Service\RadioWeek;

/** Compares captured file identities. No disk or transfer state is consulted here. */
final class RadioDifferentialPlan
{
    /**
     * Each occurrence has a diffusionId, filename, admission, artifact (verified capture or null)
     * and issue (code or null). The reference is a previous plan's complete expected state.
     *
     * Supply only reports accepted by RadioPreparationPublisher::read() in $verifiedReports.
     * A raw result.json, a previous plan or a ZIP path alone is not integrity evidence.
     */
    public function plan(
        string $week,
        string $attemptId,
        string $revision,
        array $occurrences,
        ?array $reference = null,
        array $verifiedReports = [],
    ): array {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $week) !== 1) {
            throw new \InvalidArgumentException('Invalid differential preparation week.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $week, new \DateTimeZone('Europe/Paris'));
        if ($date === false || RadioWeek::start($date)->format('Y-m-d') !== $week
            || !self::isAttemptId($attemptId) || !self::isDigest($revision)) {
            throw new \InvalidArgumentException('Invalid differential preparation context.');
        }
        if ($reference !== null && (($reference['week'] ?? null) !== $week
            || !self::isAttemptId($reference['attemptId'] ?? null) || $reference['attemptId'] === $attemptId
            || !self::isDigest($reference['revision'] ?? null) || !is_array($reference['expected'] ?? null))) {
            throw new \InvalidArgumentException('A differential reference must be a distinct preparation of the same week.');
        }

        $current = self::indexOccurrences($occurrences);
        $previous = self::indexOccurrences($reference['expected'] ?? [], withProvenance: true);
        $verified = self::verifiedArtifacts($week, $attemptId, $verifiedReports);
        $expected = $copyIds = $removed = [];
        foreach ($current as $id => $row) {
            $old = $previous[$id] ?? null;
            $artifact = $row['artifact'];
            $before = $old['artifact'] ?? null;
            $changes = self::changes($old, $row);
            $origin = null;
            if ($artifact !== null) {
                if ($changes === ['unchanged']) {
                    $owner = $old['origin']['attemptId'] ?? $reference['attemptId'];
                    if (($verified[$owner][$id] ?? null) === $artifact) {
                        $origin = ['attemptId' => $owner, 'artifact' => $artifact];
                    }
                }
                if ($origin === null) {
                    $copyIds[] = $id;
                }
            }
            if ($row['admission'] === 'excluded' && $old !== null && $old['admission'] !== 'excluded') {
                $removed[$id] = $old;
            }
            $expected[] = $row + ['previous' => $before, 'changes' => $changes, 'origin' => $origin];
        }

        foreach (array_diff_key($previous, $current) as $id => $old) {
            if ($old['admission'] !== 'excluded') {
                $removed[$id] = $old;
            }
        }
        ksort($removed, SORT_NUMERIC);

        return [
            'week' => $week, 'attemptId' => $attemptId, 'revision' => $revision,
            'referenceAttemptId' => $reference['attemptId'] ?? null,
            'expected' => $expected, 'copyIds' => $copyIds, 'removed' => array_values($removed),
        ];
    }

    private static function changes(?array $old, array $row): array
    {
        if ($row['admission'] === 'excluded') {
            return ['excluded'];
        }
        $artifact = $row['artifact'];
        if ($artifact === null) {
            return ['unavailable'];
        }
        if ($old === null || $old['admission'] === 'excluded') {
            return ['added'];
        }

        $before = $old['artifact'];
        $changes = [];
        if ($old['filename'] !== null && $old['filename'] !== $row['filename']) {
            $changes[] = 'renamed';
        }
        if ($before === null) {
            $changes[] = 'newly_available';
        } elseif ($before['sha256'] !== $artifact['sha256'] || $before['size'] !== $artifact['size']) {
            $changes[] = 'replaced';
        }

        return $changes === [] ? ['unchanged'] : $changes;
    }

    private static function indexOccurrences(array $rows, bool $withProvenance = false): array
    {
        $index = $names = [];
        foreach ($rows as $row) {
            $id = $row['diffusionId'] ?? null;
            $name = $row['filename'] ?? null;
            $artifact = $row['artifact'] ?? null;
            $admission = $row['admission'] ?? null;
            $issue = $row['issue'] ?? null;
            if (!is_int($id) || $id < 1 || isset($index[$id]) || ($name !== null && !is_string($name))
                || !in_array($admission, ['candidate', 'excluded', 'unavailable', 'naming'], true)
                || ($issue !== null && !is_string($issue))) {
                throw new \InvalidArgumentException('Invalid or repeated differential occurrence.');
            }
            $file = self::artifact($artifact);
            if ($artifact !== null && ($file === null || $file['diffusionId'] !== $id || $file['filename'] !== $name
                || $admission !== 'candidate' || isset($names[$name]))) {
                throw new \InvalidArgumentException('Invalid, mismatched or repeated captured artifact.');
            }
            if ($file !== null) {
                $names[$name] = true;
            }
            $index[$id] = [
                'diffusionId' => $id, 'filename' => $name, 'admission' => $admission,
                'artifact' => $file, 'issue' => $issue,
            ];
            if ($withProvenance && isset($row['origin'])) {
                $owner = $row['origin']['attemptId'] ?? null;
                if (!self::isAttemptId($owner) || $file === null || self::artifact($row['origin']['artifact'] ?? null) !== $file) {
                    throw new \InvalidArgumentException('Invalid reference provenance.');
                }
                $index[$id]['origin'] = ['attemptId' => $owner, 'artifact' => $file];
            }
        }
        ksort($index, SORT_NUMERIC);

        return $index;
    }

    private static function verifiedArtifacts(string $week, string $attemptId, array $reports): array
    {
        $verified = [];
        foreach ($reports as $report) {
            $id = $report['attemptId'] ?? null;
            if (!self::isAttemptId($id) || $id === $attemptId || ($report['week'] ?? null) !== $week
                || !in_array($report['version'] ?? null, [1, 2, 3], true)
                || ($report['version'] >= 2 && !self::isDigest($report['revision'] ?? null))
                || ($report['status'] ?? null) !== 'ready' || ($report['archive']['path'] ?? null) !== 'audio.zip'
                || !self::isDigest($report['archive']['sha256'] ?? null) || !is_int($report['archive']['size'] ?? null)
                || $report['archive']['size'] < 1 || !is_array($report['artifacts'] ?? null)) {
                continue;
            }
            if (isset($verified[$id])) {
                throw new \InvalidArgumentException('Repeated verified preparation.');
            }
            $entries = [];
            foreach ($report['artifacts'] as $entry) {
                $file = self::artifact($entry);
                if ($file === null || isset($entries[$file['diffusionId']])) {
                    continue 2;
                }
                $entries[$file['diffusionId']] = $file;
            }
            $verified[$id] = $entries;
        }

        return $verified;
    }

    private static function artifact(mixed $value): ?array
    {
        if (!is_array($value) || !is_int($value['diffusionId'] ?? null) || $value['diffusionId'] < 1
            || !is_string($value['filename'] ?? null) || strlen($value['filename']) > 255
            || preg_match("/\A[A-Za-z0-9][A-Za-z0-9'-]*\.mp3\z/", $value['filename']) !== 1
            || !self::isDigest($value['sha256'] ?? null) || !is_int($value['size'] ?? null) || $value['size'] < 0) {
            return null;
        }

        return ['diffusionId' => $value['diffusionId'], 'filename' => $value['filename'],
            'size' => $value['size'], 'sha256' => $value['sha256']];
    }

    private static function isDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private static function isAttemptId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $value) === 1;
    }
}
