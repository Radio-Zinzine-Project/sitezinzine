<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunMerger;
use PHPUnit\Framework\TestCase;

final class AudioInventoryPathRunMergerTest extends TestCase
{
    private array $streams = [];
    private bool $wrapperRegistered = false;

    protected function tearDown(): void
    {
        try {
            foreach ($this->streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
            if ($this->wrapperRegistered) { stream_wrapper_unregister('zinzinemerger'); }
        } finally { $this->streams = []; parent::tearDown(); }
    }

    /** @dataProvider mergeCases */
    public function testOutputIsAnExactStrictlySortedCodecRun(array $runs, array $expected): void
    {
        $codec = new AudioInventoryPathRunCodec(1024);
        $inputs = array_map(fn (array $paths): mixed => $this->inputRun($paths), $runs);
        $output = $this->memory();
        $report = (new AudioInventoryPathRunMerger($codec, 4))->merge($inputs, $output);
        self::assertSame(['status' => 'completed', 'input_runs' => count($runs), 'paths_merged' => count($expected)], $report);
        self::assertTrue(is_resource($output));
        rewind($output);
        self::assertSame($expected, iterator_to_array($codec->read($output), false));
        foreach ($inputs as $input) { self::assertTrue(is_resource($input)); self::assertTrue(feof($input)); }
    }

    public static function mergeCases(): array
    {
        return [
            'no input runs' => [[], []], 'one encoded empty run' => [[[]], []],
            'one nonempty run' => [[['a.mp3', 'b.mp3']], ['a.mp3', 'b.mp3']],
            'empty runs amongst nonempty' => [[[], ['a.mp3'], [], ['b.mp3']], ['a.mp3', 'b.mp3']],
            'interleaved' => [[['a', 'd', 'g'], ['b', 'e', 'h'], ['c', 'f', 'i']], ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']],
            'unequal sizes' => [[['a', 'c', 'e', 'f'], ['b'], ['d', 'g']], ['a', 'b', 'c', 'd', 'e', 'f', 'g']],
            'binary case and accent order' => [[['A.mp3', 'é.mp3'], ['Z.mp3', 'a.mp3']], ['A.mp3', 'Z.mp3', 'a.mp3', 'é.mp3']],
            'numeric looking strings use strcmp' => [[['1', '2'], ['10']], ['1', '10', '2']],
            'non UTF-8 bytes' => [[["x/\xfe.mp3"], ["x/\xff.mp3"]], ["x/\xfe.mp3", "x/\xff.mp3"]],
            'historical special characters' => [[["a\n.mp3", 'dir\\name.mp3'], ['a b.mp3']], ["a\n.mp3", 'a b.mp3', 'dir\\name.mp3']],
        ];
    }

    /** @dataProvider invalidRunOrders */
    public function testDuplicatesAndDisorderLeaveNoSuccessfulReport(array $runs): void
    {
        $codec = new AudioInventoryPathRunCodec(1024);
        $inputs = array_map(fn (array $paths): mixed => $this->inputRun($paths), $runs);
        $output = $this->memory();
        $merger = new AudioInventoryPathRunMerger($codec, 4);
        $report = null;
        try { $report = $merger->merge($inputs, $output); self::fail('Invalid order must not complete.'); }
        catch (\UnexpectedValueException $error) { self::assertNull($report); }
        self::assertTrue(is_resource($output));
        foreach ($inputs as $input) { self::assertTrue(is_resource($input)); }
        rewind($output);
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array($codec->read($output), false);
    }

    public static function invalidRunOrders(): array
    {
        return [
            'cross-run duplicate' => [[['a', 'c'], ['b', 'c']]],
            'duplicate inside a run' => [[['a', 'a']]],
            'unsorted run' => [[['b', 'a']]],
            'late local duplicate' => [[['a', 'c', 'e', 'e'], ['b', 'd']]],
            'late local disorder' => [[['a', 'c', 'f', 'e'], ['b', 'd']]],
            'late cross-run duplicate' => [[['a', 'c', 'z'], ['b', 'd', 'z']]],
        ];
    }

    /** @dataProvider damagedRunKinds */
    public function testAllInputFootersMustValidateBeforeOutputCanBeFinalized(string $kind): void
    {
        $codec = new AudioInventoryPathRunCodec(1024);
        $input = $this->inputRun($kind === 'empty checksum' ? [] : ['z.mp3']);
        $bytes = stream_get_contents($input); // run() leaves the cursor at zero.
        if ($kind === 'truncated') { $bytes = substr($bytes, 0, -1); }
        elseif ($kind === 'counter') { $bytes = substr_replace($bytes, pack('N2', 0, 99), -40, 8); }
        else { $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1); }
        $damaged = $this->memory($bytes);
        $output = $this->memory();
        $merger = new AudioInventoryPathRunMerger($codec, 3);
        $report = null;
        try { $report = $merger->merge([$this->inputRun(['a.mp3']), $damaged], $output); self::fail('Footer verification must fail.'); }
        catch (\UnexpectedValueException $error) { self::assertNull($report); }
        rewind($output);
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array($codec->read($output), false);
    }

    public static function damagedRunKinds(): array { return [['truncated'], ['checksum'], ['counter'], ['empty checksum']]; }

    public function testReadFailurePropagatesWithoutDrainingAnotherInputOrClosingAnything(): void
    {
        $failure = new \RuntimeException('Original read failure');
        $first = $this->controlled('first', '', $failure);
        $second = $this->controlled('second', $this->encoded(['a.mp3']), new \LogicException('Secondary read must not occur'));
        $output = $this->memory();
        $merger = $this->merger();
        try { $merger->merge([$first, $second], $output); self::fail('Read must fail.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertSame(0, AudioInventoryMergerTestStream::$states['second']['reads']);
        foreach ([$first, $second, $output] as $stream) { self::assertTrue(is_resource($stream)); }
        self::assertSame(0, AudioInventoryMergerTestStream::$states['first']['closes']);
    }

    public function testOutputWriteFailureDoesNotBecomeACompletedMerge(): void
    {
        $input = $this->inputRun(['a.mp3', 'b.mp3']);
        $output = $this->controlled('output', '', null, 8);
        $merger = $this->merger();
        $report = null;
        try { $report = $merger->merge([$input], $output); self::fail('Write must fail.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('write', $error->getMessage()); }
        self::assertNull($report);
        self::assertSame("ZMP3RUN\x01", AudioInventoryMergerTestStream::$states['output']['data']);
        self::assertTrue(is_resource($input)); self::assertTrue(is_resource($output));
        self::assertSame(0, AudioInventoryMergerTestStream::$states['output']['closes']);
    }

    public function testInputLimitIsCheckedBeforeAnyRunReadOrOutputWrite(): void
    {
        $inputs = [$this->inputRun([]), $this->inputRun([]), $this->inputRun([])];
        $output = $this->memory();
        $merger = new AudioInventoryPathRunMerger(new AudioInventoryPathRunCodec(1024), 2);
        try { $merger->merge($inputs, $output); self::fail('The input limit must be enforced.'); }
        catch (\LengthException $error) { self::assertSame(0, ftell($output)); }
        foreach ($inputs as $stream) { self::assertSame(0, ftell($stream)); self::assertTrue(is_resource($stream)); }
    }

    public function testInputStreamEnumerationIsBoundedAndDoesNotResumeAfterLimit(): void
    {
        $produced = 0;
        $one = $this->inputRun([]); $two = $this->inputRun([]); $three = $this->inputRun([]);
        $inputs = (static function () use ($one, $two, $three, &$produced): \Generator {
            ++$produced; yield $one; ++$produced; yield $two; ++$produced; yield $three;
            self::fail('No additional stream may be requested after the cap is exceeded.');
        })();
        $merger = new AudioInventoryPathRunMerger(new AudioInventoryPathRunCodec(1024), 2);
        try { $merger->merge($inputs, $this->memory()); self::fail('Expected reader limit.'); }
        catch (\LengthException $error) { self::assertSame(3, $produced); }
        unset($inputs);
    }

    public function testEnumerationExceptionIsPreservedBeforeAnyOutputWrite(): void
    {
        $failure = new \RuntimeException('Original stream-list failure');
        $input = $this->inputRun(['a.mp3']);
        $inputs = (static function () use ($input, $failure): \Generator { yield $input; throw $failure; })();
        $output = $this->memory();
        $merger = $this->merger();
        try { $merger->merge($inputs, $output); self::fail('Enumeration failure must propagate.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertSame(0, ftell($input)); self::assertSame(0, ftell($output));
    }

    /** @dataProvider invalidLimits */
    public function testReaderLimitMustBePositive(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryPathRunMerger(new AudioInventoryPathRunCodec(1024), $limit);
    }

    public static function invalidLimits(): array { return [[0], [-1]]; }

    /** @dataProvider invalidStreamValues */
    public function testInvalidInputsAreRejectedWithoutWritingOutput(mixed $invalid): void
    {
        $output = $this->memory();
        $merger = $this->merger();
        try { $merger->merge([$invalid], $output); self::fail('Expected an open input stream.'); }
        catch (\InvalidArgumentException $error) { self::assertSame(0, ftell($output)); }
    }

    public static function invalidStreamValues(): array { return [[null], [false], ['not-a-stream']]; }

    public function testClosedInputIsRejected(): void
    {
        $input = $this->inputRun([]); fclose($input);
        $merger = $this->merger();
        $this->expectException(\InvalidArgumentException::class);
        $merger->merge([$input], $this->memory());
    }

    public function testOutputCannotBeAnInputResource(): void
    {
        $input = $this->inputRun(['a.mp3']);
        $merger = $this->merger();
        try { $merger->merge([$input], $input); self::fail('Aliased output must be rejected.'); }
        catch (\InvalidArgumentException $error) { self::assertSame(0, ftell($input)); }
    }

    public function testSameInputResourceCannotBeEnumeratedTwice(): void
    {
        $input = $this->inputRun(['a.mp3']);
        $merger = $this->merger();
        $this->expectException(\InvalidArgumentException::class);
        $merger->merge([$input, $input], $this->memory());
    }

    public function testOutputMustBeEmptyAndIsNeverTruncatedOrRewound(): void
    {
        $output = $this->memory('existing');
        $merger = $this->merger();
        try { $merger->merge([], $output); self::fail('Existing output must not be overwritten.'); }
        catch (\InvalidArgumentException $error) { self::assertSame('existing', stream_get_contents($output)); }
    }

    public function testInputPositionsAreOwnedByCallerAndNeverImplicitlyRewound(): void
    {
        $input = $this->inputRun(['a.mp3']); fseek($input, 0, SEEK_END);
        $merger = $this->merger();
        $this->expectException(\UnexpectedValueException::class);
        $merger->merge([$input], $this->memory());
    }

    public function testLargeSyntheticRunsAreMergedWithoutAccumulatingTheirPaths(): void
    {
        $codec = new AudioInventoryPathRunCodec(64);
        $inputs = [];
        for ($part = 0; $part < 3; ++$part) {
            $stream = $this->temporary();
            $codec->write($stream, (static function () use ($part): \Generator {
                for ($i = $part; $i < 100000; $i += 3) { yield sprintf('SYN/%08d.mp3', $i); }
            })());
            rewind($stream); $inputs[] = $stream;
        }
        $output = $this->temporary();
        $baselinePeak = memory_get_peak_usage();
        $result = (new AudioInventoryPathRunMerger($codec, 3))->merge($inputs, $output);
        self::assertLessThan(2 * 1024 * 1024, memory_get_peak_usage() - $baselinePeak, 'Merge must not materialize all input or output paths.');
        self::assertSame(['status' => 'completed', 'input_runs' => 3, 'paths_merged' => 100000], $result);
        rewind($output); $count = 0;
        foreach ($codec->read($output) as $path) {
            if ($path !== sprintf('SYN/%08d.mp3', $count)) { self::fail('Large output is not the exact sorted union.'); }
            ++$count;
        }
        self::assertSame(100000, $count);
        foreach ($inputs as $stream) { self::assertTrue(is_resource($stream)); self::assertTrue(feof($stream)); }
        self::assertTrue(is_resource($output));
    }

    private function merger(): AudioInventoryPathRunMerger { return new AudioInventoryPathRunMerger(new AudioInventoryPathRunCodec(1024), 4); }

    private function inputRun(array $paths): mixed
    {
        $stream = $this->memory();
        (new AudioInventoryPathRunCodec(1024))->write($stream, $paths); rewind($stream);
        return $stream;
    }

    private function encoded(array $paths): string
    {
        return stream_get_contents($this->inputRun($paths));
    }

    private function memory(string $bytes = ''): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open isolated memory stream.'); }
        $this->streams[] = $stream;
        if ($bytes !== '') { fwrite($stream, $bytes); rewind($stream); }
        return $stream;
    }

    private function temporary(): mixed
    {
        $stream = fopen('php://temp/maxmemory:65536', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open isolated temporary stream.'); }
        $this->streams[] = $stream; return $stream;
    }

    private function controlled(string $id, string $bytes = '', ?\Throwable $readFailure = null, ?int $writeFailureAfter = null): mixed
    {
        if (!$this->wrapperRegistered) {
            if (!stream_wrapper_register('zinzinemerger', AudioInventoryMergerTestStream::class)) { throw new \RuntimeException('Cannot register test stream.'); }
            $this->wrapperRegistered = true;
            AudioInventoryMergerTestStream::$states = [];
        }
        AudioInventoryMergerTestStream::$states[$id] = ['data' => $bytes, 'read_failure' => $readFailure, 'write_failure_after' => $writeFailureAfter, 'reads' => 0, 'closes' => 0];
        $stream = fopen('zinzinemerger://' . $id, 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open local test stream.'); }
        $this->streams[] = $stream; return $stream;
    }
}

/** In-memory fault injection. All real frame validation/writing remains in the codec. */
final class AudioInventoryMergerTestStream
{
    public mixed $context;
    public static array $states = [];
    private string $id;
    private int $position = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { $this->id = substr($path, strlen('zinzinemerger://')); return true; }
    public function stream_read(int $count): string
    {
        $state = &self::$states[$this->id]; ++$state['reads'];
        if ($state['read_failure'] !== null) { throw $state['read_failure']; }
        $bytes = substr($state['data'], $this->position, $count); $this->position += strlen($bytes); return $bytes;
    }
    public function stream_write(string $bytes): int|false
    {
        $state = &self::$states[$this->id];
        if ($state['write_failure_after'] !== null && $this->position >= $state['write_failure_after']) { return false; }
        $length = min(strlen($bytes), $state['write_failure_after'] === null ? PHP_INT_MAX : $state['write_failure_after'] - $this->position);
        $state['data'] = substr($state['data'], 0, $this->position) . substr($bytes, 0, $length) . substr($state['data'], $this->position + $length);
        $this->position += $length; return $length;
    }
    public function stream_tell(): int { return $this->position; }
    public function stream_stat(): array { return ['size' => strlen(self::$states[$this->id]['data'])]; }
    public function stream_eof(): bool { return $this->position >= strlen(self::$states[$this->id]['data']); }
    public function stream_flush(): bool { return true; }
    public function stream_close(): void { ++self::$states[$this->id]['closes']; }
}
