<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunBuilder;
use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunMerger;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use PHPUnit\Framework\TestCase;

final class AudioInventoryAssociationRunMergerTest extends TestCase
{
    private array $streams = [];
    private bool $registered = false;

    protected function tearDown(): void
    {
        try {
            foreach ($this->streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
            if ($this->registered) { stream_wrapper_unregister('zinzineassociationmerge'); }
        } finally { $this->streams = []; parent::tearDown(); }
    }

    private static function row(string $path = 'a', int $id = 17, string $origin = 'thumbnailMp3', bool $missing = false, string $storage = 'emissionsMp3'): array
    {
        return ['storage' => $storage, 'relative_path' => $path, 'emission_id' => $id, 'origin' => $origin, 'thumbnail_missing' => $missing];
    }
    private function codec(int $limit = 1024): AudioInventoryAssociationRunCodec { return new AudioInventoryAssociationRunCodec($limit); }
    private function merger(int $inputs = 4, int $recordBytes = 1024): AudioInventoryAssociationRunMerger { return new AudioInventoryAssociationRunMerger($this->codec($recordBytes), $inputs); }
    private function memory(string $bytes = ''): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot allocate isolated memory stream.'); }
        $this->streams[] = $stream;
        if ($bytes !== '') { fwrite($stream, $bytes); rewind($stream); }
        return $stream;
    }
    private function input(array $rows): mixed
    {
        $stream = $this->memory(); $this->codec()->write($stream, $rows); rewind($stream); return $stream;
    }
    private function readAll(mixed $stream): array { rewind($stream); return iterator_to_array($this->codec()->read($stream), false); }

    /** @dataProvider mergeCases */
    public function testRealCodecMergePreservesEveryRecordAndSortsByExactPath(array $groups): void
    {
        $inputs = []; $before = []; $all = [];
        foreach ($groups as $group) { $inputs[] = $this->input($group); $before[] = stream_get_contents(end($inputs)); rewind(end($inputs)); foreach ($group as $row) { $all[] = $row; } }
        $output = $this->memory(); $report = $this->merger()->merge($inputs, $output);
        self::assertSame(['status' => 'completed', 'input_runs' => count($groups), 'associations_merged' => count($all)], $report);
        $rows = $this->readAll($output);
        $paths = array_column($all, 'relative_path'); usort($paths, strcmp(...));
        self::assertSame($paths, array_column($rows, 'relative_path'));
        $expectedRecords = array_map(serialize(...), $all); $actualRecords = array_map(serialize(...), $rows);
        sort($expectedRecords, SORT_STRING); sort($actualRecords, SORT_STRING); self::assertSame($expectedRecords, $actualRecords);
        foreach ($inputs as $i => $stream) { self::assertTrue(is_resource($stream)); self::assertTrue(feof($stream)); rewind($stream); self::assertSame($before[$i], stream_get_contents($stream)); }
        self::assertTrue(is_resource($output));
    }
    public static function mergeCases(): array
    {
        $a = self::row();
        return [
            'none' => [[]], 'one empty' => [[[]]], 'several empty' => [[[], [], []]],
            'one run' => [[[$a, self::row('b'), self::row('z')]]],
            'interleaved two' => [[[self::row('a'), self::row('c')], [self::row('b'), self::row('d')]]],
            'interleaved four' => [[[self::row('a'), self::row('e')], [self::row('b'), self::row('f')], [], [self::row('c'), self::row('d')]]],
            'duplicates inside' => [[[$a, $a, self::row('a', 18)]]],
            'identical across runs' => [[[$a, $a], [$a], [$a, $a]]],
            'both origins' => [[[$a, self::row('a', 17, 'local_audio_url', true)], [self::row('a', 18)]]],
            'binary paths' => [[[self::row('A'), self::row('É'), self::row("\xff")], [self::row('a'), self::row('é')]]],
            'numeric paths' => [[[self::row('1'), self::row('2')], [self::row('10')]]],
            'historical fields' => [[[self::row("dir/a\n b", PHP_INT_MAX, 'local_audio_url', true, "s\0\xff")], [self::row('dir\\name')]]],
        ];
    }

    /** @dataProvider disorderedRuns */
    public function testDescendingPathInsideAnyRunPreventsSuccess(array $groups): void
    {
        $streams = array_map($this->input(...), $groups); $output = $this->memory(); $report = null;
        try { $report = $this->merger()->merge($streams, $output); self::fail('Unsorted run accepted.'); }
        catch (\UnexpectedValueException) { self::assertNull($report); }
        foreach ($streams as $stream) { self::assertTrue(is_resource($stream)); }
        self::assertTrue(is_resource($output));
        $this->expectException(\UnexpectedValueException::class); $this->readAll($output);
    }
    public static function disorderedRuns(): array
    {
        return [[[[self::row('b'), self::row('a')]]],
            [[[self::row('a'), self::row('d'), self::row('c')], [self::row('b'), self::row('z')]]]];
    }

    /** @dataProvider corruptInputs */
    public function testFullInputIntegrityIsRequiredBeforeOutputCanComplete(string $bytes): void
    {
        $bad = $this->memory($bytes); $good = $this->input([self::row('z')]); $output = $this->memory(); $report = null;
        try { $report = $this->merger()->merge([$bad, $good], $output); self::fail('Corrupt input accepted.'); }
        catch (\UnexpectedValueException) { self::assertNull($report); }
        self::assertTrue(is_resource($bad)); self::assertTrue(is_resource($good)); self::assertTrue(is_resource($output));
        $this->expectException(\UnexpectedValueException::class); $this->readAll($output);
    }
    public static function corruptInputs(): array
    {
        $payload = self::payload(self::row()); $valid = self::frame($payload);
        $corruptChecksum = $valid; $corruptChecksum[strlen($valid) - 1] = ~$corruptChecksum[strlen($valid) - 1];
        $invalidAssociation = self::frame(self::payload(self::row('../outside')));
        return [
            'raw empty' => [''], 'header' => ['X'.substr($valid, 1)],
            'unknown version' => ['ZMP3ASC'."\x02".substr($valid, 8)],
            'truncated record' => [substr($valid, 0, 22)],
            'truncated counter' => [substr($valid, 0, -36)],
            'counter mismatch with correct hash' => [self::frame($payload, 2)],
            'checksum after last record' => [$corruptChecksum], 'extra bytes' => [$valid.'x'],
            'appended frame' => [$valid.self::frame(null, 0)],
            'invalid empty run counter' => [self::frame(null, 1)],
            'invalid association despite correct hash' => [$invalidAssociation],
        ];
    }

    public function testReaderRecordLimitIsNotBypassed(): void
    {
        $input = $this->input([self::row()]); $output = $this->memory();
        $this->expectException(\UnexpectedValueException::class); $this->merger(2, 1)->merge([$input], $output);
    }

    public function testTooManyInputsStopsEnumerationBeforeAnyCodecReadOrWrite(): void
    {
        $inputs = [$this->input([]), $this->input([]), $this->input([])]; $output = $this->memory(); $pulled = 0;
        $source = (static function () use ($inputs, &$pulled): \Generator { foreach ($inputs as $stream) { ++$pulled; yield $stream; } self::fail('Over-budget source drained.'); })();
        $this->expectException(\LengthException::class);
        try { $this->merger(2)->merge($source, $output); }
        finally { self::assertSame(3, $pulled); self::assertSame(0, ftell($output)); foreach ($inputs as $stream) { self::assertSame(0, ftell($stream)); } }
    }

    /** @dataProvider badBudgets */
    public function testInvalidReaderBudget(int $budget): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->merger($budget);
    }
    public static function badBudgets(): array { return [[0], [-1]]; }

    public function testRepeatedResourceOrInputOutputAliasIsRejectedWithoutWriting(): void
    {
        $input = $this->input([self::row()]); $output = $this->memory();
        foreach ([[[$input, $input], $output], [[$output], $output]] as [$streams, $target]) {
            try { $this->merger()->merge($streams, $target); self::fail('Aliased resource accepted.'); }
            catch (\InvalidArgumentException) { self::assertSame(0, ftell($target)); self::assertTrue(is_resource($target)); }
        }
    }

    public function testOutputMustBeEmptyAndPositionedAtZero(): void
    {
        $output = $this->memory('keep');
        foreach ([0, 2] as $position) {
            fseek($output, $position);
            try { $this->merger()->merge([], $output); self::fail('Nonempty output accepted.'); }
            catch (\InvalidArgumentException) { rewind($output); self::assertSame('keep', stream_get_contents($output)); }
        }
    }

    /** @dataProvider invalidResources */
    public function testInvalidResourceArguments(mixed $value, bool $output): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->merger()->merge($output ? [] : [$value], $output ? $value : $this->memory());
    }
    public static function invalidResources(): array { return [[null, true], [false, false], ['php://memory', true], [17, false]]; }

    public function testClosedResourceIsRejected(): void
    {
        $input = $this->input([]); fclose($input);
        $this->expectException(\InvalidArgumentException::class); $this->merger()->merge([$input], $this->memory());
    }

    public function testInputPositionIsOwnedByCallerAndNeverRewound(): void
    {
        $stream = $this->memory('prefix'.self::frame(self::payload(self::row()))); fseek($stream, 6); $output = $this->memory();
        self::assertSame(1, $this->merger()->merge([$stream], $output)['associations_merged']);
        self::assertSame([self::row()], $this->readAll($output)); rewind($stream); self::assertSame('prefix', fread($stream, 6));
    }

    public function testInputIterableExceptionIsPreservedWithoutDrainingOrClosing(): void
    {
        $failure = new \DomainException('input enumeration'); $input = $this->input([]); $output = $this->memory();
        $source = (static function () use ($failure, $input): \Generator { yield $input; throw $failure; })();
        try { $this->merger()->merge($source, $output); self::fail('Input exception hidden.'); }
        catch (\DomainException $actual) { self::assertSame($failure, $actual); }
        self::assertSame(0, ftell($input)); self::assertSame(0, ftell($output)); self::assertTrue(is_resource($input));
    }

    private function controlled(string $id, string $bytes = ''): mixed
    {
        if (!$this->registered) {
            if (!stream_wrapper_register('zinzineassociationmerge', AssociationMergerTestStream::class)) { throw new \RuntimeException('Cannot register test stream.'); }
            $this->registered = true; AssociationMergerTestStream::$states = [];
        }
        AssociationMergerTestStream::$states[$id] = ['data' => $bytes, 'closes' => 0, 'max_write' => 8192,
            'read_error' => null, 'read_false' => false, 'read_empty' => false, 'write_after' => null, 'zero_write' => false, 'flush_error' => false];
        $stream = fopen('zinzineassociationmerge://'.$id, 'w+b'); $this->streams[] = $stream; return $stream;
    }

    /** @dataProvider readingFaults */
    public function testReadErrorsAndNoProgressPreventSuccessAndKeepStreamsOpen(string $mode): void
    {
        $input = $this->controlled('in', self::frame(self::payload(self::row()))); $output = $this->memory();
        $failure = new \DomainException('read');
        if ($mode === 'throw') { AssociationMergerTestStream::$states['in']['read_error'] = $failure; }
        else { AssociationMergerTestStream::$states['in'][$mode === 'false' ? 'read_false' : 'read_empty'] = true; }
        try { $this->merger()->merge([$input], $output); self::fail('Read failure hidden.'); }
        catch (\Throwable $actual) {
            if ($mode === 'throw') { self::assertSame($failure, $actual); }
            else { self::assertInstanceOf(\RuntimeException::class, $actual); }
        }
        self::assertSame(0, AssociationMergerTestStream::$states['in']['closes']); self::assertTrue(is_resource($output));
    }
    public static function readingFaults(): array { return [['throw'], ['false'], ['empty']]; }

    /** @dataProvider writingFaults */
    public function testWriteFailuresIncludingFooterAndFlushCannotReturnSuccess(string $mode, int $offset): void
    {
        $input = $this->input([self::row()]); $output = $this->controlled('out'); $report = null;
        if ($mode === 'false') { AssociationMergerTestStream::$states['out']['write_after'] = $offset; }
        else { AssociationMergerTestStream::$states['out'][$mode === 'zero' ? 'zero_write' : 'flush_error'] = true; }
        try { $report = $this->merger()->merge([$input], $output); self::fail('Write failure hidden.'); }
        catch (\RuntimeException) { self::assertNull($report); }
        self::assertTrue(is_resource($input)); self::assertTrue(is_resource($output)); self::assertSame(0, AssociationMergerTestStream::$states['out']['closes']);
    }
    public static function writingFaults(): array
    {
        $footer = 12 + strlen(self::payload(self::row()));
        return [['false', 0], ['false', 12], ['false', $footer], ['false', $footer + 12], ['zero', 0], ['flush', 0]];
    }

    public function testPartialOutputWritesRetainAllRecords(): void
    {
        $input = $this->input([self::row('a'), self::row('a', 18)]); $output = $this->controlled('out');
        AssociationMergerTestStream::$states['out']['max_write'] = 2;
        self::assertSame(2, $this->merger()->merge([$input], $output)['associations_merged']);
        $rows = iterator_to_array($this->codec()->read($this->memory(AssociationMergerTestStream::$states['out']['data'])), false);
        self::assertSame([self::row('a'), self::row('a', 18)], $rows); self::assertSame(0, AssociationMergerTestStream::$states['out']['closes']);
    }

    public function testRealBuilderOutputCanBeMergedWithoutDeduplication(): void
    {
        $inputs = [];
        (new AudioInventoryAssociationRunBuilder($this->codec(), 2, 1024))->build(
            [self::row('z'), self::row('a'), self::row('a'), self::row('b')], fn () => $this->memory(),
            static function ($stream) use (&$inputs): void { rewind($stream); $inputs[] = $stream; });
        $output = $this->memory(); self::assertSame(4, $this->merger()->merge($inputs, $output)['associations_merged']);
        self::assertSame(['a', 'a', 'b', 'z'], array_column($this->readAll($output), 'relative_path'));
    }

    public function testWorkspaceFilesRemainOwnedUnremovedAndUnfinalizedByMerger(): void
    {
        $root = sys_get_temp_dir().'/zinzine-association-merger-'.bin2hex(random_bytes(12)); mkdir($root, 0700);
        $workspace = new AudioInventoryTemporaryWorkspace($root, 3, 3); $ids = []; $paths = [];
        try {
            foreach ([[self::row('a')], [self::row('b')]] as $rows) {
                $id = $workspace->createRun(); $ids[] = $id; $stream = $workspace->openWrite($id);
                $this->codec()->write($stream, $rows); $workspace->close($id); $workspace->finalize($id);
            }
            $outId = $workspace->createRun(); $out = $workspace->openWrite($outId);
            $inputs = array_map($workspace->openRead(...), $ids);
            self::assertSame(2, $this->merger()->merge($inputs, $out)['associations_merged']);
            foreach ($inputs as $stream) { self::assertTrue(is_resource($stream)); } self::assertTrue(is_resource($out));
            $runs = (new \ReflectionProperty($workspace, 'runs'))->getValue($workspace);
            self::assertSame('writing', $runs[$outId]['state']);
            foreach ($runs as $run) { self::assertFileExists($run['path']); }
            foreach ($ids as $id) { $workspace->close($id); }
            $workspace->close($outId); $workspace->finalize($outId);
        } finally { $workspace->cleanup(); rmdir($root); }
    }

    public function testOneHundredThousandAssociationsAcrossFourRunsHaveBoundedMergeMemory(): void
    {
        $codec = $this->codec(); $inputs = [];
        for ($run = 0; $run < 4; ++$run) {
            $stream = fopen('php://temp/maxmemory:65536', 'w+b'); $this->streams[] = $stream;
            $codec->write($stream, (static function () use ($run): \Generator {
                for ($i = $run; $i < 100000; $i += 4) { yield self::row(sprintf('SYN/%08d', $i), $i + 1); }
            })()); rewind($stream); $inputs[] = $stream;
        }
        $output = fopen('php://temp/maxmemory:65536', 'w+b'); $this->streams[] = $output;
        self::assertTrue(stream_filter_register('zinzine.associationmerger.memory', AssociationMergerMemoryProbe::class));
        AssociationMergerMemoryProbe::$baseline = memory_get_usage(); AssociationMergerMemoryProbe::$growth = 0;
        $filter = stream_filter_append($output, 'zinzine.associationmerger.memory', STREAM_FILTER_WRITE);
        if ($filter === false) { throw new \RuntimeException('Cannot attach isolated memory probe.'); }
        try { $report = $this->merger()->merge($inputs, $output); }
        finally { stream_filter_remove($filter); }
        self::assertSame(['status' => 'completed', 'input_runs' => 4, 'associations_merged' => 100000], $report);
        self::assertLessThan(2 * 1024 * 1024, AssociationMergerMemoryProbe::$growth);
        rewind($output); $count = 0;
        foreach ($codec->read($output) as $row) {
            if ($row['relative_path'] !== sprintf('SYN/%08d', $count) || $row['emission_id'] !== $count + 1) { self::fail('Association order or fields changed.'); }
            ++$count;
        }
        self::assertSame(100000, $count); foreach ($inputs as $stream) { self::assertTrue(is_resource($stream)); self::assertTrue(feof($stream)); }
    }

    private static function payload(array $row): string
    {
        $bytes = '';
        foreach ([$row['storage'], $row['relative_path'], (string) $row['emission_id']] as $field) { $bytes .= pack('N', strlen($field)).$field; }
        return $bytes.($row['origin'] === 'thumbnailMp3' ? "\x01" : "\x02").($row['thumbnail_missing'] ? "\x01" : "\x00");
    }
    private static function frame(?string $payload, int $count = 1): string
    {
        $bytes = "ZMP3ASC\x01".($payload === null ? '' : pack('N', strlen($payload)).$payload).pack('N', 0).pack('N2', 0, $count);
        return $bytes.hash('sha256', $bytes, true);
    }
}

/** Samples during actual writes, so a temporary whole-input buffer cannot hide. */
final class AssociationMergerMemoryProbe extends \php_user_filter
{
    public static int $baseline = 0;
    public static int $growth = 0;
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        self::$growth = max(self::$growth, memory_get_usage() - self::$baseline);
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen; stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}

/** Synthetic per-stream I/O faults; no production path is opened by this wrapper. */
final class AssociationMergerTestStream
{
    public mixed $context;
    public static array $states = [];
    private string $id;
    private int $position = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { $this->id = substr($path, strpos($path, '://') + 3); return true; }
    public function stream_read(int $count): string|false
    {
        $state = self::$states[$this->id];
        if ($state['read_error']) { throw $state['read_error']; }
        if ($state['read_false']) { return false; } if ($state['read_empty']) { return ''; }
        $part = substr($state['data'], $this->position, $count); $this->position += strlen($part); return $part;
    }
    public function stream_write(string $bytes): int|false
    {
        $state = &self::$states[$this->id];
        if ($state['zero_write']) { return 0; }
        if ($state['write_after'] !== null && $this->position >= $state['write_after']) { return false; }
        $length = min(strlen($bytes), $state['max_write'], $state['write_after'] === null ? PHP_INT_MAX : $state['write_after'] - $this->position);
        $state['data'] = substr($state['data'], 0, $this->position).substr($bytes, 0, $length).substr($state['data'], $this->position + $length);
        $this->position += $length; return $length;
    }
    public function stream_eof(): bool { $state = self::$states[$this->id]; return !$state['read_empty'] && $this->position >= strlen($state['data']); }
    public function stream_tell(): int { return $this->position; }
    public function stream_stat(): array { return ['size' => strlen(self::$states[$this->id]['data'])]; }
    public function stream_flush(): bool { return !self::$states[$this->id]['flush_error']; }
    public function stream_close(): void { ++self::$states[$this->id]['closes']; }
}
