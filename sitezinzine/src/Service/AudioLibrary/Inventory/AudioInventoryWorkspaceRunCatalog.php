<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Catalog framing belongs to the codec; file and stream ownership stays in the
 * workspace. This component never discards files or cleans up the whole workspace.
 * The workspace has no file-type tag: a catalog ID is an opaque reference, not
 * proof of a workspace-declared type, nor an input for the path run merger.
 * Synchronous, non-reentrant use only; diagnostics belong to the latest operation.
 */
class AudioInventoryWorkspaceRunCatalog
{
    /** @var list<\Throwable> */
    private array $closeFailures = [];

    public function __construct(
        private readonly AudioInventoryTemporaryWorkspace $workspace,
        private readonly AudioInventoryRunCatalogCodec $codec,
    ) {
    }

    /** @param iterable<string> $runIds */
    public function create(iterable $runIds): string
    {
        $this->closeFailures = [];
        $id = $this->workspace->createRun();
        $stream = $this->workspace->openWrite($id);
        $failure = null;
        try {
            $this->codec->write($stream, $runIds);
        } catch (\Throwable $initial) {
            $failure = $initial;
        }
        $failure = $this->closePreservingFailure($id, $failure);
        if ($failure !== null) { throw $failure; }
        $this->workspace->finalize($id);
        return $id;
    }

    /**
     * Runs the consumer while its file is open. Returning normally is permitted
     * only after normal exhaustion, including codec count/hash/EOF validation.
     * Partial consumption throws LogicException; no implicit draining occurs.
     * Consumer effects remain provisional until this method returns successfully.
     * Retaining an iterator does not extend its permitted lifetime beyond callback
     * scope. The caller must still discard catalogs and finally clean the workspace.
     * @param \Closure(iterable<string>):mixed $consumer
     */
    public function consume(string $catalogId, \Closure $consumer): mixed
    {
        $this->closeFailures = [];
        $stream = $this->workspace->openRead($catalogId);
        $completed = false;
        $active = true;
        $result = null;
        $failure = null;
        $ids = (function () use ($stream, &$completed, &$active): \Generator {
            if (!$active) { throw new \LogicException('Catalog iterator is outside its consumption scope.'); }
            foreach ($this->codec->read($stream) as $id) {
                yield $id;
                // Check before resuming the codec, whose file may now be closed.
                if (!$active) { throw new \LogicException('Catalog iterator is outside its consumption scope.'); }
            }
            $completed = true;
        })();
        try {
            $result = $consumer($ids);
            if (!$completed) {
                throw new \LogicException('Catalog consumption requires normal exhaustion and full integrity validation.');
            }
        } catch (\Throwable $initial) {
            $failure = $initial;
        } finally {
            $active = false;
        }
        $failure = $this->closePreservingFailure($catalogId, $failure);
        if ($failure !== null) { throw $failure; }
        return $result;
    }

    /** Retrieve before starting another operation; no messages or traces are logged. @return list<\Throwable> */
    public function getCloseFailures(): array
    {
        return $this->closeFailures;
    }

    private function closePreservingFailure(string $id, ?\Throwable $failure): ?\Throwable
    {
        try {
            $this->closeRun($id);
        } catch (\Throwable $closing) {
            if ($failure === null) { return $closing; }
            $this->closeFailures[] = $closing;
        }
        return $failure;
    }

    /** Delegation seam consistent with the workspace merger; never directly fclose(). */
    protected function closeRun(string $id): void
    {
        $this->workspace->close($id);
    }
}
