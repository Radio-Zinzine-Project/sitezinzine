<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\Mp3StorageLockTimeout;
use App\Service\Mp3StorageLock;
use App\Service\Mp3TrashPath;
use App\Tests\Support\Mp3TestStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class Mp3StorageLockTest extends TestCase
{
    private string $root;
    private string $file;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_geteuid')) {
            self::markTestSkipped('Requires PHP POSIX on the Linux test platform.');
        }
        $this->root = Mp3TestStorage::root() . '/storage-lock-' . bin2hex(random_bytes(8));
        foreach (['library', 'work', 'trash', 'public'] as $directory) {
            (new Filesystem())->mkdir($this->root . '/' . $directory, 0700);
        }
        $this->file = $this->root . '/trash/.storage.lock';
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    private function lock(int $timeout = 100): Mp3StorageLock
    {
        return new Mp3StorageLock(new Mp3TrashPath(
            $this->root . '/trash', $this->root . '/library', $this->root . '/work', $this->root . '/public'
        ), $timeout);
    }

    public function testKeepsTheSameFileAndClosesDescriptors(): void
    {
        $lock = $this->lock();
        $before = count(scandir('/proc/self/fd'));
        self::assertSame('result', $lock->synchronized(static fn () => 'result'));
        $inode = fileinode($this->file);
        self::assertGreaterThan(0, $inode);
        self::assertSame(0600, fileperms($this->file) & 0777);
        for ($i = 0; $i < 5; ++$i) {
            $lock->synchronized(static fn () => null);
        }
        clearstatcache(true, $this->file);
        self::assertSame($inode, fileinode($this->file));
        self::assertSame($before, count(scandir('/proc/self/fd')));
    }

    public function testExceptionAndNestedCallReleaseTheLock(): void
    {
        $lock = $this->lock();
        $failure = new \DomainException('callback failure');
        try {
            $lock->synchronized(function () use ($failure): void {
                try {
                    $this->lock()->synchronized(static fn () => self::fail('Nested callback ran.'));
                    self::fail('Nested acquisition was accepted.');
                } catch (\LogicException) {
                    self::assertFileExists($this->file);
                }
                throw $failure;
            });
            self::fail('Callback exception was swallowed.');
        } catch (\DomainException $error) {
            self::assertSame($failure, $error);
        }
        self::assertTrue($this->lock(0)->synchronized(static fn () => true));
    }

    /** @dataProvider unsafeStates */
    public function testRejectsUnsafePaths(string $state): void
    {
        $lock = $this->lock();
        if ($state === 'leaf link') {
            self::assertTrue(symlink($this->root . '/missing', $this->file));
        } elseif ($state === 'root link') {
            rmdir($this->root . '/trash');
            self::assertTrue(symlink($this->root . '/work', $this->root . '/trash'));
        } elseif ($state === 'public') {
            $lock = new Mp3StorageLock(new Mp3TrashPath(
                $this->root . '/public', $this->root . '/library', $this->root . '/work', $this->root . '/public'
            ), 100);
        } else {
            chmod($this->root . '/trash', 0755);
        }
        $this->expectException(\RuntimeException::class);
        $lock->synchronized(static fn () => self::fail('Unsafe callback ran.'));
    }

    public static function unsafeStates(): iterable
    {
        foreach (['leaf link', 'root link', 'public', 'public mode'] as $state) {
            yield $state => [$state];
        }
    }

    public function testReportsSubstitutedFileAndDoesNotRemoveEitherName(): void
    {
        try {
            $this->lock()->synchronized(function (): void {
                rename($this->file, $this->file . '.old');
                file_put_contents($this->file, '');
                chmod($this->file, 0600);
            });
            self::fail('Replacement of the lock file went undetected.');
        } catch (\RuntimeException) {
            self::assertFileExists($this->file);
            self::assertFileExists($this->file . '.old');
        }
    }

    /** @dataProvider holderExitModes */
    public function testContentionAndAcquisitionAfterHolderExits(bool $kill): void
    {
        $input = new InputStream();
        $code = <<<'PHP'
            require $argv[1];
            $root = $argv[2];
            $paths = new \App\Service\Mp3TrashPath("$root/trash", "$root/library", "$root/work", "$root/public");
            (new \App\Service\Mp3StorageLock($paths, 100))->synchronized(static function (): void {
                echo "LOCKED\n";
                fflush(STDOUT);
                fgets(STDIN);
            });
            PHP;
        $holder = new Process([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $this->root],
            input: $input, timeout: 6);
        try {
            $holder->start();
            $output = '';
            self::assertTrue($holder->waitUntil(static function (string $type, string $chunk) use (&$output): bool {
                if ($type === Process::OUT) {
                    $output .= $chunk;
                }
                return str_contains($output, "LOCKED\n");
            }));
            $inode = fileinode($this->file);
            $started = hrtime(true);
            try {
                $this->lock(80)->synchronized(static fn () => self::fail('Contending callback ran.'));
                self::fail('Timeout expected.');
            } catch (Mp3StorageLockTimeout) {
                $elapsed = (hrtime(true) - $started) / 1_000_000;
                self::assertGreaterThanOrEqual(65, $elapsed);
                self::assertLessThan(1500, $elapsed);
            }
            if ($kill) {
                $holder->signal(9);
            } else {
                $input->write("release\n");
                $input->close();
                self::assertSame(0, $holder->wait());
            }
            self::assertTrue($this->lock(1000)->synchronized(static fn () => true));
            $holder->wait();
            self::assertFalse($holder->isRunning());
            self::assertSame($inode, fileinode($this->file));
        } finally {
            if ($holder->isRunning()) {
                $holder->stop(0, 9);
            }
            $input->close();
        }
    }

    public static function holderExitModes(): iterable
    {
        yield 'normal exit' => [false];
        yield 'SIGKILL' => [true];
    }
}
