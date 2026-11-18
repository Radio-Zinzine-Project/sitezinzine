<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunCodec;
use PHPUnit\Framework\TestCase;

final class AudioInventoryAssociationRunCodecTest extends TestCase
{
    private const HEADER = "ZMP3ASC\x01";
    private array $streams = [];
    private bool $registered = false;

    protected function tearDown(): void
    {
        try {
            foreach ($this->streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
            if ($this->registered) { stream_wrapper_unregister('zinzineassociation'); }
        } finally { $this->streams = []; parent::tearDown(); }
    }

    private static function association(string $path = 'a.mp3', int $id = 17, string $origin = 'thumbnailMp3', bool $missing = false): array
    {
        return ['storage' => 'emissionsMp3', 'relative_path' => $path, 'emission_id' => $id, 'origin' => $origin, 'thumbnail_missing' => $missing];
    }

    /** @dataProvider sequences */
    public function testExactVersionedRoundTripPreservesOrderFieldsAndDuplicates(array $rows): void
    {
        $codec = new AudioInventoryAssociationRunCodec(1024);
        $stream = $this->memory();
        $before = $rows;
        self::assertSame(count($rows), $codec->write($stream, $rows));
        self::assertTrue(is_resource($stream));
        rewind($stream);
        self::assertSame(self::frame(array_map(self::payload(...), $rows)), stream_get_contents($stream));
        rewind($stream);
        self::assertSame($rows, iterator_to_array($codec->read($stream), false));
        self::assertSame($before, $rows);
        self::assertTrue(is_resource($stream));
    }

    public static function sequences(): array
    {
        $a = self::association();
        return [
            'empty' => [[]], 'one' => [[$a]],
            'unsorted and identical repeats' => [[self::association('z'), $a, $a]],
            'both origins' => [[$a, self::association('a.mp3', 17, 'local_audio_url', true)]],
            'multiple emissions' => [[$a, self::association('a.mp3', 18)]],
            'case and accents' => [[self::association('Été/A.MP3'), self::association('été/a.mp3')]],
            'historical characters' => [[self::association("dir/a b\n#?%.mp3"), self::association('dir\\name')]],
            'raw bytes' => [[self::association("dir/\xff\xfe.mp3")]],
            'zero-looking path' => [[self::association('0')]],
            'largest integer' => [[self::association('a', PHP_INT_MAX)]],
            'nonempty storage bytes' => [[array_replace($a, ['storage' => "other\0\xff"]) ]],
        ];
    }

    public function testArrayKeyOrderIsNotPartOfAssociationData(): void
    {
        $row = self::association();
        $stream = $this->memory(); $codec = new AudioInventoryAssociationRunCodec(1024);
        self::assertSame(1, $codec->write($stream, [array_reverse($row, true)]));
        rewind($stream);
        self::assertSame([$row], iterator_to_array($codec->read($stream), false));
    }

    /** @dataProvider invalidAssociations */
    public function testInvalidDataIsRejectedBeforeWritingItsRecord(mixed $row): void
    {
        $codec = new AudioInventoryAssociationRunCodec(1024); $stream = $this->memory();
        try { $codec->write($stream, [$row]); self::fail('Invalid association accepted.'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('association', strtolower($error->getMessage())); }
        rewind($stream); self::assertSame(self::HEADER, stream_get_contents($stream));
        self::assertTrue(is_resource($stream));
    }

    public static function invalidAssociations(): array
    {
        $a = self::association();
        $cases = [[null], ['string'], [[]], [17], [array_replace($a, ['extra' => true])]];
        foreach (array_keys($a) as $key) { $missing = $a; unset($missing[$key]); $cases[] = [$missing]; }
        foreach ([['storage', ''], ['storage', 17], ['storage', null], ['relative_path', 17],
            ['relative_path', ''], ['relative_path', '/absolute'], ['relative_path', '\\absolute'],
            ['relative_path', 'C:/drive'], ['relative_path', 'a/../b'], ['relative_path', 'a/./b'],
            ['relative_path', 'a//b'], ['relative_path', "nul\0path"],
            ['emission_id', '17'], ['emission_id', 0], ['emission_id', -1], ['emission_id', 1.0],
            ['emission_id', true], ['origin', 'external'], ['origin', 0], ['thumbnail_missing', 0],
            ['thumbnail_missing', null], ['thumbnail_missing', 'false']] as [$key, $value]) {
            $cases[] = [array_replace($a, [$key => $value])];
        }
        return $cases;
    }

    public function testRecordBudgetIncludesAllEncodedFieldsAndAcceptsExactBoundary(): void
    {
        $a = self::association('é'); $size = strlen(self::payload($a));
        $stream = $this->memory(); $codec = new AudioInventoryAssociationRunCodec($size);
        self::assertSame(1, $codec->write($stream, [$a])); rewind($stream);
        self::assertSame([$a], iterator_to_array($codec->read($stream), false));
        $this->expectException(\UnexpectedValueException::class);
        (new AudioInventoryAssociationRunCodec($size - 1))->write($this->memory(), [$a]);
    }

    public function testOversizedAnnouncedRecordIsRejectedBeforePayloadRead(): void
    {
        $stream = $this->memory(self::HEADER . pack('N', 1000));
        $this->expectException(\UnexpectedValueException::class); $this->expectExceptionMessage('length');
        try { iterator_to_array((new AudioInventoryAssociationRunCodec(32))->read($stream), false); }
        finally { self::assertSame(12, ftell($stream)); }
    }

    /** @dataProvider invalidLimits */
    public function testInvalidRecordLimit(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class); new AudioInventoryAssociationRunCodec($limit);
    }
    public static function invalidLimits(): array { return [[0], [-1], [0x100000000]]; }

    /** @dataProvider corruptFrames */
    public function testCorruptFramesCannotComplete(string $frame): void
    {
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array((new AudioInventoryAssociationRunCodec(1024))->read($this->memory($frame)), false);
    }

    public static function corruptFrames(): array
    {
        $valid = self::frame([self::payload(self::association())]);
        $changed = $valid; $changed[16] = 'X';
        $cases = [
            'empty raw input' => [''], 'signature' => ['X'.substr($valid, 1)],
            'version' => ['ZMP3ASC' . "\x02" . substr($valid, 8)],
            'changed payload' => [$changed], 'counter' => [self::frame([self::payload(self::association())], 2)],
            'high counter word' => [self::frame([], 0, 1)],
            'checksum' => [substr($valid, 0, -32) . str_repeat("\0", 32)],
            'extra bytes' => [$valid.'x'], 'appended frame' => [$valid.self::frame([])],
        ];
        // Exercise every truncation boundary, including fields, footer and checksum.
        for ($i = 1; $i < strlen($valid); ++$i) { $cases['truncated at '.$i] = [substr($valid, 0, $i)]; }
        return $cases;
    }

    /** @dataProvider invalidPayloads */
    public function testDecodedFieldsAreStrictlyValidatedEvenWithCorrectChecksum(string $payload): void
    {
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array((new AudioInventoryAssociationRunCodec(1024))->read($this->memory(self::frame([$payload]))), false);
    }

    public static function invalidPayloads(): array
    {
        $prefix = self::field('emissionsMp3').self::field('a');
        $valid = $prefix.self::field('17')."\x01\x00";
        $cases = [[pack('N', 0xffffffff)], [self::field('').self::field('a').self::field('17')."\x01\x00"],
            [self::field('emissionsMp3').self::field('../outside').self::field('17')."\x01\x00"],
            [$prefix.self::field('17')."\x03\x00"], [$prefix.self::field('17')."\x01\x02"],
            [substr($valid, 0, -1)], [$valid.'extra'], ['x']];
        foreach (['', '0', '-1', '+17', '017', '17.0', '17x', (string)PHP_INT_MAX.'0'] as $id) {
            $cases[] = [$prefix.self::field($id)."\x01\x00"];
        }
        return $cases;
    }

    public function testReadIsLazyAndFooterValidationRequiresFullExhaustion(): void
    {
        $a = self::association(); $payload = self::payload($a);
        $stream = $this->memory(self::frame([$payload], 2));
        $iterator = (new AudioInventoryAssociationRunCodec(1024))->read($stream);
        self::assertSame(0, ftell($stream)); self::assertSame($a, $iterator->current());
        self::assertSame(12 + strlen($payload), ftell($stream));
        self::assertTrue(is_resource($stream));
        $this->expectException(\UnexpectedValueException::class); $iterator->next();
    }

    public function testCallerControlsPositionAndCanReleasePartialRead(): void
    {
        $a = self::association(); $stream = $this->memory('prefix'); fseek($stream, 0, SEEK_END);
        $codec = new AudioInventoryAssociationRunCodec(1024);
        self::assertSame(1, $codec->write($stream, [$a]));
        fseek($stream, 6); $iterator = $codec->read($stream); self::assertSame($a, $iterator->current());
        unset($iterator); self::assertTrue(is_resource($stream)); rewind($stream);
        self::assertSame('prefix', fread($stream, 6));
    }

    /** @dataProvider invalidStreams */
    public function testInvalidStreamArguments(mixed $stream): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AudioInventoryAssociationRunCodec(1024))->write($stream, []);
    }
    public static function invalidStreams(): array { return [[null], [false], ['php://memory'], [17]]; }

    public function testClosedAndWrongModeStreamsAreRejected(): void
    {
        $codec = new AudioInventoryAssociationRunCodec(1024);
        $closed = $this->memory(); fclose($closed);
        foreach ([[$closed, true], [$closed, false], [fopen('php://memory', 'rb'), true], [fopen('php://output', 'wb'), false]] as [$stream, $write]) {
            if (is_resource($stream)) { $this->streams[] = $stream; }
            try { $write ? $codec->write($stream, []) : $codec->read($stream); self::fail('Invalid stream accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testPartialWritesAreHandledWithoutClosing(): void
    {
        $stream = $this->controlled(); AudioInventoryAssociationCodecTestStream::$maxWrite = 2;
        $rows = [self::association('b'), self::association('a')];
        self::assertSame(2, (new AudioInventoryAssociationRunCodec(1024))->write($stream, $rows));
        self::assertSame(self::frame(array_map(self::payload(...), $rows)), AudioInventoryAssociationCodecTestStream::$data);
        self::assertGreaterThan(10, AudioInventoryAssociationCodecTestStream::$writeCalls);
        self::assertSame(0, AudioInventoryAssociationCodecTestStream::$closeCalls);
    }

    /** @dataProvider writeFailureOffsets */
    public function testWriteErrorsAtHeaderRecordAndTrailerNeverReturnSuccess(int $offset): void
    {
        $stream = $this->controlled(); AudioInventoryAssociationCodecTestStream::$failWriteAfter = $offset;
        $result = null;
        try { $result = (new AudioInventoryAssociationRunCodec(1024))->write($stream, [self::association()]); self::fail('Write failure hidden.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('write', strtolower($error->getMessage())); }
        self::assertNull($result); self::assertSame(0, AudioInventoryAssociationCodecTestStream::$closeCalls);
    }
    public static function writeFailureOffsets(): array
    {
        $end = 12 + strlen(self::payload(self::association()));
        return [[0], [10], [18], [$end], [$end + 4], [$end + 12]];
    }

    public function testZeroWriteProgressFails(): void
    {
        $stream = $this->controlled(); AudioInventoryAssociationCodecTestStream::$zeroWrite = true;
        $this->expectException(\RuntimeException::class);
        (new AudioInventoryAssociationRunCodec(1024))->write($stream, []);
    }

    public function testFlushFailureCannotReturnSuccess(): void
    {
        $stream = $this->controlled(); AudioInventoryAssociationCodecTestStream::$flushFails = true;
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('flush');
        (new AudioInventoryAssociationRunCodec(1024))->write($stream, []);
    }

    public function testPartialReadsAreHandledWithoutClosing(): void
    {
        $a = self::association(); $stream = $this->controlled(self::frame([self::payload($a)]));
        AudioInventoryAssociationCodecTestStream::$maxRead = 2;
        self::assertSame([$a], iterator_to_array((new AudioInventoryAssociationRunCodec(1024))->read($stream), false));
        self::assertGreaterThan(10, AudioInventoryAssociationCodecTestStream::$readCalls);
        self::assertSame(0, AudioInventoryAssociationCodecTestStream::$closeCalls);
    }

    /** @dataProvider readFaults */
    public function testReadErrorAndZeroProgressAreNotNormalEof(bool $empty, int $after): void
    {
        $stream = $this->controlled(self::frame([]));
        AudioInventoryAssociationCodecTestStream::$failReadAfter = $after;
        AudioInventoryAssociationCodecTestStream::$emptyRead = $empty;
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new AudioInventoryAssociationRunCodec(1024))->read($stream), false);
    }
    public static function readFaults(): array { return [[false, 0], [true, 0], [false, 52], [true, 52]]; }

    public function testSourceFailurePropagatesWithoutFinishingOrClosingFrame(): void
    {
        $failure = new \LogicException('source');
        $source = (static function () use ($failure): \Generator { yield self::association(); throw $failure; })();
        $codec = new AudioInventoryAssociationRunCodec(1024); $stream = $this->memory(); $result = null;
        try { $result = $codec->write($stream, $source); self::fail('Source failure hidden.'); }
        catch (\LogicException $actual) { self::assertSame($failure, $actual); }
        self::assertNull($result); self::assertTrue(is_resource($stream)); rewind($stream);
        $this->expectException(\UnexpectedValueException::class); iterator_to_array($codec->read($stream), false);
    }

    public function testLargeProgressiveRoundTripHasBoundedMemory(): void
    {
        $codec = new AudioInventoryAssociationRunCodec(1024);
        $stream = fopen('php://temp/maxmemory:65536', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open isolated temporary stream.'); }
        $this->streams[] = $stream; $baseline = memory_get_usage(); $growth = 0; $produced = 0;
        $source = (static function () use (&$growth, &$produced, $baseline): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                $growth = max($growth, memory_get_usage() - $baseline); ++$produced;
                yield self::association('SYN/'.$i, $i + 1);
            }
        })();
        self::assertSame(100000, $codec->write($stream, $source)); self::assertSame(100000, $produced);
        rewind($stream); $read = 0;
        foreach ($codec->read($stream) as $row) {
            if ($row !== self::association('SYN/'.$read, $read + 1)) { self::fail('Data changed.'); }
            ++$read; $growth = max($growth, memory_get_usage() - $baseline);
        }
        self::assertSame(100000, $read); self::assertLessThan(2 * 1024 * 1024, $growth);
        self::assertTrue(is_resource($stream));
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
        if (!stream_wrapper_register('zinzineassociation', AudioInventoryAssociationCodecTestStream::class)) { throw new \RuntimeException('Cannot register test wrapper.'); }
        $this->registered = true; AudioInventoryAssociationCodecTestStream::reset($bytes);
        $stream = fopen('zinzineassociation://isolated', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open test wrapper.'); }
        $this->streams[] = $stream; stream_set_read_buffer($stream, 0);
        return $stream;
    }

    private static function field(string $bytes): string { return pack('N', strlen($bytes)).$bytes; }
    private static function payload(array $row): string
    {
        return self::field($row['storage']).self::field($row['relative_path']).self::field((string)$row['emission_id'])
            .($row['origin'] === 'thumbnailMp3' ? "\x01" : "\x02").($row['thumbnail_missing'] ? "\x01" : "\x00");
    }
    private static function frame(array $payloads, ?int $count = null, int $highCount = 0): string
    {
        $bytes = self::HEADER;
        foreach ($payloads as $payload) { $bytes .= pack('N', strlen($payload)).$payload; }
        $bytes .= pack('N', 0).pack('N2', $highCount, $count ?? count($payloads));
        return $bytes.hash('sha256', $bytes, true);
    }
}

/** In-memory I/O faults, registered only inside this targeted test process. */
final class AudioInventoryAssociationCodecTestStream
{
    public mixed $context;
    public static string $data = '';
    public static int $maxWrite = 8192;
    public static int $maxRead = 8192;
    public static ?int $failWriteAfter = null;
    public static ?int $failReadAfter = null;
    public static bool $zeroWrite = false;
    public static bool $emptyRead = false;
    public static bool $flushFails = false;
    public static int $writeCalls = 0;
    public static int $readCalls = 0;
    public static int $closeCalls = 0;
    private int $position = 0;

    public static function reset(string $bytes): void
    {
        self::$data = $bytes; self::$maxWrite = self::$maxRead = 8192;
        self::$failWriteAfter = self::$failReadAfter = null;
        self::$zeroWrite = self::$emptyRead = self::$flushFails = false;
        self::$writeCalls = self::$readCalls = self::$closeCalls = 0;
    }
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $bytes): int|false
    {
        ++self::$writeCalls;
        if (self::$zeroWrite) { return 0; }
        if (self::$failWriteAfter !== null && $this->position >= self::$failWriteAfter) { return false; }
        $length = min(strlen($bytes), self::$maxWrite, self::$failWriteAfter === null ? PHP_INT_MAX : self::$failWriteAfter - $this->position);
        self::$data = substr(self::$data, 0, $this->position).substr($bytes, 0, $length).substr(self::$data, $this->position + $length);
        $this->position += $length; return $length;
    }
    public function stream_read(int $count): string|false
    {
        ++self::$readCalls;
        if (self::$failReadAfter !== null && $this->position >= self::$failReadAfter) { return self::$emptyRead ? '' : false; }
        $length = min($count, self::$maxRead, self::$failReadAfter === null ? PHP_INT_MAX : self::$failReadAfter - $this->position);
        $bytes = substr(self::$data, $this->position, $length); $this->position += strlen($bytes); return $bytes;
    }
    public function stream_eof(): bool
    {
        if (self::$failReadAfter !== null && $this->position >= self::$failReadAfter) { return false; }
        return $this->position >= strlen(self::$data);
    }
    public function stream_tell(): int { return $this->position; }
    public function stream_stat(): array { return []; }
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return false; }
    public function stream_flush(): bool { return !self::$flushFails; }
    public function stream_close(): void { ++self::$closeCalls; }
}
