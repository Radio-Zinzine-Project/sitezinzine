<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Consumes scanner observations, never certifies the library's completeness. */
final class AudioInventoryPhysicalCollector
{
    /**
     * Context supplies library_id, storage_kind (real|synthetic), generation,
     * scope, source and evidence_id. Only these fields enter the report, exactly
     * as supplied; provenance is not authenticated and no proof is fabricated.
     * Default strict mode retains the original six required fields and reader.
     * Explicit observation_only keeps scope/source/evidence_id mandatory: the
     * latter identifies the actual observation, NEVER the library. Unknown
     * library_id/storage_kind/generation are null (missing also means unknown).
     * Known values are retained; storage kind, if known, is real or synthetic.
     * A missing reader produces null observations, not the declared generation.
     * collection_mode is added ONLY in observation_only, preserving strict
     * report shape and existing callers. Neither mode authenticates evidence.
     *
     * Each file observation is delivered unchanged. Only an explicit false
     * interrupts consumption. Error observations increment error_count; reaching
     * the end still gives completed, which is not a claim of an error-free scan.
     * No list of paths or errors is retained, sorted or deduplicated.
     *
     * Generation is observed before iteration and after normal completion or
     * explicit interruption. Exceptions propagate unchanged, without a report
     * or a secondary generation read that could mask the original failure.
     * Generation equality alone does not establish a stable filesystem snapshot.
     *
     * The caller owns the iterable and must release a suspended generator after
     * interruption/failure. The collector neither resumes it nor closes resources
     * held by external references. Consumer effects cannot be rolled back here.
     * Path containment remains the scanner's responsibility; this component only
     * validates observation structure and performs no filesystem operations.
     *
     * @param iterable<array{type:string, relative_path:string, reason?:string}> $observations
     * @param \Closure(array):mixed $consumeFile
     * @param (\Closure():?string)|null $readGeneration Required in strict mode; null observations allowed only in observation_only.
     * @return array{library_id:?string, storage_kind:?string, generation:?string, scope:string, source:string, evidence_id:string, status:'completed'|'interrupted', error_count:int, generation_before:?string, generation_after:?string, collection_mode?:'observation_only'}
     */
    public function collect(
        iterable $observations,
        array $context,
        \Closure $consumeFile,
        ?\Closure $readGeneration = null,
        string $mode = 'strict',
    ): array {
        if (!in_array($mode, ['strict', 'observation_only'], true)) {
            throw new \InvalidArgumentException('Unknown physical collection mode.');
        }
        $observationOnly = $mode === 'observation_only';
        if (!$observationOnly && $readGeneration === null) {
            throw new \InvalidArgumentException('Strict collection requires a generation observer.');
        }
        $report = [];
        foreach (['library_id', 'storage_kind', 'generation', 'scope', 'source', 'evidence_id'] as $field) {
            $value = $context[$field] ?? null;
            $unknownAllowed = $observationOnly && in_array($field, ['library_id', 'storage_kind', 'generation'], true);
            if (!($unknownAllowed && $value === null) && !$this->isText($value)) {
                throw new \InvalidArgumentException('Physical collection requires explicit identity and provenance context.');
            }
            $report[$field] = $value;
        }
        if ($report['storage_kind'] !== null && !in_array($report['storage_kind'], ['real', 'synthetic'], true)) {
            throw new \InvalidArgumentException('Physical storage kind must be real or synthetic.');
        }
        if ($observationOnly) { $report['collection_mode'] = 'observation_only'; }

        $before = $this->readGeneration($readGeneration, $observationOnly);
        $status = 'completed';
        $errors = 0;
        foreach ($observations as $observation) {
            if (!is_array($observation)
                || !in_array($observation['type'] ?? null, ['file', 'error'], true)
                || !is_string($observation['relative_path'] ?? null)) {
                throw new \UnexpectedValueException('Malformed physical scanner observation.');
            }
            if ($observation['type'] === 'error') {
                // Root errors legitimately carry an empty relative_path.
                if (!$this->isText($observation['reason'] ?? null)) {
                    throw new \UnexpectedValueException('A scanner error must contain a reason.');
                }
                ++$errors;
            } else {
                if ($observation['relative_path'] === '') {
                    throw new \UnexpectedValueException('A file observation must contain its relative path.');
                }
                if ($consumeFile($observation) === false) {
                    $status = 'interrupted';
                    break;
                }
            }
        }

        return $report + [
            'status' => $status,
            'error_count' => $errors,
            'generation_before' => $before,
            'generation_after' => $this->readGeneration($readGeneration, $observationOnly),
        ];
    }

    private function readGeneration(?\Closure $readGeneration, bool $unknownAllowed): ?string
    {
        if ($readGeneration === null) { return null; }
        $generation = $readGeneration();
        if ($unknownAllowed && $generation === null) { return null; }
        if (!$this->isText($generation)) {
            throw new \UnexpectedValueException('An observed generation must be a nonempty string.');
        }
        return $generation;
    }

    private function isText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
