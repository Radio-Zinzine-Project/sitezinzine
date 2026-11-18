<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Lossless framed paths on caller-owned streams; no sorting or filesystem lookup. */
final readonly class AudioInventoryPathRunCodec
{
    private const SIGNATURE = 'ZMP3RUN';
    private const VERSION = "\x01";
    private const UINT32_MAX = 0xffffffff;

    public function __construct(private int $maxRecordBytes)
    {
        if ($maxRecordBytes < 1 || $maxRecordBytes > self::UINT32_MAX) {
            throw new \InvalidArgumentException('Record byte limit must fit a positive unsigned 32-bit length.');
        }
    }

    /**
     * V1 frame, all integers big-endian:
     *   7-byte signature ZMP3RUN, 1-byte version 01;
     *   repeated [uint32 positive length, exact path bytes];
     *   uint32 zero terminator, uint64 record count, 32-byte SHA-256.
     * The checksum covers the entire frame preceding it, including the footer.
     * It detects corruption, not provenance or authenticity.
     *
     * Writes from the current position, handling partial writes, then flushes.
     * No seek, truncate, close or fsync. Failure may leave an unfinished frame;
     * only a normal return confirms that all frame bytes were written/flushed.
     * Source exceptions propagate without completing or repairing the frame.
     *
     * @param iterable<string> $paths
     */
    public function write(mixed $stream, iterable $paths): int
    {
        $this->assertStream($stream, true);
        $header = self::SIGNATURE . self::VERSION;
        $this->writeAll($stream, $header);
        $hash = hash_init('sha256');
        hash_update($hash, $header);
        $count = 0;
        foreach ($paths as $path) {
            $this->validatePath($path);
            $length = strlen($path);
            if ($length > $this->maxRecordBytes) {
                throw new \UnexpectedValueException('Path record length exceeds the configured byte limit.');
            }
            $prefix = pack('N', $length);
            $this->writeAll($stream, $prefix);
            $this->writeAll($stream, $path);
            hash_update($hash, $prefix);
            hash_update($hash, $path);
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
            throw new \RuntimeException('Cannot flush the path run stream.');
        }
        return $count;
    }

    /**
     * Reads one frame at the current position, requiring EOF after its checksum.
     * Reading is lazy: yielded paths remain provisional until the generator is
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
            throw new \UnexpectedValueException('Invalid path run signature.');
        }
        if ($this->readExact($stream, 1) !== self::VERSION) {
            throw new \UnexpectedValueException('Unsupported path run version.');
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
            if ($length > $this->maxRecordBytes) {
                throw new \UnexpectedValueException('Path record length exceeds the configured byte limit.');
            }
            $path = $this->readExact($stream, $length);
            $this->validatePath($path);
            hash_update($hash, $path);
            if ($count === PHP_INT_MAX) {
                throw new \OverflowException('Path record count exceeds the PHP integer range.');
            }
            ++$count;
            yield $path;
        }
        $counter = $this->readExact($stream, 8);
        if ($counter !== $this->encodeCount($count)) {
            throw new \UnexpectedValueException('Path run record counter is inconsistent.');
        }
        hash_update($hash, $counter);
        if (!hash_equals(hash_final($hash, true), $this->readExact($stream, 32))) {
            throw new \UnexpectedValueException('Path run checksum is inconsistent.');
        }
        $extra = @fread($stream, 1);
        if ($extra === false) {
            throw new \RuntimeException('Cannot verify the end of the path run stream.');
        }
        if ($extra !== '') {
            throw new \UnexpectedValueException('Unexpected data after path run termination.');
        }
        if (!feof($stream)) {
            throw new \RuntimeException('Cannot establish the end of the path run stream.');
        }
    }

    private function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($bytes, $offset, 8192));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot complete a path run write.');
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
                throw new \RuntimeException('Cannot read the path run stream.');
            }
            if ($part === '') {
                if (feof($stream)) {
                    throw new \UnexpectedValueException('Truncated path run stream.');
                }
                throw new \RuntimeException('Path run read made no progress.');
            }
            $bytes .= $part;
        }
        return $bytes;
    }

    private function validatePath(mixed $path): void
    {
        // Same structural policy as AudioInventoryPathComparator, without its
        // ordering/uniqueness checks. No normalization, UTF-8 or extension rule.
        if (!is_string($path) || $path === '' || str_contains($path, "\0")
            || str_starts_with($path, '/') || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new \UnexpectedValueException('Expected a structurally valid relative path string.');
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new \UnexpectedValueException('Relative paths cannot contain empty or traversal components.');
            }
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
