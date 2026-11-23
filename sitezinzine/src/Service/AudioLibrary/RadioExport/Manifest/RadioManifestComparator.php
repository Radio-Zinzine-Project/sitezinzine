<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Manifest;

/** Compares revisions of the same week. Groups contain diffusion IDs and may overlap. */
final class RadioManifestComparator
{
    /** @return array<string, list<int>> */
    public function compare(array $previous, array $current): array
    {
        if ($previous['weekStart'] != $current['weekStart'] || $previous['weekEnd'] != $current['weekEnd']) {
            throw new \InvalidArgumentException('Only manifests for the same radio week can be compared.');
        }

        $before = array_column($previous['entries'], null, 'diffusionId');
        $after = array_column($current['entries'], null, 'diffusionId');
        ksort($before, SORT_NUMERIC);
        ksort($after, SORT_NUMERIC);
        $diff = [
            'added' => array_keys(array_diff_key($after, $before)),
            'removed' => array_keys(array_diff_key($before, $after)),
            'renamed' => [], 'audioChanged' => [], 'scheduleChanged' => [], 'emissionChanged' => [],
            'statusChanged' => [], 'unchanged' => [],
        ];

        $fields = [
            'renamed' => ['filename'],
            'audioChanged' => ['mp3Path', 'url', 'localUrlPath', 'audioKind'],
            'scheduleChanged' => ['startsAt'],
            'emissionChanged' => ['emissionId', 'emissionTitle', 'categoryCode'],
            'statusChanged' => ['live', 'deleted', 'issues'],
        ];
        foreach (array_intersect_key($after, $before) as $id => $new) {
            $old = $before[$id];
            $changed = false;
            foreach ($fields as $group => $keys) {
                foreach ($keys as $key) {
                    $different = $key === 'startsAt' ? $old[$key] != $new[$key] : $old[$key] !== $new[$key];
                    if ($different) {
                        $diff[$group][] = $id;
                        $changed = true;
                        break;
                    }
                }
            }
            if (!$changed) {
                $diff['unchanged'][] = $id;
            }
        }

        return $diff;
    }
}
