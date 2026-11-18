<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunBuilder;
use App\Service\AudioLibrary\Inventory\AudioInventoryAssociationRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use PHPUnit\Framework\TestCase;

final class AudioInventoryAssociationRunBuilderTest extends TestCase
{
    private array $streams = [];
    private bool $registered = false;

    protected function tearDown(): void
    {
        try {
            foreach ($this->streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
            if ($this->registered) { stream_wrapper_unregister('zinzineassociationbuilder'); }
        } finally { $this->streams = []; parent::tearDown(); }
    }

    private static function row(string $path = 'a', int $id = 17, string $origin = 'thumbnailMp3', bool $missing = false, string $storage = 'emissionsMp3'): array
    {
        return ['storage' => $storage, 'relative_path' => $path, 'emission_id' => $id, 'origin' => $origin, 'thumbnail_missing' => $missing];
    }
    private function builder(int $count = 3, int $bytes = 1024, int $recordBytes = 1024): AudioInventoryAssociationRunBuilder
    {
        return new AudioInventoryAssociationRunBuilder(new AudioInventoryAssociationRunCodec($recordBytes), $count, $bytes);
    }
    private function memory(string $bytes = ''): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) { throw new \RuntimeException('Cannot allocate isolated memory stream.'); }
        $this->streams[] = $stream;
        if ($bytes !== '') { fwrite($stream, $bytes); rewind($stream); }
        return $stream;
    }
    private static function encodedFileBytes(array $rows): int
    {
        $stream = fopen('php://memory', 'w+b');
        try { (new AudioInventoryAssociationRunCodec(65536))->write($stream, $rows); return ftell($stream); }
        finally { fclose($stream); }
    }
    private static function report(int $received = 0, int $runs = 0, int $serialized = 0, int $associationBytes = 0, int $encodedBytes = 0, string $status = 'completed'): array
    {
        return ['status' => $status, 'associations_received' => $received, 'runs_produced' => $runs,
            'associations_serialized' => $serialized, 'association_bytes' => $associationBytes, 'encoded_bytes' => $encodedBytes];
    }

    public function testEmptyInputDoesNotAllocateStream(): void
    {
        self::assertSame(self::report(), $this->builder()->build([],
            static function (): mixed { self::fail('Empty input must not open a run.'); },
            static function (): void { self::fail('Empty input must not finalize a run.'); }));
    }

    /** @dataProvider associationCases */
    public function testEachRunSortsByExactPathAndPreservesEveryAssociation(array $rows): void
    {
        $before = $rows; $decoded = []; $metadata = [];
        $report = $this->builder(100)->build($rows, fn (int $index) => $this->memory(),
            function ($stream, array $run) use (&$decoded, &$metadata): void {
                self::assertTrue(is_resource($stream)); self::assertSame($run['encoded_bytes'], ftell($stream));
                self::assertSame($run['encoded_bytes'], fstat($stream)['size']); rewind($stream);
                $decoded = iterator_to_array((new AudioInventoryAssociationRunCodec(1024))->read($stream), false); $metadata = $run;
            });
        $paths = array_column($rows, 'relative_path'); usort($paths, strcmp(...));
        self::assertSame($paths, array_column($decoded, 'relative_path'));
        $originalRecords = array_map(serialize(...), $rows); $decodedRecords = array_map(serialize(...), $decoded);
        sort($originalRecords, SORT_STRING); sort($decodedRecords, SORT_STRING);
        self::assertSame($originalRecords, $decodedRecords); self::assertSame($before, $rows);
        $bytes = self::encodedFileBytes($rows);
        self::assertSame(['run_index' => 1, 'status' => 'finalized', 'association_count' => count($rows),
            'association_bytes' => $bytes - 52, 'encoded_bytes' => $bytes], $metadata);
        self::assertSame(self::report(count($rows), 1, count($rows), $bytes - 52, $bytes), $report);
        self::assertTrue(is_resource($this->streams[0]));
    }
    public static function associationCases(): array
    {
        $a = self::row();
        return [
            'one' => [[$a]], 'unsorted' => [[self::row('z'), self::row('a'), self::row('b')]],
            'identical repeated' => [[$a, $a, $a]],
            'multiple emissions' => [[$a, self::row('a', 18), self::row('a', 19)]],
            'both origins' => [[$a, self::row('a', 17, 'local_audio_url', true)]],
            'binary order' => [[self::row('é'), self::row('a'), self::row('A'), self::row("\xff"), self::row('É')]],
            'numeric-looking paths' => [[self::row('2'), self::row('10'), self::row('1')]],
            'historical bytes and metadata' => [[self::row("dir/a\n b.mp3", PHP_INT_MAX, 'local_audio_url', true, "s\0\xff"), self::row('dir\\name')]],
        ];
    }

    public function testCountBoundaryProducesIndividuallySortedRunsWithoutGlobalSort(): void
    {
        $rows = [self::row('z'), self::row('y'), self::row('b'), self::row('a'), self::row('c')];
        $runs = []; $totalBytes = 0;
        $report = $this->builder(2)->build($rows, fn () => $this->memory(), function ($stream, $run) use (&$runs, &$totalBytes): void {
            rewind($stream); $runs[] = array_column(iterator_to_array((new AudioInventoryAssociationRunCodec(1024))->read($stream), false), 'relative_path');
            self::assertSame(count($runs), $run['run_index']); self::assertLessThanOrEqual(2, $run['association_count']); $totalBytes += $run['encoded_bytes'];
        });
        self::assertSame([['y', 'z'], ['a', 'b'], ['c']], $runs);
        self::assertSame(self::report(5, 3, 5, $totalBytes - 3 * 52, $totalBytes), $report);
    }

    public function testByteBudgetUsesWholeEncodedRecordsAndIncludesFrame(): void
    {
        $rows = [self::row('b', 9), self::row('a', PHP_INT_MAX, 'local_audio_url', true, str_repeat('storage', 8)), self::row('c', 100)];
        $budget = self::encodedFileBytes([$rows[1]]); $counts = [];
        $report = $this->builder(100, $budget)->build($rows, fn () => $this->memory(), function ($stream, array $run) use ($budget, &$counts): void {
            self::assertLessThanOrEqual($budget, ftell($stream)); self::assertSame($run['encoded_bytes'], ftell($stream));
            $counts[] = $run['association_count'];
        });
        self::assertSame([1, 1, 1], $counts); self::assertSame(3, $report['associations_serialized']);
        self::assertSame(array_sum(array_map(static fn ($s) => ftell($s), $this->streams)), $report['encoded_bytes']);
    }

    public function testExactByteBoundaryFlushesBeforeAdvancingSource(): void
    {
        $a = self::row('a'); $budget = self::encodedFileBytes([$a]);
        $source = (static function () use ($a): \Generator { yield $a; self::fail('Source resumed after interruption.'); })();
        $report = $this->builder(10, $budget)->build($source, fn () => $this->memory(), static fn () => false);
        self::assertSame(self::report(1, 1, 1, $budget - 52, $budget, 'interrupted'), $report);
    }

    public function testByteLookaheadIsReceivedButNotSerializedWhenCallbackInterrupts(): void
    {
        $a = self::row('a'); $b = self::row(str_repeat('b', 30));
        $budget = self::encodedFileBytes([$b]); $received = 0;
        $source = (static function () use ($a, $b, &$received): \Generator {
            ++$received; yield $a; ++$received; yield $b; self::fail('Source resumed after callback interruption.');
        })();
        $report = $this->builder(10, $budget)->build($source, fn () => $this->memory(), static fn () => false);
        $bytes = self::encodedFileBytes([$a]);
        self::assertSame(2, $received); self::assertSame(self::report(2, 1, 1, $bytes - 52, $bytes, 'interrupted'), $report);
    }

    public function testOversizedSingleAssociationIsRefusedBeforeOpening(): void
    {
        $row = self::row(); $budget = self::encodedFileBytes([$row]) - 1;
        $this->expectException(\UnexpectedValueException::class);
        $this->builder(10, $budget)->build([$row], static function (): mixed { self::fail('No output should open for oversized record.'); }, static fn () => null);
    }

    /** @dataProvider invalidLimits */
    public function testInvalidLimits(int $count, int $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->builder($count, $bytes);
    }
    public static function invalidLimits(): array { return [[0, 100], [-1, 100], [3, 0], [3, -1]]; }

    /** @dataProvider badAssociations */
    public function testMalformedAssociationsFailClearlyWithoutNotification(mixed $row): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->builder()->build([$row], fn () => $this->memory(), static function (): void { self::fail('Invalid association finalized.'); });
    }
    public static function badAssociations(): array
    {
        $a = self::row();
        return [[null], ['bad'], [[]], [array_diff_key($a, ['relative_path' => true])],
            [array_replace($a, ['extra' => 1])], [array_replace($a, ['relative_path' => 3])],
            [array_replace($a, ['emission_id' => '17'])], [array_replace($a, ['storage' => null])],
            [array_replace($a, ['thumbnail_missing' => 1])], [array_replace($a, ['origin' => 'other'])],
            [self::row('../outside')]];
    }

    public function testCodecRecordBudgetIsStillEnforced(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->builder(10, 1024, 1)->build([self::row()], fn () => $this->memory(), static function (): void { self::fail('Codec failure finalized.'); });
    }

    public function testFactoryFailurePropagatesUnchanged(): void
    {
        $failure = new \DomainException('factory');
        $this->expectExceptionObject($failure);
        $this->builder()->build([self::row()], static function () use ($failure): mixed { throw $failure; }, static function (): void { self::fail('Factory failure finalized.'); });
    }

    public function testNonemptyOrWrongPositionFactoryStreamsAreNotOverwritten(): void
    {
        $stream = $this->memory('original');
        foreach ([0, 3] as $position) {
            fseek($stream, $position);
            try { $this->builder()->build([self::row()], static fn () => $stream, static fn () => null); self::fail('Invalid stream accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(is_resource($stream)); rewind($stream); self::assertSame('original', stream_get_contents($stream)); }
        }
    }

    /** @dataProvider invalidFactoryValues */
    public function testInvalidFactoryResult(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->build([self::row()], static fn () => $value, static fn () => null);
    }
    public static function invalidFactoryValues(): array { return [[null], [false], ['php://memory']]; }

    public function testSourceFailurePreservesOriginalAndDoesNotFlushIncompleteBuffer(): void
    {
        $failure = new \RuntimeException('source'); $finalized = 0;
        $source = (static function () use ($failure): \Generator { yield self::row('b'); yield self::row('a'); yield self::row('c'); throw $failure; })();
        try { $this->builder(2)->build($source, fn () => $this->memory(), static function () use (&$finalized): void { ++$finalized; }); self::fail('Source failure hidden.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertSame(1, $finalized); self::assertCount(1, $this->streams); self::assertTrue(is_resource($this->streams[0]));
    }

    public function testFinalizationCallbackFailureDoesNotResumeSource(): void
    {
        $failure = new \DomainException('notification');
        $source = (static function (): \Generator { yield self::row(); self::fail('Source resumed after failed callback.'); })();
        $this->expectExceptionObject($failure);
        $this->builder(1)->build($source, fn () => $this->memory(), static function () use ($failure): void { throw $failure; });
    }

    public function testOnlyStrictFalseInterrupts(): void
    {
        foreach ([null, 0, '', 'false'] as $answer) {
            self::assertSame('completed', $this->builder(1)->build([self::row(), self::row('b')], fn () => $this->memory(), static fn () => $answer)['status']);
        }
        $source = (static function (): \Generator { yield self::row(); self::fail('Source resumed after false.'); })();
        self::assertSame('interrupted', $this->builder(1)->build($source, fn () => $this->memory(), static fn () => false)['status']);
    }

    public function testWriteFailureLeavesCallerOwnedStreamOpenAndNoNotification(): void
    {
        self::assertTrue(stream_wrapper_register('zinzineassociationbuilder', AssociationBuilderFailingStream::class)); $this->registered = true;
        AssociationBuilderFailingStream::$bytes = ''; AssociationBuilderFailingStream::$closes = 0;
        $stream = fopen('zinzineassociationbuilder://isolated', 'wb'); $this->streams[] = $stream;
        $this->expectException(\RuntimeException::class);
        try { $this->builder()->build([self::row()], static fn () => $stream, static function (): void { self::fail('Failed codec notified.'); }); }
        finally { self::assertTrue(is_resource($stream)); self::assertSame(0, AssociationBuilderFailingStream::$closes); self::assertSame("ZMP3ASC\x01", AssociationBuilderFailingStream::$bytes); }
    }

    public function testWorkspaceClosureAndFinalizationBelongToOwner(): void
    {
        $root = sys_get_temp_dir().'/zinzine-association-builder-'.bin2hex(random_bytes(12)); mkdir($root, 0700);
        $workspace = new AudioInventoryTemporaryWorkspace($root, 1, 2); $id = null;
        try {
            $report = $this->builder()->build([self::row('z'), self::row('a')], static function () use ($workspace, &$id) {
                $id = $workspace->createRun(); return $workspace->openWrite($id);
            }, static function ($stream) use ($workspace, &$id): void {
                self::assertTrue(is_resource($stream));
                try { $workspace->openRead($id); self::fail('Builder finalized workspace file prematurely.'); } catch (\LogicException) { /* Owner alone finalizes. */ }
                $workspace->close($id); $workspace->finalize($id);
            });
            self::assertSame(2, $report['associations_serialized']);
            $stream = $workspace->openRead($id);
            try { self::assertSame(['a', 'z'], array_column(iterator_to_array((new AudioInventoryAssociationRunCodec(1024))->read($stream), false), 'relative_path')); }
            finally { $workspace->close($id); }
        } finally { $workspace->cleanup(); rmdir($root); }
    }

    public function testOneHundredThousandAssociationsUseBoundedBuffersAndImmediateRunConsumption(): void
    {
        $produced = $serialized = $runs = $encoded = $growth = 0; $baseline = memory_get_usage();
        $source = (static function () use (&$produced, &$serialized, &$growth, $baseline): \Generator {
            for ($i = 0; $i < 100000; ++$i) {
                if ($produced - $serialized >= 100) { self::fail('Input accumulated before flushing full block.'); }
                ++$produced; $growth = max($growth, memory_get_usage() - $baseline);
                yield self::row(sprintf('SYN/%08d', 100000 - $i), $i + 1);
            }
        })();
        $codec = new AudioInventoryAssociationRunCodec(1024);
        $report = $this->builder(100, 64000)->build($source, static function () { return fopen('php://memory', 'w+b'); },
            static function ($stream, array $run) use ($codec, &$serialized, &$runs, &$encoded, &$growth, $baseline): void {
                try {
                    if (ftell($stream) !== $run['encoded_bytes'] || $run['encoded_bytes'] > 64000 || $run['association_count'] !== 100) { self::fail('Encoded budget/count violated.'); }
                    rewind($stream); $previous = null; $count = 0;
                    foreach ($codec->read($stream) as $row) {
                        if ($previous !== null && strcmp($previous, $row['relative_path']) > 0) { self::fail('Unsorted run.'); }
                        $previous = $row['relative_path']; ++$count;
                    }
                    if ($count !== 100) { self::fail('Associations lost.'); }
                    $serialized += $count; ++$runs; $encoded += $run['encoded_bytes']; $growth = max($growth, memory_get_usage() - $baseline);
                } finally { fclose($stream); }
            });
        self::assertSame(100000, $produced); self::assertSame(100000, $serialized); self::assertSame(1000, $runs);
        self::assertLessThan(2 * 1024 * 1024, $growth);
        self::assertSame(self::report(100000, 1000, 100000, $encoded - 1000 * 52, $encoded), $report);
    }
}

/** Local fault stream, closed only by the test owner. */
final class AssociationBuilderFailingStream
{
    public mixed $context;
    public static string $bytes = '';
    public static int $closes = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $bytes): int|false
    {
        if (strlen(self::$bytes) >= 8) { return false; }
        $part = substr($bytes, 0, 8 - strlen(self::$bytes)); self::$bytes .= $part; return strlen($part);
    }
    public function stream_tell(): int { return strlen(self::$bytes); }
    public function stream_eof(): bool { return false; }
    public function stream_stat(): array { return ['size' => strlen(self::$bytes)]; }
    public function stream_flush(): bool { return true; }
    public function stream_close(): void { ++self::$closes; }
}
