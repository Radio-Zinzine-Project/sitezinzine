<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Owns one private directory and every stream it returns. Call cleanup() in finally.
 * writing -> closed successfully -> finalize() (producer's explicit success claim)
 * -> finalized; discard() is terminal. One stream per run, reads restart at zero.
 * maxRuns bounds total creations, including discarded records, not just live files.
 * The active registry retains only owned files; discarded identifiers become unknown.
 * A monotone creation ordinal prevents ID reuse without retaining tombstones.
 * No codec validation, fsync durability, hard disk quota or crash recovery is implied.
 * A trusted parent and operational volume isolation are required: PHP path checks
 * cannot eliminate TOCTOU races against an actor able to replace parent directories.
 */
final class AudioInventoryTemporaryWorkspace
{
    private string $root;
    private string $directory;
    private array $rootIdentity;
    private array $directoryIdentity;
    private array $runs = [];
    private int $createdRuns = 0;
    private bool $cleaned = false;
    private bool $closing = false;

    public function __construct(string $authorizedRoot, private int $maxOpenStreams, private int $maxRuns)
    {
        if ($maxOpenStreams < 1 || $maxRuns < 1) {
            throw new \InvalidArgumentException('Workspace limits must be positive.');
        }
        clearstatcache();
        $root = realpath($authorizedRoot);
        if ($root === false || is_link($authorizedRoot) || !is_dir($root)
            || !is_readable($root) || !is_writable($root)) {
            throw new \InvalidArgumentException('An existing accessible non-symbolic temporary root is required.');
        }
        $this->root = $root;
        $this->rootIdentity = $this->identity($root);
        $this->directory = $root.DIRECTORY_SEPARATOR.'inventory-'.bin2hex(random_bytes(16));
        if (!@mkdir($this->directory, 0700)) {
            throw new \RuntimeException('Cannot exclusively create private workspace.');
        }
        try {
            $this->directoryIdentity = $this->identity($this->directory);
            $this->privatePermissions($this->directory, 0700);
            $this->assertDirectory();
        } catch (\Throwable $failure) {
            @rmdir($this->directory);
            throw $failure;
        }
    }

    public function createRun(): string
    {
        $this->assertUsable();
        $this->assertDirectory();
        if ($this->createdRuns >= $this->maxRuns) { throw new \LengthException('Run budget exhausted.'); }
        $id = sprintf('%016x', $this->createdRuns + 1).bin2hex(random_bytes(16));
        $path = $this->directory.DIRECTORY_SEPARATOR.$id.'.run';
        // Creation itself briefly owns a descriptor and must obey the same budget.
        $this->assertStreamBudget();
        $stream = @fopen($path, 'xb');
        if ($stream === false) { throw new \RuntimeException('Cannot exclusively create run.'); }
        ++$this->createdRuns;
        $this->runs[$id] = [
            'path' => $path, 'identity' => fstat($stream), 'state' => 'writing',
            'stream' => $stream, 'mode' => null, 'written' => false, 'closed' => false,
        ];
        try { $this->privatePermissions($path, 0600); }
        finally { $this->release($id, false); }
        return $id;
    }

    public function openWrite(string $runId): mixed
    {
        $this->assertUsable(); $this->assertRun($runId);
        $run = $this->runs[$runId];
        if ($run['state'] !== 'writing' || $run['written']) { throw new \LogicException('Run cannot be rewritten.'); }
        $stream = $this->open($runId, 'r+b');
        $this->runs[$runId]['written'] = true;
        $this->runs[$runId]['mode'] = 'write';
        return $stream;
    }

    /** Explicit producer success declaration, only after workspace-owned close(). */
    public function finalize(string $runId): void
    {
        $this->assertUsable(); $this->assertRun($runId);
        $run = $this->runs[$runId];
        if ($run['state'] !== 'writing' || !$run['closed'] || $run['stream'] !== null) {
            throw new \LogicException('Finalization requires a successfully closed writer.');
        }
        $this->assertFile($runId, true);
        $this->runs[$runId]['state'] = 'finalized';
    }

    public function openRead(string $runId): mixed
    {
        $this->assertUsable(); $this->assertRun($runId);
        if ($this->runs[$runId]['state'] !== 'finalized') { throw new \LogicException('Only finalized runs are readable.'); }
        $stream = $this->open($runId, 'rb');
        $this->runs[$runId]['mode'] = 'read';
        return $stream;
    }

    public function close(string $runId): void
    {
        $this->assertRun($runId);
        $this->release($runId, true);
    }

    public function discard(string $runId): void
    {
        $this->assertRun($runId);
        $failure = null;
        $stream = $this->runs[$runId]['stream'];
        try { $this->release($runId, false); } catch (\Throwable $error) { $failure = $error; }
        if (is_resource($stream)) {
            // Keep ownership if a failed closure left a live descriptor.
            $this->runs[$runId]['stream'] = $stream;
            throw new \RuntimeException('Cannot discard a run with an open stream.', 0, $failure);
        }
        $this->assertFile($runId, false);
        if (!@unlink($this->runs[$runId]['path'])) { throw new \RuntimeException('Cannot remove owned run.', 0, $failure); }
        unset($this->runs[$runId]);
        if ($failure !== null) { throw $failure; }
    }

    /** Attempts all closures/removals; throws on residue. Never recursively deletes. */
    public function cleanup(): void
    {
        if ($this->cleaned) { return; }
        $this->closing = true;
        $errors = [];
        foreach (array_keys($this->runs) as $id) {
            try { $this->discard($id); } catch (\Throwable $failure) { $errors[] = $failure; }
        }
        try {
            $this->assertDirectory();
            if (!@rmdir($this->directory)) { throw new \RuntimeException('Workspace contains residue or cannot be removed.'); }
            $this->cleaned = true;
        } catch (\Throwable $failure) { $errors[] = $failure; }
        if ($errors !== []) {
            throw new \RuntimeException('Workspace cleanup failed ('.count($errors).' error(s)); inspect owned operation for residue.', 0, $errors[0]);
        }
    }

    private function open(string $id, string $mode): mixed
    {
        if ($this->runs[$id]['stream'] !== null) { throw new \LogicException('Only one stream per run is allowed.'); }
        $this->assertStreamBudget();
        $this->assertFile($id, true);
        $stream = @fopen($this->runs[$id]['path'], $mode);
        if ($stream === false) { throw new \RuntimeException('Cannot open owned run.'); }
        try {
            $this->sameIdentity($this->runs[$id]['identity'], fstat($stream));
            $this->assertFile($id, true);
        } catch (\Throwable $failure) { fclose($stream); throw $failure; }
        return $this->runs[$id]['stream'] = $stream;
    }

    private function release(string $id, bool $certifyWriter): void
    {
        $stream = $this->runs[$id]['stream'];
        if ($stream === null) { return; }
        $writer = $this->runs[$id]['mode'] === 'write';
        $this->runs[$id]['closed'] = $writer ? false : $this->runs[$id]['closed'];
        $this->runs[$id]['stream'] = null;
        if (!is_resource($stream)) { throw new \RuntimeException('Owned stream was closed outside the workspace.'); }
        $failure = null;
        try {
            if ($writer && !@fflush($stream)) { throw new \RuntimeException('Cannot flush owned run.'); }
            $stat = fstat($stream);
            if ($stat === false) { throw new \RuntimeException('Cannot inspect owned stream.'); }
            $this->sameIdentity($this->runs[$id]['identity'], $stat);
        } catch (\Throwable $error) { $failure = $error; }
        if (!@fclose($stream) && $failure === null) { $failure = new \RuntimeException('Cannot close owned stream.'); }
        if ($failure !== null) { throw $failure; }
        if ($writer) {
            $this->assertFile($id, false);
            $this->runs[$id]['identity'] = $stat;
            $this->runs[$id]['closed'] = $certifyWriter;
        }
    }

    private function assertStreamBudget(): void
    {
        $open = 0;
        foreach ($this->runs as $run) { if (is_resource($run['stream'])) { ++$open; } }
        if ($open >= $this->maxOpenStreams) { throw new \LengthException('Open stream budget exhausted.'); }
    }

    private function assertRun(string $id): void
    {
        if (!isset($this->runs[$id])) { throw new \InvalidArgumentException('Unknown operation run identifier.'); }
    }

    private function assertUsable(): void
    {
        if ($this->closing || $this->cleaned) { throw new \LogicException('Workspace is closed for new work.'); }
    }

    private function assertDirectory(): void
    {
        $this->sameIdentity($this->rootIdentity, $this->identity($this->root));
        $this->sameIdentity($this->directoryIdentity, $this->identity($this->directory));
        if (realpath($this->directory) !== $this->directory || !is_dir($this->directory)) {
            throw new \RuntimeException('Workspace directory changed.');
        }
    }

    private function assertFile(string $id, bool $checkSize): void
    {
        $this->assertDirectory();
        $run = $this->runs[$id];
        $stat = $this->identity($run['path']);
        if (dirname($run['path']) !== $this->directory || ($stat['mode'] & 0170000) !== 0100000) {
            throw new \RuntimeException('Run is not an owned regular file.');
        }
        $this->sameIdentity($run['identity'], $stat);
        if ($checkSize && $stat['size'] !== $run['identity']['size']) { throw new \RuntimeException('Run size changed externally.'); }
    }

    private function identity(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || is_link($path)) { throw new \RuntimeException('Owned path is missing or symbolic.'); }
        return $stat;
    }

    private function sameIdentity(array $expected, array|false $actual): void
    {
        if ($actual === false || $expected['dev'] !== $actual['dev'] || $expected['ino'] !== $actual['ino']
            || ($expected['mode'] & 0170000) !== ($actual['mode'] & 0170000)) {
            throw new \RuntimeException('Owned filesystem identity changed.');
        }
    }

    private function privatePermissions(string $path, int $mode): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $changed = @chmod($path, $mode);
            clearstatcache(true, $path);
            if (!$changed || (fileperms($path) & 0777) !== $mode) {
                throw new \RuntimeException('Cannot establish private permissions.');
            }
        }
    }
}
