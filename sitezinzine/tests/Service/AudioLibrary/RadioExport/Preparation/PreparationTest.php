<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationAdmission;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationWorkspace;
use App\Tests\Support\Mp3TestStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PreparationTest extends TestCase
{
    public function testAdmissionKeepsEveryOccurrenceAndExcludesUnsafeOutputs(): void
    {
        $rows = [
            $this->entry(1, 'safe.mp3'),
            $this->entry(2, 'collision.mp3'),
            $this->entry(3, 'collision.mp3'),
            $this->entry(4, '../escape.mp3'),
            $this->entry(5, str_repeat('a', 252) . '.mp3'),
            $this->entry(6, 'live.mp3', ['mp3Path' => null, 'audioKind' => 'none', 'live' => true]),
            $this->entry(7, 'deleted.mp3', ['deleted' => true]),
            $this->entry(8, 'absent.mp3', ['mp3Path' => null, 'audioKind' => 'none']),
            $this->entry(9, null, ['issues' => ['ambiguous_title_markers']]),
            $this->entry(10, 'external.mp3', ['mp3Path' => null, 'audioKind' => 'external_url']),
            $this->entry(11, 'ambiguous.mp3', ['mp3Path' => null, 'audioKind' => 'uncertain_url']),
            $this->entry(12, 'conflicting.mp3', ['localUrlPath' => 'other.mp3']),
            $this->entry(13, 'url-only.mp3', ['mp3Path' => null, 'localUrlPath' => 'other.mp3']),
        ];
        $result = (new RadioPreparationAdmission())->admit($this->manifest($rows));
        self::assertSame([1], $result['candidateIds']);
        self::assertSame(range(1, 13), array_column($result['entries'], 'diffusionId'));
        self::assertSame(['candidate', 'naming', 'naming', 'naming', 'naming', 'excluded', 'excluded',
            'unavailable', 'naming', 'excluded', 'unavailable', 'unavailable', 'unavailable'], array_column($result['entries'], 'admission'));
        self::assertContains('filename_collision', $result['entries'][1]['issues']);
        self::assertContains('filename_collision', $result['entries'][2]['issues']);
        self::assertContains('unsafe_filename', $result['entries'][3]['issues']);
        self::assertContains('filename_too_long', $result['entries'][4]['issues']);
        self::assertSame('Synthetic', $result['entries'][7]['emissionTitle']);
    }

    public function testRejectsDuplicateOccurrenceIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RadioPreparationAdmission())->admit($this->manifest([$this->entry(1, 'a.mp3'), $this->entry(1, 'b.mp3')]));
    }

    public function testRejectsNonCanonicalWeek(): void
    {
        $manifest = $this->manifest([]);
        $manifest['weekStart'] = $manifest['weekStart']->modify('+1 day');
        $this->expectException(\InvalidArgumentException::class);
        (new RadioPreparationAdmission())->admit($manifest);
    }

    public function testWorkspaceIsPrivateExclusiveAndIsolatedByWeekAndAttempt(): void
    {
        $root = Mp3TestStorage::root() . '/export-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $first = $this->workspace($root, '2026-10-20', 'one');
            $otherAttempt = $this->workspace($root, '2026-10-20', 'two');
            $otherWeek = $this->workspace($root, '2026-10-27', 'one');
            self::assertSame('running', $first->state());
            self::assertSame(0700, fileperms($first->directory()) & 0777);
            self::assertSame($root . '/2026-10-20/one', $first->directory());
            self::assertNotSame($first->directory(), $otherAttempt->directory());
            self::assertNotSame($first->directory(), $otherWeek->directory());

            try {
                $this->workspace($root, '2026-10-20', 'one');
                self::fail('Concurrent claim of a workspace must fail.');
            } catch (\RuntimeException) {
                self::assertDirectoryExists($first->directory());
            }
            $first->fail();
            self::assertSame('failed', $first->state());
            self::assertDirectoryDoesNotExist($first->directory());
            self::assertDirectoryExists($otherAttempt->directory());
            self::assertDirectoryExists($otherWeek->directory());
            self::assertSame($otherWeek->finishReportOnly(), $otherWeek->report());
            self::assertSame('copies_ready', $otherWeek->state());
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    public function testWorkspaceRejectsProtectedRootsTraversalAndSymlinks(): void
    {
        $root = Mp3TestStorage::root() . '/export-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            foreach ([
                [Mp3TestStorage::library(), 'attempt'],
                [$root, '../escape'],
            ] as [$path, $id]) {
                try {
                    $this->workspace($path, '2026-10-20', $id);
                    self::fail('An unsafe workspace must be rejected.');
                } catch (\InvalidArgumentException|\RuntimeException) {
                    self::assertSame(['.', '..'], scandir($root));
                }
            }

            $link = $root . '/2026-10-20';
            self::assertTrue(symlink(Mp3TestStorage::work(), $link));
            try {
                $this->expectException(\RuntimeException::class);
                $this->workspace($root, '2026-10-20', 'attempt');
            } finally {
                self::assertFileDoesNotExist(Mp3TestStorage::work() . '/attempt');
                unlink($link);
            }
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    public function testIncompleteAttemptCannotBeFinalizedAndFailurePreservesUnknownFiles(): void
    {
        $root = Mp3TestStorage::root() . '/export-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $workspace = $this->workspace($root, '2026-10-20', 'owned', [$this->entry(1, 'source.mp3')]);
            try {
                $workspace->finishReportOnly();
                self::fail('A workspace with candidates cannot finalize without copying.');
            } catch (\LogicException) {
                self::assertSame('running', $workspace->state());
            }
            $foreign = $workspace->directory() . '/unknown';
            file_put_contents($foreign, 'synthetic');
            try {
                $workspace->fail();
                self::fail('Unknown entries must prevent cleanup.');
            } catch (\RuntimeException) {
                self::assertSame('failed', $workspace->state());
                self::assertSame('synthetic', file_get_contents($foreign));
            }
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    private function workspace(string $root, string $week, string $id, array $rows = []): RadioPreparationWorkspace
    {
        return RadioPreparationWorkspace::open($root, [
            'library' => Mp3TestStorage::library(), 'work' => Mp3TestStorage::work(),
            'trash' => Mp3TestStorage::root() . '/trash', 'public' => dirname(__DIR__, 5) . '/public',
        ], $this->manifest($rows, $week), $id);
    }

    private function manifest(array $rows, string $date = '2026-10-20'): array
    {
        $week = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Paris'));

        return ['weekStart' => $week, 'weekEnd' => $week->modify('+7 days'), 'entries' => $rows];
    }

    private function entry(int $id, ?string $filename, array $overrides = []): array
    {
        return $overrides + [
            'diffusionId' => $id, 'emissionId' => 42, 'emissionTitle' => 'Synthetic', 'categoryCode' => 'TST',
            'startsAt' => new \DateTimeImmutable('2026-10-25T02:30:00+01:00'), 'filename' => $filename, 'issues' => [],
            'mp3Path' => 'library/source.mp3', 'localUrlPath' => null, 'audioKind' => 'local_reference', 'live' => false, 'deleted' => false,
        ];
    }
}
