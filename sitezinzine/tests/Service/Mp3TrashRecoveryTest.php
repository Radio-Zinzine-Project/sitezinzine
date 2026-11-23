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

final class Mp3TrashRecoveryTest extends TestCase
{
    private const PATH = 'witness.mp3';
    private const BASE = 'https://example.test/uploads/emissionsMp3';
    private const URL = self::BASE . '/' . self::PATH;
    private const DATA = 'synthetic';
    private string $root;

    protected function setUp(): void
    {
        $this->root = Mp3TestStorage::root() . '/trash-recovery-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir(array_map(fn ($dir) => $this->root . '/' . $dir,
            ['library', 'work', 'trash', 'public']), 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** @dataProvider scenarios */
    public function testRecovery(string $fault, bool $pending, bool $hasPublic, bool $hasPrivate,
        ?string $failure, Phase $final, bool $keepsPublic, bool $keepsPrivate, ?string $blockCode = null): void
    {
        $library = $this->root . '/library';
        $paths = new Mp3TrashPath($this->root . '/trash', $library, $this->root . '/work', $this->root . '/public');
        $lock = new Mp3StorageLock($paths, 0);
        $phase = $pending ? Phase::RETRAIT_PUBLIC_EN_ATTENTE : Phase::SUPPRESSION_PREPAREE;
        $operation = new Mp3TrashOperation(str_repeat('a', 32), 42, self::PATH, self::URL, strlen(self::DATA),
            hash('sha256', self::DATA), 7, new \DateTimeImmutable());
        if ($pending) {
            $operation->confirmDisassociation(new \DateTimeImmutable());
        }
        if ($fault === 'blocked') {
            $operation->block($phase, 'manual_review', new \DateTimeImmutable());
        }
        $blockVersion = null;
        if ($blockCode !== null) {
            $operation->block($phase, $blockCode, new \DateTimeImmutable());
            $blockVersion = $fault === 'stale version' ? '2020-01-01 00:00:00.000000' : $operation->blockVersion();
        }
        $durable = $phase;
        $public = $library . '/' . self::PATH;
        $private = $this->root . '/trash/' . $operation->trashRelativePath();
        if ($hasPublic) {
            file_put_contents($public, $fault === 'wrong hash' ? 'incorrect' : self::DATA);
        }
        if ($hasPrivate) {
            $hasPublic && $fault !== 'collision' ? link($public, $private) : file_put_contents($private, self::DATA);
        }
        $row = ['thumbnail_mp3' => $pending ? null : self::PATH, 'url' => $pending ? null : self::URL,
            'deleted_at' => $fault === 'deleted' ? '2026-11-19' : null];
        if ($fault === 'association') {
            $row['url'] = 'https://another.test/audio';
        }
        $original = $row;
        $draft = $row;
        $level = $fault === 'external transaction' ? 1 : 0;
        $commits = 0;
        $emissionLocked = false;
        $db = $this->createMock(Connection::class);
        $requestId = str_repeat('f', 32);
        $trace = [];
        $db->method('isAutoCommit')->willReturn(true);
        $db->method('isTransactionActive')->willReturnCallback(function () use (&$level): bool { return $level !== 0; });
        $db->method('transactional')->willReturnCallback(static function (callable $work) use ($db, $fault, &$trace): mixed {
            $isInitial = $trace === [];
            $result = $work($db);
            if ($isInitial && $fault === 'request commit lost') {
                throw new \RuntimeException('Initial commit acknowledgement lost');
            }
            return $result;
        });
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

            if ($event['event_type'] === 'request' && $fault === 'duplicate request') {
                throw new \Doctrine\DBAL\Exception\UniqueConstraintViolationException(
                    new \Doctrine\DBAL\Driver\PDO\Exception('duplicate'), null,
                );
            }
            if (($event['event_type'] === 'request' && $fault === 'request write')
                || ($event['event_type'] === 'transition_confirmed' && $fault === 'trace confirmation')
                || ($event['event_type'] === 'refusal' && $fault === 'refusal write')) {
                throw new \RuntimeException('Synthetic trace write failure');
            }

            $trace[] = $event;
            return 1;
        });

        if (in_array($fault, ['lock', 'external transaction', 'terminal phase'], true)) {
            $db->expects(self::never())->method('beginTransaction');
        }
        $db->method('getTransactionNestingLevel')->willReturnCallback(function () use (&$level): int { return $level; });
        $db->method('beginTransaction')->willReturnCallback(function () use ($lock, &$level, &$emissionLocked): void {
            self::assertTrue($lock->isHeldByCurrentProcess());
            $level = 1;
            $emissionLocked = false;
        });
        $db->method('iterateColumn')->willReturnCallback(static fn (): iterable =>
            in_array($fault, ['alias', 'deleted_alias'], true) ? new \ArrayIterator(['https://legacy.example.test/audio/' . self::PATH])
                : new \ArrayIterator()
        );
        $db->method('fetchOne')->willReturnCallback(static function (string $sql, array $params) use ($fault, $operation, &$commits): int {
            if (str_starts_with($sql, 'SELECT emission_id')) {
                return 42;
            }
            if (str_contains($sql, 'active_marker')) {
                self::assertSame([$operation->id(), 42, self::PATH], $params);
                return (int) ($fault === 'reservation' || ($fault === 'new reservation' && $commits === 1));
            }
            self::assertSame([42, self::PATH, self::URL, self::URL], $params);
            return (int) ($fault === 'reference');
        });
        $db->method('fetchAssociative')->willReturnCallback(function (string $sql) use (&$row, &$emissionLocked, $fault, &$trace): array|false {
            self::assertStringContainsString('FOR UPDATE', $sql);
            if (str_contains($sql, 'mp3_administrative_event')) {
                return array_intersect_key($trace[0], array_flip(['request_id', 'operation_id', 'actor_id', 'attempt_id', 'action', 'expected_phase']));
            }
            $emissionLocked = true;
            return $fault === 'missing' ? false : ($fault === 'refusal write' ? array_replace($row, ['deleted_at' => '2026-11-19']) : $row);
        });
        $db->method('update')->willReturnCallback(function (string $table, array $values, array $criteria) use (&$draft): int {
            self::assertSame('emission', $table);
            self::assertSame(['id' => 42], $criteria);
            self::assertSame(['thumbnail_mp3' => null, 'url' => null], $values);
            $draft = array_replace($draft, $values);
            return 1;
        });
        $db->method('commit')->willReturnCallback(function () use ($fault, &$commits, &$level, &$durable, &$row, &$draft, $operation): void {
            ++$commits;
            if ($fault === 'commit lost') {
                throw new \RuntimeException('Commit acknowledgement lost before write');
            }
            $durable = $operation->phase();
            $row = $draft;
            $level = 0;
            if ($fault === 'committed lost' || ($fault === 'final commit lost' && $commits === 2)) {
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
            if ($fault === 'flush' || ($fault === 'final flush' && $operation->phase() === Phase::EN_CORBEILLE)) {
                throw new \RuntimeException('Flush interrupted');
            }
        });
        $io = $this->createMock(Mp3DiskIo::class);
        $io->method('link')->willReturnCallback(function (string $from, string $to) use ($lock, $fault, $private, &$durable): bool {
            self::assertTrue($lock->isHeldByCurrentProcess());
            self::assertSame($private, $to);
            self::assertSame(Phase::SUPPRESSION_PREPAREE, $durable);
            return $fault !== 'link' && link($from, $to);
        });
        $io->method('unlink')->willReturnCallback(function (string $path) use ($lock, $fault, $public, &$durable, &$row): bool {
            self::assertTrue($lock->isHeldByCurrentProcess());
            self::assertSame($public, $path);
            self::assertSame(Phase::RETRAIT_PUBLIC_EN_ATTENTE, $durable);
            self::assertNull($row['thumbnail_mp3']);
            self::assertNull($row['url']);
            return $fault !== 'unlink' && unlink($path);
        });
        $service = new Mp3TrashRecovery($lock, new Mp3TrashDisk($library, $paths, $io), new NullLogger(), self::BASE, new Mp3AdministrativeJournal(), new \App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver([self::BASE, 'https://legacy.example.test/audio']));
        $holder = null;
        try {
            if ($fault === 'lock') {
                $lock->synchronized(static fn () => null);
                $holder = fopen($this->root . '/trash/.storage.lock', 'r+b');
                self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
            }
            $expected = match ($fault) {
                'stale phase' => Phase::RETRAIT_PUBLIC_EN_ATTENTE,
                'terminal phase' => Phase::EN_CORBEILLE,
                default => $phase,
            };
            self::assertSame(Phase::EN_CORBEILLE, $service->resumeDeletion($em, $operation->id(), 9, $expected, $requestId, $blockVersion));
            self::assertNull($failure);
        } catch (Mp3TrashRecoveryFailure $error) {
            self::assertSame($failure, $error->reason);
            self::assertSame($operation->id(), $error->operationId);
            self::assertSame(9, $error->actorId);
            self::assertSame($requestId, $error->requestId);
            if ($fault === 'refusal write') {
                self::assertInstanceOf(\DomainException::class, $error->getPrevious());
                self::assertSame('Synthetic trace write failure', $error->traceFailure?->getMessage());
            }
        } finally {
            if (is_resource($holder)) { fclose($holder); }
        }
        if ($failure === null) {
            self::assertSame(['request', ...array_fill(0, $pending ? 1 : 2, 'transition_confirmed')], array_column($trace, 'event_type'));
        } elseif ($failure === 'commit_uncertain') {
            self::assertSame('uncertain', $trace[array_key_last($trace)]['event_type']);
        } elseif ($fault === 'stale phase') {
            self::assertSame(['request', 'refusal'], array_column($trace, 'event_type'));
            self::assertSame(Phase::SUPPRESSION_PREPAREE->value, $trace[1]['observed_phase']);
        }
        if ($blockCode !== null) {
            self::assertSame($blockCode, $operation->lastDiagnosticCode());
            if ($failure === null) {
                self::assertFalse($operation->isBlocked());
                self::assertStringStartsWith('unblocked_', $trace[1]['result_code']);
            }
        }
        self::assertSame($final, $durable);
        self::assertSame($final === Phase::SUPPRESSION_PREPAREE ? $original : array_replace($original,
            ['thumbnail_mp3' => null, 'url' => null]), $row);
        self::assertSame($keepsPublic, is_file($public));
        self::assertSame($keepsPrivate, is_file($private));
        if ($keepsPrivate) { self::assertSame(self::DATA, file_get_contents($private)); }
        self::assertSame($fault === 'external transaction' ? 1 : 0, $level);
        self::assertTrue($lock->synchronized(static fn () => true));
    }

    public static function scenarios(): iterable
    {
        $prepared = Phase::SUPPRESSION_PREPAREE;
        $pending = Phase::RETRAIT_PUBLIC_EN_ATTENTE;
        $done = Phase::EN_CORBEILLE;
        yield 'blocked deletion resolved' => ['', false, true, false, null, $done, false, true, 'public_file_missing'];
        yield 'blocked public removal resolved' => ['', true, true, true, null, $done, false, true, 'private_file_missing'];
        yield 'blocked deletion still missing' => ['', false, false, false, 'conflict', $prepared, false, false, 'public_file_missing'];
        yield 'blocked deletion unknown' => ['', false, true, false, 'conflict', $prepared, true, false, 'file_missing'];
        yield 'blocked deletion stale version' => ['stale version', false, true, false, 'conflict', $prepared, true, false, 'public_file_missing'];
        yield 'blocked deletion stale phase' => ['stale phase', false, true, false, 'conflict', $prepared, true, false, 'public_file_missing'];
        yield 'alias appears after disassociation' => ['alias', true, true, true, 'conflict', $pending, true, true];
        yield 'blocked deletion referenced' => ['reference', false, true, false, 'conflict', $prepared, true, false, 'public_file_missing'];
        yield 'blocked deletion reserved' => ['reservation', false, true, false, 'conflict', $prepared, true, false, 'public_file_missing'];
        yield 'blocked deletion wrong hash' => ['wrong hash', false, true, false, 'conflict', $prepared, true, false, 'public_file_missing'];
        yield 'blocked deletion collides' => ['collision', true, true, true, 'conflict', $pending, true, true, 'private_file_missing'];
        yield 'blocked deletion rollback' => ['trace confirmation', false, true, false, 'trace_unavailable', $prepared, true, true, 'public_file_missing'];
        yield 'blocked deletion commit uncertain' => ['commit lost', false, true, false, 'commit_uncertain', $prepared, true, true, 'public_file_missing'];
        yield 'blocked deletion duplicate' => ['duplicate request', false, true, false, 'duplicate_request', $prepared, true, false, 'public_file_missing'];

        yield 'from reservation' => ['', false, true, false, null, $done, false, true];
        yield 'private already linked' => ['', false, true, true, null, $done, false, true];
        yield 'from public removal pending' => ['', true, true, true, null, $done, false, true];
        yield 'public already removed' => ['', true, false, true, null, $done, false, true];
        foreach (['stale phase', 'deleted', 'missing', 'association', 'reference', 'reservation', 'blocked'] as $fault) {
            yield $fault => [$fault, false, true, false, 'conflict', $prepared, true, false];
        }
        foreach (['lock', 'external transaction', 'terminal phase'] as $fault) {
            yield $fault => [$fault, false, true, false, 'refused', $prepared, true, false];
        }
        yield 'reservation checked again before removal' => ['reservation', true, true, true, 'conflict', $pending, true, true];
        yield 'reservation appeared before removal' => ['new reservation', false, true, false, 'conflict', $pending, true, true];
        yield 'collision' => ['collision', true, true, true, 'conflict', $pending, true, true];
        yield 'wrong hash' => ['wrong hash', false, true, false, 'conflict', $prepared, true, false];
        yield 'duplicate request' => ['duplicate request', false, true, false, 'duplicate_request', $prepared, true, false];
        yield 'request commit uncertain' => ['request commit lost', false, true, false, 'commit_uncertain', $prepared, true, false];
        yield 'request write failed' => ['request write', false, true, false, 'trace_unavailable', $prepared, true, false];
        yield 'confirmation write failed' => ['trace confirmation', false, true, false, 'trace_unavailable', $prepared, true, true];
        yield 'refusal write failed' => ['refusal write', false, true, false, 'conflict', $prepared, true, false];
        yield 'link failed' => ['link', false, true, false, 'interrupted', $prepared, true, false];
        yield 'flush failed' => ['flush', false, true, false, 'interrupted', $prepared, true, true];
        yield 'no commit' => ['commit lost', false, true, false, 'commit_uncertain', $prepared, true, true];
        yield 'commit succeeded' => ['committed lost', false, true, false, 'commit_uncertain', $pending, true, true];
        yield 'unlink failed' => ['unlink', false, true, false, 'interrupted', $pending, true, true];
        yield 'final flush failed' => ['final flush', false, true, false, 'interrupted', $pending, false, true];
        yield 'final commit uncertain' => ['final commit lost', false, true, false, 'commit_uncertain', $done, false, true];
    }
}
