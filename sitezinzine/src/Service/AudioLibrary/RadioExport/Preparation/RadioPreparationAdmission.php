<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\RadioWeek;

/** Classifies declared sources. A candidate still requires a successful physical read. */
final class RadioPreparationAdmission
{
    public function admit(array $manifest): array
    {
        $start = $manifest['weekStart'];
        $end = $manifest['weekEnd'];
        if ($start->getTimezone()->getName() !== 'Europe/Paris' || $end->getTimezone()->getName() !== 'Europe/Paris'
            || $start != RadioWeek::start($start) || $end != $start->modify('+7 days')) {
            throw new \InvalidArgumentException('A preparation requires a canonical radio week in Europe/Paris.');
        }

        $ids = $names = [];
        foreach ($manifest['entries'] as $entry) {
            $id = $entry['diffusionId'];
            if (!is_int($id) || $id < 1 || isset($ids[$id])) {
                throw new \InvalidArgumentException('Duplicate or invalid diffusion identifier.');
            }
            if ($entry['startsAt'] < $start || $entry['startsAt'] >= $end) {
                throw new \InvalidArgumentException('A diffusion is outside the selected radio week.');
            }
            $ids[$id] = true;
            $name = $entry['filename'];
            if ($name !== null) {
                $names[$name] = ($names[$name] ?? 0) + 1;
            }
        }

        $entries = $candidateIds = [];
        foreach ($manifest['entries'] as $entry) {
            $name = $entry['filename'];
            $path = $entry['mp3Path'];
            $urlPath = $entry['localUrlPath'];
            $issues = $entry['issues'];
            $namingIssue = match (true) {
                $name === null => 'missing_filename',
                strlen($name) > 255 => 'filename_too_long',
                preg_match("/\A[A-Za-z0-9][A-Za-z0-9'-]*\.mp3\z/", $name) !== 1 => 'unsafe_filename',
                $names[$name] > 1 || in_array('filename_collision', $issues, true) => 'filename_collision',
                default => null,
            };
            $present = $path !== null && $path !== '';
            $excluded = match (true) {
                $entry['deleted'] => 'deleted',
                $entry['live'] && !$present && $urlPath === null => 'live_without_mp3',
                $entry['audioKind'] === 'external_url' => 'external_url',
                default => null,
            };
            $sourceIssue = match (true) {
                $urlPath !== null && (!$present || $urlPath !== $path) => 'source_needs_resolution',
                $entry['audioKind'] === 'uncertain_url' => 'uncertain_url',
                !$present => 'no_audio_reference',
                default => null,
            };

            $entry['admission'] = match (true) {
                $excluded !== null => 'excluded',
                $namingIssue !== null => 'naming',
                $sourceIssue !== null => 'unavailable',
                default => 'candidate',
            };
            foreach ([$excluded, $namingIssue, $excluded === null ? $sourceIssue : null] as $issue) {
                if ($issue !== null && !in_array($issue, $issues, true)) {
                    $issues[] = $issue;
                }
            }
            $entry['issues'] = $issues;
            $entries[] = $entry;
            if ($entry['admission'] === 'candidate') {
                $candidateIds[] = $entry['diffusionId'];
            }
        }

        return ['weekStart' => $start, 'weekEnd' => $end, 'entries' => $entries, 'candidateIds' => $candidateIds];
    }
}
