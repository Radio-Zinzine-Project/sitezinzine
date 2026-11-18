<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use PHPUnit\Framework\TestCase;

final class AudioInventoryTemporaryWorkspaceTest extends TestCase
{
    private string $root;
    private array $workspaces = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-workspace-test-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->workspaces as $workspace) {
            try { $workspace->cleanup(); } catch (\Throwable) { /* Deliberate sabotage is removed below. */ }
        }
        $this->removeFixture($this->root);
        parent::tearDown();
    }

    private function removeFixture(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
        chmod($path, 0700);
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') { $this->removeFixture($path.'/'.$entry); }
        }
        rmdir($path);
    }

    private function workspace(int $streams = 2, int $runs = 4): AudioInventoryTemporaryWorkspace
    {
        return $this->workspaces[] = new AudioInventoryTemporaryWorkspace($this->root, $streams, $runs);
    }

    private function directory(): string
    {
        $entries = array_values(array_diff(scandir($this->root), ['.', '..']));
        self::assertCount(1, $entries);
        return $this->root.'/'.$entries[0];
    }

    private function file(): string
    {
        $dir = $this->directory();
        $entries = array_values(array_diff(scandir($dir), ['.', '..']));
        self::assertCount(1, $entries);
        return $dir.'/'.$entries[0];
    }

    private function finalized(AudioInventoryTemporaryWorkspace $workspace): string
    {
        $id = $workspace->createRun();
        fwrite($workspace->openWrite($id), "historique\nÉmission.mp3");
        $workspace->close($id);
        $workspace->finalize($id);
        return $id;
    }

    public function testMissingRootIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryTemporaryWorkspace($this->root.'/missing', 1, 1);
    }

    public function testFileRootIsRejected(): void
    {
        file_put_contents($this->root.'/file', 'outside');
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryTemporaryWorkspace($this->root.'/file', 1, 1);
    }

    public function testSymbolicRootIsRejected(): void
    {
        if (!@symlink($this->root, $this->root.'-link')) { self::markTestSkipped('Symlinks unavailable.'); }
        try {
            $this->expectException(\InvalidArgumentException::class);
            new AudioInventoryTemporaryWorkspace($this->root.'-link', 1, 1);
        } finally { unlink($this->root.'-link'); }
    }

    public function testInaccessibleRootIsRejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') { self::markTestSkipped('POSIX permissions required.'); }
        chmod($this->root, 0000);
        clearstatcache();
        if (is_readable($this->root) && is_writable($this->root)) { self::markTestSkipped('Privileged account bypasses permissions.'); }
        $this->expectException(\InvalidArgumentException::class);
        $this->workspace();
    }

    /** @dataProvider invalidLimits */
    public function testInvalidLimits(int $streams, int $runs): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->workspace($streams, $runs);
    }

    public static function invalidLimits(): array { return [[0, 1], [1, 0], [-1, 2]]; }

    public function testOperationsAreIsolatedAndCleanupIsTargeted(): void
    {
        $a = $this->workspace(); $b = $this->workspace();
        $id = $this->finalized($a); $other = $this->finalized($b);
        self::assertNotSame($id, $other);
        $a->cleanup();
        self::assertSame("historique\nÉmission.mp3", stream_get_contents($b->openRead($other)));
    }

    public function testPrivatePermissions(): void
    {
        $workspace = $this->workspace(); $workspace->createRun();
        if (PHP_OS_FAMILY === 'Windows') { self::markTestSkipped('Windows ACLs require operational verification.'); }
        self::assertSame(0700, fileperms($this->directory()) & 0777);
        self::assertSame(0600, fileperms($this->file()) & 0777);
    }

    public function testCreateRunDoesNotOpenAStream(): void
    {
        $workspace = $this->workspace(1);
        $a = $workspace->createRun(); $b = $workspace->createRun();
        self::assertNotSame($a, $b);
        self::assertTrue(is_resource($workspace->openWrite($b)));
    }

    public function testWritingStartsEmptyAndCloseOwnsResource(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun();
        $stream = $workspace->openWrite($id);
        self::assertSame(0, ftell($stream)); self::assertSame(0, fstat($stream)['size']);
        fwrite($stream, 'abc'); $workspace->close($id);
        self::assertFalse(is_resource($stream));
    }

    public function testIncompleteRunCannotBeRead(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun();
        $this->expectException(\LogicException::class); $workspace->openRead($id);
    }

    public function testOpenWriterCannotBeFinalized(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun(); $workspace->openWrite($id);
        $this->expectException(\LogicException::class); $workspace->finalize($id);
    }

    public function testUnwrittenRunCannotBeFinalized(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun();
        $this->expectException(\LogicException::class); $workspace->finalize($id);
    }

    public function testFinalizationAndReopeningFromBeginning(): void
    {
        $workspace = $this->workspace(); $id = $this->finalized($workspace);
        $stream = $workspace->openRead($id); self::assertSame(0, ftell($stream)); fread($stream, 4);
        $workspace->close($id);
        self::assertSame("historique\nÉmission.mp3", stream_get_contents($workspace->openRead($id)));
    }

    public function testSimultaneousReadsOfSameRunAreRejected(): void
    {
        $workspace = $this->workspace(); $id = $this->finalized($workspace); $workspace->openRead($id);
        $this->expectException(\LogicException::class); $workspace->openRead($id);
    }

    public function testFinalizedRunCannotBeRewritten(): void
    {
        $workspace = $this->workspace(); $id = $this->finalized($workspace);
        $this->expectException(\LogicException::class); $workspace->openWrite($id);
    }

    public function testStreamLimitCanBeReleased(): void
    {
        $workspace = $this->workspace(1); $a = $workspace->createRun(); $b = $workspace->createRun();
        $stream = $workspace->openWrite($a);
        try { $workspace->openWrite($b); self::fail('Limit ignored.'); } catch (\LengthException) { self::assertTrue(is_resource($stream)); }
        $workspace->close($a); self::assertTrue(is_resource($workspace->openWrite($b)));
    }

    public function testRunBudgetIsTotalPerOperation(): void
    {
        $workspace = $this->workspace(1, 1); $id = $workspace->createRun(); $workspace->discard($id);
        $this->expectException(\LengthException::class); $workspace->createRun();
    }

    /** @dataProvider unknownIds */
    public function testUnknownIdIsRejected(string $method): void
    {
        $workspace = $this->workspace();
        $this->expectException(\InvalidArgumentException::class); $workspace->$method('../outside');
    }

    public static function unknownIds(): array { return array_map(static fn ($m) => [$m], ['openWrite', 'openRead', 'close', 'finalize', 'discard']); }

    public function testForeignIdIsRejected(): void
    {
        $a = $this->workspace(); $id = $a->createRun(); $b = $this->workspace();
        $this->expectException(\InvalidArgumentException::class); $b->openWrite($id);
    }

    public function testSubstitutedSymlinkNeverDeletesExternalTarget(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun(); $file = $this->file();
        $external = $this->root.'/external'; file_put_contents($external, 'untouched'); unlink($file);
        if (!@symlink($external, $file)) { self::markTestSkipped('Symlinks unavailable.'); }
        try { $workspace->openWrite($id); self::fail('Symlink accepted.'); } catch (\RuntimeException) {}
        try { $workspace->cleanup(); self::fail('Residue concealed.'); } catch (\RuntimeException) {}
        self::assertSame('untouched', file_get_contents($external)); self::assertTrue(is_link($file));
    }

    public function testReplacedFileIsRejected(): void
    {
        $workspace = $this->workspace(); $id = $this->finalized($workspace); $file = $this->file();
        rename($file, $file.'.original'); file_put_contents($file, 'replacement');
        $this->expectException(\RuntimeException::class); $workspace->openRead($id);
    }

    public function testAlteredFinalizedFileIsRejected(): void
    {
        $workspace = $this->workspace(); $id = $this->finalized($workspace); file_put_contents($this->file(), 'longer altered payload than original');
        $this->expectException(\RuntimeException::class); $workspace->openRead($id);
    }

    public function testDiscardClosesAndDeletesOnlyOwnedBlock(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun(); $file = $this->file();
        $stream = $workspace->openWrite($id); $workspace->discard($id);
        self::assertFalse(is_resource($stream)); self::assertFileDoesNotExist($file);
        $this->expectException(\LogicException::class); $workspace->openRead($id);
    }

    public function testCleanupClosesResourcesAndIsRepeatable(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun(); $stream = $workspace->openWrite($id);
        $workspace->cleanup(); $workspace->cleanup();
        self::assertFalse(is_resource($stream)); self::assertSame(['.', '..'], scandir($this->root));
        $this->expectException(\LogicException::class); $workspace->createRun();
    }

    public function testFinallyCleanupPreservesProducerException(): void
    {
        $workspace = $this->workspace(); $failure = new \RuntimeException('producer');
        try {
            try { $id = $workspace->createRun(); fwrite($workspace->openWrite($id), 'partial'); throw $failure; }
            finally { $workspace->cleanup(); }
        } catch (\RuntimeException $caught) { self::assertSame($failure, $caught); }
        self::assertSame(['.', '..'], scandir($this->root));
    }

    public function testUnknownDirectoryPreventsRecursiveCleanup(): void
    {
        $workspace = $this->workspace(); $dir = $this->directory(); mkdir($dir.'/foreign'); file_put_contents($dir.'/foreign/keep', 'keep');
        try { $workspace->cleanup(); self::fail('Residue concealed.'); } catch (\RuntimeException) {}
        self::assertSame('keep', file_get_contents($dir.'/foreign/keep'));
    }

    public function testExternallyClosedWriterCannotBeCertified(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun(); fclose($workspace->openWrite($id));
        try { $workspace->close($id); self::fail('External closure accepted.'); } catch (\RuntimeException) {}
        $this->expectException(\LogicException::class); $workspace->finalize($id);
    }

    private function activeRuns(AudioInventoryTemporaryWorkspace $workspace): array
    {
        // Read-only structural measurement, independent of PHP's global peak allocator.
        return (new \ReflectionProperty($workspace, 'runs'))->getValue($workspace);
    }

    public function testSuccessfulDiscardRemovesMetadataAfterClosingItsStream(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun();
        $path = $this->activeRuns($workspace)[$id]['path'];
        $stream = $workspace->openWrite($id);
        $workspace->discard($id);
        self::assertFalse(is_resource($stream)); self::assertFileDoesNotExist($path);
        self::assertSame([], $this->activeRuns($workspace));
    }

    /** @dataProvider unknownIds */
    public function testRemovedIdIsRejectedByEveryOperation(string $method): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun(); $workspace->discard($id);
        $this->expectException(\InvalidArgumentException::class); $workspace->$method($id);
    }

    public function testDiscardedIdentifierCannotBeDiscardedAgain(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun(); $workspace->discard($id);
        $this->expectException(\InvalidArgumentException::class); $workspace->discard($id);
    }

    public function testRepeatedCyclesKeepRegistryEmptyWithoutResettingCreationBudget(): void
    {
        $workspace = $this->workspace(1, 512);
        $previous = null;
        for ($i = 0; $i < 512; ++$i) {
            $id = $workspace->createRun();
            self::assertNotSame($previous, $id);
            self::assertCount(1, $this->activeRuns($workspace));
            $workspace->discard($id);
            self::assertSame([], $this->activeRuns($workspace));
            self::assertSame(serialize([]), serialize($this->activeRuns($workspace)));
            $previous = $id;
        }
        $this->expectException(\LengthException::class); $workspace->createRun();
    }

    public function testFailedRemovalKeepsIdentityAndAllowsLaterCleanup(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun();
        $before = $this->activeRuns($workspace)[$id]; $path = $before['path'];
        $backup = $this->root.'/saved-owned-run';
        rename($path, $backup); mkdir($path, 0700);
        try {
            try { $workspace->cleanup(); self::fail('Substituted path must not be removed.'); }
            catch (\RuntimeException) {
                self::assertSame($before, $this->activeRuns($workspace)[$id]);
                self::assertDirectoryExists($path); self::assertFileExists($backup);
            }
        } finally { rmdir($path); rename($backup, $path); }
        $workspace->cleanup(); $workspace->cleanup();
        self::assertSame([], $this->activeRuns($workspace)); self::assertFileDoesNotExist($path);
    }

    public function testPhysicalUnlinkFailureRetainsMetadataForRetry(): void
    {
        $workspace = $this->workspace(); $id = $workspace->createRun();
        $before = $this->activeRuns($workspace)[$id]; $path = $before['path']; $dir = dirname($path);
        $probe = $dir.'/permission-probe'; file_put_contents($probe, 'probe');
        try {
            if (PHP_OS_FAMILY === 'Windows') { chmod($probe, 0444); chmod($path, 0444); }
            else { chmod($dir, 0500); }
            if (@unlink($probe)) { self::markTestSkipped('Account/filesystem does not permit deterministic unlink denial.'); }
            try { $workspace->discard($id); self::fail('Unlink denial concealed.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('Cannot remove owned run', $error->getMessage()); }
            self::assertSame($before, $this->activeRuns($workspace)[$id]);
            self::assertFileExists($path);
        } finally {
            chmod($dir, 0700); chmod($path, 0600);
            if (file_exists($probe)) { chmod($probe, 0600); unlink($probe); }
        }
        $workspace->discard($id); self::assertSame([], $this->activeRuns($workspace));
    }

    public function testCleanupRemovesAllActiveMetadataAndPreservesOtherWorkspace(): void
    {
        $a = $this->workspace(); $b = $this->workspace();
        $old = $a->createRun(); $a->discard($old);
        $a->openWrite($a->createRun()); $a->createRun();
        $other = $this->finalized($b);
        $a->cleanup(); $a->cleanup();
        self::assertSame([], $this->activeRuns($a)); self::assertCount(1, $this->activeRuns($b));
        self::assertSame("historique\nÉmission.mp3", stream_get_contents($b->openRead($other)));
    }

    public function testIntegratedMergeStillWorksAfterPreviousRunsWereRemoved(): void
    {
        $workspace = $this->workspace(3, 5);
        $old = $workspace->createRun(); $workspace->discard($old);
        $codec = new \App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec(1024);
        $inputs = [];
        foreach ([['a', 'c'], ['b', 'd']] as $paths) {
            $id = $workspace->createRun();
            $codec->write($workspace->openWrite($id), $paths); $workspace->close($id); $workspace->finalize($id);
            $inputs[] = $id;
        }
        $merger = new \App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunMerger(
            $workspace, new \App\Service\AudioLibrary\Inventory\AudioInventoryPathRunMerger($codec, 2), 2,
        );
        $output = $merger->merge($inputs);
        foreach ($inputs as $id) { $workspace->discard($id); }
        self::assertCount(1, $this->activeRuns($workspace));
        self::assertSame(['a', 'b', 'c', 'd'], iterator_to_array($codec->read($workspace->openRead($output)), false));
        $workspace->close($output); $workspace->discard($output);
        self::assertSame([], $this->activeRuns($workspace));
        $last = $workspace->createRun(); $workspace->discard($last);
        $this->expectException(\LengthException::class); $workspace->createRun();
    }
}
