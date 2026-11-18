<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Lossless associations on caller-owned streams; no sorting or deduplication. */
final readonly class AudioInventoryAssociationRunCodec
{
    private const HEADER = "ZMP3ASC\x01";
    private const KEYS = ['storage', 'relative_path', 'emission_id', 'origin', 'thumbnail_missing'];

    /** maxRecordBytes includes all payload fields and their internal framing. */
    public function __construct(private int $maxRecordBytes)
    {
        if ($maxRecordBytes < 1 || $maxRecordBytes > 0xffffffff) {
            throw new \InvalidArgumentException('Record byte limit must fit a positive unsigned 32-bit length.');
        }
    }

    /**
     * V1: ZMP3ASC + version 01, repeated [uint32 payload length, payload],
     * uint32 zero terminator, uint64 count, SHA-256 (32 bytes). Integers in
     * framing are big-endian. The hash covers header, records, terminator/count.
     * Payload: three uint32-length-prefixed byte strings (storage, relative path,
     * canonical positive decimal emission ID), origin byte (1 thumbnailMp3,
     * 2 local_audio_url), missing-thumbnail byte (0 false, 1 true).
     * Decimal IDs avoid platform-dependent integer packing; out-of-range IDs
     * are rejected on reading, never rounded or converted into floats.
     *
     * No UTF-8 conversion, normalization, seek, truncate, close or fsync.
     * Writes at current position, then flushes; a failure may leave a partial
     * frame. Source exceptions propagate unchanged without a success trailer.
     * Integrity is not authenticity, provenance or collection completeness.
     *
     * @param iterable<array{storage:string, relative_path:string, emission_id:positive-int, origin:'thumbnailMp3'|'local_audio_url', thumbnail_missing:bool}> $associations
     */
    public function write(mixed $stream, iterable $associations): int
    {
        $this->assertStream($stream, true);
        $this->writeAll($stream, self::HEADER);
        $hash = hash_init('sha256');
        hash_update($hash, self::HEADER);
        $count = 0;
        foreach ($associations as $association) {
            $this->validateAssociation($association);
            $id = (string) $association['emission_id'];
            // Check the entire payload size before allocating its encoded copy.
            $length = 14 + strlen($association['storage']) + strlen($association['relative_path']) + strlen($id);
            if ($length > $this->maxRecordBytes) {
                throw new \UnexpectedValueException('Association record length exceeds the configured byte limit.');
            }
            $payload = $this->field($association['storage']).$this->field($association['relative_path']).$this->field($id)
                .($association['origin'] === 'thumbnailMp3' ? "\x01" : "\x02")
                .($association['thumbnail_missing'] ? "\x01" : "\x00");
            $prefix = pack('N', $length);
            $this->writeAll($stream, $prefix);
            $this->writeAll($stream, $payload);
            hash_update($hash, $prefix);
            hash_update($hash, $payload);
            if ($count === PHP_INT_MAX) {
                throw new \OverflowException('Association record count exceeds the PHP integer range.');
            }
            ++$count;
        }
        $footer = pack('N', 0).$this->encodeCount($count);
        $this->writeAll($stream, $footer);
        hash_update($hash, $footer);
        $this->writeAll($stream, hash_final($hash, true));
        if (!@fflush($stream)) {
            throw new \RuntimeException('Cannot flush the association run stream.');
        }
        return $count;
    }

    /**
     * Reads at current position. Yielded records are provisional until full
     * generator exhaustion validates count, checksum and strict EOF. Dropping
     * a partial iterator does not validate the remainder or close the stream.
     * Caller controls stream lifetime and cleanup. Memory is O(maxRecordBytes),
     * independent of the number of records, excluding caller/source retention.
     *
     * @return \Generator<int, array{storage:string, relative_path:string, emission_id:int, origin:string, thumbnail_missing:bool}>
     */
    public function read(mixed $stream): \Generator
    {
        $this->assertStream($stream, false);
        return $this->decode($stream);
    }

    private function decode(mixed $stream): \Generator
    {
        if ($this->readExact($stream, 7) !== 'ZMP3ASC') {
            throw new \UnexpectedValueException('Invalid association run format: signature.');
        }
        if ($this->readExact($stream, 1) !== "\x01") {
            throw new \UnexpectedValueException('Invalid association run format: unsupported version.');
        }
        $hash = hash_init('sha256');
        hash_update($hash, self::HEADER);
        $count = 0;
        while (true) {
            $prefix = $this->readExact($stream, 4);
            $length = unpack('Nlength', $prefix)['length'];
            hash_update($hash, $prefix);
            if ($length === 0) { break; }
            // No announced record payload is read or allocated before this check.
            if ($length > $this->maxRecordBytes) {
                throw new \UnexpectedValueException('Association record length exceeds the configured byte limit.');
            }
            $payload = $this->readExact($stream, $length);
            $association = $this->decodeAssociation($payload);
            hash_update($hash, $payload);
            if ($count === PHP_INT_MAX) {
                throw new \OverflowException('Association record count exceeds the PHP integer range.');
            }
            ++$count;
            yield $association;
        }
        $counter = $this->readExact($stream, 8);
        if ($counter !== $this->encodeCount($count)) {
            throw new \UnexpectedValueException('Invalid association run format: inconsistent record counter.');
        }
        hash_update($hash, $counter);
        if (!hash_equals(hash_final($hash, true), $this->readExact($stream, 32))) {
            throw new \UnexpectedValueException('Invalid association run format: inconsistent checksum.');
        }
        $extra = @fread($stream, 1);
        if ($extra === false) { throw new \RuntimeException('Cannot verify the end of the association run stream.'); }
        if ($extra !== '') { throw new \UnexpectedValueException('Invalid association run format: trailing data.'); }
        if (!feof($stream)) { throw new \RuntimeException('Association run read made no progress at end of stream.'); }
    }

    private function decodeAssociation(string $payload): array
    {
        $offset = 0;
        $storage = $this->readField($payload, $offset);
        $path = $this->readField($payload, $offset);
        $id = $this->readField($payload, $offset);
        $maximum = (string) PHP_INT_MAX;
        if (!preg_match('/^[1-9][0-9]*$/D', $id) || strlen($id) > strlen($maximum)
            || (strlen($id) === strlen($maximum) && strcmp($id, $maximum) > 0)) {
            throw new \UnexpectedValueException('Invalid association data: emission ID must be a representable positive integer.');
        }
        if (strlen($payload) - $offset !== 2) {
            throw new \UnexpectedValueException('Invalid association run format: missing flags or surplus fields.');
        }
        $origin = match ($payload[$offset]) {
            "\x01" => 'thumbnailMp3',
            "\x02" => 'local_audio_url',
            default => throw new \UnexpectedValueException('Invalid association data: unknown origin.'),
        };
        $missing = match ($payload[$offset + 1]) {
            "\x00" => false,
            "\x01" => true,
            default => throw new \UnexpectedValueException('Invalid association data: non-boolean thumbnail flag.'),
        };
        $association = ['storage' => $storage, 'relative_path' => $path, 'emission_id' => (int) $id,
            'origin' => $origin, 'thumbnail_missing' => $missing];
        $this->validateAssociation($association);
        return $association;
    }

    private function validateAssociation(mixed $association): void
    {
        if (!is_array($association) || count($association) !== 5 || array_diff(self::KEYS, array_keys($association)) !== []
            || !is_string($association['storage']) || $association['storage'] === ''
            || !is_string($association['relative_path'])
            || !is_int($association['emission_id']) || $association['emission_id'] <= 0
            || !in_array($association['origin'], ['thumbnailMp3', 'local_audio_url'], true)
            || !is_bool($association['thumbnail_missing'])) {
            throw new \UnexpectedValueException('Invalid association data: expected exactly five correctly typed fields.');
        }
        $path = $association['relative_path'];
        // Same structural policy as the reconciler/path codec. No path rewriting.
        if ($path === '' || str_contains($path, "\0") || $path[0] === '/' || $path[0] === '\\'
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new \UnexpectedValueException('Invalid association data: relative path.');
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new \UnexpectedValueException('Invalid association data: relative path component.');
            }
        }
    }

    private function field(string $bytes): string { return pack('N', strlen($bytes)).$bytes; }

    private function readField(string $payload, int &$offset): string
    {
        if (strlen($payload) - $offset < 4) {
            throw new \UnexpectedValueException('Invalid association run format: truncated field length.');
        }
        $length = unpack('Nlength', substr($payload, $offset, 4))['length'];
        $offset += 4;
        if ($length > strlen($payload) - $offset) {
            throw new \UnexpectedValueException('Invalid association run format: field length exceeds record.');
        }
        $bytes = substr($payload, $offset, $length);
        $offset += $length;
        return $bytes;
    }

    private function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($bytes, $offset, 8192));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot complete an association run write.');
            }
            $offset += $written;
        }
    }

    private function readExact(mixed $stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = @fread($stream, min(8192, $length - strlen($bytes)));
            if ($part === false) { throw new \RuntimeException('Cannot read the association run stream.'); }
            if ($part === '') {
                if (feof($stream)) { throw new \UnexpectedValueException('Invalid association run format: truncated stream.'); }
                throw new \RuntimeException('Association run read made no progress.');
            }
            $bytes .= $part;
        }
        return $bytes;
    }

    private function assertStream(mixed $stream, bool $writing): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Expected an open stream owned by the caller.');
        }
        $mode = stream_get_meta_data($stream)['mode'];
        $supported = $writing ? strpbrk($mode, 'waxc+') !== false : str_starts_with($mode, 'r') || str_contains($mode, '+');
        if (!$supported) { throw new \InvalidArgumentException('The caller stream does not support the requested operation.'); }
    }

    private function encodeCount(int $count): string
    {
        return pack('N2', intdiv($count, 0x100000000), $count % 0x100000000);
    }
}
