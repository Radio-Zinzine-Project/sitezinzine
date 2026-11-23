<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Exception\Mp3AdministrativeRequestUncertain;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIdentityVerifier;
use App\Service\AudioLibrary\Orphan\Mp3CleanupResolutionDecision;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanup;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupAttemptJournal;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupResult;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupPreconditions;
use App\Service\Mp3DiskIo;
use App\Service\Mp3StorageLock;
use App\Service\Mp3TrashPath;
use App\Tests\Support\Mp3TestStorage;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverError;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

final class Mp3DeferredCleanupTest extends TestCase
{
    /** @dataProvider cases */
    public function testRemoval(string $scenario, string $expected): void
    {
        $root = Mp3TestStorage::root();
        $library = Mp3TestStorage::library();
        $relative = bin2hex(random_bytes(8)) . '.mp3';
        $file = $library . '/' . $relative;
        self::assertSame(5, file_put_contents($file, 'audio'));
        clearstatcache(true, $file);
        $identity = array_intersect_key(lstat($file), array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))
            + ['sha256' => hash('sha256', 'audio')];
        $proof = Mp3CleanupHistoricalIdentity::capture('emissionsMp3', $relative, null, $identity)->toArray();
        $rows = [json_encode($proof, JSON_THROW_ON_ERROR)];
        if ($scenario === 'missing') {
            $rows = [null];
        } elseif ($scenario === 'unusable') {
            $rows = ['{"state":"complete"}'];
        } elseif ($scenario === 'conflict') {
            $other = $proof;
            ++$other['identity']['ino'];
            $rows = [...array_fill(0, 21, $rows[0]), json_encode($other, JSON_THROW_ON_ERROR)];
        }

        $lock = new Mp3StorageLock(new Mp3TrashPath($root . '/trash', $library, $root . '/work', dirname(__DIR__, 4) . '/public'), 0);
        $db = $this->createMock(Connection::class);
        $disk = $this->createMock(Mp3DiskIo::class);
        $disk->expects(self::never())->method('link');
        $journal = $this->createMock(Mp3DeferredCleanupAttemptJournal::class);
        $requestId = str_repeat('a', 32);
        $requestFailure = in_array($scenario, ['insert failure', 'initial uncertain', 'duplicate', 'concurrent'], true);
        $observationOnly = $scenario === 'pending before removal';
        $reservationConfirmed = $observationOnly;
        $journal->expects($observationOnly ? self::never() : self::once())->method('reserve')->with($db, $requestId, 17, 42, 19)
            ->willReturnCallback(function () use ($scenario, $lock, &$reservationConfirmed): void {
                self::assertFalse($lock->isHeldByCurrentProcess());
                match ($scenario) {
                    'insert failure' => throw new \RuntimeException('Synthetic insert failure'),
                    'initial uncertain' => throw new Mp3AdministrativeRequestUncertain('Synthetic commit uncertainty'),
                    'duplicate', 'concurrent' => throw new UniqueConstraintViolationException($this->createMock(DriverError::class), null),
                    default => $reservationConfirmed = true,
                };
            });
        $journal->expects($requestFailure || $observationOnly ? self::never() : self::once())->method('finish')
            ->willReturnCallback(static function ($session, $id, Mp3DeferredCleanupResult $result) use ($db, $requestId, $scenario, $expected, $lock): void {
                self::assertSame($db, $session);
                self::assertSame($requestId, $id);
                self::assertFalse($lock->isHeldByCurrentProcess());
                if ($scenario === 'final failure') {
                    self::assertSame(Mp3DeferredCleanupResult::Removed, $result);
                    throw new \RuntimeException('Synthetic final outcome failure');
                }
                self::assertSame($expected, $result->value);
            });
        $dbReads = $requestFailure || $scenario === 'busy' ? 0 : ($scenario === 'final failure' ? 2 : 1);
        $transactionLevel = 0;
        $db->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$transactionLevel): int {
            return $transactionLevel;
        });
        $db->expects(self::exactly($dbReads))->method('transactional')
            ->willReturnCallback(static function (\Closure $work) use ($db, $lock, &$transactionLevel, &$reservationConfirmed): mixed {
                self::assertTrue($reservationConfirmed);
                self::assertTrue($lock->isHeldByCurrentProcess());
                $transactionLevel = 1;
                try {
                    return $work($db);
                } finally {
                    $transactionLevel = 0;
                }
            });
        $db->expects(self::exactly($dbReads))->method('executeStatement')
            ->with('SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ WRITE');
        foreach (['insert', 'update', 'delete'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $db->method('fetchAssociative')->willReturnCallback(static function (string $sql, array $params) use ($relative, $scenario): array {
            self::assertStringContainsString('FOR UPDATE', $sql);
            self::assertSame([17], $params);

            return ['id' => '17', 'status' => $scenario === 'resolved' ? 'resolved' : 'open',
                'storage' => 'emissionsMp3', 'obsolete_relative_path' => $relative];
        });
        $db->method('fetchOne')->willReturnCallback(static function (string $sql) use ($scenario): string {
            return match (true) {
                str_contains($sql, 'FROM mp3_cleanup_event') => $scenario === 'stale' ? '43' : '42',
                str_contains($sql, 'FROM emission') => $scenario === 'physical' ? '1' : '0',
                default => $scenario === 'reserved' ? '1' : '0',
            };
        });
        $reads = 0;
        $db->method('iterateColumn')->willReturnCallback(static function (string $sql) use ($rows, $scenario, $relative, $file, &$reads): \Traversable {
            if (str_contains($sql, 'FROM mp3_cleanup_event')) {
                self::assertStringNotContainsString('LIMIT', $sql);
                return new \ArrayIterator($rows);
            }
            if (++$reads === 2 && $scenario === 'changed') {
                self::assertSame(7, file_put_contents($file, 'changed'));
            }

            return new \ArrayIterator($scenario === 'url' ? ['https://example.test/mp3/' . $relative] : []);
        });
        $disk->expects(in_array($scenario, ['success', 'refused', 'still present', 'final failure'], true) ? self::once() : self::never())
            ->method('unlink')->willReturnCallback(static function (string $target) use ($file, $lock, $scenario): bool {
                self::assertSame($file, $target);
                self::assertTrue($lock->isHeldByCurrentProcess());

                return $scenario === 'still present' || (in_array($scenario, ['success', 'final failure'], true) && unlink($target));
            });

        $cleanup = new Mp3DeferredCleanup(
            $lock, new Mp3DeferredCleanupPreconditions($lock, new Mp3CleanupResolutionDecision(), $library),
            new Mp3CleanupIdentityVerifier($library), $disk, $library, $journal,
        );
        $urls = new AudioLocalUrlResolver(['https://example.test/mp3']);
        $check = fn () => $cleanup->remove($db, 17, ['issue_id' => 17, 'last_event_id' => 42], $urls, $requestId, 19);

        try {
            if ($scenario === 'absent') {
                unlink($file);
            } elseif ($scenario === 'different' || $scenario === 'symlink') {
                rename($file, $file . '.original');
                if ($scenario === 'different') {
                    file_put_contents($file, 'audio');
                } else {
                    symlink($file . '.original', $file);
                }
            }
            if ($scenario === 'busy') {
                $lock->synchronized(static fn () => null);
                $holder = fopen($root . '/trash/.storage.lock', 'rb');
                self::assertIsResource($holder);
                try {
                    self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
                    self::assertSame($expected, $check()->value);
                } finally {
                    fclose($holder);
                }
            } elseif (!$observationOnly) {
                self::assertSame($expected, $check()->value);
            }
            if ($scenario === 'final failure' || $observationOnly) {
                if ($observationOnly) {
                    $lock->synchronized(static fn () => null);
                }
                $pending = ['state' => 'pending', 'result_code' => null, 'issue_id' => 17, 'expected_event_id' => 42];
                $journal->expects(self::once())->method('find')->with($db, $requestId)->willReturn($pending);
                self::assertSame(
                    ['attempt' => $pending, 'needs_reconciliation' => true,
                        'observation' => $observationOnly ? $expected : 'absent_now'],
                    $cleanup->reconcile($db, $requestId, $urls),
                );
            }
            self::assertSame(0, $transactionLevel);
            self::assertFalse($lock->isHeldByCurrentProcess());
            self::assertSame(!in_array($scenario, ['success', 'absent', 'final failure'], true), file_exists($file));
        } finally {
            if (is_link($file)) {
                unlink($file);
            }
        }
    }

    public static function cases(): iterable
    {
        yield ['success', 'removed'];
        yield ['absent', 'already_absent'];
        yield ['physical', 'reference_active'];
        yield ['url', 'reference_active'];
        yield ['reserved', 'reservation_active'];
        yield ['stale', 'stale_view'];
        yield ['resolved', 'already_resolved'];
        yield ['missing', 'missing_proof'];
        yield ['unusable', 'unusable_proof'];
        yield ['conflict', 'conflicting_proofs'];
        yield ['different', 'different'];
        yield ['changed', 'different'];
        yield ['symlink', 'invalid_path'];
        yield ['refused', 'removal_refused'];
        yield ['still present', 'indeterminate'];
        yield ['busy', 'lock_unavailable'];
        yield ['insert failure', 'request_not_recorded'];
        yield ['initial uncertain', 'request_uncertain'];
        yield ['duplicate', 'duplicate_or_active_request'];
        yield ['concurrent', 'duplicate_or_active_request'];
        yield ['final failure', 'outcome_uncertain'];
        yield ['pending before removal', 'identity_present'];
    }
}
