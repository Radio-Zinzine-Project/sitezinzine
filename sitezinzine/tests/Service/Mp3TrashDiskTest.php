<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Service\Mp3DiskIo;
use App\Service\Mp3TrashDisk;
use App\Service\Mp3TrashPath;
use App\Tests\Support\Mp3TestStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class Mp3TrashDiskTest extends TestCase
{
    private const CONTENT = 'synthetic disk witness';
    private string $root;
    private string $publicFile;
    private string $privateFile;
    private Mp3TrashOperation $operation;

    protected function setUp(): void
    {
        $this->root = Mp3TestStorage::root() . '/disk-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir(array_map(fn (string $dir) => $this->root . '/' . $dir, ['library', 'trash', 'work', 'public']), 0700);
        $this->operation = new Mp3TrashOperation(str_repeat('a', 32), 1, 'origin.data', null,
            strlen(self::CONTENT), hash('sha256', self::CONTENT), 2, new \DateTimeImmutable());
        $this->publicFile = $this->root . '/library/origin.data';
        $this->privateFile = $this->root . '/trash/' . $this->operation->trashRelativePath();
        file_put_contents($this->publicFile, self::CONTENT);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function disk(?Mp3DiskIo $io = null): Mp3TrashDisk
    {
        $library = $this->root . '/library';
        return new Mp3TrashDisk($library, new Mp3TrashPath($this->root . '/trash', $library,
            $this->root . '/work', $this->root . '/public'), $io ?? new Mp3DiskIo());
    }

    public function testBothDirectionsAndReplay(): void
    {
        $disk = $this->disk();
        $disk->prepareTrash($this->operation);
        $disk->prepareTrash($this->operation);
        self::assertSame(fileinode($this->publicFile), fileinode($this->privateFile));
        $disk->finalizeTrash($this->operation);
        $disk->finalizeTrash($this->operation);
        self::assertFileDoesNotExist($this->publicFile);
        self::assertSame(self::CONTENT, file_get_contents($this->privateFile));
        $disk->prepareRestore($this->operation);
        $disk->prepareRestore($this->operation);
        self::assertSame(fileinode($this->publicFile), fileinode($this->privateFile));
        $disk->finalizeRestore($this->operation);
        $disk->finalizeRestore($this->operation);
        self::assertFileDoesNotExist($this->privateFile);
        self::assertSame(self::CONTENT, file_get_contents($this->publicFile));
    }

    /** @dataProvider unsafeCases */
    public function testRefusesUnsafeDiskStates(string $method, ?string $public, ?string $private): void
    {
        if (($public === 'symlink' || $private === 'symlink') && DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('Symbolic links require the Linux test container.');
        }
        $outside = $this->root . '/outside.data';
        file_put_contents($outside, self::CONTENT);
        unlink($this->publicFile);
        foreach ([$this->publicFile => $public, $this->privateFile => $private] as $path => $state) {
            if ($state === 'symlink') {
                self::assertTrue(symlink($outside, $path));
            } elseif ($state !== null) {
                file_put_contents($path, $state);
            }
        }
        try {
            $this->disk()->$method($this->operation);
            self::fail('Unsafe operation should fail.');
        } catch (\RuntimeException) {
            foreach ([$this->publicFile => $public, $this->privateFile => $private] as $path => $state) {
                if ($state === null) {
                    self::assertFileDoesNotExist($path);
                } elseif ($state === 'symlink') {
                    self::assertSame($outside, readlink($path));
                } else {
                    self::assertSame($state, file_get_contents($path));
                }
            }
            self::assertSame(self::CONTENT, file_get_contents($outside));
        }
    }

    public static function unsafeCases(): iterable
    {
        yield 'missing origin' => ['prepareTrash', null, null];
        yield 'missing private link before public unlink' => ['finalizeTrash', self::CONTENT, null];
        yield 'missing private source' => ['prepareRestore', null, null];
        yield 'missing public link before private unlink' => ['finalizeRestore', null, self::CONTENT];
        yield 'private collision with identical bytes' => ['prepareTrash', self::CONTENT, self::CONTENT];
        yield 'public collision with identical bytes' => ['prepareRestore', self::CONTENT, self::CONTENT];
        yield 'identity mismatch before public unlink' => ['finalizeTrash', self::CONTENT, self::CONTENT];
        yield 'identity mismatch before private unlink' => ['finalizeRestore', self::CONTENT, self::CONTENT];
        yield 'unexpected hash with same size' => ['prepareTrash', str_repeat('x', strlen(self::CONTENT)), null];
        yield 'unexpected private content' => ['prepareRestore', null, 'different'];
        yield 'symbolic public source' => ['prepareTrash', 'symlink', null];
        yield 'symbolic private destination' => ['prepareTrash', self::CONTENT, 'symlink'];
    }

    public function testLinkFailureRetainsTheSource(): void
    {
        $io = $this->createMock(Mp3DiskIo::class);
        $io->expects(self::once())->method('link')->willReturn(false);
        $io->expects(self::never())->method('unlink');
        try {
            $this->disk($io)->prepareTrash($this->operation);
            self::fail('Link failure should be reported.');
        } catch (\RuntimeException) {
            self::assertSame(self::CONTENT, file_get_contents($this->publicFile));
            self::assertFileDoesNotExist($this->privateFile);
        }
    }

    public function testUnlinkFailureKeepsBothNamesForRetry(): void
    {
        $this->disk()->prepareTrash($this->operation);
        $io = $this->createMock(Mp3DiskIo::class);
        $io->expects(self::once())->method('unlink')->with($this->publicFile)->willReturn(false);
        try {
            $this->disk($io)->finalizeTrash($this->operation);
            self::fail('Unlink failure should be reported.');
        } catch (\RuntimeException) {
            self::assertSame(self::CONTENT, file_get_contents($this->publicFile));
            self::assertSame(fileinode($this->publicFile), fileinode($this->privateFile));
        }
        $this->disk()->finalizeTrash($this->operation);
        self::assertFileDoesNotExist($this->publicFile);
        self::assertSame(self::CONTENT, file_get_contents($this->privateFile));
    }

    public function testDestinationCreatedJustBeforeNativeLinkIsNeverOverwritten(): void
    {
        $io = $this->createMock(Mp3DiskIo::class);
        $io->expects(self::once())->method('link')->willReturnCallback(
            static function (string $source, string $destination): bool {
                file_put_contents($destination, 'concurrent occupant');

                return (new Mp3DiskIo())->link($source, $destination);
            }
        );
        $io->expects(self::never())->method('unlink');
        try {
            $this->disk($io)->prepareTrash($this->operation);
            self::fail('The native link must fail when its destination becomes occupied.');
        } catch (\RuntimeException) {
            self::assertSame('concurrent occupant', file_get_contents($this->privateFile));
            self::assertSame(self::CONTENT, file_get_contents($this->publicFile));
        }
    }
}
