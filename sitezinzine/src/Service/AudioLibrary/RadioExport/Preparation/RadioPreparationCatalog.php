<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\Mp3StoragePath;
use App\Service\RadioWeek;

final readonly class RadioPreparationCatalog
{
    public function __construct(private RadioPreparationPublisher $publisher) {}

    /** The root is deployment configuration. Only sanitized rows are intended for display. */
    public function read(string $root, \DateTimeImmutable $week): array
    {
        if ($week->getTimezone()->getName() !== 'Europe/Paris' || $week != RadioWeek::start($week)) {
            throw new \InvalidArgumentException('A canonical Paris Tuesday is required.');
        }
        $result = ['rows' => [], 'reference' => null, 'orderUncertain' => false];
        $root = Mp3StoragePath::directory($root);
        self::requirePrivateDirectory($root);
        $directory = Mp3StoragePath::resolve($root, $week->format('Y-m-d'));
        clearstatcache(true, $directory);
        if (@lstat($directory) === false) {
            return $result;
        }
        self::requirePrivateDirectory($directory);

        $reports = $rows = [];
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $id = $entry->getFilename();
            if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $id) !== 1) {
                continue;
            }
            $attempt = $entry->getPathname();
            $validDirectory = !$entry->isLink() && $entry->isDir() && self::isPrivateDirectory($attempt);
            $report = $validDirectory ? $this->publisher->read($root, $week, $id) : null;
            $reports[$id] = $report;
            $marker = $validDirectory ? self::markerTime($attempt . '/result.json') : null;
            $failure = $validDirectory ? self::markerTime($attempt . '/failure.json') : null;
            $rows[$id] = [
                'attemptId' => $id, 'revision' => $report['revision'] ?? null, 'recordMtime' => $marker ?? $failure,
                'type' => match (true) {
                    $report === null => null,
                    $report['status'] === 'report_only' => 'report_only',
                    isset($report['differential']['referenceAttemptId']) => 'differential',
                    default => 'complete',
                },
                'status' => match (true) {
                    $report !== null => 'published',
                    $failure !== null => 'failed',
                    !$validDirectory || $marker !== null => 'unusable',
                    default => 'incomplete',
                },
                'fileCount' => $report === null ? null : count($report['artifacts']),
                'anomalyCount' => $report === null ? null : count(array_filter($report['entries'], static fn (array $row): bool =>
                    $row['issues'] !== [] || isset($report['sourceIssues'][$row['diffusionId']]))),
                'archiveAvailable' => ($report['status'] ?? null) === 'ready',
                'reportAvailable' => $report !== null && RadioPreparationPublisher::isReportDownloadable($report),
            ];
        }

        $artifactIndexes = [];
        foreach (array_filter($reports) as $id => $report) {
            $artifactIndexes[$id] = array_column($report['artifacts'], null, 'diffusionId');
        }
        $dependencies = $usable = [];
        foreach (array_filter($reports) as $id => $report) {
            $base = $report['differential']['referenceAttemptId'] ?? null;
            if ($base !== null && (!is_string($base) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $base) !== 1)) {
                $rows[$id]['status'] = 'unusable';
                continue;
            }
            $dependencies[$id] = $base !== null && isset($reports[$base]) ? [$base => true] : [];
            foreach ($report['differential']['expected'] ?? [] as $item) {
                $origin = $item['origin'];
                if ($origin === null) {
                    continue;
                }
                if (($artifactIndexes[$origin['attemptId']][$origin['artifact']['diffusionId']] ?? null) !== $origin['artifact']) {
                    $rows[$id]['status'] = 'origin_unavailable';
                } else {
                    // The direct origin had to be published before this report reused its verified bytes.
                    $dependencies[$id][$origin['attemptId']] = true;
                }
            }
            if ($rows[$id]['status'] === 'published') {
                $usable[$id] = $report;
            }
        }
        $ancestors = [];
        foreach ($dependencies as $id => $_) {
            $ancestors[$id] = self::ancestors($dependencies, (string) $id, [], $ancestors);
            if ($ancestors[$id] === null) {
                $rows[$id]['status'] = 'unusable';
                unset($usable[$id]);
            }
        }

        foreach ($usable as $id => $report) {
            if (array_diff_key($usable, $ancestors[$id] + [$id => true]) === []) {
                $result['reference'] = $report;
                break;
            }
        }
        $result['orderUncertain'] = count($usable) > 1 && $result['reference'] === null;
        usort($rows, static function (array $a, array $b) use ($ancestors): int {
            // Depth respects a verified dependency. Marker times only order unrelated rows for display.
            return (count($ancestors[$b['attemptId']] ?? []) <=> count($ancestors[$a['attemptId']] ?? []))
                ?: ($b['recordMtime'] <=> $a['recordMtime']) ?: strcmp($b['attemptId'], $a['attemptId']);
        });
        $result['rows'] = $rows;

        return $result;
    }

    private static function ancestors(array $dependencies, string $id, array $visiting, array &$cache): ?array
    {
        if (isset($visiting[$id])) {
            return null;
        }
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        $visiting[$id] = true;
        $ancestors = $dependencies[$id] ?? [];
        foreach (array_keys($ancestors) as $parent) {
            $chain = self::ancestors($dependencies, (string) $parent, $visiting, $cache);
            if ($chain === null) {
                return null;
            }
            $ancestors += $chain;
        }

        return $cache[$id] = $ancestors;
    }

    private static function markerTime(string $path): ?int
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        return $stat !== false && ($stat['mode'] & 0170000) === 0100000 ? $stat['mtime'] : null;
    }

    private static function requirePrivateDirectory(string $path): void
    {
        if (!self::isPrivateDirectory($path)) {
            throw new \RuntimeException('Radio export catalog requires a private storage directory.');
        }
    }

    private static function isPrivateDirectory(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        return $stat !== false && ($stat['mode'] & 0177777) === 0040700 && function_exists('posix_geteuid')
            && $stat['uid'] === posix_geteuid();
    }
}
