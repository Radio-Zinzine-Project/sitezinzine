<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIdentityVerifier;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupResolutionDecision;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanup;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupAttemptJournal;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupPreconditions;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupResult;
use App\Service\Mp3DiskIo;
use App\Service\Mp3StorageLock;
use App\Service\Mp3TrashPath;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use App\Tests\Support\Mp3TestStorage;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;
use Doctrine\DBAL\Exception\DriverException;

final class Mp3DeferredCleanupIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    public function testIncidentIsLockedThroughRemovalWithoutUpdatingIt(): void
    {
        $relative = $this->newFixturePath();
        $db = $this->newConnection();
        $observer = $this->newConnection();
        // The URL scan sees only this test's synthetic reference table.
        $db->executeStatement('CREATE TEMPORARY TABLE emission (thumbnail_mp3 VARCHAR(255), url VARCHAR(255)) ENGINE=InnoDB');

        $library = Mp3TestStorage::library();
        $root = Mp3TestStorage::root();
        $file = $library . '/' . $relative;
        self::assertTrue(mkdir(dirname($file), 0700, true));
        self::assertSame(5, file_put_contents($file, 'audio'));
        $stat = lstat($file);
        self::assertIsArray($stat);
        $evidence = Mp3CleanupHistoricalIdentity::capture('emissionsMp3', $relative, null, $stat
            + ['sha256' => hash('sha256', 'audio')]);
        $adapter = new Mp3CleanupIssueDbalAdapter($db);
        $writer = new Mp3CleanupIssueWriter($db, new Mp3CleanupIssueGrouper(
            $adapter->findActiveIssues(...), $adapter->createIssue(...), $adapter->appendEvent(...),
        ));
        $issueId = $writer->record('emissionsMp3', new Mp3CleanupAnomaly(
            'obsolete_cleanup_failed', 42, $relative,
            ['replacement_relative_path' => dirname($relative) . '/new.mp3', 'commit_confirmed' => true, 'reference_count' => 0],
            ['class' => \RuntimeException::class, 'code' => 0, 'sql_state' => null], $evidence,
        ));
        $eventId = (int) $observer->fetchOne('SELECT MAX(id) FROM mp3_cleanup_event WHERE issue_id = ?', [$issueId]);
        self::assertGreaterThan(0, $eventId);

        $lock = new Mp3StorageLock(new Mp3TrashPath($root . '/trash', $library, $root . '/work', dirname(__DIR__, 4) . '/public'), 0);
        $disk = $this->createMock(Mp3DiskIo::class);
        $disk->expects(self::once())->method('unlink')->willReturnCallback(
            static function (string $target) use ($issueId, $observer, $lock, $file): bool {
                self::assertSame($file, $target);
                self::assertTrue($lock->isHeldByCurrentProcess());
                try {
                    $observer->transactional(static fn () => $observer->fetchOne(
                        'SELECT id FROM mp3_cleanup_issue WHERE id = ? FOR UPDATE NOWAIT', [$issueId],
                    ));
                    self::fail('The incident row must remain locked during unlink.');
                } catch (DriverException $error) {
                    self::assertSame(3572, $error->getPrevious()->getCode());
                }

                return unlink($target);
            },
        );
        $cleanup = new Mp3DeferredCleanup(
            $lock, new Mp3DeferredCleanupPreconditions($lock, new Mp3CleanupResolutionDecision(), $library),
            new Mp3CleanupIdentityVerifier($library), $disk, $library, $this->createMock(Mp3DeferredCleanupAttemptJournal::class),
        );

        self::assertSame(Mp3DeferredCleanupResult::Removed, $cleanup->remove(
            $db, $issueId, ['issue_id' => $issueId, 'last_event_id' => $eventId],
            new AudioLocalUrlResolver(['https://example.test/mp3']), str_repeat('a', 32), 19,
        ));
        self::assertFileDoesNotExist($file);
        self::assertSame(0, $db->getTransactionNestingLevel());
        self::assertFalse($lock->isHeldByCurrentProcess());
        self::assertSame('open', $observer->transactional(static fn () => $observer->fetchOne(
            'SELECT status FROM mp3_cleanup_issue WHERE id = ? FOR UPDATE NOWAIT', [$issueId],
        )));
        self::assertSame(1, (int) $observer->fetchOne('SELECT COUNT(*) FROM mp3_cleanup_event WHERE issue_id = ?', [$issueId]));
    }
}
