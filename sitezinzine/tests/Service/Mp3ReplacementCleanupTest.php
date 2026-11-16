<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Categories, Emission};
use App\Enum\Mp3FailureStage;
use App\Exception\Mp3OperationFailure;
use App\Repository\CategorieTagImageRepository;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\Service\{Mp3Processor, Mp3Replacement};
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
    private ?\Throwable $removalFailure = null;
    private array $timeline = [];
    private array $removals = [];
    private array $received = [];
    private int $level = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $project = dirname(__DIR__, 2);
        $this->base = Mp3TestStorage::root() . '/cleanup-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->base . '/TST/2024', 0700);
        // The fixture starts directly with MPEG frames. Read a bounded excerpt, never copy its 87 MB.
        $audio = file_get_contents($project . '/tests/Fixtures/mp3/test.mp3', false, null, 0, 65536);
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
            $this->timeline[] = 'remove';
            $this->removals[] = $files;
            if ($this->removalFailure !== null) { throw $this->removalFailure; }
            (new Filesystem())->remove($files);
        });
        $processor = new Mp3Processor($this->createMock(StorageInterface::class), $filesystem,
            $this->createMock(CategorieTagImageRepository::class), $this->base,
            'https://example.test/mp3', $project, $this->base . '/private');
        $this->replacement = new Mp3Replacement($processor);
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
        yield 'still referenced' => [2, true];
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
        } elseif ($references > 0) {
            self::assertSame(['commit', 'references'], $this->timeline);
            self::assertSame([], $this->removals);
            $this->assertOriginalPreserved();
        } else {
            self::assertSame(['commit', 'references', 'remove'], $this->timeline);
            self::assertFileDoesNotExist($this->base . '/' . $this->original);
        }
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
    }

    public function testEnclosingTransactionDefersCleanupAndReporting(): void
    {
        $this->injectReporter();
        $this->execute(0, initialLevel: 1);
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
        $connection->expects(self::once())->method('beginTransaction')->willReturnCallback(function (): void { ++$this->level; });
        $connection->method('fetchAssociative')->willReturn(['thumbnail_mp3' => $original, 'url' => $originalUrl]);
        $connection->expects(self::once())->method('commit')->willReturnCallback(function () use ($commitFailure): void {
            $this->timeline[] = 'commit';
            if ($commitFailure !== null) { throw $commitFailure; }
            --$this->level;
        });
        $connection->expects($commitFailure === null ? self::never() : self::once())->method('rollBack')->willReturnCallback(function (): void {
            $this->timeline[] = 'rollback';
            --$this->level;
        });
        $cleanupExpected = $commitFailure === null && $initialLevel === 0 && $original !== null;
        $connection->expects($cleanupExpected ? self::once() : self::never())->method('fetchOne')
            ->with('SELECT COUNT(*) FROM emission WHERE thumbnail_mp3 = ?', [$original])
            ->willReturnCallback(function () use ($references): int {
                $this->timeline[] = 'references';
                if ($references instanceof \Throwable) { throw $references; }
                return $references;
            });
        $manager = $this->createMock(EntityManagerInterface::class);
        $events = new EventManager();
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getEventManager')->willReturn($events);
        $manager->expects(self::exactly(2))->method('flush')->willReturnCallback(static function () use ($manager, $events): void {
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
