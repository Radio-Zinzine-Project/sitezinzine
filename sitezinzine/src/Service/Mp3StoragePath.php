<?php

declare(strict_types=1);

namespace App\Service;

/** Shared lexical and filesystem checks for MP3 storage paths. */
final class Mp3StoragePath
{
    public static function resolve(string $configuredRoot, string $relative): string
    {
        return self::resolvePath($configuredRoot, $relative, false);
    }

    /** Resolve the parent safely, leaving the final entry for lstat() only. */
    public static function resolveEntryForInspection(string $configuredRoot, string $relative): string
    {
        return self::resolvePath($configuredRoot, $relative, true);
    }

    private static function resolvePath(string $configuredRoot, string $relative, bool $inspectLeaf): string
    {
        if ($relative === '' || preg_match('~(^[/\\\\]|[\\\\:]|(^|/)\.\.(/|$)|\x00)~', $relative)) {
            throw new \RuntimeException('Chemin MP3 non autorisé.');
        }
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException('Chemin MP3 ambigu non autorisé.');
            }
        }
        $root = self::directory($configuredRoot);
        $path = $root;
        foreach ($parts as $index => $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            clearstatcache(true, $path);
            if ((!$inspectLeaf || $index !== array_key_last($parts)) && is_link($path)) {
                throw new \RuntimeException('Lien symbolique non autorisé dans le stockage MP3.');
            }
        }
        return $path;
    }

    public static function directory(string $configuredRoot): string
    {
        // Check the configured path BEFORE realpath() erases symbolic components.
        // Trusted parents remain necessary: PHP path checks do not eliminate TOCTOU.
        $configured = rtrim($configuredRoot, '/\\');
        do {
            clearstatcache(true, $configured);
            if (is_link($configured)) {
                throw new \RuntimeException('Lien symbolique non autorisé dans la racine MP3.');
            }
            $parent = dirname($configured);
            if ($parent === $configured) { break; }
            $configured = $parent;
        } while ($configured !== '.');
        $root = realpath($configuredRoot);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('Stockage MP3 introuvable.');
        }
        return $root;
    }
}
