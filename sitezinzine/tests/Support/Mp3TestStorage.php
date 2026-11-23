<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** One exclusively owned directory per PHPUnit process; never accepts a cleanup path. */
final class Mp3TestStorage
{
    private static ?string $root = null;

    public static function initialize(): void
    {
        if (self::$root !== null) {
            return;
        }

        $parent = realpath(sys_get_temp_dir());
        if ($parent === false) {
            throw new \RuntimeException('Temporary directory unavailable.');
        }
        $project = realpath(dirname(__DIR__, 2));
        if ($project === false
            || str_starts_with($parent . DIRECTORY_SEPARATOR, $project . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('MP3 test storage must be outside the project.');
        }
        $root = $parent . DIRECTORY_SEPARATOR . 'zinzine-mp3-tests-' . bin2hex(random_bytes(16));
        if (!mkdir($root, 0700)) {
            throw new \RuntimeException('Cannot create isolated MP3 storage.');
        }
        self::$root = $root;
        register_shutdown_function([self::class, 'cleanup']);
        foreach (['library', 'work', 'trash'] as $directory) {
            if (!mkdir($root . '/' . $directory, 0700)) {
                throw new \RuntimeException('Cannot create MP3 test directory: ' . $directory);
            }
        }
        putenv('MP3_TEST_STORAGE=' . $root);
        $_ENV['MP3_TEST_STORAGE'] = $_SERVER['MP3_TEST_STORAGE'] = $root;
    }

    public static function root(): string
    {
        if (self::$root === null) {
            throw new \LogicException('MP3 test bootstrap must run first.');
        }

        return self::$root;
    }

    public static function library(): string
    {
        return self::root() . '/library';
    }

    public static function work(): string
    {
        return self::root() . '/work';
    }

    public static function cleanup(): void
    {
        $root = self::root();
        // Refuse a replaced root or any symlink/junction resolving outside it.
        if (is_link($root) || realpath($root) !== $root) {
            throw new \RuntimeException('Unsafe MP3 cleanup root.');
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        $paths = [];
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            $resolved = realpath($path);
            if ($entry->isLink() || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Unsafe entry in MP3 test storage.');
            }
            $paths[] = [$path, $entry->isDir()];
        }
        // Validate every entry before deleting anything. No shared directory is removed.
        foreach ($paths as [$path, $directory]) {
            if ($directory) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($root);
    }
}
