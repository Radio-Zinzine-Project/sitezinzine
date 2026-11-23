<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\Mp3StorageLock;
use App\Service\Mp3StoragePath;

class RadioPreparationCopier
{
    private const BLOCK_BYTES = 65536;

    public function __construct(private readonly Mp3StorageLock $lock) {}

    /** Returns the admission report enriched with verified artifacts and per-occurrence source issues. */
    public function prepare(RadioPreparationWorkspace $workspace, ?callable $plan = null): array
    {
        if ($workspace->state() !== 'running') {
            throw new \LogicException('Only a running preparation can receive copies.');
        }
        try {
            $groups = [];
            foreach ($workspace->report()['entries'] as $entry) {
                if ($entry['admission'] === 'candidate') {
                    $groups[$entry['mp3Path']][] = $entry;
                }
            }
            $artifacts = $issues = $scratchById = $signatures = [];
            foreach ($groups as $source => $entries) {
                $scratch = '.source-' . $entries[0]['diffusionId'] . '.part';
                $capture = $this->lock->synchronized(fn () => $this->capture($workspace, (string) $source, $scratch));
                if (is_array($capture)) {
                    $signature = $capture['signature'];
                    unset($capture['signature']);
                }
                foreach ($entries as $entry) {
                    $id = $entry['diffusionId'];
                    if (is_string($capture)) {
                        $issues[$id] = $capture;
                        continue;
                    }
                    if ($plan === null) {
                        $this->duplicate($workspace, $scratch, $entry['filename'], $capture);
                    } else {
                        $scratchById[$id] = $scratch;
                    }
                    $signatures[$id] = $signature;
                    $artifacts[$id] = ['diffusionId' => $id, 'filename' => $entry['filename']] + $capture;
                }
                if ($plan === null && is_array($capture)) {
                    $workspace->discardFile($scratch);
                }
            }

            $differential = null;
            if ($plan !== null) {
                $differential = $plan($workspace->report()['entries'], $artifacts, $issues);
                $artifacts = array_intersect_key($artifacts, array_flip($differential['copyIds']));
                foreach ($artifacts as $id => $artifact) {
                    $this->duplicate($workspace, $scratchById[$id], $artifact['filename'], [
                        'size' => $artifact['size'], 'sha256' => $artifact['sha256'],
                    ]);
                }
                foreach (array_unique($scratchById) as $scratch) {
                    $workspace->discardFile($scratch);
                }
            }

            // Preserve manifest order even when nonadjacent entries share a source.
            $ordered = [];
            foreach ($workspace->report()['candidateIds'] as $id) {
                if (isset($artifacts[$id])) {
                    $ordered[] = $artifacts[$id];
                }
            }

            return $workspace->complete($ordered, $issues, $differential, $signatures);
        } catch (\Throwable $error) {
            try {
                $workspace->fail('Impossible de terminer la copie des MP3.');
            } catch (\Throwable) {
                throw new \RuntimeException('Radio preparation failed and its workspace could not be safely cleaned.', 0, $error);
            }
            throw $error;
        }
    }

    /** Read-only source observation. The light probe does not open or hash the file. */
    public function probeSource(string $library, string $relative, bool $full): array|string
    {
        $resolve = static fn (): string => Mp3StoragePath::resolve($library, $relative);
        if ($full) {
            return $this->lock->isHeldByCurrentProcess()
                ? $this->readSource($resolve)
                : $this->lock->synchronized(fn () => $this->readSource($resolve));
        }

        $source = self::locateSource($resolve);

        return is_array($source) ? ['signature' => self::signature($source[1])] : $source;
    }

    private function capture(RadioPreparationWorkspace $workspace, string $source, string $scratch): array|string
    {
        $created = false;
        $result = $this->readSource(fn () => $workspace->sourcePath($source), function () use ($workspace, $scratch, &$created) {
            $output = $workspace->createFile($scratch);
            $created = true;

            return $output;
        });
        if ($created) {
            if (is_string($result)) {
                $workspace->discardFile($scratch);
            } else {
                $workspace->ownedFile($scratch);
            }
        }

        return $result;
    }

    private static function locateSource(callable $resolve): array|string
    {
        try {
            $path = $resolve();
        } catch (\RuntimeException) {
            return 'source_unsafe';
        }
        $before = self::stat($path);
        if ($before === false) {
            $entries = @scandir(dirname($path), SCANDIR_SORT_NONE);

            return $entries !== false && !in_array(basename($path), $entries, true) ? 'source_absent' : 'source_unreadable';
        }

        return ($before['mode'] & 0170000) === 0100000 ? [$path, $before] : 'source_not_regular';
    }

    private function readSource(callable $resolve, ?callable $createOutput = null): array|string
    {
        $source = self::locateSource($resolve);
        if (is_string($source)) {
            return $source;
        }
        [$path, $before] = $source;
        $input = $this->openSource($path);
        if ($input === false) {
            return 'source_unreadable';
        }
        try {
            if (!self::sameFile($before, fstat($input)) || !self::sourceMatches($resolve, $path, $before)) {
                return 'source_changed';
            }
            $output = $createOutput === null ? null : $createOutput();
            try {
                $result = $this->streamCopy($input, $output, $before['size']);
            } finally {
                if ($output !== null) {
                    fclose($output);
                }
            }
            if (!$this->sourceMatches($resolve, $path, $before) || !self::sameFile($before, fstat($input))) {
                return 'source_changed';
            }

            return is_array($result) ? $result + ['signature' => self::signature($before)] : $result;
        } finally {
            fclose($input);
        }
    }

    private static function signature(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    private function duplicate(RadioPreparationWorkspace $workspace, string $scratch, string $name, array $expected): void
    {
        $path = $workspace->ownedFile($scratch);
        $before = self::stat($path);
        $input = @fopen($path, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Cannot read the private MP3 snapshot.');
        }
        try {
            if ($before === false || !self::sameFile($before, fstat($input))) {
                throw new \RuntimeException('The private MP3 snapshot identity changed.');
            }
            $output = $workspace->createFile($name);
            try {
                $result = $this->streamCopy($input, $output, $expected['size']);
            } finally {
                fclose($output);
            }
            if ($result !== $expected || !self::sameFile($before, fstat($input))
                || !self::sameFile($before, self::stat($workspace->ownedFile($scratch)))) {
                throw new \RuntimeException('The private MP3 output could not be verified.');
            }
            $workspace->ownedFile($name);
        } finally {
            fclose($input);
        }
    }

    /** @return array{size: int, sha256: string}|string */
    private function streamCopy(mixed $input, mixed $output, int $expectedSize): array|string
    {
        $hash = hash_init('sha256');
        $size = 0;
        while (true) {
            $block = @fread($input, self::BLOCK_BYTES);
            if ($block === false || ($block === '' && !feof($input))) {
                return 'source_unreadable';
            }
            if ($block === '') {
                break;
            }
            $length = strlen($block);
            $size += $length;
            if ($size > $expectedSize) {
                return 'source_changed';
            }
            if ($output !== null && $this->write($output, $block) !== $length) {
                throw new \RuntimeException('Incomplete write to the private MP3 storage.');
            }
            hash_update($hash, $block);
        }
        if ($size !== $expectedSize) {
            return 'source_changed';
        }
        if ($output !== null && !fflush($output)) {
            throw new \RuntimeException('Cannot flush the private MP3 output.');
        }

        return ['size' => $size, 'sha256' => hash_final($hash)];
    }

    /** @return resource|false */
    protected function openSource(string $path): mixed
    {
        return @fopen($path, 'rb');
    }

    protected function write(mixed $output, string $block): int|false
    {
        return @fwrite($output, $block);
    }

    private function sourceMatches(callable $resolve, string $path, array $before): bool
    {
        try {
            return $resolve() === $path && self::sameFile($before, self::stat($path));
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function stat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    private static function sameFile(array $before, array|false $after): bool
    {
        if ($after === false || ($after['mode'] & 0170000) !== 0100000) {
            return false;
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
            if ($before[$key] !== $after[$key]) {
                return false;
            }
        }

        return true;
    }
}
