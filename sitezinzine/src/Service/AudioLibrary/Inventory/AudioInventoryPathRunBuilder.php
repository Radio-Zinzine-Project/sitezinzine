<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Builds bounded, individually sorted runs; never merges or certifies an inventory. */
final readonly class AudioInventoryPathRunBuilder
{
    public function __construct(
        private AudioInventoryPathRunCodec $codec,
        private int $maxPathsPerRun,
        private int $maxPathBytesPerRun,
    ) {
        if ($maxPathsPerRun < 1 || $maxPathBytesPerRun < 1) {
            throw new \InvalidArgumentException('Both path run limits must be positive.');
        }
    }

    /**
     * openRun receives a one-based run index and must return an empty writable
     * stream at position zero, registered by its owner before returning it.
     * The builder never opens paths, selects a directory, seeks, truncates,
     * closes or deletes any stream. Failed writes leave resources owned by the
     * caller, without a finalization notification.
     *
     * onFinalized receives the stream (positioned after the frame) and metadata,
     * only after codec write/flush succeeded. The owner controls further reading,
     * persistence and closure. Strict false interrupts; other returns continue.
     * Exceptions propagate unchanged, without a success summary or extra source
     * iteration. Already finalized runs remain valid even if a later step fails.
     *
     * paths_received counts source values pulled, not just serialized paths.
     * A byte boundary may require one lookahead path; if notification interrupts,
     * that received path is not serialized. Exact bounds flush without lookahead.
     * Budgets count raw string bytes, not frame bytes or PHP array overhead.
     * Both count and byte limits bound buffering; the source/owner must also avoid
     * global accumulation. No uniqueness is claimed across separate runs.
     * completed means this iterable ended, never physical completeness.
     *
     * @param iterable<string> $paths
     * @param \Closure(int):mixed $openRun
     * @param \Closure(mixed, array{run_index:int, status:'finalized', path_count:int, path_bytes:int}):mixed $onFinalized
     * @return array{status:'completed'|'interrupted', paths_received:int, runs_produced:int, paths_serialized:int}
     */
    public function build(iterable $paths, \Closure $openRun, \Closure $onFinalized): array
    {
        $received = $runs = $serialized = $bytes = 0;
        $block = [];
        foreach ($paths as $path) {
            ++$received;
            if (!is_string($path)) {
                throw new \UnexpectedValueException('Path runs require string paths.');
            }
            $length = strlen($path);
            if ($length > $this->maxPathBytesPerRun) {
                throw new \UnexpectedValueException('An individual path exceeds the block byte budget.');
            }
            // Subtraction avoids overflow when the configured byte budget is large.
            if ($block !== [] && $bytes > $this->maxPathBytesPerRun - $length) {
                if (!$this->finalize($block, $bytes, $runs, $serialized, $openRun, $onFinalized)) {
                    return $this->report('interrupted', $received, $runs, $serialized);
                }
                $bytes = 0;
            }
            $block[] = $path;
            $bytes += $length;
            if (count($block) === $this->maxPathsPerRun || $bytes === $this->maxPathBytesPerRun) {
                if (!$this->finalize($block, $bytes, $runs, $serialized, $openRun, $onFinalized)) {
                    return $this->report('interrupted', $received, $runs, $serialized);
                }
                $bytes = 0;
            }
        }
        if ($block !== [] && !$this->finalize($block, $bytes, $runs, $serialized, $openRun, $onFinalized)) {
            return $this->report('interrupted', $received, $runs, $serialized);
        }
        return $this->report('completed', $received, $runs, $serialized);
    }

    private function finalize(
        array &$block,
        int $bytes,
        int &$runs,
        int &$serialized,
        \Closure $openRun,
        \Closure $onFinalized,
    ): bool {
        usort($block, strcmp(...));
        $count = count($block);
        for ($i = 1; $i < $count; ++$i) {
            if (strcmp($block[$i - 1], $block[$i]) === 0) {
                throw new \UnexpectedValueException('Duplicate path within a run; no deduplication is performed.');
            }
        }
        $index = $runs + 1;
        $stream = $openRun($index);
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Run factory must supply an open caller-owned stream.');
        }
        $stat = @fstat($stream);
        if (ftell($stream) !== 0 || ($stat['size'] ?? null) !== 0) {
            throw new \InvalidArgumentException('Run factory must supply an empty stream positioned at zero.');
        }
        // Structural and record-length validation, framing and I/O belong to the codec.
        $written = $this->codec->write($stream, $block);
        if ($written !== $count) {
            throw new \UnexpectedValueException('Codec did not confirm the complete run record count.');
        }
        ++$runs;
        $serialized += $written;
        $block = [];
        return $onFinalized($stream, [
            'run_index' => $index, 'status' => 'finalized', 'path_count' => $written, 'path_bytes' => $bytes,
        ]) !== false;
    }

    private function report(string $status, int $received, int $runs, int $serialized): array
    {
        return ['status' => $status, 'paths_received' => $received, 'runs_produced' => $runs, 'paths_serialized' => $serialized];
    }
}
