<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Mp3AdministrativeJournal;
use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3TrashRecoveryFailure;
use App\Service\{Mp3DiskIo, Mp3StorageLock, Mp3TrashDisk, Mp3TrashPath, Mp3TrashRecovery};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

final class Mp3RestoreRecoveryTest extends TestCase
{
    private const PATH = 'witness.mp3';
    private const BASE = 'https://example.test/uploads/emissionsMp3';
    private const URL = self::BASE . '/' . self::PATH;
    private const DATA = 'synthetic';
    private const ATTEMPT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private string $root;

    protected function setUp(): void
    {
        $this->root = Mp3TestStorage::root() . '/restore-recovery-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir(array_map(fn (string $dir) => $this->root . '/' . $dir,
            ['library', 'work', 'trash', 'public']), 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** @dataProvider scenarios */
    public function testRestorationRecovery(string $fault, Phase $expected, string $files, ?string $failure,
        Phase $final, ?string $publicAfter, ?string $privateAfter, ?string $blockCode = null): void
    {
        $library = $this->root . '/library';
        $paths = new Mp3TrashPath($this->root . '/trash', $library, $this->root . '/work', $this->root . '/public');
        $lock = new Mp3StorageLock($paths, 0);
        $at = new \DateTimeImmutable();
        $operation = new Mp3TrashOperation(str_repeat('c', 32), 42, self::PATH, self::URL,
            strlen(self::DATA), hash('sha256', self::DATA), 7, $at);
        $operation->confirmDisassociation($at);
        $operation->confirmPublicRemoval($at);
        $operation->prepareRestoration(self::ATTEMPT, 8, $at);
        if (in_array($fault, ['cancelled', 'replaced'], true)) {
            $operation->cancelRestoration(self::ATTEMPT, $at);
        }
        if ($fault === 'replaced') {
            $operation->prepareRestoration(self::OTHER, 10, $at);
        }
        $pending = $expected === Phase::RETRAIT_PRIVE_EN_ATTENTE && $fault !== 'stale phase';
        if ($pending || $fault === 'finished') {
            $operation->confirmAssociation(self::ATTEMPT, $at);
        }
        if ($fault === 'finished') {
            $operation->confirmPrivateRemoval(self::ATTEMPT, $at);
        }
        if ($fault === 'blocked') {
            $operation->block($expected, 'manual_review', $at);
        }
        $blockVersion = null;
        if ($blockCode !== null) {
            $operation->block($operation->phase(), $blockCode, new \DateTimeImmutable());
            $blockVersion = $fault === 'stale version' ? '2020-01-01 00:00:00.000000' : $operation->blockVersion();
        }
        $durable = $operation->phase();
        $public = $library . '/' . self::PATH;
        $private = $this->root . '/trash/' . $operation->trashRelativePath();
        if ($files !== 'public') {
            file_put_contents($private, $files === 'wrong hash' ? 'incorrect' : self::DATA);
        }
        if ($files === 'pair') {
            self::assertTrue(link($private, $public));
        } elseif (in_array($files, ['public', 'distinct', 'collision'], true)) {
            file_put_contents($public, $files === 'collision' ? 'occupant' : self::DATA);
        }
        $row = ['thumbnail_mp3' => $pending || $fault === 'finished' ? self::PATH : null,
            'url' => match (true) {
                $fault === 'new audio' => 'https://elsewhere.test/audio',
                $pending || $fault === 'finished' => self::URL,
                default => null,
            }, 'deleted_at' => $fault === 'deleted' ? '2026-11-19' : null];
        $original = $draft = $row;
        $level = $fault === 'external transaction' ? 1 : 0;
        $commits = 0;
        $emissionLocked = false;
        $db = $this->createMock(Connection::class);
        $requestId = str_repeat('f', 32);
        $trace = [];
        $db->method('isAutoCommit')->willReturn(true);
        $db->method('isTransactionActive')->willReturnCallback(function () use (&$level): bool { return $level !== 0; });
        $db->method('transactional')->willReturnCallback(static fn (callable $work) => $work($db));
        $db->method('insert')->willReturnCallback(function (string $table, array $event) use (
            $requestId, $fault, $lock, &$trace, &$level,
        ): int {
            self::assertSame('mp3_administrative_event', $table);
            self::assertSame(['request_id' => $requestId], array_intersect_key($event, ['request_id' => true]));
            if (in_array($event['event_type'], ['request', 'transition_confirmed'], true)) {
                self::assertTrue($lock->isHeldByCurrentProcess());
            }
            if ($event['event_type'] === 'transition_confirmed') {
                self::assertSame(1, $level);
            }

            $trace[] = $event;
            return 1;
        });

        $db->method('getTransactionNestingLevel')->willReturnCallback(function () use (&$level): int { return $level; });
        if (in_array($fault, ['lock', 'external transaction'], true)) {
            $db->expects(self::never())->method('beginTransaction');
        }
        $db->method('beginTransaction')->willReturnCallback(function () use ($lock, &$level, &$emissionLocked): void {
            self::assertTrue($lock->isHeldByCurrentProcess());
            $level = 1;
            $emissionLocked = false;
        });
        $db->method('fetchAssociative')->willReturnCallback(function (string $sql) use (&$row, &$emissionLocked, &$trace): array {
            self::assertStringContainsString('FOR UPDATE', $sql);
            if (str_contains($sql, 'mp3_administrative_event')) {
                return array_intersect_key($trace[0], array_flip(['request_id', 'operation_id', 'actor_id', 'attempt_id', 'action', 'expected_phase']));
            }
            $emissionLocked = true;
            return $row;
        });
        $db->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use ($fault, $operation, $lock, &$commits): int {
            self::assertTrue($lock->isHeldByCurrentProcess());
            if (str_starts_with($sql, 'SELECT emission_id')) {
                return 42;
            }
            if (str_contains($sql, 'active_marker')) {
                self::assertSame([$operation->id(), 42, self::PATH], $params);
                return (int) ($fault === 'reserved' || ($fault === 'new reservation' && $commits === 1));
            }
            self::assertSame([42, self::PATH, self::URL, self::URL], $params);
            return (int) in_array($fault, ['url occupied', 'referenced'], true);
        });
        $db->method('update')->willReturnCallback(function (string $table, array $values, array $criteria) use (&$draft): int {
            self::assertSame('emission', $table);
            self::assertSame(['id' => 42], $criteria);
            self::assertSame(['thumbnail_mp3' => self::PATH, 'url' => self::URL], $values);
            $draft = array_replace($draft, $values);
            return 1;
        });
        $db->method('commit')->willReturnCallback(function () use ($fault, &$commits, &$level, &$durable, &$row, &$draft, $operation): void {
            ++$commits;
            if ($fault === 'commit lost' || ($fault === 'final commit lost' && $commits === 2)) {
                throw new \RuntimeException('Commit acknowledgement lost before write');
            }
            $durable = $operation->phase();
            $row = $draft;
            $level = 0;
            if ($fault === 'committed lost' || ($fault === 'final committed lost' && $commits === 2)) {
                throw new \RuntimeException('Commit acknowledgement lost after write');
            }
        });
        $db->method('rollBack')->willReturnCallback(function () use (&$level): void { $level = 0; });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($db);
        $em->method('isOpen')->willReturn(true);
        $em->method('getUnitOfWork')->willReturn($this->createMock(UnitOfWork::class));
        $em->method('find')->with(Mp3TrashOperation::class, $operation->id(), LockMode::PESSIMISTIC_WRITE)
            ->willReturnCallback(function () use (&$emissionLocked, $operation): Mp3TrashOperation {
                self::assertTrue($emissionLocked);
                return $operation;
            });
        $em->method('flush')->willReturnCallback(function () use ($fault, $operation): void {
            if ($fault === 'flush' || ($fault === 'final flush' && $operation->phase() === Phase::RESTAUREE)) {
                throw new \RuntimeException('Flush interrupted');
            }
        });
        $io = $this->createMock(Mp3DiskIo::class);
        $io->method('link')->willReturnCallback(function (string $from, string $to) use ($lock, $fault, $private, $public, &$durable): bool {
            self::assertTrue($lock->isHeldByCurrentProcess());
            self::assertSame([$private, $public], [$from, $to]);
            self::assertSame(Phase::RESTAURATION_PREPAREE, $durable);
            return $fault !== 'link' && link($from, $to);
        });
        $io->method('unlink')->willReturnCallback(function (string $path) use ($lock, $fault, $private, &$durable, &$row): bool {
            self::assertTrue($lock->isHeldByCurrentProcess());
            self::assertSame($private, $path);
            self::assertSame(Phase::RETRAIT_PRIVE_EN_ATTENTE, $durable);
            self::assertSame(self::PATH, $row['thumbnail_mp3']);
            self::assertSame(self::URL, $row['url']);
            return $fault !== 'unlink' && unlink($path);
        });
        $service = new Mp3TrashRecovery($lock, new Mp3TrashDisk($library, $paths, $io), new NullLogger(), self::BASE, new Mp3AdministrativeJournal(), new \App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver([self::BASE, 'https://legacy.example.test/audio']));
        $holder = null;
        $attempt = $fault === 'stale attempt' ? self::OTHER : self::ATTEMPT;
        try {
            if ($fault === 'lock') {
                $lock->synchronized(static fn () => null);
                $holder = fopen($this->root . '/trash/.storage.lock', 'r+b');
                self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
            }
            $restored = $service->resumeRestoration($em, $operation->id(), 9, $expected, $attempt, $requestId, $blockVersion);
            self::assertNull($failure);
            self::assertSame(Phase::RESTAUREE, $restored->phase());
            self::assertSame('restored', $restored->restorationAttempts()[self::ATTEMPT]['outcome']);
            self::assertSame(8, $restored->restorationAttempts()[self::ATTEMPT]['actor_id']);
        } catch (Mp3TrashRecoveryFailure $error) {
            self::assertSame($failure, $error->reason);
            self::assertSame($attempt, $error->attemptId);
            self::assertSame(9, $error->actorId);
            self::assertSame($requestId, $error->requestId);
        } finally {
            if (is_resource($holder)) {
                fclose($holder);
            }
        }
        if ($failure === null) {
            self::assertSame(['request', ...array_fill(0, $pending ? 1 : 2, 'transition_confirmed')], array_column($trace, 'event_type'));
        } elseif ($failure === 'commit_uncertain') {
            self::assertSame('uncertain', $trace[array_key_last($trace)]['event_type']);
        } elseif ($fault === 'stale attempt') {
            self::assertSame(['request', 'refusal'], array_column($trace, 'event_type'));
            self::assertSame(self::OTHER, $trace[1]['attempt_id']);
            self::assertSame(Phase::RESTAURATION_PREPAREE->value, $trace[1]['observed_phase']);
        }
        if ($blockCode !== null) {
            self::assertSame($blockCode, $operation->lastDiagnosticCode());
            if ($failure === null) {
                self::assertFalse($operation->isBlocked());
                self::assertStringStartsWith('unblocked_', $trace[1]['result_code']);
            }
        }
        self::assertSame($final, $durable);
        $linked = in_array($final, [Phase::RETRAIT_PRIVE_EN_ATTENTE, Phase::RESTAUREE], true);
        self::assertSame($linked ? array_replace($original, ['thumbnail_mp3' => self::PATH, 'url' => self::URL]) : $original, $row);
        self::assertSame($publicAfter, is_file($public) ? file_get_contents($public) : null);
        self::assertSame($privateAfter, is_file($private) ? file_get_contents($private) : null);
        self::assertSame($fault === 'external transaction' ? 1 : 0, $level);
        self::assertTrue($lock->synchronized(static fn () => true));
    }

    public static function scenarios(): iterable
    {
        $prepared = Phase::RESTAURATION_PREPAREE;
        $pending = Phase::RETRAIT_PRIVE_EN_ATTENTE;
        $done = Phase::RESTAUREE;
        $data = self::DATA;
        yield 'blocked restoration resolved' => ['', $prepared, 'private', null, $done, $data, null, 'private_file_missing'];
        yield 'blocked private removal resolved' => ['', $pending, 'pair', null, $done, $data, null, 'public_file_missing'];
        yield 'blocked restoration stale attempt' => ['stale attempt', $prepared, 'private', 'conflict', $prepared, null, $data, 'private_file_missing'];

        yield 'from prepared' => ['', $prepared, 'private', null, $done, $data, null];
        yield 'public already linked' => ['', $prepared, 'pair', null, $done, $data, null];
        yield 'from pending' => ['', $pending, 'pair', null, $done, $data, null];
        yield 'private already removed' => ['', $pending, 'public', null, $done, $data, null];
        yield 'stale phase' => ['stale phase', $pending, 'private', 'conflict', $prepared, null, $data];
        foreach (['stale attempt', 'replaced', 'deleted', 'new audio', 'url occupied', 'referenced', 'reserved', 'blocked'] as $fault) {
            yield $fault => [$fault, $prepared, 'private', 'conflict', $prepared, null, $data];
        }
        yield 'cancelled' => ['cancelled', $prepared, 'private', 'conflict', Phase::EN_CORBEILLE, null, $data];
        yield 'finished' => ['finished', $prepared, 'public', 'conflict', $done, $data, null];
        yield 'collision' => ['collision', $prepared, 'collision', 'conflict', $prepared, 'occupant', $data];
        yield 'distinct inodes' => ['', $pending, 'distinct', 'conflict', $pending, $data, $data];
        yield 'wrong hash' => ['', $prepared, 'wrong hash', 'conflict', $prepared, null, 'incorrect'];
        foreach (['lock', 'external transaction'] as $fault) {
            yield $fault => [$fault, $prepared, 'private', 'refused', $prepared, null, $data];
        }
        yield 'link failed' => ['link', $prepared, 'private', 'interrupted', $prepared, null, $data];
        yield 'flush failed' => ['flush', $prepared, 'private', 'interrupted', $prepared, $data, $data];
        yield 'association not committed' => ['commit lost', $prepared, 'private', 'commit_uncertain', $prepared, $data, $data];
        yield 'association committed' => ['committed lost', $prepared, 'private', 'commit_uncertain', $pending, $data, $data];
        yield 'new reservation before removal' => ['new reservation', $prepared, 'private', 'conflict', $pending, $data, $data];
        yield 'unlink failed' => ['unlink', $prepared, 'private', 'interrupted', $pending, $data, $data];
        yield 'final flush failed' => ['final flush', $prepared, 'private', 'interrupted', $pending, $data, null];
        yield 'final commit not written' => ['final commit lost', $prepared, 'private', 'commit_uncertain', $pending, $data, null];
        yield 'final commit written' => ['final committed lost', $prepared, 'private', 'commit_uncertain', $done, $data, null];
    }
}
