<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

use Closure;
use Generator;
use InvalidArgumentException;
use LengthException;
use UnexpectedValueException;

/** Progressive full outer join; never authorizes a file operation. */
final class AudioInventoryReconciler
{
    public function __construct(
        private readonly AudioInventoryClassifier $classifier,
        private readonly int $maxAssociationsPerPath,
    ) {
        if ($maxAssociationsPerPath < 1) {
            throw new InvalidArgumentException('The association budget must be positive.');
        }
    }

    /**
     * Context: storage, scope, references (SQL collector report),
     * physical_collection (physical collector report), storage_decision.
     * Evidence is supplied and bound to these streams by the caller, not
     * authenticated here. No common SQL/filesystem snapshot is implied.
     * Missing evidence fails closed. test_subset never permits absence claims:
     * this API has no membership proof defining its covered paths.
     *
     * Inputs remain caller-owned. Only one head per stream and one bounded
     * association group are retained. Association grouping needs one lookahead.
     * Emitted rows are provisional until normal exhaustion of BOTH streams;
     * completed means join traversal completed, not certified inventory.
     * Exceptions propagate unchanged, with no completion report. Strict false
     * interrupts without draining inputs. Counters count emitted rows only.
     */
    public function reconcile(iterable $physicalPaths, iterable $associations, array $collectionContext, Closure $consumeResult): array
    {
        $storage = $collectionContext['storage'] ?? null;
        $scope = $collectionContext['scope'] ?? null;
        if (!is_string($storage) || $storage === '' || !is_string($scope) || $scope === '') {
            throw new InvalidArgumentException('Nonempty storage and scope are required.');
        }
        $references = $collectionContext['references'] ?? [];
        $referencesComplete = is_array($references)
            && ($references['status'] ?? null) === 'completed'
            && ($references['references_complete'] ?? null) === true
            && ($references['uncertainty_count'] ?? null) === 0
            && ($references['temporal_consistency'] ?? null) === 'repeatable_read';
        $absenceAllowed = $this->absenceAllowed($collectionContext);
        $physical = $this->validated($physicalPaths, false, $storage);
        $referencesStream = $this->validated($associations, true, $storage);
        $report = ['status' => 'completed', 'processed_paths' => 0, 'physical_paths' => 0, 'referenced_paths' => 0, 'results_emitted' => 0];

        while (true) {
            $hasPhysical = $physical->valid();
            $hasReference = $referencesStream->valid();
            if (!$hasPhysical && !$hasReference) {
                return $report;
            }
            $physicalPath = $hasPhysical ? $physical->current() : null;
            $referencePath = $hasReference ? $referencesStream->current()['relative_path'] : null;
            $path = !$hasPhysical ? $referencePath : (!$hasReference || strcmp($physicalPath, $referencePath) <= 0 ? $physicalPath : $referencePath);
            $observed = $hasPhysical && $physicalPath === $path;
            $group = [];
            while ($referencesStream->valid() && $referencesStream->current()['relative_path'] === $path) {
                if (count($group) === $this->maxAssociationsPerPath) {
                    throw new LengthException('Association budget exceeded for one path.');
                }
                $group[] = $referencesStream->current();
                $referencesStream->next();
            }
            $present = $observed ? true : ($absenceAllowed ? false : null);
            $row = $this->classifier->classify($storage, $path, $present, $referencesComplete, $group) + [
                'physical_observed' => $observed,
                'present' => $present,
                'associations' => $group,
                'references_complete' => $referencesComplete,
                'absence_conclusions_allowed' => $absenceAllowed,
                'scope' => $scope,
            ];
            ++$report['processed_paths'];
            ++$report['results_emitted'];
            $report['physical_paths'] += (int) $observed;
            $report['referenced_paths'] += (int) ($group !== []);
            if ($consumeResult($row) === false) {
                $report['status'] = 'interrupted';
                return $report;
            }
            if ($observed) {
                $physical->next();
            }
        }
    }

    private function validated(iterable $source, bool $associations, string $storage): Generator
    {
        $previous = null;
        foreach ($source as $value) {
            if ($associations) {
                if (!is_array($value) || ($value['storage'] ?? null) !== $storage
                    || !is_int($value['emission_id'] ?? null) || $value['emission_id'] <= 0
                    || !in_array($value['origin'] ?? null, ['thumbnailMp3', 'local_audio_url'], true)
                    || !is_bool($value['thumbnail_missing'] ?? null)) {
                    throw new UnexpectedValueException('Invalid inventory association.');
                }
                $path = $value['relative_path'] ?? null;
            } else {
                $path = $value;
            }
            $this->validatePath($path);
            if ($previous !== null && (strcmp($previous, $path) > 0 || (!$associations && $previous === $path))) {
                throw new UnexpectedValueException('Inventory input order or uniqueness violated.');
            }
            $previous = $path;
            yield $value;
        }
    }

    private function validatePath(mixed $path): void
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0")
            || $path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:/', $path)) {
            throw new UnexpectedValueException('Invalid relative inventory path.');
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new UnexpectedValueException('Invalid relative inventory path component.');
            }
        }
    }

    private function absenceAllowed(array $context): bool
    {
        $decision = $context['storage_decision'] ?? null;
        $physical = $context['physical_collection'] ?? null;
        if (($context['scope'] ?? null) !== 'web_full' || !is_array($decision) || !is_array($physical)
            || ($decision['state'] ?? null) !== 'verified'
            || ($decision['absence_conclusions_allowed'] ?? null) !== true
            || ($decision['storage_kind'] ?? null) !== 'real'
            || ($physical['status'] ?? null) !== 'completed'
            || ($physical['error_count'] ?? null) !== 0
            || ($physical['scope'] ?? null) !== $context['scope']) {
            return false;
        }
        foreach (['library_id', 'storage_kind', 'generation'] as $key) {
            if (!is_string($decision[$key] ?? null) || $decision[$key] === '' || ($physical[$key] ?? null) !== $decision[$key]) {
                return false;
            }
        }
        return ($physical['generation_before'] ?? null) === $decision['generation']
            && ($physical['generation_after'] ?? null) === $decision['generation'];
    }
}
