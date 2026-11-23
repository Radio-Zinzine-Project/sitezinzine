<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3TrashCancellationFailure;
use App\Service\{Mp3AdministrativeJournal, Mp3DiskIo, Mp3StorageLock, Mp3TrashCancellation, Mp3TrashDisk, Mp3TrashPath};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

final class Mp3TrashCancellationTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testCancellation(string $fault, bool $restoring, ?string $reason): void
    {
        $root = Mp3TestStorage::root() . '/cancel-' . bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir([$root . '/library', $root . '/trash', $root . '/work', $root . '/public'], 0700);
        $lock = new Mp3StorageLock(new Mp3TrashPath($root . '/trash', $root . '/library', $root . '/work', $root . '/public'), 0);
        $holder = null;
        try {
            $at = new \DateTimeImmutable();
            $id = str_repeat('a', 32);
            $attempt = str_repeat('b', 32);
            $request = str_repeat('c', 32);
            $base = 'https://example.test/uploads/emissionsMp3';
            $path = $fault === 'unknown absence' ? 'missing/witness.mp3' : 'witness.mp3';
            $url = $base . '/' . $path;
            $operation = new Mp3TrashOperation($id, 42, $path, $url, 9, hash('sha256', 'synthetic'), 7, $at);
            if ($restoring) {
                $operation->confirmDisassociation($at);
                $operation->confirmPublicRemoval($at);
                $operation->prepareRestoration($attempt, 8, $at);
            }
            $original = $operation->phase();
            $blockVersion = null;
            if ($fault === 'resolved block') {
                $operation->block($original, $restoring ? 'private_file_missing' : 'public_file_missing', $at);
                $blockVersion = $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            }
            if ($fault === 'blocked') {
                $operation->block($original, 'manual_review', $at);
            }
            if ($fault === 'stale phase') {
                $operation->confirmDisassociation($at);
                $original = $operation->phase();
            }
            $public = $root . '/library/' . $path;
            $private = $root . '/trash/' . $operation->trashRelativePath();
            [$keep, $absent] = $restoring ? [$private, $public] : [$public, $private];
            file_put_contents($keep, $fault === 'hash' ? 'incorrect' : 'synthetic');
            if ($fault === 'second link') {
                self::assertTrue(link($keep, $absent));
            } elseif ($fault === 'collision') {
                file_put_contents($absent, 'occupied');
            } elseif ($fault === 'symlink') {
                self::assertTrue(symlink($keep, $absent));
            }
            $before = array_map(static fn ($name) => is_link($name) ? readlink($name) : (is_file($name) ? file_get_contents($name) : null), [$public, $private]);
            $io = $this->createMock(Mp3DiskIo::class);
            $io->expects(self::never())->method('link');
            $io->expects(self::never())->method('unlink');
            $db = $this->createMock(Connection::class);
            $level = 0;
            $durable = $original;
            $events = [];
            $staged = [];
            $emissionLocked = false;
            $db->method('isAutoCommit')->willReturn(true);
            $db->method('isTransactionActive')->willReturnCallback(function () use (&$level): bool { return $level > 0; });
            $db->method('getTransactionNestingLevel')->willReturnCallback(function () use (&$level): int { return $level; });
            $db->method('transactional')->willReturnCallback(static fn (callable $work) => $work($db));
            $db->method('beginTransaction')->willReturnCallback(function () use ($lock, &$level): void {
                self::assertTrue($lock->isHeldByCurrentProcess());
                $level = 1;
            });
            $db->method('commit')->willReturnCallback(function () use ($fault, &$level, &$durable, &$events, &$staged, $operation): void {
                if ($fault === 'commit') {
                    throw new \RuntimeException('Commit response lost');
                }
                $durable = $operation->phase();
                array_push($events, ...$staged);
                $level = 0;
            });
            $db->method('rollBack')->willReturnCallback(function () use (&$level, &$staged): void { $level = 0; $staged = []; });
            $db->expects(self::never())->method('update');
            $db->method('insert')->willReturnCallback(function (string $table, array $row) use ($fault, $lock, &$events, &$staged): int {
                self::assertSame('mp3_administrative_event', $table);
                if ($row['event_type'] === 'request') {
                    self::assertTrue($lock->isHeldByCurrentProcess());
                    if ($fault === 'duplicate') {
                        throw new \Doctrine\DBAL\Exception\UniqueConstraintViolationException(new \Doctrine\DBAL\Driver\PDO\Exception('duplicate'), null);
                    }
                    if ($fault === 'request') {
                        throw new \RuntimeException('Request write failed');
                    }
                }
                if ($row['event_type'] === 'transition_confirmed') {
                    if ($fault === 'confirmation') {
                        throw new \RuntimeException('Confirmation write failed');
                    }
                    $staged[] = $row;
                } else {
                    $events[] = $row;
                }
                return 1;
            });
            $db->method('fetchOne')->willReturnCallback(static fn (string $sql): int => match (true) {
                str_starts_with($sql, 'SELECT emission_id') => 42,
                str_contains($sql, 'active_marker') => (int) ($fault === 'reservation'),
                default => (int) ($fault === 'reference'),
            });
            $db->method('fetchAssociative')->willReturnCallback(function (string $sql) use ($restoring, $fault, $path, $url, &$events, &$emissionLocked): array {
                self::assertStringContainsString('FOR UPDATE', $sql);
                if (str_contains($sql, 'mp3_administrative_event')) {
                    return array_intersect_key($events[0], array_flip(['request_id','operation_id','actor_id','attempt_id','action','expected_phase']));
                }
                $emissionLocked = true;
                return ['thumbnail_mp3' => $restoring ? null : $path,
                    'url' => $fault === 'association' ? 'https://elsewhere.test/audio' : ($restoring ? null : $url),
                    'deleted_at' => $fault === 'deleted' ? '2026-11-19' : null];
            });
            $em = $this->createMock(EntityManagerInterface::class);
            $em->method('getConnection')->willReturn($db);
            $em->method('isOpen')->willReturn(true);
            $em->method('getUnitOfWork')->willReturn($this->createMock(UnitOfWork::class));
            $em->method('find')->willReturnCallback(function () use ($operation, &$emissionLocked): Mp3TrashOperation {
                self::assertTrue($emissionLocked);
                return $operation;
            });
            $service = new Mp3TrashCancellation($lock, new Mp3TrashDisk($root . '/library',
                new Mp3TrashPath($root . '/trash', $root . '/library', $root . '/work', $root . '/public'), $io),
                new Mp3AdministrativeJournal(), new NullLogger(), $base);
            if ($fault === 'lock') {
                $lock->synchronized(static fn () => null);
                $holder = fopen($root . '/trash/.storage.lock', 'r+b');
                self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
            }
            try {
                $result = $restoring
                    ? $service->cancelRestoration($em, $id, 9, Phase::RESTAURATION_PREPAREE, $request, $fault === 'stale attempt' ? str_repeat('d', 32) : $attempt, $blockVersion)
                    : $service->cancelDeletion($em, $id, 9, $fault === 'wrong phase' ? Phase::EN_CORBEILLE : Phase::SUPPRESSION_PREPAREE, $request, $blockVersion);
                self::assertNull($reason);
                self::assertSame($restoring ? Phase::EN_CORBEILLE : Phase::ANNULEE, $result);
                self::assertSame(['request', 'transition_confirmed'], array_column($events, 'event_type'));
                if ($blockVersion !== null) {
                    self::assertFalse($operation->isBlocked());
                    self::assertSame($restoring ? 'private_file_missing' : 'public_file_missing', $operation->lastDiagnosticCode());
                    self::assertSame('unblocked_cancelled', $events[1]['result_code']);
                }
                if ($restoring) {
                    self::assertSame('cancelled', $operation->restorationAttempts()[$attempt]['outcome']);
                    self::assertSame(8, $operation->restorationAttempts()[$attempt]['actor_id']);
                }
            } catch (Mp3TrashCancellationFailure $error) {
                self::assertSame($reason, $error->reason);
                self::assertSame($request, $error->requestId);
                self::assertSame($original, $durable);
                if (in_array($reason, ['conflict', 'commit_uncertain'], true) || $fault === 'confirmation') {
                    self::assertSame(['request', match ($reason) {
                        'conflict' => 'refusal', 'commit_uncertain' => 'uncertain', default => 'failure',
                    }], array_column($events, 'event_type'));
                } else {
                    self::assertSame([], $events);
                }
            } finally {
                if (is_resource($holder)) { fclose($holder); }
            }
            self::assertSame($before, array_map(static fn ($name) => is_link($name) ? readlink($name) : (is_file($name) ? file_get_contents($name) : null), [$public, $private]));
            self::assertTrue($lock->synchronized(static fn () => true));
        } finally {
            $fs->remove($root);
        }
    }

    public static function scenarios(): iterable
    {
        yield 'deletion' => ['', false, null];
        yield 'restoration' => ['', true, null];
        yield 'resolved deletion block' => ['resolved block', false, null];
        yield 'resolved restoration block' => ['resolved block', true, null];
        foreach (['blocked', 'stale phase', 'deleted', 'association', 'second link', 'collision', 'hash', 'symlink', 'reference', 'reservation'] as $fault) {
            yield $fault => [$fault, false, 'conflict'];
        }
        foreach (['stale attempt', 'unknown absence', 'second link', 'association'] as $fault) {
            yield 'restoration ' . $fault => [$fault, true, 'conflict'];
        }
        foreach (['wrong phase' => 'refused', 'duplicate' => 'duplicate_request', 'request' => 'trace_unavailable',
            'confirmation' => 'trace_unavailable', 'commit' => 'commit_uncertain', 'lock' => 'refused'] as $fault => $reason) {
            yield $fault => [$fault, false, $reason];
        }
    }
}
