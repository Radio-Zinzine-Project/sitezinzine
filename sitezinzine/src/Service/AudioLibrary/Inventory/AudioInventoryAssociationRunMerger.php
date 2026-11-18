<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** One bounded K-way merge of caller-owned, already-open association streams. */
final readonly class AudioInventoryAssociationRunMerger
{
    public function __construct(private AudioInventoryAssociationRunCodec $codec, private int $maxOpenRuns)
    {
        if ($maxOpenRuns < 1) {
            throw new \InvalidArgumentException('At least one input reader must be permitted.');
        }
    }

    /**
     * Inputs are readable streams positioned by their owner. Output must be a
     * distinct, empty writable stream at position zero. No opening, seeking,
     * closing, deletion, renaming or workspace finalization happens here.
     * Resource identity checks reject repeated handles, not two handles onto
     * the same underlying file: physical input/output isolation is caller-owned.
     * maxOpenRuns bounds this merger's readers, not files already open elsewhere;
     * the owner must budget K readers plus one writer and any other resources.
     *
     * One current association per input, plus codec buffers bounded by record
     * size. Linear minimum selection costs O(records * K), with O(K * record
     * size) memory, excluding caller retention. Equal paths are retained exactly;
     * no secondary ordering, grouping, normalization or deduplication is imposed.
     *
     * Validating nondecreasing merged output validates every input subsequence.
     * Each codec iterator is exhausted, including after its last association,
     * so count/hash/EOF checks finish BEFORE the output codec writes its trailer.
     * Empty inputs also undergo full integrity checks. Only complete output
     * write/flush yields a report; any exception propagates unchanged, without
     * draining other inputs or attempting cleanup. The owner must treat every
     * failed output as unpublished, even if all bytes preceded a flush failure.
     * No durability, provenance, collection completeness or multi-pass guarantee.
     *
     * @param iterable<mixed> $inputStreams
     * @return array{status:'completed',input_runs:int,associations_merged:int}
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
        foreach ($streams as $stream) { $readers[] = $this->codec->read($stream); }
        $count = $this->codec->write($outputStream, $this->mergedAssociations($readers));
        return ['status' => 'completed', 'input_runs' => count($streams), 'associations_merged' => $count];
    }

    /** @param array<int,\Generator<int,array>> $readers */
    private function mergedAssociations(array $readers): \Generator
    {
        $previousPath = null;
        while ($readers !== []) {
            $minimum = null;
            foreach ($readers as $index => $reader) {
                if (!$reader->valid()) {
                    unset($readers[$index]);
                    continue;
                }
                if ($minimum === null || strcmp($reader->current()['relative_path'], $readers[$minimum]->current()['relative_path']) < 0) {
                    $minimum = $index;
                }
            }
            if ($minimum === null) { return; }
            $association = $readers[$minimum]->current();
            $path = $association['relative_path'];
            if ($previousPath !== null && strcmp($previousPath, $path) > 0) {
                throw new \UnexpectedValueException('An input association run is not nondecreasing strcmp-sorted.');
            }
            $previousPath = $path;
            yield $association;
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
