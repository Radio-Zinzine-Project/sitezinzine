<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Read-only discovery, not an audio validator or an atomic filesystem snapshot. */
final class AudioInventoryScanner
{
    private readonly string $root;

    /** @param list<string> $excludedDirectories Relative preparation directories, never traversed. */
    public function __construct(string $authorizedRoot, private readonly array $excludedDirectories = ['private'])
    {
        $root = realpath($authorizedRoot);
        if ($root === false || !is_dir($root) || $this->hasSymbolicComponent($authorizedRoot)) {
            throw new \InvalidArgumentException('An existing non-symbolic storage root is required.');
        }
        $this->root = $root;
        foreach ($excludedDirectories as $excluded) {
            if (!is_string($excluded) || $excluded === '' || preg_match('~(^/|[\\\\:\x00]|(^|/)\.{1,2}(/|$)|/$|//)~', $excluded)) {
                throw new \InvalidArgumentException('Preparation exclusions must be contained relative directories.');
            }
        }
    }

    /**
     * Authorization is checked immediately; traversal starts on iteration.
     * Consume the entire generator to collect errors. Even a completed scan is
     * only a sequence of observations: changes between checks remain possible.
     * Paths use '/' as the relative directory separator; filename bytes are kept.
     *
     * @return \Generator<int, array{type:'file', relative_path:string}|array{type:'error', relative_path:string, reason:string}>
     */
    public function scan(string $root): \Generator
    {
        if ($this->hasSymbolicComponent($root) || realpath($root) !== $this->root) {
            throw new \InvalidArgumentException('This root is not authorized for this scanner.');
        }

        return $this->walk('');
    }

    private function walk(string $relative): \Generator
    {
        $problem = $this->problem($relative);
        if ($problem !== null) {
            yield $this->error($relative, $problem);
            return;
        }
        $path = $this->absolute($relative);
        $identity = @lstat($path);
        $directory = @opendir($path);
        if ($directory === false) {
            yield $this->error($relative, 'unreadable_directory');
            return;
        }

        try {
            while (true) {
                // Recheck after every suspension and before touching a child.
                $problem = $this->problem($relative);
                $current = $problem === null ? @lstat($path) : false;
                if ($problem !== null || $current === false
                    || $identity['dev'] !== $current['dev'] || $identity['ino'] !== $current['ino']) {
                    yield $this->error($relative, $problem ?? 'directory_changed');
                    return;
                }
                $readFailed = false;
                set_error_handler(static function () use (&$readFailed): bool {
                    $readFailed = true;
                    return true;
                });
                try {
                    $name = readdir($directory);
                } finally {
                    restore_error_handler();
                }
                if ($readFailed) {
                    yield $this->error($relative, 'directory_read_failed');
                    return;
                }
                if ($name === false) {
                    return;
                }
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $child = $relative === '' ? $name : $relative . '/' . $name;
                $problem = $this->problem($child);
                if ($problem !== null) {
                    yield $this->error($child, $problem);
                    continue;
                }
                $childPath = $this->absolute($child);
                $stat = @lstat($childPath);
                if ($stat === false) {
                    yield $this->error($child, 'unavailable');
                    continue;
                }
                $kind = $stat['mode'] & 0170000;
                if ($kind === 0040000) {
                    if (!in_array($child, $this->excludedDirectories, true)) {
                        yield from $this->walk($child);
                    }
                } elseif ($kind === 0100000 && strcasecmp(pathinfo($name, PATHINFO_EXTENSION), 'mp3') === 0) {
                    if (!is_readable($childPath)) {
                        yield $this->error($child, 'unreadable_file');
                        continue;
                    }
                    yield ['type' => 'file', 'relative_path' => $child];
                    // A file may disappear while the consumer handles this observation.
                    // Check the parent first, so an invalidated tree is not traversed.
                    if ($this->problem($relative) === null) {
                        $problem = $this->problem($child);
                        if ($problem !== null || !is_readable($childPath)) {
                            yield $this->error($child, $problem ?? 'unreadable_file');
                        }
                    }
                }
            }
        } finally {
            closedir($directory);
        }
    }

    /** Revalidate all ancestors: checking just the last path component follows replaced parents. */
    private function problem(string $relative): ?string
    {
        $path = $this->root;
        $parts = $relative === '' ? [] : explode('/', $relative);
        foreach (array_merge([''], $parts) as $part) {
            if ($part !== '') {
                $path .= DIRECTORY_SEPARATOR . $part;
            }
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false) {
                return 'unavailable';
            }
            if (($stat['mode'] & 0170000) === 0120000) {
                return 'symlink_skipped';
            }
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            return 'unavailable';
        }
        if ($resolved !== $this->root && !str_starts_with($resolved, $this->root . DIRECTORY_SEPARATOR)) {
            return 'outside_root';
        }

        return null;
    }

    private function hasSymbolicComponent(string $path): bool
    {
        do {
            clearstatcache(true, $path);
            if (is_link($path)) {
                return true;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        } while ($path !== '.');

        return false;
    }

    private function absolute(string $relative): string
    {
        return $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . $relative;
    }

    private function error(string $relative, string $reason): array
    {
        return ['type' => 'error', 'relative_path' => $relative, 'reason' => $reason];
    }
}
