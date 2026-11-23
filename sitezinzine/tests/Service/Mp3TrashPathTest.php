<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Mp3TrashPath;
use App\Tests\Support\Mp3TestStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class Mp3TrashPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Mp3TestStorage::root() . '/trash-path-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir([
            $this->root . '/library',
            $this->root . '/work',
            $this->root . '/public/nested',
            $this->root . '/trash/nested',
        ], 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function paths(?string $root): Mp3TrashPath
    {
        return new Mp3TrashPath($root, $this->root . '/library', $this->root . '/work', $this->root . '/public');
    }

    public function testResolvesAnExistingFileOrUnoccupiedLeafWithoutWriting(): void
    {
        $paths = $this->paths($this->root . '/trash');
        $directory = realpath($this->root . '/trash/nested');
        $existing = $directory . DIRECTORY_SEPARATOR . 'existant.data';
        file_put_contents($existing, 'witness');
        self::assertSame($existing, $paths->resolve('nested/existant.data'));
        $destination = $directory . DIRECTORY_SEPARATOR . 'absent.data';
        self::assertSame($destination, $paths->resolve('nested/absent.data'));
        self::assertFileDoesNotExist($destination);
        self::assertSame('witness', file_get_contents($existing));
    }

    /** @dataProvider unsafeRelativePaths */
    public function testRejectsUnsafeFilePath(string $relative): void
    {
        $paths = $this->paths($this->root . '/trash');
        $this->expectException(\RuntimeException::class);
        $paths->resolve($relative);
    }

    public static function unsafeRelativePaths(): iterable
    {
        foreach (['', '../library/file', 'nested/../file', '/file', 'C:/file', 'nested\\file',
            'nested//file', './file', "file\0", 'missing/file', 'nested'] as $path) {
            yield [$path];
        }
    }

    /** @dataProvider disallowedRoots */
    public function testRequiresAnExplicitPrivateAndDisjointRoot(?string $suffix): void
    {
        $root = match ($suffix) {
            null, '' => $suffix,
            'relative' => 'trash',
            default => $this->root . $suffix,
        };
        (new Filesystem())->mkdir($this->root . '/library/nested', 0700);
        $paths = $this->paths($root);
        $this->expectException(\RuntimeException::class);
        $paths->resolve('file');
    }

    public static function disallowedRoots(): iterable
    {
        foreach ([null, '', 'relative', '/missing', '/library', '/library/nested', '/work', '/public/nested', '/.'] as $root) {
            yield [$root];
        }
    }

    /** @dataProvider symbolicPaths */
    public function testRejectsSymbolicComponents(string $kind): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This test requires symbolic links in a Linux container.');
        }
        $root = $this->root . '/trash';
        $relative = 'file';
        switch ($kind) {
            case 'root':
                $root = $this->root . '/alias';
                self::assertTrue(symlink($this->root . '/trash', $root));
                break;
            case 'ancestor':
                self::assertTrue(symlink($this->root, $this->root . '/alias'));
                $root = $this->root . '/alias/trash';
                break;
            case 'parent':
                self::assertTrue(symlink($this->root . '/library', $root . '/alias'));
                $relative = 'alias/file';
                break;
            case 'dangling leaf':
                self::assertTrue(symlink($this->root . '/library/missing', $root . '/file'));
                break;
        }
        $paths = $this->paths($root);
        $this->expectException(\RuntimeException::class);
        $paths->resolve($relative);
    }

    public static function symbolicPaths(): iterable
    {
        foreach (['root', 'ancestor', 'parent', 'dangling leaf'] as $kind) {
            yield $kind => [$kind];
        }
    }
}
