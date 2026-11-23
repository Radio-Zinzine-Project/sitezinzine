<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Emission;
use App\Repository\CategorieTagImageRepository;
use App\Service\Mp3Processor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Vich\UploaderBundle\Storage\StorageInterface;

/** Small, exclusively owned fixtures; no kernel, SQL or audio-content processing. */
final class Mp3DeletionPathTest extends TestCase
{
    private string $root;
    private array $hashes = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-mp3-deletion-'.bin2hex(random_bytes(10));
        mkdir($this->root, 0700);
        foreach (['library', 'library/sub', 'outside'] as $dir) { mkdir($this->root.'/'.$dir, 0700); }
        foreach (['library/inside.mp3', 'library/sub/inside.mp3', 'outside/sentinel.mp3'] as $path) {
            file_put_contents($this->root.'/'.$path, 'synthetic fixture '.$path);
            $this->hashes[$path] = hash_file('sha256', $this->root.'/'.$path);
        }
    }

    protected function tearDown(): void
    {
        try { $this->remove($this->root); } finally { parent::tearDown(); }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
        $directory = opendir($path);
        try { while (($entry = readdir($directory)) !== false) { if ($entry !== '.' && $entry !== '..') { $this->remove($path.'/'.$entry); } } }
        finally { closedir($directory); }
        rmdir($path);
    }

    private function processor(?string $base = null, ?Filesystem $filesystem = null): Mp3Processor
    {
        return new Mp3Processor($this->createMock(StorageInterface::class), $filesystem ?? new Filesystem(),
            $this->createMock(CategorieTagImageRepository::class), $base ?? $this->root.'/library',
            'https://audio.example.test/mp3', dirname(__DIR__, 2));
    }

    private function assertRefused(string $relative, ?string $base = null): void
    {
        $emission = (new Emission())->setTitre('unchanged')->setThumbnailMp3($relative)->setUrl('https://audio.example.test/original');
        $failure = null;
        try { $this->processor($base)->delete($emission); } catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure, 'Unsafe path must be rejected before removal or entity mutation.');
        self::assertSame($relative, $emission->getThumbnailMp3());
        self::assertSame('https://audio.example.test/original', $emission->getUrl());
        self::assertSame('unchanged', $emission->getTitre());
        foreach ($this->hashes as $path => $hash) {
            self::assertFileExists($this->root.'/'.$path);
            self::assertSame($hash, hash_file('sha256', $this->root.'/'.$path));
        }
    }

    /** @dataProvider unsafePaths */
    public function testUnsafeHistoricalPathCannotRemoveFixturesOrClearEntity(string $path): void { $this->assertRefused($path); }

    public static function unsafePaths(): array
    {
        return [
            'Unix absolute' => ['/inside.mp3'], 'Unix double slash' => ['//outside/sentinel.mp3'],
            'Windows absolute slash' => ['C:/outside/sentinel.mp3'], 'Windows absolute backslash' => ['C:\\outside\\sentinel.mp3'],
            'Windows drive relative' => ['C:inside.mp3'], 'Windows UNC' => ['\\\\server\\share\\sentinel.mp3'],
            'Windows rooted' => ['\\inside.mp3'], 'parent traversal' => ['../outside/sentinel.mp3'],
            'nested traversal' => ['sub/../../outside/sentinel.mp3'], 'internal parent alias' => ['sub/../inside.mp3'],
            'current directory alias' => ['./inside.mp3'], 'nested current directory' => ['sub/./inside.mp3'],
            'repeated separator' => ['sub//inside.mp3'], 'trailing file separator' => ['inside.mp3/'],
            'trailing directory separator' => ['sub/'], 'NUL byte' => ["inside.mp3\0"],
            'stream wrapper' => ['file://inside.mp3'],
        ];
    }

    /** @dataProvider symbolicCases */
    public function testSymbolicFileParentOrRootIsNeverFollowed(string $case): void
    {
        if (PHP_OS_FAMILY === 'Windows') { self::markTestSkipped('POSIX symbolic-link cases are validated under PHP 8.3/Linux.'); }
        $base = $this->root.'/library'; $relative = 'inside.mp3';
        switch ($case) {
            case 'file':
                symlink($this->root.'/outside/sentinel.mp3', $base.'/linked.mp3'); $relative = 'linked.mp3'; break;
            case 'dangling file':
                symlink($this->root.'/outside/missing.mp3', $base.'/linked.mp3'); $relative = 'linked.mp3'; break;
            case 'parent':
                symlink($this->root.'/outside', $base.'/linked'); $relative = 'linked/sentinel.mp3'; break;
            case 'root': case 'root trailing slash':
                symlink($base, $this->root.'/linked-root'); $base = $this->root.'/linked-root'.($case === 'root trailing slash' ? '/' : ''); break;
            case 'root ancestor':
                symlink($this->root, $this->root.'/linked-ancestor'); $base = $this->root.'/linked-ancestor/library'; break;
        }
        $this->assertRefused($relative, $base);
        if (in_array($case, ['file', 'dangling file'], true)) { self::assertTrue(is_link($this->root.'/library/linked.mp3')); }
    }
    public static function symbolicCases(): array { return array_map(static fn ($v) => [$v], ['file', 'dangling file', 'parent', 'root', 'root trailing slash', 'root ancestor']); }

    /** @dataProvider historicalNames */
    public function testSafeHistoricalNamesAreRemovedExactlyWithoutRenaming(string $path): void
    {
        $full = $this->root.'/library/'.$path;
        if (!is_dir(dirname($full))) { mkdir(dirname($full), 0700, true); }
        file_put_contents($full, 'small historical fixture');
        $emission = (new Emission())->setThumbnailMp3($path)->setUrl('https://audio.example.test/original');
        $this->processor()->delete($emission);
        self::assertFileDoesNotExist($full); self::assertNull($emission->getThumbnailMp3()); self::assertNull($emission->getUrl());
        self::assertSame($this->hashes['outside/sentinel.mp3'], hash_file('sha256', $this->root.'/outside/sentinel.mp3'));
    }
    public static function historicalNames(): array { return [['Ancien Fichier.MP3'], ['Émissions Été/ÉMISSION 1998.Mp3'], ['ARCHIVES Web/ABC_1999 - Long Nom.mp3']]; }

    public function testMissingValidFileKeepsExistingReferenceClearingBehavior(): void
    {
        $emission = (new Emission())->setThumbnailMp3('missing.mp3')->setUrl('https://audio.example.test/missing');
        $this->processor()->delete($emission);
        self::assertNull($emission->getThumbnailMp3()); self::assertNull($emission->getUrl());
    }

    public function testNoPhysicalReferenceLeavesExternalUrlUnchanged(): void
    {
        $emission = (new Emission())->setUrl('https://external.example.test/audio.mp3');
        $this->processor()->delete($emission);
        self::assertNull($emission->getThumbnailMp3()); self::assertSame('https://external.example.test/audio.mp3', $emission->getUrl());
    }

    public function testFilesystemFailureDoesNotClearEntityOrFile(): void
    {
        $failure = new \RuntimeException('controlled removal failure');
        $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['remove'])->getMock();
        $fs->expects(self::once())->method('remove')->willThrowException($failure);
        $emission = (new Emission())->setThumbnailMp3('inside.mp3')->setUrl('https://audio.example.test/original');
        try { $this->processor(null, $fs)->delete($emission); self::fail('Removal error must propagate.'); }
        catch (\RuntimeException $error) { self::assertSame($failure, $error); }
        self::assertSame('inside.mp3', $emission->getThumbnailMp3()); self::assertSame('https://audio.example.test/original', $emission->getUrl());
        self::assertSame($this->hashes['library/inside.mp3'], hash_file('sha256', $this->root.'/library/inside.mp3'));
    }

    public function testSharedPathGuardAlsoProtectsObsoleteRemoval(): void
    {
        $failure = null;
        try { $this->processor()->removeObsolete('sub/./inside.mp3'); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertFileExists($this->root.'/library/sub/inside.mp3');
    }

    public function testStorageRootMustBeAnExistingDirectory(): void
    {
        foreach ([$this->root.'/missing', $this->root.'/outside/sentinel.mp3'] as $base) { $this->assertRefused('inside.mp3', $base); }
    }
}
