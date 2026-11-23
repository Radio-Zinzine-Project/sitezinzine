<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Categories, Emission};
use App\Enum\Mp3FailureStage;
use App\Enum\Mp3StoredFileState;
use App\Exception\Mp3OperationFailure;
use App\Repository\CategorieTagImageRepository;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\Service\{Mp3Processor, Mp3Replacement, Mp3StorageLock, Mp3TrashPath};
use App\Tests\Support\Mp3TestStorage;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;

/** Real preparation/reporter, isolated small files, no kernel or SQL connection. */
final class Mp3ReplacementCleanupTest extends TestCase
{
    private string $base;
    private string $original = 'TST/2024/original.mp3';
    private string $originalUrl = 'https://example.test/mp3/TST/2024/original.mp3';
    private string $originalHash;
    private Emission $emission;
    private Mp3Replacement $replacement;
    private Mp3Processor $processor;
    private bool $cleanupConfigured = true;
    private Mp3StorageLock $lock;
    private string $lockFile;
    private bool $checkLock = false;
    private bool $replaceOriginalAtCommit = false;
    private bool $leaveOriginalWhenRemoving = false;
    private ?\Throwable $removalFailure = null;
    private array $timeline = [];
    private array $removals = [];
    private array $received = [];
    private int $level = 0;
    private array $reservedPaths = [];
    private array $referencingUrls = [];
    private int $oldPathReservations = 0;
    private int|false $activeOperation = false;
    private ?\RuntimeException $reservationFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        $project = dirname(__DIR__, 2);
        $this->base = Mp3TestStorage::root() . '/cleanup-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->base . '/TST/2024', 0700);
        // Read a bounded excerpt of the synthetic MP3 fixture.
        $audio = file_get_contents($project . '/tests/Fixtures/mp3/test-short.mp3', false, null, 0, 65536);
        self::assertIsString($audio);
        self::assertSame(65536, strlen($audio));
        file_put_contents($this->base . '/' . $this->original, $audio);
        file_put_contents($this->base . '/upload.mp3', $audio);
        $this->originalHash = hash('sha256', $audio);
        $this->emission = (new Emission())->setTitre('Titre')->setDatepub(new \DateTime('2024-06-15'))
            ->setCategorie((new Categories())->setSlug('TST')->setTitre('Album'))
            ->setThumbnailMp3($this->original)->setUrl($this->originalUrl);
        (new \ReflectionProperty(Emission::class, 'id'))->setValue($this->emission, 42);
        $filesystem = $this->getMockBuilder(Filesystem::class)->onlyMethods(['remove'])->getMock();
        $filesystem->method('remove')->willReturnCallback(function (string|iterable $files): void {
            if ($this->checkLock) { $this->assertStorageLocked(); }
            $this->timeline[] = 'remove';
            $this->removals[] = $files;
            if ($this->removalFailure !== null) { throw $this->removalFailure; }
            if (!$this->leaveOriginalWhenRemoving) {
                (new Filesystem())->remove($files);
            }
        });
        $this->processor = new Mp3Processor($this->createMock(StorageInterface::class), $filesystem,
            $this->createMock(CategorieTagImageRepository::class), $this->base,
            'https://example.test/mp3', $project, $this->base . '/private');
        $this->lock = new Mp3StorageLock(new Mp3TrashPath(Mp3TestStorage::root() . '/trash',
            Mp3TestStorage::library(), Mp3TestStorage::work(), $project . '/public'), 20);
        $this->lockFile = Mp3TestStorage::root() . '/trash/.storage.lock';
        $this->replacement = new Mp3Replacement($this->processor, $this->lock, 'https://example.test/mp3',
            '["https://legacy.example.test/audio"]');
    }

    public function testGlobalLockCoversTheTransactionAndPostCommitRemoval(): void
    {
        $this->checkLock = true;
        $this->execute(0);
        self::assertSame(['commit', 'references', 'remove'], $this->timeline);
        self::assertTrue($this->lock->synchronized(static fn () => true));
    }

    public function testSkipsReservedNamesAndPhysicalCollisionsWithOneRead(): void
    {
        $stem = 'TST/2024/TST20240615Titre';
        $this->reservedPaths = [$stem . '.mp3', $stem . '-1.mp3', $stem . '-3.mp3'];
        $occupied = $this->base . '/' . $stem . '-2.mp3';
        file_put_contents($occupied, 'physical occupant');

        $this->execute(0);

        self::assertSame($stem . '-4.mp3', $this->emission->getThumbnailMp3());
        self::assertSame('physical occupant', file_get_contents($occupied));
        foreach ($this->reservedPaths as $path) {
            self::assertFileDoesNotExist($this->base . '/' . $path);
        }
        self::assertFileDoesNotExist($this->base . '/' . $this->original);
        self::assertSame(['commit', 'references', 'remove'], $this->timeline);
    }

    public function testReservationReadFailureRollsBackAndDoesNotPublish(): void
    {
        $this->reservationFailure = new \RuntimeException('Reservation read failed');
        $this->injectReporter();
        try {
            $this->execute(0);
            self::fail('The replacement must fail before publication.');
        } catch (Mp3OperationFailure $error) {
            self::assertSame(Mp3FailureStage::DOCTRINE, $error->stage);
            self::assertSame($this->reservationFailure, $error->getPrevious());
            self::assertTrue($error->rollbackConfirmed);
        }
        self::assertSame(['rollback'], $this->timeline);
        self::assertSame([], $this->received);
        self::assertSame([$this->base . '/' . $this->original], glob($this->base . '/TST/2024/*.mp3'));
        $this->assertOriginalPreserved();
    }

    /** @dataProvider operations */
    public function testActiveOperationRefusesBeforeAnyFlushOrPublication(bool $reprocess): void
    {
        $this->activeOperation = 42;
        $this->injectReporter();
        try {
            $this->execute(0, $reprocess);
            self::fail('An active trash operation must prevent the replacement.');
        } catch (Mp3OperationFailure $error) {
            self::assertSame(Mp3FailureStage::CONFLICT, $error->stage);
            self::assertTrue($error->rollbackConfirmed);
            self::assertTrue($error->originalPreserved);
        }
        self::assertSame($this->original, $this->emission->getThumbnailMp3());
        self::assertSame($this->originalUrl, $this->emission->getUrl());
        self::assertSame([$this->base . '/' . $this->original], glob($this->base . '/TST/2024/*.mp3'));
        self::assertSame(['rollback'], $this->timeline);
        self::assertSame([], $this->received);
        $this->assertOriginalPreserved();
    }

    public function testConfirmedAbsenceDoesNotCreateAnIncidentWithoutHistoricalIdentity(): void
    {
        unlink($this->base . '/' . $this->original);
        $this->injectReporter();

        $this->execute(0);

        $this->assertSuccessfulReplacement();
        self::assertSame([], $this->removals);
        self::assertSame([], $this->received);
    }

    public function testUnconfirmedAbsenceReportsRetentionWithoutInventingHistoricalIdentity(): void
    {
        $this->emission->setThumbnailMp3('unavailable-parent/original.mp3');
        $this->injectReporter();

        $this->execute(0);

        $this->assertSuccessfulReplacement();
        self::assertSame([], $this->removals);
        self::assertCount(1, $this->received);
        self::assertSame('obsolete_cleanup_failed', $this->received[0]->reason);
        self::assertNull($this->received[0]->historicalIdentity?->identity);
    }

    public function testPresentUnreadableOriginalIsPreservedWithoutHistoricalIdentity(): void
    {
        $path = $this->base . '/' . $this->original;
        self::assertTrue(chmod($path, 0000));
        try {
            clearstatcache(true, $path);
            if (is_readable($path)) {
                self::markTestSkipped('Run as an unprivileged user to check unreadable file permissions.');
            }
            $this->injectReporter();
            self::assertSame(Mp3StoredFileState::Unverifiable, $this->processor->inspectStoredFile($this->original)['state']);

            $this->execute(0);

            $this->assertSuccessfulReplacement();
            self::assertSame([], $this->removals);
            self::assertCount(1, $this->received);
            self::assertSame('obsolete_cleanup_failed', $this->received[0]->reason);
            self::assertNull($this->received[0]->historicalIdentity?->identity);
        } finally {
            chmod($path, 0600);
        }
        $this->assertOriginalPreserved();
    }

    public function testRemovalWithoutConfirmedAbsenceReportsRetention(): void
    {
        $this->leaveOriginalWhenRemoving = true;
        $this->injectReporter();

        $this->execute(0);

        $this->assertSuccessfulReplacement();
        $this->assertOriginalPreserved();
        self::assertCount(1, $this->received);
        self::assertSame('obsolete_cleanup_failed', $this->received[0]->reason);
        self::assertSame($this->originalHash, $this->received[0]->historicalIdentity?->identity['sha256']);
    }

    public function testChangedInodeWithIdenticalBytesIsNotRemoved(): void
    {
        $originalInode = fileinode($this->base . '/' . $this->original);
        $this->replaceOriginalAtCommit = true;
        $this->injectReporter();
        $this->execute(0);
        self::assertSame([], $this->removals);
        self::assertSame('obsolete_cleanup_failed', $this->received[0]->reason);
        self::assertSame($originalInode, $this->received[0]->historicalIdentity?->identity['ino']);
        $this->assertOriginalPreserved();
    }

    public function testContentionRefusesAnySqlMutation(): void
    {
        $this->lock->synchronized(static fn () => null);
        $holder = fopen($this->lockFile, 'r+b');
        self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
        try {
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::never())->method('beginTransaction');
            $manager = $this->createMock(EntityManagerInterface::class);
            $manager->method('getConnection')->willReturn($connection);
            $manager->expects(self::never())->method('flush');
            $this->emission->setThumbnailFileMp3(new UploadedFile($this->base . '/upload.mp3', 'replacement.mp3', null, null, true));
            try {
                $this->replacement->replace($this->emission, $manager, $this->original, $this->originalUrl);
                self::fail('An occupied storage lock must prevent the replacement.');
            } catch (Mp3OperationFailure $error) {
                self::assertInstanceOf(\App\Exception\Mp3StorageLockTimeout::class, $error->getPrevious());
                $this->assertOriginalPreserved();
                self::assertFileExists($this->base . '/upload.mp3');
            }
        } finally {
            fclose($holder);
        }
    }

    private function assertStorageLocked(): void
    {
        self::assertFileExists($this->lockFile);
        $probe = fopen($this->lockFile, 'r+b');
        try {
            self::assertFalse(flock($probe, LOCK_EX | LOCK_NB));
        } finally {
            fclose($probe);
        }
    }

    public static function operations(): iterable
    {
        yield 'replacement' => [false];
        yield 'editorial reprocessing' => [true];
    }

    /** @dataProvider operations */
    public function testReferenceCheckFailureReportsUnknownReferencesAfterCommit(bool $reprocess): void
    {
        $origin = new \RuntimeException('PRIVATE_REFERENCE_FAILURE', 17);
        $this->injectReporter();
        $this->execute($origin, $reprocess);
        $this->assertSuccessfulReplacement();
        self::assertSame(['commit', 'references', 'report'], $this->timeline);
        self::assertSame([], $this->removals);
        $this->assertOriginalPreserved();
        $this->assertAnomaly('reference_check_failed', null, $origin);
        $proof = $this->received[0]->historicalIdentity;
        self::assertNotNull($proof);
        self::assertSame('complete', $proof->state);
        self::assertSame($this->original, $proof->relativePath);
        self::assertSame($this->originalUrl, $proof->originalUrl);
        self::assertSame($this->originalHash, $proof->identity['sha256']);
    }

    /** @dataProvider operations */
    public function testRemovalFailureReportsConfirmedZeroReferencesAfterCommit(bool $reprocess): void
    {
        $this->removalFailure = new \RuntimeException('PRIVATE_REMOVAL_FAILURE', 18);
        $this->injectReporter();
        $this->execute(0, $reprocess);
        $this->assertSuccessfulReplacement();
        self::assertSame(['commit', 'references', 'remove', 'report'], $this->timeline);
        self::assertSame([$this->base . '/' . $this->original], $this->removals);
        $this->assertOriginalPreserved();
        $this->assertAnomaly('obsolete_cleanup_failed', 0, $this->removalFailure);
    }

    public static function noAnomalies(): iterable
    {
        yield 'successful removal' => [0, true];
        yield 'no previous MP3' => [0, false];
    }

    /** @dataProvider noAnomalies */
    public function testNormalOutcomesDoNotReport(int $references, bool $hasOriginal): void
    {
        if (!$hasOriginal) { $this->emission->setThumbnailMp3(null)->setUrl(null); }
        $this->injectReporter();
        $this->execute($references);
        $this->assertSuccessfulReplacement();
        self::assertSame([], $this->received);
        if (!$hasOriginal) {
            self::assertSame(['commit'], $this->timeline);
            self::assertSame([], $this->removals);
        } else {
            self::assertSame(['commit', 'references', 'remove'], $this->timeline);
            self::assertFileDoesNotExist($this->base . '/' . $this->original);
        }
    }

    /** @dataProvider retainedPaths */
    public function testPostCommitGuardPreservesReferencedOrReservedFile(array $urls, int $pathReferences, int $reservations): void
    {
        $this->referencingUrls = $urls;
        $this->oldPathReservations = $reservations;
        $this->injectReporter();
        $this->checkLock = true;
        $this->execute($pathReferences);

        $this->assertSuccessfulReplacement();
        $this->assertOriginalPreserved();
        self::assertSame([], $this->removals);
        self::assertCount(1, $this->received);
        self::assertSame('reference_check_failed', $this->received[0]->reason);
        self::assertNull($this->received[0]->context['reference_count']);
    }

    public static function retainedPaths(): iterable
    {
        yield 'physical reference' => [[], 1, 0];
        // The URL query must include soft-deleted emissions as well.
        yield 'local URL only on another emission' => [['https://example.test/mp3/TST/2024/original.mp3'], 0, 0];
        yield 'historical URL only (including soft-deleted emissions)' => [['https://legacy.example.test/audio/TST/2024/original.mp3'], 0, 0];
        yield 'active reservation on another emission' => [[], 0, 1];
        yield 'ambiguous local URL' => [['https://example.test/mp3/TST/2024/original.mp3?token=1'], 0, 0];
    }

    public function testUnconfiguredUrlCoverageLeavesReplacementCommittedAndReportsRetention(): void
    {
        $this->cleanupConfigured = false;
        $this->replacement = new Mp3Replacement($this->processor, $this->lock, 'https://example.test/mp3');
        $this->injectReporter();

        $this->execute(0);

        $this->assertSuccessfulReplacement();
        $this->assertOriginalPreserved();
        self::assertSame([], $this->removals);
        self::assertCount(1, $this->received);
        self::assertSame('reference_check_failed', $this->received[0]->reason);
        self::assertSame(['commit', 'report'], $this->timeline);
    }

    public function testUnconfirmedCommitDoesNotRunCleanupOrReport(): void
    {
        $this->injectReporter();
        $origin = new \RuntimeException('Commit outcome unknown');
        try {
            $this->execute(0, commitFailure: $origin);
            self::fail('An unconfirmed commit must remain a business failure.');
        } catch (Mp3OperationFailure $error) {
            self::assertSame($origin, $error->getPrevious());
            self::assertSame(Mp3FailureStage::COMMIT, $error->stage);
            self::assertFalse($error->rollbackConfirmed);
        }
        self::assertSame(['commit', 'rollback'], $this->timeline);
        self::assertSame([], $this->received);
        self::assertSame([], $this->removals);
        $this->assertOriginalPreserved();
        self::assertTrue($this->lock->synchronized(static fn () => true));
    }

    public function testEnclosingTransactionDefersCleanupAndReporting(): void
    {
        $this->injectReporter();
        $this->reservedPaths = ['TST/2024/TST20240615Titre.mp3'];
        $this->lock->synchronized(fn () => $this->execute(0, initialLevel: 1));
        self::assertSame('TST/2024/TST20240615Titre-1.mp3', $this->emission->getThumbnailMp3());
        $this->assertSuccessfulReplacement(1);
        self::assertSame(['commit'], $this->timeline);
        self::assertSame([], $this->received);
        self::assertSame([], $this->removals);
        $this->assertOriginalPreserved();
    }

    public static function failures(): iterable
    {
        yield 'reference check' => [true];
        yield 'physical removal' => [false];
    }

    /** @dataProvider failures */
    public function testRecipientAndFallbackFailuresDoNotInvalidateReplacement(bool $referenceFailure): void
    {
        $loggerCalls = 0;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function () use (&$loggerCalls): void {
            ++$loggerCalls;
            throw new \RuntimeException('PRIVATE_LOGGER_FAILURE');
        });
        $this->injectReporter($logger, true);
        $origin = new \RuntimeException('PRIVATE_CLEANUP_FAILURE', 19);
        if (!$referenceFailure) { $this->removalFailure = $origin; }
        $this->execute($referenceFailure ? $origin : 0);
        $this->assertSuccessfulReplacement();
        self::assertSame(1, $loggerCalls);
        $this->assertAnomaly($referenceFailure ? 'reference_check_failed' : 'obsolete_cleanup_failed', $referenceFailure ? null : 0, $origin);
        $this->assertOriginalPreserved();
    }

    /** @dataProvider failures */
    public function testWithoutReporterCleanupFailuresKeepExistingBehavior(bool $referenceFailure): void
    {
        $origin = new \RuntimeException('Cleanup unavailable');
        if (!$referenceFailure) { $this->removalFailure = $origin; }
        $this->execute($referenceFailure ? $origin : 0);
        $this->assertSuccessfulReplacement();
        self::assertSame([], $this->received);
        $this->assertOriginalPreserved();
    }

    private function injectReporter(?LoggerInterface $logger = null, bool $failRecipient = false): void
    {
        $recipient = function (Mp3CleanupAnomaly $anomaly) use ($failRecipient): void {
            $this->timeline[] = 'report';
            $this->received[] = $anomaly;
            if ($failRecipient) { throw new \RuntimeException('PRIVATE_RECIPIENT_FAILURE'); }
        };
        $reporter = $logger === null ? new Mp3CleanupReporter($recipient) : new Mp3CleanupReporter($recipient, $logger);
        $this->replacement->setCleanupReporter($reporter);
    }

    private function execute(int|\Throwable $references, bool $reprocess = false, int $initialLevel = 0, ?\Throwable $commitFailure = null): void
    {
        $original = $this->emission->getThumbnailMp3();
        $originalUrl = $this->emission->getUrl();
        if (!$reprocess) {
            $this->emission->setThumbnailFileMp3(new UploadedFile($this->base . '/upload.mp3', 'replacement.mp3', 'audio/mpeg', null, true));
        }
        $this->level = $initialLevel;
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(fn (): int => $this->level);
        $connection->expects(self::once())->method('beginTransaction')->willReturnCallback(function (): void {
            if ($this->checkLock) { $this->assertStorageLocked(); }
            ++$this->level;
        });
        $canCommit = $this->reservationFailure === null && $this->activeOperation === false;
        $cleanupExpected = $this->cleanupConfigured && $commitFailure === null && $canCommit && $initialLevel === 0 && $original !== null;
        $lineLocked = false;
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql) use (&$lineLocked, $original, $originalUrl): array {
            self::assertStringContainsString('FOR UPDATE', $sql);
            $lineLocked = true;
            return ['thumbnail_mp3' => $original, 'url' => $originalUrl];
        });
        $connection->expects(self::exactly($cleanupExpected ? ($references === 0 ? 3 : 2) : 1))->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$lineLocked, $original, $references): int|false {
                if (str_contains($sql, 'COUNT(*) FROM mp3_trash_operation')) {
                    self::assertSame('SELECT COUNT(*) FROM mp3_trash_operation WHERE active_marker = 1 AND original_thumbnail_mp3 = ?', $sql);
                    self::assertSame([$original], $params);
                    $this->assertStorageLocked();
                    return $this->oldPathReservations;
                }
                if (str_contains($sql, 'mp3_trash_operation')) {
                    self::assertSame('SELECT id FROM mp3_trash_operation WHERE emission_id = ? AND active_marker = 1 FOR SHARE', $sql);
                    self::assertSame([42], $params);
                    self::assertTrue($lineLocked);
                    $this->assertStorageLocked();
                    return $this->activeOperation;
                }
                self::assertSame('SELECT COUNT(*) FROM emission WHERE thumbnail_mp3 = ?', $sql);
                self::assertSame([$original], $params);
                self::assertSame(0, $this->level);
                $this->timeline[] = 'references';
                if ($references instanceof \Throwable) { throw $references; }
                return $references;
        });
        $connection->method('iterateColumn')->willReturnCallback(function (string $sql, array $params): \Traversable {
            self::assertSame('SELECT url FROM emission WHERE url IS NOT NULL AND url <> ?', $sql);
            self::assertSame([''], $params);
            $this->assertStorageLocked();
            yield from $this->referencingUrls;
        });
        $connection->expects($this->activeOperation === false ? self::once() : self::never())->method('fetchFirstColumn')
            ->with('SELECT original_thumbnail_mp3 FROM mp3_trash_operation WHERE active_marker = 1 FOR SHARE')
            ->willReturnCallback(function () use ($initialLevel): array {
                $this->assertStorageLocked();
                self::assertSame($initialLevel + 1, $this->level);
                if ($this->reservationFailure !== null) {
                    throw $this->reservationFailure;
                }
                return $this->reservedPaths;
            });
        $connection->expects($canCommit ? self::once() : self::never())->method('commit')->willReturnCallback(function () use ($commitFailure): void {
            $this->timeline[] = 'commit';
            if ($commitFailure !== null) { throw $commitFailure; }
            if ($this->replaceOriginalAtCommit) {
                $path = $this->base . '/' . $this->original;
                copy($path, $path . '.new');
                rename($path . '.new', $path);
            }
            --$this->level;
        });
        $connection->expects($commitFailure === null && $canCommit ? self::never() : self::once())->method('rollBack')->willReturnCallback(function (): void {
            $this->timeline[] = 'rollback';
            --$this->level;
        });
        $manager = $this->createMock(EntityManagerInterface::class);
        $events = new EventManager();
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getEventManager')->willReturn($events);
        $manager->expects(self::exactly($this->activeOperation !== false ? 0 : ($canCommit ? 2 : 1)))->method('flush')->willReturnCallback(static function () use ($manager, $events): void {
            $events->dispatchEvent(Events::onFlush, new OnFlushEventArgs($manager));
        });
        if ($reprocess) {
            $this->replacement->reprocess($this->emission, $manager, $original, $originalUrl);
        } else {
            $this->replacement->replace($this->emission, $manager, $original, $originalUrl);
        }
    }

    private function assertSuccessfulReplacement(int $remainingLevel = 0): void
    {
        self::assertSame($remainingLevel, $this->level);
        self::assertNotSame($this->original, $this->emission->getThumbnailMp3());
        self::assertFileExists($this->base . '/' . $this->emission->getThumbnailMp3());
        self::assertSame('https://example.test/mp3/' . $this->emission->getThumbnailMp3(), $this->emission->getUrl());
        self::assertNull($this->emission->getThumbnailFileMp3());
    }

    private function assertOriginalPreserved(): void
    {
        self::assertFileExists($this->base . '/' . $this->original);
        self::assertSame($this->originalHash, hash_file('sha256', $this->base . '/' . $this->original));
    }

    private function assertAnomaly(string $reason, ?int $references, \Throwable $origin): void
    {
        self::assertCount(1, $this->received);
        $anomaly = $this->received[0];
        self::assertSame($reason, $anomaly->reason);
        self::assertSame(42, $anomaly->emissionId);
        self::assertSame($this->original, $anomaly->obsoleteRelativePath);
        self::assertSame(['replacement_relative_path' => $this->emission->getThumbnailMp3(),
            'commit_confirmed' => true, 'reference_count' => $references], $anomaly->context);
        self::assertSame(['class' => $origin::class, 'code' => $origin->getCode(), 'sql_state' => null], $anomaly->diagnostic);
        self::assertStringNotContainsString('PRIVATE_', json_encode($anomaly, JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->base)) {
                (new Filesystem())->remove($this->base);
                self::assertDirectoryDoesNotExist($this->base);
            }
        } finally {
            parent::tearDown();
        }
    }
}
