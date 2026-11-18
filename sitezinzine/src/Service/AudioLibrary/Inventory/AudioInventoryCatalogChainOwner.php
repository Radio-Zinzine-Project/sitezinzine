<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Controls one in-process chain using a dedicated, already-built assembly.
 * All dependencies MUST share this workspace and be handed over for exclusive
 * synchronous use. Existing APIs cannot prove that identity or revoke aliases.
 * Only output IDs from the actual merge pass can become active; arbitrary external
 * reports/IDs cannot be published through this API. No service, state or stream
 * is exposed. No discard(), cleanup(), confirmRetirement() or destructor cleanup.
 * The caller remains responsible for eventual operation-wide resource disposal.
 * Failed candidates remain workspace-owned; a failed pass cannot enumerate all
 * provisional IDs. There is no durable publication, restart recovery, global
 * constant-memory guarantee, or certification of physical MP3 completeness.
 */
class AudioInventoryCatalogChainOwner
{
    /** @var list<\Throwable> */
    private array $secondaryFailures = [];
    private bool $busy = false;
    private bool $blocked = false;
    private ?string $candidateCatalogId = null;

    protected function __construct(
        private readonly AudioInventoryCatalogChainState $state,
        private readonly AudioInventoryTemporaryWorkspace $workspace,
        private readonly AudioInventoryWorkspaceRunCatalog $catalog,
        private readonly AudioInventoryCatalogReferenceValidator $validator,
        private readonly AudioInventoryMergePass $mergePass,
    ) {
    }

    /**
     * Validate the initial file before constructing private chain state. If start
     * fails, the original error propagates; secondary diagnostics remain on the
     * supplied validator (no owner instance has been returned).
     */
    public static function start(
        string $operationId,
        string $initialCatalogId,
        AudioInventoryTemporaryWorkspace $workspace,
        AudioInventoryWorkspaceRunCatalog $catalog,
        AudioInventoryCatalogReferenceValidator $validator,
        AudioInventoryMergePass $mergePass,
    ): static {
        if (trim($operationId) === '' || trim($initialCatalogId) === '') {
            throw new \InvalidArgumentException('Operation and initial catalog identifiers are required.');
        }
        self::assertValidation($validator->validate($initialCatalogId), $initialCatalogId);
        return new static(new AudioInventoryCatalogChainState($operationId, $initialCatalogId), $workspace, $catalog, $validator, $mergePass);
    }

    /** No caller-supplied candidate or success declaration. */
    public function replaceActiveCatalog(): array
    {
        $this->assertAvailable();
        $this->busy = true;
        $this->secondaryFailures = [];
        $token = null;
        $published = false;
        try {
            $before = $this->state->snapshot();
            $previous = $before['active_catalog_id'];
            $token = $this->state->beginReplacement($previous);
            $this->candidateCatalogId = null;
            $input = $this->validateCatalog($previous);
            try {
                $pass = $this->mergePass->run($previous);
            } catch (\Throwable $failure) {
                array_push($this->secondaryFailures, ...$this->mergePass->getCloseFailures());
                throw $failure;
            }
            $candidate = $pass['output_catalog_id'] ?? null;
            if (!is_string($candidate) || trim($candidate) === '') {
                throw new \UnexpectedValueException('Merge pass did not return a catalog identifier.');
            }
            $this->candidateCatalogId = $candidate;
            $output = $this->validateCatalog($candidate);
            $this->assertCounters($pass, $input, $output);
            if ($before['revision'] === PHP_INT_MAX) { throw new \OverflowException('Catalog revision exhausted.'); }
            // Build the return value before the final state transition. Nothing
            // external is invoked after publication; no subsequent abort on success.
            $result = [
                'previous_catalog_id' => $previous,
                'active_catalog_id' => $candidate,
                'pass_report' => $pass,
                'revision' => $before['revision'] + 1,
                'retirement_pending' => true,
            ];
            $this->publishReplacement($token, $candidate);
            $published = true;
            return $result;
        } catch (\Throwable $initial) {
            if ($token !== null && !$published) {
                try {
                    $this->abortReplacement($token);
                } catch (\Throwable $abortFailure) {
                    $this->secondaryFailures[] = $abortFailure;
                    $this->blocked = true;
                }
            }
            throw $initial;
        } finally {
            $this->busy = false;
        }
    }

    /** Callback receives scoped catalog IDs iterable, never an exposed file stream. */
    public function withActiveCatalog(\Closure $consumer): mixed
    {
        $this->assertAvailable();
        $this->busy = true;
        $this->secondaryFailures = [];
        try {
            return $this->state->withActiveCatalog(function (string $id) use ($consumer): mixed {
                try {
                    return $this->catalog->consume($id, $consumer);
                } catch (\Throwable $failure) {
                    array_push($this->secondaryFailures, ...$this->catalog->getCloseFailures());
                    throw $failure;
                }
            });
        } finally {
            $this->busy = false;
        }
    }

    /** Constant-size local diagnostic state, not an exhaustive residual-file list. */
    public function snapshot(): array
    {
        return $this->state->snapshot() + ['blocked' => $this->blocked, 'candidate_catalog_id' => $this->candidateCatalogId];
    }

    /** @return list<\Throwable> Retrieve before the next accepted operation resets diagnostics. */
    public function getSecondaryFailures(): array
    {
        return $this->secondaryFailures;
    }

    private function validateCatalog(string $id): array
    {
        try {
            $report = $this->validator->validate($id);
        } catch (\Throwable $failure) {
            array_push($this->secondaryFailures, ...$this->validator->getCloseFailures());
            throw $failure;
        }
        self::assertValidation($report, $id);
        return $report;
    }

    private static function assertValidation(array $report, string $id): void
    {
        if (($report['catalog_id'] ?? null) !== $id || ($report['valid'] ?? null) !== true
            || !is_int($report['reference_count'] ?? null) || $report['reference_count'] < 0
            || ($report['validated_runs'] ?? null) !== $report['reference_count']) {
            throw new \UnexpectedValueException('Incoherent catalog validation report.');
        }
    }

    private function assertCounters(array $pass, array $input, array $output): void
    {
        foreach (['input_runs', 'output_runs', 'merged_groups'] as $field) {
            if (!is_int($pass[$field] ?? null) || $pass[$field] < 0) {
                throw new \UnexpectedValueException('Invalid merge pass counter.');
            }
        }
        $carried = $pass['carried_run_id'] ?? null;
        if (($carried !== null && (!is_string($carried) || $carried === ''))
            || $pass['input_runs'] !== $input['reference_count']
            || $pass['output_runs'] !== $output['reference_count']
            || $pass['merged_groups'] !== $pass['output_runs'] - ($carried === null ? 0 : 1)
            || ($pass['input_runs'] > 1 && $pass['output_runs'] >= $pass['input_runs'])
            || ($pass['input_runs'] <= 1 && $pass['output_runs'] !== $pass['input_runs'])) {
            throw new \UnexpectedValueException('Incoherent merge pass counters.');
        }
    }

    private function assertAvailable(): void
    {
        if ($this->busy || $this->blocked) { throw new \DomainException('Chain owner is busy or blocked after an unsuccessful abort.'); }
    }

    /** Narrow delegation seams for failure characterization; no external publication API. */
    protected function publishReplacement(string $token, string $id): void
    {
        $this->state->publishReplacement($token, $id);
    }

    protected function abortReplacement(string $token): void
    {
        $this->state->abortReplacement($token);
    }
}
