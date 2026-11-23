<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\AudioLibrary\RadioExport\Manifest\RadioDifferentialPlan;
use App\Service\AudioLibrary\RadioExport\Manifest\RadioManifestBuilder;
use App\Service\AudioLibrary\RadioExport\Selection\WeeklyAudioSelection;
use App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime;
use App\Service\GridWeekValidation;
use App\Service\RadioWeek;

final readonly class RadioPreparationOrchestrator
{
    public function __construct(
        private GridWeekValidation $validation,
        private WeeklyAudioSelection $selection,
        private RadioManifestBuilder $manifest,
        private RadioPreparationCopier $copier,
        private RadioPreparationPublisher $publisher,
        private RadioExportWeekLifetime $lifetime,
        private RadioDifferentialPlan $differential = new RadioDifferentialPlan(),
    ) {}

    /** @param array{library: string, work: string, trash: string, public: string} $protectedRoots */
    public function prepare(string $root, array $protectedRoots, \DateTimeImmutable $week, string $attemptId, ?string $referenceAttemptId = null, bool $requireReference = false): array
    {
        if ($week->getTimezone()->getName() !== 'Europe/Paris' || $week != RadioWeek::start($week)) {
            throw new \InvalidArgumentException('Radio preparation requires the canonical Paris Tuesday.');
        }
        $this->lifetime->requireOpen($week);
        $snapshot = $this->validation->captureForExport($week);
        if (!$snapshot['validated']) {
            throw new \DomainException('Radio preparation requires a manually validated week.');
        }

        $selection = $this->selection->fromSnapshot($snapshot['weekStart'], $snapshot['rows']);
        $this->lifetime->requireOpen($week);
        $workspace = RadioPreparationWorkspace::open($root, $protectedRoots, $this->manifest->build($selection), $attemptId);
        $plan = $referenceAttemptId === null ? null : function (array $entries, array $artifacts, array $issues) use (
            $root, $week, $attemptId, $referenceAttemptId, $snapshot, $requireReference
        ): array {
            $report = $this->publisher->read($root, $week, $referenceAttemptId);
            if ($requireReference && ($report['version'] ?? 0) < 2) {
                throw new \RuntimeException('The selected radio reference became unavailable.');
            }
            $baseline = null;
            $verified = [];
            if ($report !== null && $report['version'] >= 2) {
                $verified[$referenceAttemptId] = $report;
                $baseline = $report['differential'] ?? [
                    'week' => $report['week'], 'attemptId' => $report['attemptId'], 'revision' => $report['revision'],
                    'expected' => self::occurrences($report['entries'], array_column($report['artifacts'], null, 'diffusionId'), $report['sourceIssues']),
                ];
                foreach ($baseline['expected'] as $row) {
                    $origin = $row['origin']['attemptId'] ?? null;
                    if ($origin !== null && !array_key_exists($origin, $verified)) {
                        $source = $this->publisher->read($root, $week, $origin);
                        $verified[$origin] = ($source['version'] ?? 0) >= 2 ? $source : null;
                    }
                }
            }

            return $this->differential->plan($week->format('Y-m-d'), $attemptId, $snapshot['revision'],
                self::occurrences($entries, $artifacts, $issues), $baseline, array_filter($verified));
        };
        $this->copier->prepare($workspace, $plan);

        return $this->publisher->publish($workspace, $snapshot['revision']);
    }

    /** @return array{report: array, current: bool, unavailableOrigins?: list<int>}|null */
    public function read(string $root, \DateTimeImmutable $week, string $attemptId): ?array
    {
        $report = $this->publisher->read($root, $week, $attemptId);
        if ($report === null) {
            return null;
        }
        $current = false;
        if ($report['version'] >= 2) {
            $state = $this->validation->captureForExport($week);
            $current = $state['validated'] && hash_equals($state['revision'], $report['revision']);
        }

        $result = ['report' => $report, 'current' => $current];
        if ($report['version'] === 3) {
            $result['unavailableOrigins'] = [];
            $verified = [];
            foreach ($report['differential']['expected'] as $row) {
                $origin = $row['origin'];
                if ($origin === null) {
                    continue;
                }
                $id = $origin['attemptId'];
                if (!array_key_exists($id, $verified)) {
                    $source = $this->publisher->read($root, $week, $id);
                    $verified[$id] = ($source['version'] ?? 0) >= 2
                        ? array_column($source['artifacts'], null, 'diffusionId')
                        : [];
                }
                if (($verified[$id][$row['diffusionId']] ?? null) !== $origin['artifact']) {
                    $result['unavailableOrigins'][] = $row['diffusionId'];
                }
            }
        }

        return $result;
    }

    /** The caller supplies a report already accepted by the publisher's reader. */
    public function checkFreshness(string $library, \DateTimeImmutable $week, ?array $verifiedReport, bool $full = false): array
    {
        $state = $this->validation->captureForExport($week);
        if ($verifiedReport === null || !$state['validated']
            || ($verifiedReport['week'] ?? null) !== $state['weekStart']->format('Y-m-d')
            || ($verifiedReport['revision'] ?? null) !== $state['revision']) {
            return ['status' => 'preparation_required', 'issues' => []];
        }

        $selection = $this->selection->fromSnapshot($state['weekStart'], $state['rows']);
        $manifest = $this->manifest->build($selection);
        $entries = (new RadioPreparationAdmission())->admit($manifest)['entries'];
        $result = (new RadioPreparationFreshness($this->copier))->check($library, $verifiedReport, true, $full, $entries);
        if ($full) {
            $after = $this->validation->captureForExport($week);
            if (!$after['validated'] || $after['revision'] !== $state['revision']) {
                $result['status'] = 'preparation_required';
            }
        }

        return $result;
    }

    private static function occurrences(array $entries, array $artifacts, array $issues): array
    {
        return array_map(static function (array $entry) use ($artifacts, $issues): array {
            $id = $entry['diffusionId'];

            return [
                'diffusionId' => $id, 'filename' => $entry['filename'], 'admission' => $entry['admission'],
                'artifact' => $artifacts[$id] ?? null, 'issue' => $issues[$id] ?? $entry['issues'][0] ?? null,
            ];
        }, $entries);
    }
}
