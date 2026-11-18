<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Merges one bounded group, using only workspace-owned streams.
 * Inputs are never discarded. The caller owns the workspace and its final cleanup.
 * No preflight budget API exists on the workspace: its opening/creation refusals
 * are propagated after closing only streams successfully obtained by this call.
 */
class AudioInventoryWorkspaceRunMerger
{
    /** @var list<\Throwable> Secondary closure failures from the most recent call. */
    private array $closeFailures = [];

    public function __construct(
        private readonly AudioInventoryTemporaryWorkspace $workspace,
        private readonly AudioInventoryPathRunMerger $merger,
        private readonly int $maxInputRuns,
    ) {
        if ($maxInputRuns < 2) {
            throw new \InvalidArgumentException('At least two input runs must be permitted.');
        }
    }

    /**
     * @param array<array-key, string> $inputRunIds Between two and maxInputRuns distinct opaque IDs.
     * @return string A new finalized output ID, never returned after any failure.
     */
    public function merge(array $inputRunIds): string
    {
        $this->closeFailures = [];
        if (count($inputRunIds) < 2 || count($inputRunIds) > $this->maxInputRuns) {
            throw new \InvalidArgumentException('Input group size is outside the configured bounds.');
        }
        $seen = [];
        foreach ($inputRunIds as $id) {
            if (!is_string($id) || $id === '' || strpbrk($id, "/\\\0") !== false || isset($seen[$id])) {
                throw new \InvalidArgumentException('Distinct workspace run identifiers are required, not paths.');
            }
            $seen[$id] = true;
        }

        $outputId = null;
        $outputOpened = false;
        $openedInputs = [];
        $failure = null;
        try {
            $outputId = $this->workspace->createRun();
            $output = $this->workspace->openWrite($outputId);
            $outputOpened = true;
            $inputs = [];
            foreach ($inputRunIds as $id) {
                $inputs[] = $this->workspace->openRead($id);
                $openedInputs[] = $id;
            }
            $this->merger->merge($inputs, $output);
        } catch (\Throwable $initial) {
            $failure = $initial;
        }

        // Readers first, then writer. Each successfully obtained resource gets
        // one closure attempt even if an earlier attempt failed. Never fclose().
        if ($outputOpened) { $openedInputs[] = $outputId; }
        foreach ($openedInputs as $id) {
            try {
                $this->closeRun($id);
            } catch (\Throwable $closing) {
                if ($failure === null) { $failure = $closing; }
                else { $this->closeFailures[] = $closing; }
            }
        }
        if ($failure !== null) { throw $failure; }

        // Only a successful merge AND all successful closures allow promotion.
        // A finalization exception propagates with every obtained stream closed.
        $this->workspace->finalize($outputId);
        return $outputId;
    }

    /**
     * Retrieve immediately after failure, before another merge resets diagnostics.
     * The original exception is thrown unchanged; secondary objects remain here.
     * This service is synchronous and must not be used reentrantly/concurrently.
     * @return list<\Throwable>
     */
    public function getCloseFailures(): array
    {
        return $this->closeFailures;
    }

    /** Narrow delegation point: ownership and actual closure remain in the workspace. */
    protected function closeRun(string $runId): void
    {
        $this->workspace->close($runId);
    }
}
