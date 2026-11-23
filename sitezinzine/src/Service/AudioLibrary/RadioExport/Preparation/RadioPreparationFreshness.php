<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

final readonly class RadioPreparationFreshness
{
    public function __construct(private RadioPreparationCopier $sources) {}

    /**
     * $report must come from a verified preparation. $gridCurrent is a fresh observation of its revision.
     * Optionally supply freshly admitted entries to also compare the output of the naming and admission rules.
     * The result describes observations made during this call.
     */
    public function check(string $library, ?array $report, bool $gridCurrent, bool $full = false, ?array $currentEntries = null): array
    {
        if ($report === null || !$gridCurrent) {
            return ['status' => 'preparation_required', 'issues' => []];
        }
        $entries = array_column($report['entries'], null, 'diffusionId');
        if ($currentEntries !== null && self::expectations($entries) !== self::expectations(array_column($currentEntries, null, 'diffusionId'))) {
            return ['status' => 'change_detected', 'issues' => []];
        }

        $artifacts = array_column($report['artifacts'], null, 'diffusionId');
        $expected = array_column($report['differential']['expected'] ?? [], null, 'diffusionId');
        $observations = $issues = [];
        $changed = $unavailable = false;
        foreach ($entries as $id => $entry) {
            if ($entry['admission'] !== 'candidate') {
                continue;
            }
            $relative = $entry['mp3Path'];
            $observed = $observations[$relative] ??= $this->observe($library, $relative, $full);
            if (is_string($observed)) {
                $issues[$id] = $observed;
                $changed = $changed || $observed === 'source_changed';
                $unavailable = true;
                continue;
            }

            $artifact = $artifacts[$id] ?? $expected[$id]['artifact'] ?? null;
            if ($full) {
                if ($artifact === null || !hash_equals($artifact['sha256'], $observed['sha256'])) {
                    $changed = true;
                    $issues[$id] = $artifact === null ? 'source_now_available' : 'source_bytes_changed';
                }
                continue;
            }

            $baseline = $report['sourceSignatures'][$id] ?? null;
            if ($artifact === null || $observed['signature']['size'] !== $artifact['size'] || ($baseline !== null && $baseline !== $observed['signature'])) {
                $issues[$id] = 'source_signature_changed';
            }
        }

        return ['status' => match (true) {
            $changed => 'change_detected',
            $unavailable => 'source_unavailable',
            $full => 'current_verified',
            default => 'verification_required',
        }, 'issues' => $issues];
    }

    private function observe(string $library, string $relative, bool $full): array|string
    {
        try {
            return $this->sources->probeSource($library, $relative, $full);
        } catch (\RuntimeException) {
            return 'source_check_failed';
        }
    }

    private static function expectations(array $entries): array
    {
        ksort($entries, SORT_NUMERIC);

        return array_map(static fn (array $entry): array => [$entry['filename'], $entry['admission'], $entry['mp3Path']], $entries);
    }
}
