<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class Mp3TestStorageTest extends TestCase
{
    public function testStorageUsesPrivateTemporarySiblingDirectories(): void
    {
        $root = Mp3TestStorage::root();
        self::assertSame(realpath(sys_get_temp_dir()), dirname($root));
        $project = realpath(dirname(__DIR__, 2));
        self::assertNotFalse($project);
        self::assertFalse(str_starts_with($root . DIRECTORY_SEPARATOR, $project . DIRECTORY_SEPARATOR));

        foreach (['library', 'work', 'trash'] as $name) {
            $path = $root . DIRECTORY_SEPARATOR . $name;
            self::assertDirectoryExists($path);
            self::assertFalse(is_link($path));
            self::assertSame($path, realpath($path));
            if (DIRECTORY_SEPARATOR === '/') {
                self::assertSame(0700, fileperms($path) & 0777);
            }
        }
    }

    public function testShutdownCleansOnlyTheChildStorageAfterRefusingAnUnsafeLink(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This test requires Unix symbolic links.');
        }

        $script = <<<'PHP'
            require $argv[1];
            $storage = \App\Tests\Support\Mp3TestStorage::class;
            $storage::initialize();
            $root = $storage::root();
            $file = $root . '/work/input.mp3';
            $link = $root . '/trash/external';
            $sentinel = tempnam(sys_get_temp_dir(), 'zinzine-mp3-sentinel-');
            try {
                if (file_put_contents($file, 'synthetic') === false || !symlink($sentinel, $link)) {
                    throw new RuntimeException('Cannot create cleanup fixtures.');
                }
                try {
                    $storage::cleanup();
                } catch (RuntimeException $error) {
                    if ($error->getMessage() !== 'Unsafe entry in MP3 test storage.'
                        || !is_file($file) || !is_file($sentinel) || !is_link($link)) {
                        throw $error;
                    }
                    echo $root;
                    return;
                }
                throw new LogicException('Cleanup accepted a symbolic link.');
            } finally {
                if (is_link($link)) {
                    unlink($link);
                }
                unlink($sentinel);
            }
            PHP;
        $process = new Process([PHP_BINARY, '-r', $script, __DIR__ . '/Mp3TestStorage.php'], timeout: 10);
        $process->mustRun();
        $childRoot = $process->getOutput();
        self::assertMatchesRegularExpression('/^zinzine-mp3-tests-[a-f0-9]{32}$/', basename($childRoot));
        self::assertNotSame(Mp3TestStorage::root(), $childRoot);
        self::assertDirectoryDoesNotExist($childRoot);
        self::assertDirectoryExists(Mp3TestStorage::root());
    }
}
