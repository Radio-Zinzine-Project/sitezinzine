<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Lossless framed opaque run IDs on caller-owned streams; no path interpretation. */
final readonly class AudioInventoryRunCatalogCodec
{
    private const SIGNATURE = 'ZMP3CAT';
    private const VERSION = "\x01";
    private const UINT32_MAX = 0xffffffff;

    public function __construct(private int $maxIdentifierBytes)
    {
        if ($maxIdentifierBytes < 1 || $maxIdentifierBytes > self::UINT32_MAX) {
            throw new \InvalidArgumentException('Record byte limit must fit a positive unsigned 32-bit length.');
        }
    }

    /**
     * V1 frame, all integers big-endian:
     *   7-byte signature ZMP3CAT, 1-byte version 01;
     *   repeated [uint32 positive length, exact identifier bytes];
     *   uint32 zero terminator, uint64 record count, 32-byte SHA-256.
     * The checksum covers the entire frame preceding it, including the footer.
     * It detects corruption, not provenance or authenticity.
     *
     * Writes from the current position, handling partial writes, then flushes.
     * No seek, truncate, close or fsync. Failure may leave an unfinished frame;
     * only a normal return confirms that all frame bytes were written/flushed.
     * Source exceptions propagate without completing or repairing the frame.
     *
     * @param iterable<string> $runIds
     */
    public function write(mixed $stream, iterable $runIds): int
    {
        $this->assertStream($stream, true);
        $header = self::SIGNATURE . self::VERSION;
        $this->writeAll($stream, $header);
        $hash = hash_init('sha256');
        hash_update($hash, $header);
        $count = 0;
        foreach ($runIds as $runId) {
            $this->validateIdentifier($runId);
            $length = strlen($runId);
            if ($length > $this->maxIdentifierBytes) {
                throw new \UnexpectedValueException('Path record length exceeds the configured byte limit.');
            }
            $prefix = pack('N', $length);
            $this->writeAll($stream, $prefix);
            $this->writeAll($stream, $runId);
            hash_update($hash, $prefix);
            hash_update($hash, $runId);
            if ($count === PHP_INT_MAX) {
                throw new \OverflowException('Path record count exceeds the PHP integer range.');
            }
            ++$count;
        }
        $footer = pack('N', 0) . $this->encodeCount($count);
        $this->writeAll($stream, $footer);
        hash_update($hash, $footer);
        $this->writeAll($stream, hash_final($hash, true));
        if (!@fflush($stream)) {
            throw new \RuntimeException('Cannot flush the run catalog stream.');
        }
        return $count;
    }

    /**
     * Reads one frame at the current position, requiring EOF after its checksum.
     * Reading is lazy: yielded identifiers remain provisional until the generator is
     * fully exhausted and its footer/checksum/EOF have all been validated.
     * Releasing a partial iterator neither validates the rest nor closes the
     * stream. The caller alone manages stream position, lifetime and cleanup.
     *
     * @return \Generator<int, string>
     */
    public function read(mixed $stream): \Generator
    {
        $this->assertStream($stream, false);
        return $this->decode($stream);
    }

    private function decode(mixed $stream): \Generator
    {
        if ($this->readExact($stream, strlen(self::SIGNATURE)) !== self::SIGNATURE) {
            throw new \UnexpectedValueException('Invalid run catalog signature.');
        }
        if ($this->readExact($stream, 1) !== self::VERSION) {
            throw new \UnexpectedValueException('Unsupported run catalog version.');
        }
        $hash = hash_init('sha256');
        hash_update($hash, self::SIGNATURE . self::VERSION);
        $count = 0;
        while (true) {
            $prefix = $this->readExact($stream, 4);
            $length = unpack('Nlength', $prefix)['length'];
            hash_update($hash, $prefix);
            if ($length === 0) {
                break;
            }
            // Check before reading/allocating any announced payload.
            if ($length > $this->maxIdentifierBytes) {
                throw new \UnexpectedValueException('Path record length exceeds the configured byte limit.');
            }
            $runId = $this->readExact($stream, $length);
            $this->validateIdentifier($runId);
            hash_update($hash, $runId);
            if ($count === PHP_INT_MAX) {
                throw new \OverflowException('Path record count exceeds the PHP integer range.');
            }
            ++$count;
            yield $runId;
        }
        $counter = $this->readExact($stream, 8);
        if ($counter !== $this->encodeCount($count)) {
            throw new \UnexpectedValueException('Run catalog record counter is inconsistent.');
        }
        hash_update($hash, $counter);
        if (!hash_equals(hash_final($hash, true), $this->readExact($stream, 32))) {
            throw new \UnexpectedValueException('Run catalog checksum is inconsistent.');
        }
        $extra = @fread($stream, 1);
        if ($extra === false) {
            throw new \RuntimeException('Cannot verify the end of the run catalog stream.');
        }
        if ($extra !== '') {
            throw new \UnexpectedValueException('Unexpected data after run catalog termination.');
        }
        if (!feof($stream)) {
            throw new \RuntimeException('Cannot establish the end of the run catalog stream.');
        }
    }

    private function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($bytes, $offset, 8192));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot complete a run catalog write.');
            }
            $offset += $written;
        }
    }

    private function readExact(mixed $stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = @fread($stream, min(8192, $length - strlen($bytes)));
            if ($part === false) {
                throw new \RuntimeException('Cannot read the run catalog stream.');
            }
            if ($part === '') {
                if (feof($stream)) {
                    throw new \UnexpectedValueException('Truncated run catalog stream.');
                }
                throw new \RuntimeException('Run catalog read made no progress.');
            }
            $bytes .= $part;
        }
        return $bytes;
    }

    private function validateIdentifier(mixed $runId): void
    {
        if (!is_string($runId) || $runId === '') {
            throw new \UnexpectedValueException('Expected a nonempty opaque identifier string.');
        }
    }

    private function assertStream(mixed $stream, bool $writing): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Expected an open stream owned by the caller.');
        }
        $mode = stream_get_meta_data($stream)['mode'];
        $supported = $writing ? strpbrk($mode, 'waxc+') !== false : str_starts_with($mode, 'r') || str_contains($mode, '+');
        if (!$supported) {
            throw new \InvalidArgumentException('The caller stream does not support the requested operation.');
        }
    }

    private function encodeCount(int $count): string
    {
        return pack('N2', intdiv($count, 0x100000000), $count % 0x100000000);
    }
}
