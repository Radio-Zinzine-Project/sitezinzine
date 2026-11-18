<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use PHPUnit\Framework\TestCase;

final class AudioInventoryRunCatalogCodecTest extends TestCase
{
    private const HEADER = "ZMP3CAT\x01";
    private array $streams = [];
    private bool $wrapperRegistered = false;

    protected function tearDown(): void
    {
        try {
            foreach ($this->streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
            if ($this->wrapperRegistered) { stream_wrapper_unregister('zinzinecatalog'); }
        } finally { $this->streams = []; parent::tearDown(); }
    }

    /** @dataProvider identifierSequences */
    public function testVersionedFormatAndExactRoundTripWithoutSortingOrDeduplication(array $runIds): void
    {
        $codec = new AudioInventoryRunCatalogCodec(1024);
        $stream = $this->memory();
        $before = $runIds;
        self::assertSame(count($runIds), $codec->write($stream, $runIds));
        self::assertTrue(is_resource($stream));
        rewind($stream);
        self::assertSame(self::frame($runIds), stream_get_contents($stream));
        rewind($stream);
        self::assertSame($runIds, iterator_to_array($codec->read($stream), false));
        self::assertTrue(is_resource($stream));
        self::assertSame($before, $runIds);
    }

    public static function identifierSequences(): array
    {
        return [
            'empty' => [[]], 'one' => [['a.id']], 'unsorted and duplicate' => [['z.id', 'a.id', 'a.id']],
            'spaces' => [['Archives anciennes/Émission du 1er mai.id']], 'accents' => [['Été/Émission.MP3']],
            'literal backslash' => [['dir\\name.id']], 'newline' => [["dir/file\nname.id"]],
            'non UTF-8' => [["dir/\xff\xfe.id"]], 'zero-looking identifier' => [['0']], 'opaque bytes' => [["\0../C:/id", '/root', '../id', 'a//b', 'x', 'x']],
        ];
    }

    public function testReadIsLazyAndYieldsBeforeReadingTheNextRecordOrFooter(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->memory(self::frame(['a.id', 'b.id']));
        $runIds = $codec->read($stream);
        self::assertInstanceOf(\Generator::class, $runIds);
        self::assertSame(0, ftell($stream));
        self::assertSame('a.id', $runIds->current());
        self::assertSame(strlen(self::HEADER) + 4 + strlen('a.id'), ftell($stream));
        $runIds->next();
        self::assertSame('b.id', $runIds->current());
        $runIds->next();
        self::assertFalse($runIds->valid());
        self::assertTrue(is_resource($stream));
    }

    /** @dataProvider corruptFrames */
    public function testTruncatedOrCorruptFramesNeverFinishNormally(string $frame): void
    {
        $codec = new AudioInventoryRunCatalogCodec(1024);
        $stream = $this->memory($frame);
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array($codec->read($stream), false);
    }

    public static function corruptFrames(): array
    {
        $record = pack('N', 4) . 'a.id';
        $valid = self::frame(['a.id']);
        $changedPath = $valid;
        $changedPath[strlen(self::HEADER) + 4] = 'b';
        return [
            'empty raw stream is not encoded empty run' => [''],
            'incorrect signature' => ['X' . substr($valid, 1)],
            'unknown version' => ['ZMP3CAT' . "\x02" . substr($valid, 8)],
            'signature truncated' => ['ZMP'], 'version absent' => ['ZMP3CAT'],
            'length one byte' => [self::HEADER . "\0"],
            'length three bytes' => [self::HEADER . "\0\0\0"],
            'record content truncated' => [self::HEADER . pack('N', 5) . 'a.m'],
            'terminator missing at record boundary' => [self::HEADER . $record],
            'terminator truncated' => [self::HEADER . $record . "\0\0"],
            'counter missing' => [self::HEADER . $record . pack('N', 0)],
            'counter truncated' => [self::HEADER . $record . pack('N', 0) . str_repeat("\0", 7)],
            'counter incorrect despite matching checksum' => [self::frame(['a.id'], 2)],
            'high counter word incorrect' => [self::frame(['a.id'], 1, 1)],
            'checksum missing' => [substr($valid, 0, -32)],
            'checksum truncated' => [substr($valid, 0, -1)],
            'valid-looking path bytes altered' => [$changedPath],
            'extra bytes' => [$valid . 'x'],
            'another frame appended' => [$valid . self::frame([])],
        ];
    }

    public function testAdvertisedOversizedLengthIsRejectedBeforeAttemptingPayloadRead(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(16);
        $stream = $this->memory(self::HEADER . pack('N', 0xffffffff));
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('length');
        iterator_to_array($codec->read($stream), false);
    }

    public function testRecordLimitCountsBytesRatherThanUnicodeCharacters(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(2);
        $stream = $this->memory();
        self::assertSame(1, $codec->write($stream, ['é']));
        rewind($stream);
        self::assertSame(['é'], iterator_to_array($codec->read($stream), false));
        $this->expectException(\UnexpectedValueException::class);
        $codec->write($this->memory(), ['été']);
    }

    /** @dataProvider invalidIdentifiers */
    public function testInvalidIdentifiersAreRejectedBeforeTheirRecordIsWritten(mixed $runId): void
    {
        $codec = new AudioInventoryRunCatalogCodec(1024);
        $stream = $this->memory();
        try {
            $codec->write($stream, [$runId]);
            self::fail('Invalid record must not be written.');
        } catch (\UnexpectedValueException $error) {
            rewind($stream);
            self::assertSame(self::HEADER, stream_get_contents($stream));
            self::assertTrue(is_resource($stream));
        }
    }

    public static function invalidIdentifiers(): array { return [[null], [17], [false], [[]], [new \stdClass()], ['']]; }

    /** @dataProvider invalidLimits */
    public function testInvalidLimitIsRejected(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryRunCatalogCodec($limit);
    }

    public static function invalidLimits(): array { return [[0], [-1], [PHP_INT_MAX]]; }

    /** @dataProvider invalidStreamValues */
    public function testOnlyOpenStreamResourcesAreAccepted(mixed $stream): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $this->expectException(\InvalidArgumentException::class);
        $codec->write($stream, []);
    }

    public static function invalidStreamValues(): array { return [[null], [false], ['not-a-stream'], [new \stdClass()]]; }

    public function testClosedStreamIsRejectedByRead(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->memory(); fclose($stream);
        $this->expectException(\InvalidArgumentException::class);
        $codec->read($stream);
    }

    public function testPartialWritesAreRetriedAndPreserveTheCompleteFrame(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->controlled();
        AudioInventoryCatalogTestStream::$maxWrite = 2;
        self::assertSame(2, $codec->write($stream, ['b.id', 'a.id']));
        self::assertSame(self::frame(['b.id', 'a.id']), AudioInventoryCatalogTestStream::$data);
        self::assertGreaterThan(10, AudioInventoryCatalogTestStream::$writeCalls);
        self::assertSame(0, AudioInventoryCatalogTestStream::$closeCalls);
        self::assertTrue(is_resource($stream));
    }

    /** @dataProvider failedWriteOffsets */
    public function testWriteFailureIncludingTerminatorCounterAndChecksumNeverReturnsCount(int $offset): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->controlled();
        AudioInventoryCatalogTestStream::$failWriteAfter = $offset;
        $result = null;
        try { $result = $codec->write($stream, ['a.id']); self::fail('Write must fail.'); }
        catch (\RuntimeException $error) { self::assertNull($result); }
        self::assertSame(0, AudioInventoryCatalogTestStream::$closeCalls);
        self::assertTrue(is_resource($stream));
    }

    public static function failedWriteOffsets(): array { return [[0], [17], [21], [29]]; }

    public function testZeroProgressWriteIsAnErrorRatherThanAnInfiniteRetry(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->controlled();
        AudioInventoryCatalogTestStream::$zeroWrite = true;
        $this->expectException(\RuntimeException::class);
        $codec->write($stream, []);
    }

    public function testFlushFailureDoesNotAnnounceSuccessfulWrite(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->controlled();
        AudioInventoryCatalogTestStream::$flushFails = true;
        $this->expectException(\RuntimeException::class);
        $codec->write($stream, []);
    }

    public function testPartialReadsAreHandledWithoutClosingTheStream(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->controlled(self::frame(['a.id', 'b.id']));
        AudioInventoryCatalogTestStream::$maxRead = 2;
        self::assertSame(['a.id', 'b.id'], iterator_to_array($codec->read($stream), false));
        self::assertGreaterThan(10, AudioInventoryCatalogTestStream::$readCalls);
        self::assertSame(0, AudioInventoryCatalogTestStream::$closeCalls);
    }

    /** @dataProvider readFailureModes */
    public function testReadFailureOrNoProgressNeverBecomesNormalEof(bool $emptyRead): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->controlled(self::frame([]));
        AudioInventoryCatalogTestStream::$readFails = !$emptyRead;
        AudioInventoryCatalogTestStream::$emptyRead = $emptyRead;
        $this->expectException(\RuntimeException::class);
        iterator_to_array($codec->read($stream), false);
    }

    public static function readFailureModes(): array { return [[false], [true]]; }

    public function testSourceExceptionPropagatesAndLeavesAnExplicitlyUnfinishedRun(): void
    {
        $failure = new \RuntimeException('Original source failure');
        $runIds = (static function () use ($failure): \Generator { yield 'a.id'; throw $failure; })();
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->memory();
        $result = null;
        try { $result = $codec->write($stream, $runIds); self::fail('Source error must propagate.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertNull($result);
        self::assertTrue(is_resource($stream));
        rewind($stream);
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array($codec->read($stream), false);
    }

    public function testCallerControlsPositionAndMayReleaseAnIncompleteRead(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(32);
        $stream = $this->memory('prefix'); fseek($stream, 0, SEEK_END);
        self::assertSame(1, $codec->write($stream, ['a.id']));
        fseek($stream, strlen('prefix'));
        $runIds = $codec->read($stream);
        self::assertSame('a.id', $runIds->current());
        unset($runIds);
        self::assertTrue(is_resource($stream));
        self::assertTrue(fseek($stream, 0) === 0);
        self::assertSame('prefix', fread($stream, 6));
    }

    public function testLargeLazyRoundTripHasBoundedMemory(): void
    {
        $codec = new AudioInventoryRunCatalogCodec(64);
        $stream = fopen('php://temp/maxmemory:65536', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open isolated temporary test stream.'); }
        $this->streams[] = $stream;
        $produced = $maxWriteMemory = $maxReadMemory = 0;
        $baseline = memory_get_usage();
        $runIds = (static function () use (&$produced, &$maxWriteMemory, $baseline): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                ++$produced; $maxWriteMemory = max($maxWriteMemory, memory_get_usage() - $baseline);
                yield 'CAT/' . $i . '.id';
            }
        })();
        self::assertSame(100000, $codec->write($stream, $runIds));
        self::assertSame(100000, $produced);
        rewind($stream);
        $read = 0;
        foreach ($codec->read($stream) as $runId) {
            if ($runId !== 'CAT/' . $read . '.id') { self::fail('Round-trip order or bytes changed.'); }
            ++$read; $maxReadMemory = max($maxReadMemory, memory_get_usage() - $baseline);
        }
        self::assertSame(100000, $read);
        self::assertLessThan(2 * 1024 * 1024, $maxWriteMemory);
        self::assertLessThan(2 * 1024 * 1024, $maxReadMemory);
        self::assertTrue(is_resource($stream));
    }

    public function testInterruptedReadDoesNotValidateCorruptFooter(): void
    {
        $bytes = self::frame(['opaque']);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        $stream = $this->memory($bytes);
        $codec = new AudioInventoryRunCatalogCodec(32);
        $reader = $codec->read($stream);
        self::assertSame('opaque', $reader->current());
        self::assertLessThan(strlen($bytes), ftell($stream));
        unset($reader);
        self::assertTrue(is_resource($stream));
        rewind($stream);
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array($codec->read($stream), false);
    }

    public function testPathRunFormatIsNotAcceptedAsCatalog(): void
    {
        $stream = $this->memory("ZMP3RUN\x01".substr(self::frame([]), 8));
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array((new AudioInventoryRunCatalogCodec(32))->read($stream), false);
    }

    private function memory(string $bytes = ''): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open isolated memory stream.'); }
        $this->streams[] = $stream;
        if ($bytes !== '') { fwrite($stream, $bytes); rewind($stream); }
        return $stream;
    }

    private function controlled(string $bytes = ''): mixed
    {
        if (!stream_wrapper_register('zinzinecatalog', AudioInventoryCatalogTestStream::class)) { throw new \RuntimeException('Cannot register local test wrapper.'); }
        $this->wrapperRegistered = true;
        AudioInventoryCatalogTestStream::reset($bytes);
        $stream = fopen('zinzinecatalog://isolated', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open local test wrapper.'); }
        $this->streams[] = $stream;
        return $stream;
    }

    private static function frame(array $runIds, ?int $declaredCount = null, int $highCount = 0): string
    {
        $bytes = self::HEADER;
        foreach ($runIds as $runId) { $bytes .= pack('N', strlen($runId)) . $runId; }
        $bytes .= pack('N', 0) . pack('N2', $highCount, $declaredCount ?? count($runIds));
        return $bytes . hash('sha256', $bytes, true);
    }
}

/** In-memory fault injection only, never registered outside this targeted PHPUnit process. */
final class AudioInventoryCatalogTestStream
{
    public mixed $context;
    public static string $data = '';
    public static int $maxWrite = 8192;
    public static int $maxRead = 8192;
    public static ?int $failWriteAfter = null;
    public static bool $zeroWrite = false;
    public static bool $readFails = false;
    public static bool $emptyRead = false;
    public static bool $flushFails = false;
    public static int $writeCalls = 0;
    public static int $readCalls = 0;
    public static int $closeCalls = 0;
    private int $position = 0;

    public static function reset(string $bytes): void
    {
        self::$data = $bytes; self::$maxWrite = self::$maxRead = 8192; self::$failWriteAfter = null;
        self::$zeroWrite = self::$readFails = self::$emptyRead = self::$flushFails = false;
        self::$writeCalls = self::$readCalls = self::$closeCalls = 0;
    }

    public function stream_open(string $runId, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $bytes): int|false
    {
        ++self::$writeCalls;
        if (self::$zeroWrite) { return 0; }
        if (self::$failWriteAfter !== null && $this->position >= self::$failWriteAfter) { return false; }
        $length = min(strlen($bytes), self::$maxWrite, self::$failWriteAfter === null ? PHP_INT_MAX : self::$failWriteAfter - $this->position);
        self::$data = substr(self::$data, 0, $this->position) . substr($bytes, 0, $length) . substr(self::$data, $this->position + $length);
        $this->position += $length;
        return $length;
    }
    public function stream_read(int $count): string|false
    {
        ++self::$readCalls;
        if (self::$readFails) { return false; }
        if (self::$emptyRead) { return ''; }
        $bytes = substr(self::$data, $this->position, min($count, self::$maxRead));
        $this->position += strlen($bytes);
        return $bytes;
    }
    public function stream_eof(): bool { return !self::$emptyRead && $this->position >= strlen(self::$data); }
    public function stream_tell(): int { return $this->position; }
    public function stream_stat(): array { return []; }
    public function stream_flush(): bool { return !self::$flushFails; }
    public function stream_close(): void { ++self::$closeCalls; }
}
