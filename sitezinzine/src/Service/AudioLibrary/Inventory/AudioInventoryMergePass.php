<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * One merge pass only. All injected services must share this workspace; reader
 * and writer must be distinct. No inputs, catalogs or provisional outputs are
 * discarded here: the caller owns subsequent cleanup and publication handling.
 * Group memory is O(K); the workspace's live file registry still grows with N.
 * Two catalog reads do not establish a filesystem snapshot. A private workspace
 * without untrusted concurrent modifications is an operational precondition.
 * Synchronous/non-reentrant; secondary diagnostics belong to the latest run.
 */
class AudioInventoryMergePass
{
    /** @var list<\Throwable> */
    private array $closeFailures = [];

    public function __construct(
        private readonly AudioInventoryTemporaryWorkspace $workspace,
        private readonly AudioInventoryWorkspaceRunCatalog $reader,
        private readonly AudioInventoryWorkspaceRunCatalog $writer,
        private readonly AudioInventoryWorkspaceRunMerger $merger,
        private readonly AudioInventoryPathRunCodec $pathCodec,
        private readonly int $maxInputRuns,
    ) {
        if ($maxInputRuns < 2 || $reader === $writer) {
            throw new \InvalidArgumentException('A group limit of at least two and distinct catalog services are required.');
        }
    }

    /**
     * Returns only after both complete reads, output finalization and outer reader
     * closure. Physical finalization alone never publishes a successful pass.
     * @return array{output_catalog_id:string, input_runs:int, output_runs:int, merged_groups:int, carried_run_id:?string}
     */
    public function run(string $inputCatalogId): array
    {
        $this->closeFailures = [];
        try {
            $count = $this->reader->consume($inputCatalogId, static function (iterable $ids): int {
                $count = 0;
                foreach ($ids as $id) {
                    if ($count === PHP_INT_MAX) { throw new \OverflowException('Input count exceeds the PHP integer range.'); }
                    ++$count;
                }
                return $count;
            });

            $report = ['output_catalog_id' => '', 'input_runs' => $count, 'output_runs' => 0, 'merged_groups' => 0, 'carried_run_id' => null];
            $processed = 0;
            $output = $this->reader->consume($inputCatalogId, function (iterable $ids) use (&$report, &$processed): string {
                try {
                    // The generator is exhausted synchronously within consume's
                    // callback; it cannot escape the lifetime of the input stream.
                    return $this->writer->create($this->outputs($ids, $report, $processed));
                } catch (\Throwable $failure) {
                    array_push($this->closeFailures, ...$this->writer->getCloseFailures());
                    throw $failure;
                }
            });
            if ($processed !== $count) {
                throw new \UnexpectedValueException('Input catalog record count changed between reads.');
            }
            if ($count > 1 && $report['output_runs'] >= $count) {
                throw new \UnexpectedValueException('Merge pass did not strictly reduce the run count.');
            }
            $report['output_catalog_id'] = $output;
            return $report;
        } catch (\Throwable $failure) {
            array_push($this->closeFailures, ...$this->reader->getCloseFailures());
            throw $failure;
        }
    }

    /** @return \Generator<int, string> */
    private function outputs(iterable $ids, array &$report, int &$processed): \Generator
    {
        $group = [];
        foreach ($ids as $id) {
            if ($processed === PHP_INT_MAX) { throw new \OverflowException('Processed count exceeds the PHP integer range.'); }
            ++$processed;
            $group[] = $id;
            if (count($group) === $this->maxInputRuns) {
                $output = $this->mergeGroup($group);
                $group = [];
                ++$report['merged_groups']; ++$report['output_runs'];
                yield $output;
            }
        }
        if (count($group) >= 2) {
            $output = $this->mergeGroup($group);
            ++$report['merged_groups']; ++$report['output_runs'];
            yield $output;
        } elseif ($group !== []) {
            $id = $group[0];
            $this->validateSingleton($id);
            $report['carried_run_id'] = $id;
            ++$report['output_runs'];
            yield $id;
        }
    }

    private function mergeGroup(array $ids): string
    {
        try {
            return $this->merger->merge($ids);
        } catch (\Throwable $failure) {
            // Capture before any further merger call could reset diagnostics.
            array_push($this->closeFailures, ...$this->merger->getCloseFailures());
            throw $failure;
        }
    }

    private function validateSingleton(string $id): void
    {
        $stream = $this->workspace->openRead($id);
        $failure = null;
        try {
            $previous = null;
            foreach ($this->pathCodec->read($stream) as $path) {
                if ($previous !== null && strcmp($previous, $path) >= 0) {
                    throw new \UnexpectedValueException('Singleton paths must be strictly strcmp-sorted without duplicates.');
                }
                $previous = $path;
            }
        } catch (\Throwable $initial) {
            $failure = $initial;
        }
        try {
            $this->closeRun($id);
        } catch (\Throwable $closing) {
            if ($failure === null) { $failure = $closing; }
            else { $this->closeFailures[] = $closing; }
        }
        if ($failure !== null) { throw $failure; }
    }

    /** Retrieve before another run resets diagnostics. No logging of messages or traces. @return list<\Throwable> */
    public function getCloseFailures(): array
    {
        return $this->closeFailures;
    }

    /** Real closure delegated to the sole resource owner. */
    protected function closeRun(string $id): void
    {
        $this->workspace->close($id);
    }
}
