<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * One incremental ZMP3CAT v1 frame in a workspace-owned file.
 * Only finish() publishes its ID, after full codec validation and reader close.
 * No list of IDs, sorting, deduplication, deletion, fsync or crash recovery.
 * IDs are opaque nonempty bytes, not proof that referenced files exist.
 * Synchronous, non-reentrant use. Caller must abort on interruption and arrange
 * workspace cleanup in finally. Physical finalization alone is not publication:
 * failed validation can leave a finalized but unpublished file owned by workspace.
 */
class AudioInventoryIncrementalRunCatalogWriter
{
    private const HEADER = "ZMP3CAT\x01";
    private readonly AudioInventoryRunCatalogCodec $codec;
    private string $catalogId;
    private mixed $stream = null;
    private ?\HashContext $hash = null;
    private int $count = 0;
    private string $state = 'open';
    /** @var list<\Throwable> Secondary closure errors, retained for this writer. */
    private array $closeFailures = [];

    public function __construct(
        private readonly AudioInventoryTemporaryWorkspace $workspace,
        private readonly int $maxIdentifierBytes,
    ) {
        // Validate limits before creating any file, using the reference codec.
        $this->codec = new AudioInventoryRunCatalogCodec($maxIdentifierBytes);
        $this->catalogId = $workspace->createRun();
        try {
            $this->stream = $workspace->openWrite($this->catalogId);
            $this->hash = hash_init('sha256');
            $this->writeAll(self::HEADER);
            hash_update($this->hash, self::HEADER);
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    /** Invalid arguments leave an open writer unchanged; I/O failures are terminal. */
    public function append(string $runId): void
    {
        $this->assertOpen();
        $length = strlen($runId);
        if ($length === 0 || $length > $this->maxIdentifierBytes) {
            throw new \UnexpectedValueException('Expected a nonempty identifier within the configured byte limit.');
        }
        if ($this->count === PHP_INT_MAX) {
            $this->fail(new \OverflowException('Catalog count exceeds the PHP integer range.'));
        }
        try {
            $prefix = pack('N', $length);
            $this->writeAll($prefix);
            $this->writeAll($runId);
            hash_update($this->hash, $prefix);
            hash_update($this->hash, $runId);
            ++$this->count;
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    /** @return array{catalog_id:string, identifier_count:int} */
    public function finish(): array
    {
        $this->assertOpen();
        try {
            // Exact existing format: zero uint32, big-endian uint64 count, hash.
            $footer = pack('N', 0).pack('N2', intdiv($this->count, 0x100000000), $this->count % 0x100000000);
            $this->writeAll($footer);
            hash_update($this->hash, $footer);
            $digest = hash_final($this->hash, true);
            $this->hash = null;
            $this->writeAll($digest);
            if (!$this->flushStream($this->stream)) {
                throw new \RuntimeException('Cannot flush the incremental catalog stream.');
            }
            $this->closeOwned();
            $this->finalizeRun($this->catalogId);

            $this->stream = $this->openReadRun($this->catalogId);
            $validatedCount = 0;
            foreach ($this->codec->read($this->stream) as $unused) {
                ++$validatedCount;
            }
            if ($validatedCount !== $this->count) {
                throw new \UnexpectedValueException('Validated catalog count differs from appended identifiers.');
            }
            $this->closeOwned();
            $this->state = 'finalized';
            return ['catalog_id' => $this->catalogId, 'identifier_count' => $validatedCount];
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    /** Idempotent after abort; failed stays failed. Never withdraws a published ID. */
    public function abort(): void
    {
        if ($this->state === 'finalized') {
            throw new \LogicException('A finalized catalog cannot be aborted.');
        }
        if ($this->state === 'aborted') { return; }
        if ($this->state === 'open') { $this->state = 'aborted'; }
        $this->hash = null;
        try { $this->closeOwned(); }
        catch (\Throwable $error) { $this->state = 'failed'; throw $error; }
    }

    /** @return 'open'|'finalized'|'aborted'|'failed' */
    public function getState(): string { return $this->state; }

    /** @return list<\Throwable> Secondary errors never replace the thrown primary. */
    public function getCloseFailures(): array { return $this->closeFailures; }

    private function assertOpen(): void
    {
        if ($this->state !== 'open') { throw new \LogicException('Catalog writer is no longer open.'); }
    }

    private function fail(\Throwable $primary): never
    {
        $this->state = 'failed';
        $this->hash = null;
        try { $this->closeOwned(); }
        catch (\Throwable $secondary) { $this->closeFailures[] = $secondary; }
        throw $primary;
    }

    private function closeOwned(): void
    {
        if ($this->stream === null) { return; }
        try { $this->closeRun($this->catalogId); }
        finally {
            if (!is_resource($this->stream)) { $this->stream = null; }
        }
    }

    private function writeAll(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = $this->writeChunk($this->stream, substr($bytes, $offset, 8192));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot complete an incremental catalog write.');
            }
            $offset += $written;
        }
    }

    // Narrow delegation seams for deterministic I/O tests; workspace owns streams.
    protected function writeChunk(mixed $stream, string $bytes): int|false { return @fwrite($stream, $bytes); }
    protected function flushStream(mixed $stream): bool { return @fflush($stream); }
    protected function closeRun(string $id): void { $this->workspace->close($id); }
    protected function finalizeRun(string $id): void { $this->workspace->finalize($id); }
    protected function openReadRun(string $id): mixed { return $this->workspace->openRead($id); }
}
