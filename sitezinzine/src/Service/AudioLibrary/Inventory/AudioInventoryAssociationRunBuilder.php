<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Bounded, individually strcmp-sorted association runs; never a global merge. */
final readonly class AudioInventoryAssociationRunBuilder
{
    // ZMP3ASC v1: header 8 + terminator 4 + count 8 + SHA-256 32.
    private const FRAME_BYTES = 52;

    public function __construct(
        private AudioInventoryAssociationRunCodec $codec,
        private int $maxAssociationsPerRun,
        private int $maxEncodedBytesPerRun,
    ) {
        if ($maxAssociationsPerRun < 1 || $maxEncodedBytesPerRun < 1) {
            throw new \InvalidArgumentException('Both association run limits must be positive.');
        }
    }

    /**
     * openRun receives a one-based index and supplies an empty writable stream
     * positioned at zero, already registered by its owner. This builder never
     * opens paths, seeks, closes, truncates or deletes caller resources.
     * onFinalized receives the stream at frame end and metadata only after full
     * codec write/flush and exact byte/count checks. This notification does NOT
     * finalize a workspace file: its owner must close and finalize it itself.
     *
     * maxEncodedBytesPerRun includes the complete binary file (52 fixed bytes +
     * record bytes). association_bytes counts record prefixes and payloads;
     * encoded_bytes additionally counts every produced run's fixed framing.
     * PHP array overhead is bounded separately by maxAssociationsPerRun.
     *
     * One byte-boundary lookahead may be received before notifying the previous
     * block. On strict false, it is counted as received, NOT serialized, and no
     * further source iteration occurs. Exact count/byte bounds need no lookahead.
     * Other callback returns continue. Exceptions propagate unchanged, with no
     * report or buffer flush; prior serialized runs remain caller-owned.
     * Memory: one bounded block plus at most one lookahead association, excluding
     * source/owner retention. An oversized source value cannot itself be bounded
     * before the iterable supplies it. No global order or completeness is claimed.
     *
     * @param iterable<array{storage:string,relative_path:string,emission_id:int,origin:string,thumbnail_missing:bool}> $associations
     * @param \Closure(int):mixed $openRun
     * @param \Closure(mixed,array{run_index:int,status:string,association_count:int,association_bytes:int,encoded_bytes:int}):mixed $onFinalized
     * @return array{status:string,associations_received:int,runs_produced:int,associations_serialized:int,association_bytes:int,encoded_bytes:int}
     */
    public function build(iterable $associations, \Closure $openRun, \Closure $onFinalized): array
    {
        $report = ['status' => 'completed', 'associations_received' => 0, 'runs_produced' => 0,
            'associations_serialized' => 0, 'association_bytes' => 0, 'encoded_bytes' => 0];
        $block = [];
        $bytes = 0;
        $recordBudget = $this->maxEncodedBytesPerRun - self::FRAME_BYTES;
        foreach ($associations as $association) {
            ++$report['associations_received'];
            $length = $this->recordBytes($association);
            if ($length > $recordBudget) {
                throw new \UnexpectedValueException('An individual association exceeds the encoded run byte budget.');
            }
            // Subtraction avoids overflowing when summing large configured budgets.
            if ($block !== [] && $bytes > $recordBudget - $length) {
                if (!$this->finalize($block, $bytes, $report, $openRun, $onFinalized)) {
                    $report['status'] = 'interrupted';
                    return $report;
                }
                $bytes = 0;
            }
            $block[] = $association;
            $bytes += $length;
            if (count($block) === $this->maxAssociationsPerRun || $bytes === $recordBudget) {
                if (!$this->finalize($block, $bytes, $report, $openRun, $onFinalized)) {
                    $report['status'] = 'interrupted';
                    return $report;
                }
                $bytes = 0;
            }
        }
        if ($block !== [] && !$this->finalize($block, $bytes, $report, $openRun, $onFinalized)) {
            $report['status'] = 'interrupted';
        }
        return $report;
    }

    private function recordBytes(mixed $association): int
    {
        // Validate the fields needed for safe size arithmetic and sorting. Path
        // structure and codec-specific record limits are enforced by the codec.
        $keys = ['storage', 'relative_path', 'emission_id', 'origin', 'thumbnail_missing'];
        if (!is_array($association) || count($association) !== 5 || array_diff($keys, array_keys($association)) !== []
            || !is_string($association['storage']) || $association['storage'] === ''
            || !is_string($association['relative_path'])
            || !is_int($association['emission_id']) || $association['emission_id'] <= 0
            || !in_array($association['origin'], ['thumbnailMp3', 'local_audio_url'], true)
            || !is_bool($association['thumbnail_missing'])) {
            throw new \UnexpectedValueException('Expected exactly five correctly typed association fields.');
        }
        // Exact codec v1 record: outer length 4 + three field lengths 12 +
        // origin/boolean flags 2 + raw storage/path/canonical decimal ID bytes.
        return 18 + strlen($association['storage']) + strlen($association['relative_path'])
            + strlen((string) $association['emission_id']);
    }

    private function finalize(array &$block, int $bytes, array &$report, \Closure $openRun, \Closure $onFinalized): bool
    {
        usort($block, static fn (array $a, array $b): int => strcmp($a['relative_path'], $b['relative_path']));
        $count = count($block);
        $index = $report['runs_produced'] + 1;
        $stream = $openRun($index);
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Run factory must supply an open caller-owned stream.');
        }
        $stat = @fstat($stream);
        if (ftell($stream) !== 0 || ($stat['size'] ?? null) !== 0) {
            throw new \InvalidArgumentException('Run factory must supply an empty stream positioned at zero.');
        }
        $written = $this->codec->write($stream, $block);
        $encodedBytes = self::FRAME_BYTES + $bytes;
        $stat = @fstat($stream);
        if ($written !== $count || ftell($stream) !== $encodedBytes || ($stat['size'] ?? null) !== $encodedBytes) {
            throw new \UnexpectedValueException('Codec count or encoded size differs from the budgeted association run.');
        }
        ++$report['runs_produced'];
        $report['associations_serialized'] += $written;
        $report['association_bytes'] += $bytes;
        $report['encoded_bytes'] += $encodedBytes;
        $block = [];
        return $onFinalized($stream, ['run_index' => $index, 'status' => 'finalized',
            'association_count' => $count, 'association_bytes' => $bytes, 'encoded_bytes' => $encodedBytes]) !== false;
    }
}
