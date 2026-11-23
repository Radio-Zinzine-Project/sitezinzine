<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Manifest;

/** Builds a consultative manifest from WeeklyAudioSelection::select(). */
final readonly class RadioManifestBuilder
{
    public function __construct(private RadioFilename $filenames) {}

    public function build(array $selection): array
    {
        $byId = [];
        $nameCounts = [];
        foreach ($selection['occurrences'] as $occurrence) {
            $id = $occurrence['diffusionId'];
            if (!is_int($id) || $id < 1 || isset($byId[$id])) {
                throw new \InvalidArgumentException('A manifest requires distinct persisted diffusion identifiers.');
            }
            $name = $this->filenames->format($occurrence['categoryCode'], $occurrence['startsAt'], $occurrence['emissionTitle']);
            $filename = $name['filename'];
            $issues = $name['issues'];
            if ($filename !== null) {
                $nameCounts[$filename] = ($nameCounts[$filename] ?? 0) + 1;
            }
            if ($occurrence['audioKind'] !== 'local_reference') {
                $issues[] = $occurrence['audioKind'] === 'none' ? 'no_audio_reference' : $occurrence['audioKind'];
            }
            if ($occurrence['live']) {
                $issues[] = 'live';
            }
            if ($occurrence['deleted']) {
                $issues[] = 'deleted';
            }
            $byId[$id] = $occurrence + ['filename' => $filename, 'issues' => $issues];
        }

        $entries = array_values($byId);
        foreach ($entries as &$entry) {
            if ($entry['filename'] !== null && $nameCounts[$entry['filename']] > 1) {
                $entry['issues'][] = 'filename_collision';
            }
        }
        unset($entry);
        usort($entries, static fn (array $a, array $b): int =>
            ($a['startsAt'] <=> $b['startsAt']) ?: ($a['diffusionId'] <=> $b['diffusionId']));

        return ['weekStart' => $selection['weekStart'], 'weekEnd' => $selection['weekEnd'], 'entries' => $entries];
    }
}
