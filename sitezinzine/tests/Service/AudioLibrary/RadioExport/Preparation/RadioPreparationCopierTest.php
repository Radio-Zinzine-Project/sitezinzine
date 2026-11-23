<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationCopier;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationPublisher;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationWorkspace;
use App\Service\Mp3StorageLock;
use App\Service\Mp3TrashPath;
use App\Tests\Support\Mp3TestStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RadioPreparationCopierTest extends TestCase
{
    private string $root;
    private string $source;
    private Mp3StorageLock $lock;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(8));
        $this->root = Mp3TestStorage::root() . '/copy-' . $id;
        mkdir($this->root, 0700);
        $this->source = Mp3TestStorage::library() . '/' . $id;
        mkdir($this->source, 0700);
        $this->lock = new Mp3StorageLock(new Mp3TrashPath(Mp3TestStorage::root() . '/trash',
            Mp3TestStorage::library(), Mp3TestStorage::work(), dirname(__DIR__, 5) . '/public'), 100);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->root, $this->source]);
    }

    public function testCopiesSharedSourceOnceAndKeepsIndependentOutputs(): void
    {
        $content = str_repeat('original', 9000);
        $source = $this->source . '/source.mp3';
        file_put_contents($source, $content);
        $before = lstat($source);
        $workspace = $this->workspace(['source.mp3', 'missing.mp3', 'source.mp3']);
        $copier = new class($this->lock) extends RadioPreparationCopier {
            public int $opens = 0;

            public function __construct(private Mp3StorageLock $observedLock)
            {
                parent::__construct($observedLock);
            }

            protected function write(mixed $output, string $block): int|false
            {
                $scratch = str_ends_with(stream_get_meta_data($output)['uri'], '.part');
                TestCase::assertSame($scratch, $this->observedLock->isHeldByCurrentProcess());

                return parent::write($output, $block);
            }

            protected function openSource(string $path): mixed
            {
                ++$this->opens;
                return parent::openSource($path);
            }
        };
        $result = $copier->prepare($workspace);
        self::assertSame(1, $copier->opens);
        self::assertSame([1, 3], array_column($result['artifacts'], 'diffusionId'));
        self::assertSame([2 => 'source_absent'], $result['sourceIssues']);
        foreach ($result['artifacts'] as $artifact) {
            $output = $workspace->directory() . '/' . $artifact['filename'];
            self::assertSame(strlen($content), $artifact['size']);
            self::assertSame(hash('sha256', $content), $artifact['sha256']);
            self::assertSame($content, file_get_contents($output));
            self::assertSame(0600, fileperms($output) & 0777);
            self::assertNotSame($before['ino'], fileinode($output));
        }
        self::assertNotSame(fileinode($workspace->directory() . '/file-1.mp3'), fileinode($workspace->directory() . '/file-3.mp3'));
        clearstatcache(true, $source);
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            self::assertSame($before[$field], lstat($source)[$field]);
        }
        self::assertSame($content, file_get_contents($source));
        self::assertSame('copies_ready', $workspace->state());
        self::assertFalse($this->lock->isHeldByCurrentProcess());
    }

    public function testNoUsableSourcesProducesReportWithoutOutput(): void
    {
        mkdir($this->source . '/directory.mp3');
        symlink($this->source . '/directory.mp3', $this->source . '/link.mp3');
        $workspace = $this->workspace(['directory.mp3', 'link.mp3', 'missing.mp3']);
        $result = (new RadioPreparationCopier($this->lock))->prepare($workspace);
        self::assertSame([1 => 'source_not_regular', 2 => 'source_unsafe', 3 => 'source_absent'], $result['sourceIssues']);
        self::assertSame([], $result['artifacts']);
        self::assertSame(['.', '..'], scandir($workspace->directory()));
        self::assertSame('copies_ready', $workspace->state());
    }

    /** @dataProvider sourceChanges */
    public function testRejectsSourceMutationDuringCapture(bool $sameContent): void
    {
        $path = $this->source . '/source.mp3';
        file_put_contents($path, 'original');
        $workspace = $this->workspace(['source.mp3', 'source.mp3']);
        $copier = new class($this->lock, $path, $sameContent) extends RadioPreparationCopier {
            public function __construct(Mp3StorageLock $lock, private string $path, private bool $sameContent)
            {
                parent::__construct($lock);
            }

            protected function write(mixed $output, string $block): int|false
            {
                if ($this->sameContent) {
                    rename($this->path, $this->path . '.old');
                    file_put_contents($this->path, $block);
                } else {
                    file_put_contents($this->path, 'changed content');
                }

                return parent::write($output, $block);
            }
        };

        $result = $copier->prepare($workspace);
        self::assertSame([1 => 'source_changed', 2 => 'source_changed'], $result['sourceIssues']);
        self::assertSame([], $result['artifacts']);
        self::assertSame(['.', '..'], scandir($workspace->directory()));
        self::assertFalse($this->lock->isHeldByCurrentProcess());
    }

    public static function sourceChanges(): iterable
    {
        yield 'changed bytes at same path' => [false];
        yield 'same bytes at a different inode' => [true];
    }

    public function testUnreadableSourceIsReported(): void
    {
        file_put_contents($this->source . '/source.mp3', 'synthetic');
        $workspace = $this->workspace(['source.mp3']);
        $result = (new class($this->lock) extends RadioPreparationCopier {
            protected function openSource(string $path): mixed { return false; }
        })->prepare($workspace);

        self::assertSame([1 => 'source_unreadable'], $result['sourceIssues']);
        self::assertSame([], $result['artifacts']);
    }

    public function testPartialWriteFailsAndRemovesOwnedFragment(): void
    {
        file_put_contents($this->source . '/source.mp3', 'synthetic');
        $workspace = $this->workspace(['source.mp3']);
        $copier = new class($this->lock) extends RadioPreparationCopier {
            protected function write(mixed $output, string $block): int|false
            {
                return parent::write($output, substr($block, 0, 1));
            }
        };

        try {
            $copier->prepare($workspace);
            self::fail('An incomplete write must abort the preparation.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Incomplete write', $error->getMessage());
        }
        self::assertSame('failed', $workspace->state());
        self::assertSame(['.', '..', 'failure.json'], scandir($workspace->directory()));
        self::assertFalse($this->lock->isHeldByCurrentProcess());
    }

    /** @dataProvider blockingChanges */
    public function testPreservesUnknownDestinationAndSubstitutedWorkspace(bool $replaceWorkspace): void
    {
        file_put_contents($this->source . '/source.mp3', 'synthetic');
        $workspace = $this->workspace(['source.mp3']);
        $directory = $workspace->directory();
        if ($replaceWorkspace) {
            rename($directory, $directory . '-saved');
            mkdir($directory, 0700);
        }
        $foreign = $directory . '/file-1.mp3';
        file_put_contents($foreign, 'foreign');
        try {
            (new RadioPreparationCopier($this->lock))->prepare($workspace);
            self::fail('A destination conflict or substituted workspace must abort.');
        } catch (\RuntimeException) {
            self::assertSame('failed', $workspace->state());
        }
        self::assertSame('foreign', file_get_contents($foreign));
        self::assertSame($replaceWorkspace ? ['.', '..', 'file-1.mp3'] : ['.', '..', 'failure.json', 'file-1.mp3'], scandir($directory));
        self::assertFalse($this->lock->isHeldByCurrentProcess());
    }

    public static function blockingChanges(): iterable
    {
        yield 'occupied destination' => [false];
        yield 'substituted workspace' => [true];
    }

    public function testOutputFingerprintChangesWhenSourceBytesChangeAtSamePath(): void
    {
        $source = $this->source . '/source.mp3';
        $copier = new RadioPreparationCopier($this->lock);
        file_put_contents($source, 'same-size-a');
        $first = $copier->prepare($this->workspace(['source.mp3'], 'first'));
        file_put_contents($source, 'same-size-b');
        $second = $copier->prepare($this->workspace(['source.mp3'], 'second'));

        self::assertSame($first['artifacts'][0]['size'], $second['artifacts'][0]['size']);
        self::assertNotSame($first['artifacts'][0]['sha256'], $second['artifacts'][0]['sha256']);
    }

    public function testCleanupLeavesAReplacementOfAnOwnedFragmentUntouched(): void
    {
        $workspace = $this->workspace([]);
        $stream = $workspace->createFile('.source-1.part');
        fwrite($stream, 'owned');
        fclose($stream);
        $fragment = $workspace->directory() . '/.source-1.part';
        rename($fragment, $fragment . '-old');
        file_put_contents($fragment, 'replacement');
        chmod($fragment, 0600);
        try {
            $workspace->fail();
            self::fail('A replaced fragment cannot be deleted by the workspace.');
        } catch (\RuntimeException) {
            self::assertSame('failed', $workspace->state());
        }
        self::assertSame('replacement', file_get_contents($fragment));
        self::assertSame('owned', file_get_contents($fragment . '-old'));
    }

    public function testPublishesPartialZipAndReloadsItWithoutTheWorkspace(): void
    {
        file_put_contents($this->source . '/source.mp3', 'synthetic');
        $workspace = $this->workspace(['source.mp3', 'missing.mp3', 'source.mp3']);
        (new RadioPreparationCopier($this->lock))->prepare($workspace);
        self::assertSame('copies_ready', $workspace->state());
        $publisher = new RadioPreparationPublisher();
        $week = $workspace->report()['weekStart'];
        self::assertNull($publisher->read($this->root, $week, 'attempt'));
        $report = $publisher->publish($workspace);
        self::assertSame('ready', $workspace->state());
        self::assertSame('ready', $report['status']);
        self::assertSame($report, (new RadioPreparationPublisher())->read($this->root, $week, 'attempt'));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($workspace->directory() . '/audio.zip', \ZipArchive::RDONLY));
        try {
            self::assertSame(2, $zip->numFiles);
            self::assertSame('file-1.mp3', $zip->getNameIndex(0));
            self::assertSame('file-3.mp3', $zip->getNameIndex(1));
            self::assertSame('synthetic', $zip->getFromIndex(1));
        } finally {
            $zip->close();
        }
    }

    public function testPublishesReportOnlyForUnavailableSources(): void
    {
        $workspace = $this->workspace(['missing.mp3']);
        (new RadioPreparationCopier($this->lock))->prepare($workspace);
        $report = (new RadioPreparationPublisher())->publish($workspace);
        self::assertSame('report_only', $workspace->state());
        self::assertSame('report_only', $report['status']);
        self::assertNull($report['archive']);
        self::assertSame(['.', '..', 'result.json'], scandir($workspace->directory()));
        self::assertSame($report, (new RadioPreparationPublisher())->read($this->root, $workspace->report()['weekStart'], 'attempt'));
    }

    /** @dataProvider publicationFailures */
    public function testPublicationFailureKeepsUnknownEntries(string $failure): void
    {
        file_put_contents($this->source . '/source.mp3', 'synthetic');
        $workspace = $this->workspace(['source.mp3']);
        (new RadioPreparationCopier($this->lock))->prepare($workspace);
        $directory = $workspace->directory();
        $copy = $directory . '/file-1.mp3';
        $publisher = new RadioPreparationPublisher();
        $unknown = null;
        if ($failure === 'altered copy') {
            file_put_contents($copy, 'different');
        } elseif ($failure === 'substituted copy') {
            rename($copy, $copy . '.saved');
            file_put_contents($copy, 'synthetic');
            chmod($copy, 0600);
            $unknown = $copy;
        } elseif ($failure === 'occupied destination') {
            $unknown = $directory . '/audio.zip';
            $publisher = new class extends RadioPreparationPublisher {
                protected function verifyArchive(string $path, array $expected): array
                {
                    $verified = parent::verifyArchive($path, $expected);
                    file_put_contents(dirname($path) . '/audio.zip', 'synthetic');

                    return $verified;
                }
            };
        } elseif ($failure === 'truncated archive') {
            $publisher = new class extends RadioPreparationPublisher {
                protected function verifyArchive(string $path, array $expected): array
                {
                    file_put_contents($path, 'truncated');
                    return parent::verifyArchive($path, $expected);
                }
            };
        } elseif ($failure === 'close error') {
            $publisher = new class extends RadioPreparationPublisher {
                protected function closeArchive(\ZipArchive $archive): bool
                {
                    parent::closeArchive($archive);
                    return false;
                }
            };
        }
        try {
            $publisher->publish($workspace);
            self::fail('A failed archive must not be published.');
        } catch (\RuntimeException) {
            self::assertSame('failed', $workspace->state());
        }
        self::assertFileDoesNotExist($directory . '/result.json');
        self::assertNull((new RadioPreparationPublisher())->read($this->root, $workspace->report()['weekStart'], 'attempt'));
        $diagnostic = json_decode(file_get_contents($directory . '/failure.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(['failed', '2026-11-17', 'attempt'], [$diagnostic['status'], $diagnostic['week'], $diagnostic['attemptId']]);
        self::assertNotEmpty($diagnostic['failedAt']);
        self::assertStringNotContainsString($directory, $diagnostic['message']);
        if ($unknown !== null) {
            self::assertSame('synthetic', file_get_contents($unknown));
        }
    }

    public static function publicationFailures(): iterable
    {
        foreach (['altered copy', 'substituted copy', 'occupied destination', 'truncated archive', 'close error'] as $failure) {
            yield $failure => [$failure];
        }
    }

    public function testReadRequiresValidMarkerAndUnchangedArchiveAcrossAttempts(): void
    {
        file_put_contents($this->source . '/source.mp3', 'synthetic');
        $first = $this->workspace(['source.mp3'], 'first');
        $second = $this->workspace(['source.mp3'], 'second');
        $copier = new RadioPreparationCopier($this->lock);
        $publisher = new RadioPreparationPublisher();
        foreach ([$first, $second] as $workspace) {
            $copier->prepare($workspace);
            $publisher->publish($workspace);
        }

        $reader = new RadioPreparationPublisher();
        $week = $first->report()['weekStart'];
        $marker = $first->directory() . '/result.json';
        $archive = $first->directory() . '/audio.zip';
        rename($marker, $marker . '.saved');
        self::assertFileExists($archive);
        self::assertNull($reader->read($this->root, $week, 'first'));
        rename($marker . '.saved', $marker);
        self::assertNotNull($reader->read($this->root, $week, 'first'));

        $bytes = file_get_contents($marker);
        $invalid = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $invalid['archive']['path'] = '../audio.zip';
        file_put_contents($marker, json_encode($invalid, JSON_THROW_ON_ERROR));
        self::assertNull($reader->read($this->root, $week, 'first'));
        file_put_contents($marker, $bytes);

        rename($archive, $archive . '.saved');
        copy($archive . '.saved', $archive);
        chmod($archive, 0600);
        self::assertNull($reader->read($this->root, $week, 'first'));
        rename($archive . '.saved', $archive);
        file_put_contents($archive, 'corrupt');
        self::assertNull($reader->read($this->root, $week, 'first'));
        self::assertNotNull($reader->read($this->root, $week, 'second'));
    }

    /** @dataProvider gridChanges */
    public function testOrchestratesAnImmutableRevision(bool $validatedAfter, bool $changed, bool $missing): void
    {
        if (!$missing) {
            file_put_contents($this->source . '/source.mp3', 'synthetic');
        }
        $week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
        $row = [
            'validated' => 1, 'manual_revision' => '1', 'diffusion_id' => 1, 'emission_id' => 2,
            'horaire_diffusion' => '2026-11-17 09:00:00', 'emission_title' => 'Original',
            'category_code' => 'MUS', 'thumbnail_mp3' => basename($this->source) . '/source.mp3',
            'url' => null, 'is_live' => 0, 'deleted' => 0,
        ];
        $db = $this->createMock(\Doctrine\DBAL\Connection::class);
        $db->expects(self::never())->method('beginTransaction');
        $db->method('isAutoCommit')->willReturn(true);
        $otherWeekRow = ['validated' => null, 'manual_revision' => null, 'diffusion_id' => null];
        $db->method('fetchAllAssociative')->willReturnCallback(static function ($sql, $args) use (&$row, &$otherWeekRow): array {
            return [$args[0] === '2026-11-17' ? $row : $otherWeekRow];
        });
        $selection = new \App\Service\AudioLibrary\RadioExport\Selection\WeeklyAudioSelection(
            $this->createMock(\App\Repository\DiffusionRepository::class), 'https://example.test/uploads/emissionsMp3', '[]'
        );
        $validation = new \App\Service\GridWeekValidation($db);
        $publisher = new class extends RadioPreparationPublisher {
            public ?\Closure $beforeClose = null;

            protected function closeArchive(\ZipArchive $archive): bool
            {
                ($this->beforeClose)();
                return parent::closeArchive($archive);
            }
        };
        $publisher->beforeClose = static function () use (&$row, $validatedAfter, $changed, $db): void {
            self::assertFalse($db->isTransactionActive());
            $row['validated'] = (int) $validatedAfter;
            if ($changed) {
                $row['emission_title'] = 'Changed';
            }
        };
        $orchestrator = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationOrchestrator(
            $validation, $selection, new \App\Service\AudioLibrary\RadioExport\Manifest\RadioManifestBuilder(
                new \App\Service\AudioLibrary\RadioExport\Manifest\RadioFilename()
            ), new RadioPreparationCopier($this->lock), $publisher,
            new \App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime(new \Symfony\Component\Clock\MockClock($week))
        );
        $roots = ['library' => Mp3TestStorage::library(), 'work' => Mp3TestStorage::work(),
            'trash' => Mp3TestStorage::root() . '/trash', 'public' => dirname(__DIR__, 5) . '/public'];
        try {
            $orchestrator->prepare($this->root, $roots, $week->modify('+7 days'), 'never-validated');
            self::fail('A week without manual validation must be refused.');
        } catch (\DomainException) {
            self::assertDirectoryDoesNotExist($this->root . '/2026-11-24');
        }
        $capturedRevision = $validation->read($week)['revision'];
        $report = $orchestrator->prepare($this->root, $roots, $week, 'current');
        self::assertSame(2, $report['version']);
        self::assertSame($capturedRevision, $report['revision']);
        self::assertSame('Original', $report['entries'][0]['emissionTitle']);
        self::assertSame($missing ? 'report_only' : 'ready', $report['status']);
        self::assertSame(!$changed && $validatedAfter, $orchestrator->read($this->root, $week, 'current')['current']);
        self::assertNull($orchestrator->read($this->root, $week->modify('+7 days'), 'current'));
        $otherWeekRow['validated'] = 1;
        $otherWeekRow['manual_revision'] = '1';
        $other = $orchestrator->prepare($this->root, $roots, $week->modify('+7 days'), 'current');
        self::assertSame('2026-11-24', $other['week']);
        self::assertSame('report_only', $other['status']);
        self::assertTrue($orchestrator->read($this->root, $week->modify('+7 days'), 'current')['current']);
        self::assertNotSame($report['revision'], $other['revision']);


        $old = $this->workspace(['source.mp3'], 'legacy');
        (new RadioPreparationCopier($this->lock))->prepare($old);
        $publisher->publish($old);
        self::assertFalse($orchestrator->read($this->root, $week, 'legacy')['current']);
        $marker = $this->root . '/2026-11-17/current/result.json';
        rename($marker, $marker . '.saved');
        self::assertNull($orchestrator->read($this->root, $week, 'current'));
        rename($marker . '.saved', $marker);
        if (!$missing) {
            file_put_contents(dirname($marker) . '/audio.zip', 'broken');
            self::assertNull($orchestrator->read($this->root, $week, 'current'));
        }

        $row['validated'] = 0;
        $this->expectException(\DomainException::class);
        try {
            $orchestrator->prepare($this->root, $roots, $week, 'refused');
        } finally {
            self::assertDirectoryDoesNotExist($this->root . '/2026-11-17/refused');
        }
    }

    public static function gridChanges(): iterable
    {
        yield 'unchanged' => [true, false, false];
        yield 'automatic mutation' => [true, true, false];
        yield 'manual revocation' => [false, false, false];
        yield 'report only' => [true, false, true];
    }

    public function testDifferentialArchivesReuseDirectOriginsAndRecoverFromCorruption(): void
    {
        $week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
        $db = $this->createMock(\Doctrine\DBAL\Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $row = fn (int $id, string $title, string $time, string $path): array => [
            'validated' => 1, 'manual_revision' => '1', 'diffusion_id' => $id, 'emission_id' => $id,
            'horaire_diffusion' => '2026-11-17 ' . $time, 'emission_title' => $title,
            'category_code' => 'MUS', 'thumbnail_mp3' => basename($this->source) . '/' . $path,
            'url' => null, 'is_live' => 0, 'deleted' => 0,
        ];
        $rows = [$row(1, 'First', '09:00:00', 'first.mp3')];
        $db->method('fetchAllAssociative')->willReturnCallback(static function () use (&$rows): array { return $rows; });
        $copier = new class($this->lock) extends RadioPreparationCopier {
            public int $opens = 0;
            public bool $failWrite = false;

            protected function write(mixed $output, string $block): int|false
            {
                return $this->failWrite ? false : parent::write($output, $block);
            }
            protected function openSource(string $path): mixed
            {
                ++$this->opens;
                return parent::openSource($path);
            }
        };
        $launchClock = new \Symfony\Component\Clock\MockClock('2026-11-23T23:59:59+01:00');
        $lifetime = new \App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime($launchClock);
        $orchestrator = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationOrchestrator(
            new \App\Service\GridWeekValidation($db),
            new \App\Service\AudioLibrary\RadioExport\Selection\WeeklyAudioSelection(
                $this->createMock(\App\Repository\DiffusionRepository::class), 'https://example.test/uploads/emissionsMp3', '[]'
            ),
            new \App\Service\AudioLibrary\RadioExport\Manifest\RadioManifestBuilder(
                new \App\Service\AudioLibrary\RadioExport\Manifest\RadioFilename()
            ),
            $copier, new RadioPreparationPublisher(),
            $lifetime
        );
        $roots = ['library' => Mp3TestStorage::library(), 'work' => Mp3TestStorage::work(),
            'trash' => Mp3TestStorage::root() . '/trash', 'public' => dirname(__DIR__, 5) . '/public'];
        $prepare = fn (string $id, ?string $reference = null): array =>
            $orchestrator->prepare($this->root, $roots, $week, $id, $reference);
        $archiveEntries = function (string $id): array {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($this->root . '/2026-11-17/' . $id . '/audio.zip', \ZipArchive::RDONLY));
            try {
                $entries = [];
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
                }
                return $entries;
            } finally {
                $zip->close();
            }
        };
        file_put_contents($this->source . '/first.mp3', 'synthetic one');
        $a = $prepare('A');
        self::assertSame([1], array_column($a['artifacts'], 'diffusionId'));

        $rows[] = $row(2, 'Second', '10:00:00', 'first.mp3');
        $copier->opens = 0;
        $b = $prepare('B', 'A');
        self::assertSame(1, $copier->opens);
        self::assertSame([2], array_column($b['artifacts'], 'diffusionId'));
        self::assertSame([$a['artifacts'][0]['filename'] => 'synthetic one'], $archiveEntries('A'));
        self::assertSame([$b['artifacts'][0]['filename'] => 'synthetic one'], $archiveEntries('B'));
        $download = (new RadioPreparationPublisher())->openDownload($this->root, $week, 'B', 'zip');
        self::assertIsArray($download);
        try {
            self::assertSame($b['archive']['sha256'], hash('sha256', stream_get_contents($download['stream'])));
        } finally {
            fclose($download['stream']);
        }

        $c = $prepare('C', 'B');
        self::assertSame('report_only', $c['status']);
        self::assertSame(['A', 'B'], array_column(array_column($c['differential']['expected'], 'origin'), 'attemptId'));
        self::assertFileDoesNotExist($this->root . '/2026-11-17/C/audio.zip');
        self::assertSame($c, $orchestrator->read($this->root, $week, 'C')['report']);
        self::assertSame([], $orchestrator->read($this->root, $week, 'C')['unavailableOrigins']);
        $journalDb = $this->createMock(\Doctrine\DBAL\Connection::class);
        $journalDb->method('isAutoCommit')->willReturn(true);
        $rowsByRequest = [];
        $journalDb->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params) use (&$rowsByRequest): array|false {
                return $rowsByRequest[$params[0]] ?? false;
            }
        );
        $journalDb->expects(self::exactly(3))->method('insert')->willReturnCallback(
            static function (string $table, array $event) use (&$rowsByRequest): int {
                $rowsByRequest[$event['request_id']] = $event;
                return 1;
            }
        );
        $clock = $this->createMock(\Psr\Clock\ClockInterface::class);
        $clock->method('now')->willReturn($week);
        $confirm = new \App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferConfirmation(
            new RadioPreparationPublisher(),
            new \App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferJournal($journalDb, $clock),
            new \App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime($clock)
        );
        $paths = [
            $this->source . '/first.mp3',
            $this->root . '/2026-11-17/A/audio.zip', $this->root . '/2026-11-17/B/audio.zip',
            $this->root . '/2026-11-17/A/result.json', $this->root . '/2026-11-17/C/result.json',
        ];
        $fingerprints = array_map(static fn (string $path): string => hash_file('sha256', $path), $paths);
        foreach (['C', 'A', 'B'] as $index => $attempt) {
            $file = $attempt === 'B' ? $b['artifacts'][0] : $a['artifacts'][0];
            $event = $confirm->confirm($this->root, $week, $attempt, $file['diffusionId'], 'synthetic',
                $file['filename'], $file['sha256'], 12, str_repeat((string) ($index + 1), 32));
            self::assertSame($attempt === 'B' ? 'B' : 'A', $event['origin_attempt_id']);
        }
        self::assertSame($fingerprints, array_map(static fn (string $path): string => hash_file('sha256', $path), $paths));
        $journalDb->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params) use (&$rowsByRequest): array {
                self::assertSame('synthetic', $params[0]);
                self::assertCount(2, $params[1]);
                $events = array_values($rowsByRequest);
                return [['id' => 2] + $events[1], ['id' => 3] + $events[2]];
            }
        );
        $worklist = new \App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferWorklist(
            $orchestrator, new \App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferJournal($journalDb, $clock)
        );
        $listing = $worklist->read($this->root, $week, 'C', 'synthetic');
        self::assertSame('current', $listing['status']);
        self::assertSame(['transferred', 'transferred'], array_column($listing['lines'], 'transferState'));
        self::assertSame(['A', 'B'], array_column(array_column($listing['lines'], 'zip'), 'attemptId'));
        self::assertSame('preparation_required', $worklist->read($this->root, $week, 'A', 'synthetic')['status']);


        $marker = $this->root . '/2026-11-17/B/result.json';
        rename($marker, $marker . '.pending');
        self::assertNull($orchestrator->read($this->root, $week, 'B'));
        self::assertSame([2], $orchestrator->read($this->root, $week, 'C')['unavailableOrigins']);
        rename($marker . '.pending', $marker);
        file_put_contents($this->source . '/second.mp3', 'synthetic one');
        $rows[1] = $row(2, 'Second', '10:00:00', 'second.mp3');
        file_put_contents($this->root . '/2026-11-17/A/audio.zip', 'corrupt');
        $catalog = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationCatalog(new RadioPreparationPublisher());
        $catalogue = $catalog->read($this->root, $week);
        self::assertNull($catalogue['reference']);
        $statuses = array_column($catalogue['rows'], 'status', 'attemptId');
        self::assertSame('unusable', $statuses['A']);
        self::assertSame('origin_unavailable', $statuses['B']);
        self::assertSame('origin_unavailable', $statuses['C']);

        self::assertSame([1], $orchestrator->read($this->root, $week, 'C')['unavailableOrigins']);
        $d = $prepare('D', 'C');
        self::assertSame([1], array_column($d['artifacts'], 'diffusionId'));
        self::assertNull($orchestrator->read($this->root, $week, 'A'));
        file_put_contents($this->source . '/first.mp3', 'changed bytes');
        $rows[1] = $row(2, 'Different title', '11:00:00', 'second.mp3');
        $rows[] = $row(3, 'Missing', '12:00:00', 'missing.mp3');
        $e = $prepare('E', 'D');
        self::assertSame([1, 2], array_column($e['artifacts'], 'diffusionId'));
        self::assertSame([['replaced'], ['renamed'], ['unavailable']], array_column($e['differential']['expected'], 'changes'));
        self::assertSame([3 => 'source_absent'], $e['sourceIssues']);
        array_splice($rows, 1);
        $f = $prepare('F', 'E');
        self::assertSame('report_only', $f['status']);
        self::assertSame([2, 3], array_column($f['differential']['removed'], 'diffusionId'));

        $legacy = $this->workspace(['first.mp3'], 'legacy-reference');
        $copier->prepare($legacy);
        (new RadioPreparationPublisher())->publish($legacy);
        $g = $prepare('G', 'legacy-reference');
        self::assertNull($g['differential']['referenceAttemptId']);
        self::assertNull($g['differential']['expected'][0]['origin']);
        self::assertSame([1], array_column($g['artifacts'], 'diffusionId'));

        $launchRoot = $this->root . '/launch';
        mkdir($launchRoot, 0700);
        $launch = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationLaunch(
            $catalog, $orchestrator, $lifetime
        );
        $run = static fn (string $id): string => $launch->run($launchRoot, $roots, $week, str_repeat($id, 32), 'synthetic');
        self::assertSame('complete_zip', $run('a'));
        self::assertSame('already_requested', $run('a'));
        $lockPath = $launchRoot . '/2026-11-17/.storage.lock';
        $process = proc_open(['timeout', '--kill-after=1s', '10s', PHP_BINARY, '-r',
            '$f = fopen($argv[1], "rb"); if (!flock($f, LOCK_EX | LOCK_NB)) { exit(1); } echo "locked\n"; fgets(STDIN);',
            $lockPath], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 4);
            self::assertSame("locked\n", fgets($pipes[1]));
            self::assertSame('busy', $run('b'));
            $rows[0]['horaire_diffusion'] = '2026-11-24 09:00:00';
            self::assertSame('complete_zip', $launch->run($launchRoot, $roots, $week->modify('+7 days'), str_repeat('b', 32), 'synthetic'));
            $rows[0]['horaire_diffusion'] = '2026-11-17 09:00:00';
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
        file_put_contents($this->source . '/first.mp3', 'new bytes at preserved name');
        self::assertSame('corrective_zip', $run('b'));
        self::assertSame('unchanged', $run('c'));
        $head = $catalog->read($launchRoot, $week)['reference'];
        self::assertSame(str_repeat('c', 32), $head['attemptId']);
        self::assertSame(str_repeat('b', 32), $head['differential']['referenceAttemptId']);
        self::assertSame('report_only', $head['status']);
        self::assertFileDoesNotExist($launchRoot . '/2026-11-17/' . str_repeat('c', 32) . '/audio.zip');

        $rows[0]['validated'] = 0;
        self::assertSame('unvalidated', $run('d'));
        $rows[0]['validated'] = 1;
        self::assertSame('unchanged', $run('d'));
        $fixtureRoot = dirname(__DIR__, 5);
        require_once $fixtureRoot . '/vendor/james-heinrich/getid3/getid3/getid3.php';
        require_once $fixtureRoot . '/vendor/james-heinrich/getid3/getid3/write.php';
        $sourcePath = $this->source . '/first.mp3';
        copy($fixtureRoot . '/tests/Fixtures/mp3/test-short.mp3', $sourcePath);
        self::assertSame('corrective_zip', $run('e'));
        $writer = new \getid3_writetags();
        $writer->filename = $sourcePath;
        $writer->tagformats = ['id3v2.3'];
        $writer->tag_data = ['title' => ['Edited broadcast tag']];
        self::assertTrue($writer->WriteTags());
        self::assertSame('corrective_zip', $run('f'));
        $copier->failWrite = true;
        try {
            $run('2');
            self::fail('A simulated copy error must abort the launch.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Incomplete write', $error->getMessage());
        } finally {
            $copier->failWrite = false;
        }
        self::assertFileExists($launchRoot . '/2026-11-17/' . str_repeat('2', 32) . '/failure.json');
        self::assertSame('already_requested', $run('2'));

        $rows = [['validated' => 1, 'manual_revision' => '1', 'diffusion_id' => null]];
        self::assertSame('report_only', $run('1'));
        $withdrawn = $catalog->read($launchRoot, $week)['reference'];
        self::assertSame([1], array_column($withdrawn['differential']['removed'], 'diffusionId'));
        self::assertNull($withdrawn['archive']);
        $orchestrator->prepare($launchRoot, $roots, $week, 'independent');
        self::assertSame('ambiguous', $run('3'));

        $brokenRoot = $this->root . '/broken-launch';
        mkdir($brokenRoot . '/2026-11-17/broken', 0700, true);
        file_put_contents($brokenRoot . '/2026-11-17/broken/result.json', '{}');
        self::assertSame('reference_unavailable', $launch->run($brokenRoot, $roots, $week, str_repeat('4', 32), 'synthetic'));
        $launchClock->sleep(1);
        self::assertSame('expired', $run('5'));
        self::assertSame('expired', $run('a'));
        self::assertDirectoryDoesNotExist($launchRoot . '/2026-11-17/' . str_repeat('5', 32));
        $historical = (new RadioPreparationPublisher())->openDownload($launchRoot, $week, str_repeat('a', 32), 'zip');
        self::assertIsArray($historical);
        fclose($historical['stream']);
        $this->expectException(\DomainException::class);
        $orchestrator->prepare($launchRoot, $roots, $week, 'low-level-expired');



        foreach (['a', 'b'] as $id) {
            self::assertNotNull($orchestrator->read($launchRoot, $week, str_repeat($id, 32)));
        }

    }

    public function testSourceFreshnessRequiresVerifiedBytesAndDetectsReplacement(): void
    {
        $path = $this->source . '/source.mp3';
        file_put_contents($path, 'synthetic A');
        $workspace = $this->workspace(['source.mp3']);
        $copier = new RadioPreparationCopier($this->lock);
        $captured = $copier->prepare($workspace);
        $report = (new RadioPreparationPublisher())->publish($workspace, str_repeat('a', 64));
        $freshness = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationFreshness($copier);
        $check = fn (bool $full = false, bool $current = true): string =>
            $freshness->check(Mp3TestStorage::library(), $report, $current, $full)['status'];
        self::assertSame('verification_required', $check());
        self::assertSame('current_verified', $check(true));

        rename($path, $path . '.original');
        file_put_contents($path, 'synthetic B');
        self::assertSame('verification_required', $check());
        self::assertSame('change_detected', $check(true));
        self::assertSame([1 => 'source_signature_changed'], $freshness->check(Mp3TestStorage::library(), $report, true)['issues']);
        $renamed = $report['entries'];
        $renamed[0]['filename'] = 'NewName.mp3';
        self::assertSame('change_detected', $freshness->check(Mp3TestStorage::library(), $report, true, false, $renamed)['status']);
        self::assertSame('preparation_required', $freshness->check(Mp3TestStorage::library(), null, true)['status']);
        unlink($path);
        self::assertSame('source_unavailable', $check(true));
        rename($path . '.original', $path);
        self::assertSame('current_verified', $check(true));
        self::assertSame('preparation_required', $check(true, false));
        self::assertSame(hash_file('sha256', $path), $captured['artifacts'][0]['sha256']);
    }

    public function testFreshnessDetectsRealId3AndCoverChangesWithUnchangedAudio(): void
    {
        $root = dirname(__DIR__, 5);
        require_once $root . '/vendor/james-heinrich/getid3/getid3/getid3.php';
        require_once $root . '/vendor/james-heinrich/getid3/getid3/write.php';
        $path = $this->source . '/source.mp3';
        copy($root . '/tests/Fixtures/mp3/test-short.mp3', $path);
        $copier = new RadioPreparationCopier($this->lock);
        $freshness = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationFreshness($copier);
        $publisher = new RadioPreparationPublisher();
        $checks = [];
        $audio = static function () use ($path): string {
            $info = (new \getID3())->analyze($path);
            return hash('sha256', substr(file_get_contents($path), $info['avdataoffset'], $info['avdataend'] - $info['avdataoffset']));
        };
        $initialAudio = $audio();
        $tags = ['title' => ['Radio']];
        foreach (['tags', 'cover'] as $change) {
            $workspace = $this->workspace(['source.mp3'], $change);
            $copier->prepare($workspace);
            $report = $publisher->publish($workspace, str_repeat('a', 64));
            foreach ([false, true] as $full) {
                $start = hrtime(true);
                for ($i = 0; $i < 20; ++$i) {
                    $observed = $freshness->check(Mp3TestStorage::library(), $report, true, $full);
                }
                $checks[$change . ($full ? '-full' : '-light')] = (hrtime(true) - $start) / 20e6;
                self::assertSame($full ? 'current_verified' : 'verification_required', $observed['status']);
            }
            if ($change === 'cover') {
                $tags['attached_picture'][] = ['data' => base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jqmQAAAAASUVORK5CYII=',
                    true
                ), 'mime' => 'image/png', 'picturetypeid' => 3, 'description' => 'Cover'];
            }
            $writer = new \getid3_writetags();
            $writer->filename = $path;
            $writer->tagformats = ['id3v2.3'];
            $writer->tag_data = $tags;
            self::assertTrue($writer->WriteTags());
            self::assertSame($initialAudio, $audio());
            self::assertSame('change_detected', $freshness->check(Mp3TestStorage::library(), $report, true, true)['status']);
        }
        if (getenv('RADIO_FRESHNESS_TEST_TIMINGS') === '1') {
            fwrite(STDOUT, 'Radio freshness mean milliseconds: ' . json_encode($checks, JSON_THROW_ON_ERROR) . "\n");
        }
    }

    public function testFreshnessHandlesUnreadableReturnedAndConcurrentSources(): void
    {
        $path = $this->source . '/source.mp3';
        $publisher = new RadioPreparationPublisher();
        $copier = new RadioPreparationCopier($this->lock);
        $workspace = $this->workspace(['source.mp3'], 'missing');
        $copier->prepare($workspace);
        $missing = $publisher->publish($workspace, str_repeat('a', 64));
        file_put_contents($path, 'original');
        $check = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationFreshness($copier);
        self::assertSame(['status' => 'change_detected', 'issues' => [1 => 'source_now_available']],
            $check->check(Mp3TestStorage::library(), $missing, true, true));

        $workspace = $this->workspace(['source.mp3'], 'present');
        $copier->prepare($workspace);
        $reference = $publisher->publish($workspace, str_repeat('a', 64));
        $unreadable = new class($this->lock) extends RadioPreparationCopier {
            protected function openSource(string $path): mixed { return false; }
        };
        $check = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationFreshness($unreadable);
        self::assertSame(['status' => 'source_unavailable', 'issues' => [1 => 'source_unreadable']],
            $check->check(Mp3TestStorage::library(), $reference, true, true));

        $racing = new class($this->lock) extends RadioPreparationCopier {
            protected function openSource(string $path): mixed
            {
                $stream = parent::openSource($path);
                rename($path, $path . '.retained');
                file_put_contents($path, 'original');

                return $stream;
            }
        };
        $check = new \App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationFreshness($racing);
        self::assertSame(['status' => 'change_detected', 'issues' => [1 => 'source_changed']],
            $check->check(Mp3TestStorage::library(), $reference, true, true));
        self::assertFalse($this->lock->isHeldByCurrentProcess());
    }

    public function testDownloadKeepsTheVerifiedBytesIfThePublishedFilesChangeAfterOpening(): void
    {
        $week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
        file_put_contents($this->source . '/source.mp3', 'synthetic');
        $workspace = $this->workspace(['source.mp3']);
        (new RadioPreparationCopier($this->lock))->prepare($workspace);
        $publisher = new RadioPreparationPublisher();
        $report = $publisher->publish($workspace);
        $directory = $workspace->directory();
        $json = file_get_contents($directory . '/result.json');
        $markerPath = $directory . '/result.json';
        rename($markerPath, $markerPath . '.saved');
        self::assertNull($publisher->openDownload($this->root, $week, 'attempt', 'zip'));
        symlink($markerPath . '.saved', $markerPath);
        self::assertNull($publisher->openDownload($this->root, $week, 'attempt', 'report'));
        unlink($markerPath);
        rename($markerPath . '.saved', $markerPath);
        link($directory . '/audio.zip', $directory . '/other-link');
        self::assertNull($publisher->openDownload($this->root, $week, 'attempt', 'zip'));
        unlink($directory . '/other-link');

        $zip = $publisher->openDownload($this->root, $week, 'attempt', 'zip');
        $marker = $publisher->openDownload($this->root, $week, 'attempt', 'report');
        try {
            file_put_contents($directory . '/audio.zip', 'substituted');
            unlink($directory . '/result.json');
            file_put_contents($directory . '/result.json', '{}');
            self::assertSame($report['archive']['sha256'], hash('sha256', stream_get_contents($zip['stream'])));
            self::assertSame($json, stream_get_contents($marker['stream']));
            self::assertNull($publisher->openDownload($this->root, $week, 'attempt', 'zip'));
            self::assertNull($publisher->openDownload($this->root, $week, 'attempt', 'report'));
        } finally {
            fclose($zip['stream']);
            fclose($marker['stream']);
        }
    }

    public function testPrivateSourceReferencesAreNotDownloadableVerbatim(): void
    {
        $week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
        $workspace = $this->workspace(['source.mp3']);
        $report = $workspace->report();
        $report['entries'][0]['mp3Path'] = '/srv/private/audio.mp3';
        self::assertFalse(RadioPreparationPublisher::isReportDownloadable($report));
        $report['entries'][0]['mp3Path'] = 'library/source.mp3';
        $report['entries'][0]['url'] = 'file:///srv/private/audio.mp3';
        self::assertFalse(RadioPreparationPublisher::isReportDownloadable($report));
        $report['entries'][0]['url'] = 'https://example.test/audio.mp3';
        self::assertTrue(RadioPreparationPublisher::isReportDownloadable($report));
        $report['entries'][0]['url'] = 'https://example.test/audio.mp3?signature=synthetic';
        self::assertFalse(RadioPreparationPublisher::isReportDownloadable($report));

        $publisher = new RadioPreparationPublisher();
        $this->expectException(\InvalidArgumentException::class);
        $publisher->openDownload($this->root, $week, '../attempt', 'report');
    }

    private function workspace(array $names, string $attempt = 'attempt'): RadioPreparationWorkspace
    {
        $week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
        $entries = [];
        foreach ($names as $index => $source) {
            $id = $index + 1;
            $entries[] = ['diffusionId' => $id, 'startsAt' => $week, 'filename' => "file-$id.mp3",
                'mp3Path' => basename($this->source) . '/' . $source, 'localUrlPath' => null,
                'audioKind' => 'local_reference', 'issues' => [], 'live' => false, 'deleted' => false];
        }

        return RadioPreparationWorkspace::open($this->root, [
            'library' => Mp3TestStorage::library(), 'work' => Mp3TestStorage::work(),
            'trash' => Mp3TestStorage::root() . '/trash', 'public' => dirname(__DIR__, 5) . '/public',
        ], ['weekStart' => $week, 'weekEnd' => $week->modify('+7 days'), 'entries' => $entries], $attempt);
    }
}
