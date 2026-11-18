<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryScanner;
use PHPUnit\Framework\TestCase;

final class AudioInventoryScannerTest extends TestCase
{
    private string $temporary;
    private string $root;

    protected function setUp(): void
    {
        $this->temporary = realpath(sys_get_temp_dir()) . '/zinzine-scanner-' . bin2hex(random_bytes(12));
        mkdir($this->temporary, 0700);
        $this->root = $this->temporary . '/library';
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        // Only this test's random directory; unlink links without following them.
        $this->removeOwnedDirectory($this->temporary);
        parent::tearDown();
    }

    public function testNestedHistoricalNamesArePreservedAndContentsAreNotValidated(): void
    {
        $names = ['historique/Émission du 1er mai (copie).mp3', 'ABC/2026/Archive.MP3', 'racine.mp3'];
        foreach ($names as $name) {
            $this->file($name, 'This is deliberately not valid audio.');
        }
        $this->file('ignore.txt');
        $this->file('ignore.mp3.bak');
        mkdir($this->root . '/directory.mp3');
        $observations = iterator_to_array((new AudioInventoryScanner($this->root))->scan($this->root), false);
        $paths = array_column($observations, 'relative_path');
        sort($paths);
        sort($names);
        self::assertSame($names, $paths);
        self::assertSame(['file', 'file', 'file'], array_column($observations, 'type'));
        foreach ($names as $name) {
            self::assertSame('This is deliberately not valid audio.', file_get_contents($this->root . '/' . $name));
        }
    }

    public function testEmptyLibraryIsAnEmptyIteration(): void
    {
        self::assertSame([], iterator_to_array((new AudioInventoryScanner($this->root))->scan($this->root), false));
    }

    public function testIterationIsProgressiveAndDoesNotCreateOrModifyFiles(): void
    {
        $this->file('first.mp3', 'original');
        $scanner = new AudioInventoryScanner($this->root);
        $stream = $scanner->scan($this->root);
        self::assertInstanceOf(\Generator::class, $stream);
        // No directory traversal has been eagerly cached by scan().
        $this->file('later.mp3', 'later');
        $paths = array_column(iterator_to_array($stream, false), 'relative_path');
        sort($paths);
        self::assertSame(['first.mp3', 'later.mp3'], $paths);
        self::assertSame('original', file_get_contents($this->root . '/first.mp3'));
        self::assertSame(['.', '..', 'first.mp3', 'later.mp3'], scandir($this->root));
    }

    public function testPrivatePreparationDirectoriesAreExcludedByPathNotByFilename(): void
    {
        $this->file('private/mp3-123/ready.mp3');
        $this->file('preparation/work/input.mp3');
        $this->file('archives/private/historique.mp3');
        $this->file('ready.mp3');
        $stream = (new AudioInventoryScanner($this->root, ['private', 'preparation/work']))->scan($this->root);
        $paths = array_column(iterator_to_array($stream, false), 'relative_path');
        sort($paths);
        self::assertSame(['archives/private/historique.mp3', 'ready.mp3'], $paths);
        self::assertFileExists($this->root . '/private/mp3-123/ready.mp3');
    }

    public function testDefaultExclusionCoversProcessorPrivateDirectory(): void
    {
        $this->file('private/ready.mp3');
        self::assertSame([], iterator_to_array((new AudioInventoryScanner($this->root))->scan($this->root), false));
    }

    public function testSymbolicLinksIncludingCyclesAndBrokenLinksAreNeverFollowed(): void
    {
        $this->file('real.mp3');
        file_put_contents($this->temporary . '/outside.mp3', 'outside');
        $links = [
            'external.mp3' => $this->temporary . '/outside.mp3',
            'alias.mp3' => $this->root . '/real.mp3',
            'cycle' => $this->root,
            'broken.mp3' => $this->temporary . '/missing',
        ];
        foreach ($links as $name => $target) {
            if (!@symlink($target, $this->root . '/' . $name)) {
                self::markTestSkipped('Symbolic links unavailable on this platform.');
            }
        }
        $observations = iterator_to_array((new AudioInventoryScanner($this->root))->scan($this->root), false);
        $files = array_values(array_filter($observations, static fn (array $row): bool => $row['type'] === 'file'));
        self::assertSame([['type' => 'file', 'relative_path' => 'real.mp3']], $files);
        $errors = array_values(array_filter($observations, static fn (array $row): bool => $row['type'] === 'error'));
        self::assertCount(4, $errors);
        self::assertSame(['symlink_skipped'], array_values(array_unique(array_column($errors, 'reason'))));
        self::assertSame('outside', file_get_contents($this->temporary . '/outside.mp3'));
        self::assertTrue(is_link($this->root . '/cycle'));
    }

    public function testNonexistentAuthorizedRootIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryScanner($this->root . '/missing');
    }

    public function testFileCannotBeAuthorizedAsRoot(): void
    {
        $this->file('file.mp3');
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryScanner($this->root . '/file.mp3');
    }

    public function testAnotherRootIncludingSiblingWithSamePrefixIsRejected(): void
    {
        mkdir($this->root . '-other');
        $scanner = new AudioInventoryScanner($this->root);
        $this->expectException(\InvalidArgumentException::class);
        $scanner->scan($this->root . '-other');
    }

    public function testSymbolicRootIsRejected(): void
    {
        if (!@symlink($this->root, $this->temporary . '/alias')) {
            self::markTestSkipped('Symbolic links unavailable on this platform.');
        }
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryScanner($this->temporary . '/alias');
    }

    public function testRootReachedThroughASymbolicParentIsRejected(): void
    {
        mkdir($this->root . '/nested');
        if (!@symlink($this->root, $this->temporary . '/alias')) {
            self::markTestSkipped('Symbolic links unavailable on this platform.');
        }
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryScanner($this->temporary . '/alias/nested');
    }

    public function testDisappearedRootProducesAnErrorInsteadOfCertainAbsence(): void
    {
        $stream = (new AudioInventoryScanner($this->root))->scan($this->root);
        rmdir($this->root);
        self::assertSame([['type' => 'error', 'relative_path' => '', 'reason' => 'unavailable']], iterator_to_array($stream, false));
    }

    public function testRootReplacedByASymlinkBeforeIterationCannotEscape(): void
    {
        file_put_contents($this->temporary . '/outside.mp3', 'outside');
        $stream = (new AudioInventoryScanner($this->root))->scan($this->root);
        rmdir($this->root);
        if (!@symlink($this->temporary, $this->root)) {
            self::markTestSkipped('Symbolic links unavailable on this platform.');
        }
        self::assertSame([['type' => 'error', 'relative_path' => '', 'reason' => 'symlink_skipped']], iterator_to_array($stream, false));
    }

    public function testDisappearanceDuringTraversalIsReported(): void
    {
        $this->file('only.mp3');
        $stream = (new AudioInventoryScanner($this->root))->scan($this->root);
        self::assertSame(['type' => 'file', 'relative_path' => 'only.mp3'], $stream->current());
        unlink($this->root . '/only.mp3');
        $stream->next();
        self::assertTrue($stream->valid());
        self::assertSame(['type' => 'error', 'relative_path' => 'only.mp3', 'reason' => 'unavailable'], $stream->current());
        $stream->next();
        self::assertFalse($stream->valid());
    }

    /** @dataProvider unsafeExclusions */
    public function testExclusionsMustBeRelativeAndContained(string $exclusion): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryScanner($this->root, [$exclusion]);
    }

    public static function unsafeExclusions(): array
    {
        return [['../outside'], ['/absolute'], ['private/../outside'], [''], ['private\\work']];
    }

    private function file(string $relative, string $contents = 'synthetic'): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeOwnedDirectory(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (is_link($path) || file_exists($path)) {
                unlink($path);
            }
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $this->removeOwnedDirectory($entry->getPathname());
        }
        rmdir($path);
    }
}
