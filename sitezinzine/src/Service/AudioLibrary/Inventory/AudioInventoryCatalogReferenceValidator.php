<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Non-destructive validation of one catalog's references. Inject services sharing
 * the same workspace. No file creation, deletion, deduplication or global ID set.
 * First count/validate the frame, then reread it once per ordinal, comparing that
 * ID with every following ID before checking its path run. N(N-1)/2 comparisons,
 * O(N^2) catalog record reads, O(total path bytes) decoding, constant ID storage.
 * Reads are sequential, never nested: at most one owned stream is open here.
 * The comparison budget does not bound bytes, elapsed time or path count. This
 * algorithm is not unconditionally suitable for the complete historic library.
 * Catalogs/runs must remain immutable during validation; no snapshot, exclusive
 * ownership between catalogs, storage completeness or deletion permission follows.
 * Synchronous/non-reentrant; diagnostics belong to the latest validation.
 */
class AudioInventoryCatalogReferenceValidator
{
    /** @var list<\Throwable> */
    private array $closeFailures = [];

    public function __construct(
        private readonly AudioInventoryTemporaryWorkspace $workspace,
        private readonly AudioInventoryWorkspaceRunCatalog $catalog,
        private readonly AudioInventoryPathRunCodec $pathCodec,
        private readonly int $maxComparisons,
    ) {
        if ($maxComparisons < 1) {
            throw new \InvalidArgumentException('Comparison budget must be strictly positive.');
        }
    }

    /**
     * LengthException: comparison budget exhausted (no successful validation).
     * UnexpectedValueException: invalid frame, ordering or duplicate reference.
     * Workspace errors are propagated unchanged; no report on any failure.
     * @return array{catalog_id:string, reference_count:int, validated_runs:int, comparison_count:int, valid:true}
     */
    public function validate(string $catalogId): array
    {
        $this->closeFailures = [];
        $count = $this->consume($catalogId, static function (iterable $ids): int {
            $count = 0;
            foreach ($ids as $id) {
                if ($count === PHP_INT_MAX) { throw new \OverflowException('Catalog reference count exceeds the PHP integer range.'); }
                ++$count;
            }
            return $count;
        });
        $comparisons = $validated = 0;
        for ($ordinal = 0; $ordinal < $count; ++$ordinal) {
            $target = $this->consume($catalogId, function (iterable $ids) use ($ordinal, $count, &$comparisons): string {
                $target = null;
                $position = 0;
                foreach ($ids as $id) {
                    if ($position === $ordinal) {
                        $target = $id;
                    } elseif ($position > $ordinal) {
                        if ($comparisons >= $this->maxComparisons) {
                            throw new \LengthException('Comparison budget exhausted before full validation.');
                        }
                        ++$comparisons;
                        if ($target === $id) {
                            throw new \UnexpectedValueException('Duplicate run identifier in catalog.');
                        }
                    }
                    if ($position === PHP_INT_MAX) { throw new \OverflowException('Catalog position exceeds the PHP integer range.'); }
                    ++$position;
                }
                if ($position !== $count || $target === null) {
                    throw new \UnexpectedValueException('Catalog reference count changed between reads.');
                }
                return $target;
            });
            // consume() has validated the footer and closed the catalog before
            // opening the path run, keeping the descriptor requirement at one.
            $this->validateRun($target);
            ++$validated;
        }
        return ['catalog_id' => $catalogId, 'reference_count' => $count, 'validated_runs' => $validated, 'comparison_count' => $comparisons, 'valid' => true];
    }

    private function consume(string $catalogId, \Closure $consumer): mixed
    {
        try {
            return $this->catalog->consume($catalogId, $consumer);
        } catch (\Throwable $failure) {
            // Capture immediately: the next catalog call resets its diagnostics.
            array_push($this->closeFailures, ...$this->catalog->getCloseFailures());
            throw $failure;
        }
    }

    private function validateRun(string $runId): void
    {
        $stream = $this->workspace->openRead($runId);
        $failure = null;
        try {
            $previous = null;
            foreach ($this->pathCodec->read($stream) as $path) {
                if ($previous !== null && strcmp($previous, $path) >= 0) {
                    throw new \UnexpectedValueException('Run paths must be strictly strcmp-sorted without duplicates.');
                }
                $previous = $path;
            }
        } catch (\Throwable $initial) {
            $failure = $initial;
        }
        try {
            $this->closeRun($runId);
        } catch (\Throwable $closing) {
            if ($failure === null) { $failure = $closing; }
            else { $this->closeFailures[] = $closing; }
        }
        if ($failure !== null) { throw $failure; }
    }

    /** @return list<\Throwable> Secondary closure objects; retrieve before another validate(). */
    public function getCloseFailures(): array
    {
        return $this->closeFailures;
    }

    /** Stream lifetime remains exclusively delegated to its workspace owner. */
    protected function closeRun(string $runId): void
    {
        $this->workspace->close($runId);
    }
}
