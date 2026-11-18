<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** One bounded merge pass over caller-owned, already-open run streams. */
final readonly class AudioInventoryPathRunMerger
{
    public function __construct(private AudioInventoryPathRunCodec $codec, private int $maxOpenRuns)
    {
        if ($maxOpenRuns < 1) {
            throw new \InvalidArgumentException('At least one input reader must be permitted.');
        }
    }

    /**
     * Inputs are independently readable streams positioned by their owner.
     * Output must be a distinct, empty writable stream at position zero.
     * Resources are never opened, rewound, truncated, closed or deleted here.
     * The limit bounds readers used, not resources already opened by the caller.
     *
     * At most maxOpenRuns references/readers and one head path per active run
     * are retained. A linear minimum selection gives O(paths * maxOpenRuns)
     * comparisons, with memory bounded by readers and codec record-size limits.
     * Global strictly increasing output also validates each input subsequence;
     * duplicate or decreasing paths fail explicitly, without deduplication.
     *
     * Codec reading resumes after each last path to validate count, checksum and
     * EOF, including empty runs. Only after all input generators are exhausted
     * can the output codec write its footer/checksum and confirm its flush.
     * Exceptions propagate unchanged; no draining or secondary cleanup masks
     * them. After any exception the owner must treat the output as invalid,
     * even if a late flush failure happened after all bytes were written.
     *
     * No multi-pass orchestration, storage identity or completeness is implied.
     * @param iterable<mixed> $inputStreams
     * @return array{status:'completed', input_runs:int, paths_merged:int}
     */
    public function merge(iterable $inputStreams, mixed $outputStream): array
    {
        $this->assertStream($outputStream);
        $streams = [];
        foreach ($inputStreams as $stream) {
            if (count($streams) >= $this->maxOpenRuns) {
                throw new \LengthException('Input run count exceeds the configured reader limit.');
            }
            $this->assertStream($stream);
            $id = get_resource_id($stream);
            if ($stream === $outputStream || isset($streams[$id])) {
                throw new \InvalidArgumentException('Input streams must be distinct from each other and the output.');
            }
            $streams[$id] = $stream;
        }
        $stat = @fstat($outputStream);
        if (ftell($outputStream) !== 0 || ($stat['size'] ?? null) !== 0) {
            throw new \InvalidArgumentException('Merge output must be empty and positioned at zero.');
        }
        $readers = [];
        foreach ($streams as $stream) {
            $readers[] = $this->codec->read($stream);
        }
        $count = $this->codec->write($outputStream, $this->mergedPaths($readers));
        return ['status' => 'completed', 'input_runs' => count($streams), 'paths_merged' => $count];
    }

    /** @param array<int, \Generator<int, string>> $readers */
    private function mergedPaths(array $readers): \Generator
    {
        $previous = null;
        while ($readers !== []) {
            $minimum = null;
            foreach ($readers as $index => $reader) {
                if (!$reader->valid()) {
                    unset($readers[$index]);
                    continue;
                }
                if ($minimum === null || strcmp($reader->current(), $readers[$minimum]->current()) < 0) {
                    $minimum = $index;
                }
            }
            if ($minimum === null) {
                return;
            }
            $path = $readers[$minimum]->current();
            if ($previous !== null) {
                $order = strcmp($previous, $path);
                if ($order === 0) {
                    throw new \UnexpectedValueException('Duplicate path inside or between input runs.');
                }
                if ($order > 0) {
                    throw new \UnexpectedValueException('An input run is not strictly strcmp-sorted.');
                }
            }
            $previous = $path;
            yield $path;
            $readers[$minimum]->next();
        }
    }

    private function assertStream(mixed $stream): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Expected an open stream owned by the caller.');
        }
    }
}
