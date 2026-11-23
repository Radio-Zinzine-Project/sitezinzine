<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Transfer;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationOrchestrator;
use App\Service\AudioLibrary\RadioExport\RadioExportDestination;

final readonly class RadioTransferWorklist
{
    public function __construct(private RadioPreparationOrchestrator $preparations, private RadioTransferJournal $journal) {}

    /** Reads an explicitly selected preparation and the latest declarations for one logical destination. */
    public function read(
        string $root,
        \DateTimeImmutable $week,
        string $attemptId,
        string $destination,
        bool $allowHistorical = false,
    ): array {
        if (!RadioExportDestination::accepts($destination)) {
            throw new \InvalidArgumentException('Invalid radio export destination key.');
        }
        $snapshot = $this->preparations->read($root, $week, $attemptId);
        $confirmations = [];
        if ($snapshot !== null && ($snapshot['current'] || $allowHistorical)) {
            $report = $snapshot['report'];
            $names = array_column($report['entries'], 'filename');
            foreach ($report['differential']['removed'] ?? [] as $row) {
                $names[] = $row['artifact']['filename'] ?? $row['filename'];
            }
            $confirmations = $this->journal->latestForFiles($destination, array_filter($names, 'is_string'));
        }

        return self::evaluate($snapshot, $destination, $confirmations, $allowHistorical);
    }

    /**
     * Pure projection of an observation accepted by the preparation reader and journal declarations.
     * No route or filesystem path is exposed as a download authorization.
     */
    public static function evaluate(?array $snapshot, string $destination, array $confirmations, bool $allowHistorical = false): array
    {
        $report = $snapshot['report'] ?? null;
        $current = $snapshot['current'] ?? false;
        $result = [
            'status' => 'preparation_required', 'destination' => $destination,
            'week' => $report['week'] ?? null, 'attemptId' => $report['attemptId'] ?? null,
            'revision' => $report['revision'] ?? null, 'lines' => [], 'removed' => [],
        ];
        if (!$current && (!$allowHistorical || $report === null)) {
            return $result;
        }

        $result['status'] = $current ? 'current' : 'historical';
        $latest = [];
        foreach ($confirmations as $event) {
            $name = $event['filename'];
            if ($event['destination_key'] === $destination && (!isset($latest[$name])
                || self::isLater((string) $event['id'], (string) $latest[$name]['id']))) {
                $latest[$name] = $event;
            }
        }

        $artifacts = array_column($report['artifacts'], null, 'diffusionId');
        $expected = array_column($report['differential']['expected'] ?? [], null, 'diffusionId');
        $invalidOrigins = array_fill_keys($snapshot['unavailableOrigins'] ?? [], true);
        $entries = array_column($report['entries'], null, 'diffusionId');
        ksort($entries, SORT_NUMERIC);
        foreach ($entries as $id => $entry) {
            $row = $expected[$id] ?? null;
            $origin = $row['origin'] ?? null;
            $artifact = $artifacts[$id] ?? $row['artifact'] ?? null;
            $available = $artifact !== null && !isset($invalidOrigins[$id]);
            $name = $entry['filename'];
            $event = $latest[$name] ?? null;
            $issues = $entry['issues'];
            $sourceIssue = $report['sourceIssues'][$id] ?? $row['issue'] ?? null;
            if ($sourceIssue !== null) {
                $issues[] = $sourceIssue;
            }
            if (isset($invalidOrigins[$id])) {
                $issues[] = 'origin_unavailable';
            }

            $result['lines'][] = [
                'diffusionId' => $id, 'occurrence' => $entry, 'filename' => $name, 'sha256' => $artifact['sha256'] ?? null,
                'admission' => $entry['admission'], 'changes' => $row['changes'] ?? [],
                'transferState' => match (true) {
                    $entry['admission'] === 'excluded' => 'excluded',
                    !$available => 'unavailable',
                    $event === null => 'to_transfer',
                    hash_equals($artifact['sha256'], $event['sha256']) => 'transferred',
                    default => 'new_version',
                },
                'confirmation' => $event,
                'zip' => $available ? ['attemptId' => $origin['attemptId'] ?? $report['attemptId'], 'path' => 'audio.zip'] : null,
                'issues' => array_values(array_unique($issues)),
            ];
        }

        $removed = array_column($report['differential']['removed'] ?? [], null, 'diffusionId');
        ksort($removed, SORT_NUMERIC);
        foreach ($removed as $row) {
            $name = $row['artifact']['filename'] ?? $row['filename'];
            $result['removed'][] = $row + ['confirmation' => $latest[$name] ?? null];
        }

        return $result;
    }

    private static function isLater(string $candidate, string $existing): bool
    {
        // The unsigned BIGINT event ID can exceed the native integer range.
        return (strlen($candidate) <=> strlen($existing) ?: strcmp($candidate, $existing)) > 0;
    }
}
