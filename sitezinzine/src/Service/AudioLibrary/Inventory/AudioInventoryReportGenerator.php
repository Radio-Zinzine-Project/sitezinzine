<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Reconciliation inside the preparation scope; publication only after it closes. */
final class AudioInventoryReportGenerator
{
    private bool $used = false;
    private array $secondaryErrors = [];
    public function __construct(private readonly AudioInventoryInputPreparation $preparation,
        private readonly AudioInventoryReconciler $reconciler, private readonly AudioInventoryReportStore $store) {}

    /**
     * strict preserves the original contract. observation_only permits a missing
     * generation observer and unknown physical metadata; it NEVER supplies proof.
     * physical_collection_complete means traversal ended without reported errors,
     * not a complete library, an atomic snapshot or authenticated generation.
     * Existing persistent envelopes are unchanged; nullable metadata and the new
     * optional mode tag use their existing scalar/array representation.
     */
    public function run(string $root, array $physicalContext, ?\Closure $readGeneration = null, ?\Closure $continue = null, string $mode = 'strict'): array
    {
        if ($this->used) { throw new \LogicException('Inventory report generation is single-use.'); } $this->used = true;
        if (!in_array($mode, ['strict', 'observation_only'], true) || ($mode === 'strict' && $readGeneration === null)) {
            throw new \InvalidArgumentException('Expected a supported collection mode and a generation observer in strict mode.');
        }
        $id = $this->store->begin(); $reconciliation = null; $associationsSeen = 0; $emitted = 0;
        try {
            $prepared = $this->preparation->withSortedInputs($root, $physicalContext, $readGeneration,
                function (iterable $paths, iterable $associations, array $context) use (&$reconciliation, &$associationsSeen, &$emitted, $continue): bool {
                    $reconciliation = $this->reconciler->reconcile($paths, $associations, $context,
                        function (array $row) use (&$associationsSeen, &$emitted, $continue): bool {
                            $this->store->append($row); $associationsSeen += count($row['associations']); ++$emitted;
                            if ($continue === null) { return true; }
                            $answer = $continue('reconciliation', ['results_emitted' => $emitted]);
                            if (!is_bool($answer)) { throw new \UnexpectedValueException('Continuation must return true or false.'); } return $answer;
                        });
                    return $reconciliation['status'] === 'completed';
                }, $continue, $mode);
            if ($prepared['status'] !== 'completed' || $reconciliation === null || $reconciliation['status'] !== 'completed') {
                $this->store->abort();
                $result = ['inventory_id' => $id, 'status' => 'interrupted', 'publication' => 'unpublished'];
                if ($mode === 'observation_only') { $result['collection_mode'] = $mode; }
                return $result;
            }
            if ($prepared['consumed_paths'] !== $reconciliation['physical_paths'] || $prepared['consumed_associations'] !== $associationsSeen
                || $reconciliation['results_emitted'] !== $emitted) { throw new \UnexpectedValueException('Preparation and reconciliation counters differ.'); }
            $physical = $prepared['physical_collection']; $sql = $prepared['sql_export']['references'];
            $report = ['preparation' => $this->safeDiagnostics($prepared), 'reconciliation' => $reconciliation, 'associations_seen' => $associationsSeen,
                'scope' => $physical['scope'], 'restrictions' => ['references_complete' => $sql['references_complete'],
                    'physical_collection_complete' => $physical['status'] === 'completed' && $physical['error_count'] === 0,
                    'storage_certified' => false, 'absence_conclusions_allowed' => false, 'orphan_candidate_is_deletion_permission' => false]];
            if ($mode === 'observation_only') { $report['collection_mode'] = $mode; }
            return $this->store->publish($report);
        } catch (\Throwable $primary) {
            try { $this->store->abort(); } catch (\Throwable $secondary) { $this->secondaryErrors[] = $secondary; }
            foreach ([$this->preparation->getSecondaryErrors(), $this->store->getSecondaryErrors()] as $errors) {
                foreach ($errors as $error) { if (!in_array($error, $this->secondaryErrors, true)) { $this->secondaryErrors[] = $error; } }
            }
            throw $primary;
        }
    }
    public function getSecondaryErrors(): array { return $this->secondaryErrors; }
    /** Persist only class/code for Throwable diagnostics, never raw messages/DSNs/traces. */
    private function safeDiagnostics(mixed $value): mixed
    {
        if ($value instanceof \Throwable) { return ['exception_class' => $value::class, 'code' => $value->getCode()]; }
        if (is_array($value)) { $result = []; foreach ($value as $key => $item) { $result[$key] = $this->safeDiagnostics($item); } return $result; }
        return $value;
    }
}
