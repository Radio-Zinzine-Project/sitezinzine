<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Mp3AdministrativeJournal;
use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3RestoreFailure;
use App\Service\{Mp3DiskIo, Mp3RestoreService, Mp3StorageLock, Mp3TrashDisk, Mp3TrashPath};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class Mp3RestoreServiceTest extends TestCase
{
    private const PATH = 'TST/2026/Émission.mp3';
    private const URL = 'https://example.test/uploads/emissionsMp3/TST/2026/Émission.mp3';
    private const DATA = 'synthetic restore witness';
    private string $root;
    private string $public;
    private string $private;
    private Mp3TrashOperation $operation;
    private Mp3StorageLock $lock;
    private Phase $durable;
    private array $row = ['thumbnail_mp3' => null, 'url' => null, 'deleted_at' => null];
    private array $draft;
    private int $level = 0;
    private int $commits = 0;
    private const REQUEST = 'dddddddddddddddddddddddddddddddd';
    private const HISTORY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private array $events = [];
    private array $pending = [];

    protected function setUp(): void
    {
        $this->root = Mp3TestStorage::root() . '/restore-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir(array_map(fn (string $path) => $this->root . '/' . $path,
            ['library/TST/2026', 'trash', 'work', 'public']), 0700);
        $at = new \DateTimeImmutable();
        $this->operation = new Mp3TrashOperation(str_repeat('a', 32), 42, self::PATH, self::URL,
            strlen(self::DATA), hash('sha256', self::DATA), 7, $at);
        $this->operation->confirmDisassociation($at);
        $this->operation->confirmPublicRemoval($at);
        $this->durable = Phase::EN_CORBEILLE;
        $this->public = $this->root . '/library/' . self::PATH;
        $this->private = $this->root . '/trash/' . $this->operation->trashRelativePath();
        file_put_contents($this->private, self::DATA);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function restore(string $fault, ?string $expectedLastAttemptId = null): Mp3TrashOperation
    {
        $library = $this->root . '/library';
        $paths = new Mp3TrashPath($this->root . '/trash', $library, $this->root . '/work', $this->root . '/public');
        $this->lock = new Mp3StorageLock($paths, 0);
        $io = $this->createMock(Mp3DiskIo::class);
        $io->method('link')->willReturnCallback(function (string $from, string $to) use ($fault): bool {
            self::assertTrue($this->lock->isHeldByCurrentProcess());
            self::assertSame(Phase::RESTAURATION_PREPAREE, $this->durable);
            self::assertSame(0, $this->level);

            return $fault !== 'link' && link($from, $to);
        });
        $io->method('unlink')->willReturnCallback(function (string $path) use ($fault): bool {
            self::assertTrue($this->lock->isHeldByCurrentProcess());
            self::assertSame(Phase::RETRAIT_PRIVE_EN_ATTENTE, $this->durable);
            self::assertSame(self::URL, $this->row['url']);

            return $fault !== 'unlink' && unlink($path);
        });
        $db = $this->createMock(Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $db->method('isTransactionActive')->willReturnCallback(fn () => $this->level !== 0);
        $db->method('transactional')->willReturnCallback(function (callable $work) use ($db, $fault): mixed {
            $initial = $this->events === [];
            $result = $work($db);
            if ($initial && $fault === 'request commit lost') {
                throw new \RuntimeException('Request commit response lost');
            }
            return $result;
        });
        $db->method('insert')->willReturnCallback(function (string $table, array $event) use ($fault): int {
            self::assertSame('mp3_administrative_event', $table);
            self::assertSame(self::REQUEST, $event['request_id']);
            if ($event['event_type'] === 'request') {
                self::assertTrue($this->lock->isHeldByCurrentProcess());
                self::assertSame(0, $this->level);
                if ($fault === 'duplicate') {
                    throw new \Doctrine\DBAL\Exception\UniqueConstraintViolationException(new \Doctrine\DBAL\Driver\PDO\Exception('duplicate'), null);
                }
                if ($fault === 'request write') {
                    throw new \RuntimeException('Request write failed');
                }
            }
            if ($event['event_type'] === 'transition_confirmed') {
                self::assertSame(1, $this->level);
                self::assertTrue($this->lock->isHeldByCurrentProcess());
                if ($fault === 'trace confirmation' && $this->commits === 1) {
                    throw new \RuntimeException('Confirmation write failed');
                }
                $this->pending[] = $event;
            } else {
                if ($fault === 'refusal write' && $event['event_type'] === 'refusal') {
                    throw new \RuntimeException('Refusal write failed');
                }
                $this->events[] = $event;
            }
            return 1;
        });
        $db->method('getTransactionNestingLevel')->willReturnCallback(fn () => $this->level);
        if (in_array($fault, ['lock', 'wrong phase', 'duplicate', 'request write', 'request commit lost'], true)) {
            $db->expects(self::never())->method('beginTransaction');
        }
        $db->method('beginTransaction')->willReturnCallback(function (): void {
            self::assertTrue($this->lock->isHeldByCurrentProcess());
            $this->level = 1;
            $this->draft = $this->row;
        });
        $db->method('iterateColumn')->willReturnCallback(static fn (): iterable =>
            in_array($fault, ['alias', 'deleted_alias'], true) ? new \ArrayIterator(['https://legacy.example.test/audio/' . self::PATH])
                : new \ArrayIterator()
        );
        $db->method('fetchOne')->willReturnCallback(static function (string $sql) use ($fault): int {
            if (str_starts_with($sql, 'SELECT emission_id')) { return 42; }
            return (int) (($fault === 'url' && str_contains($sql, 'FROM emission'))
                || ($fault === 'reserved' && str_contains($sql, 'active_marker')));
        });
        $db->method('fetchAssociative')->willReturnCallback(function (string $sql) use ($fault): array|false {
            self::assertStringContainsString('FOR UPDATE', $sql);
            if (str_contains($sql, 'mp3_administrative_event')) {
                return array_intersect_key($this->events[0], array_flip(['request_id', 'operation_id', 'actor_id', 'attempt_id', 'action', 'expected_phase']));
            }
            if (str_contains($sql, 'mp3_trash_operation')) {
                return ['phase' => $this->durable->value, 'blocked' => 0,
                    'last_attempt_id' => array_key_last($this->operation->restorationAttempts())];
            }
            return in_array($fault, ['missing emission', 'refusal write'], true) ? false : $this->row;
        });
        $db->method('update')->willReturnCallback(function (string $table, array $values, array $criteria): int {
            self::assertSame('emission', $table);
            self::assertSame(['id' => 42], $criteria);
            $this->draft = array_replace($this->row, $values);
            return 1;
        });
        $db->method('commit')->willReturnCallback(function () use ($fault): void {
            if (++$this->commits === 2 && $fault === 'commit lost') {
                throw new \RuntimeException('commit response lost before recording');
            }
            $this->row = $this->draft;
            $this->durable = $this->operation->phase();
            array_push($this->events, ...$this->pending);
            $this->pending = [];
            $this->level = 0;
            if (($this->commits === 1 && $fault === 'reservation lost')
                || ($this->commits === 2 && $fault === 'committed lost')) {
                throw new \RuntimeException('commit response lost after recording');
            }
        });
        $db->method('rollBack')->willReturnCallback(function (): void { $this->level = 0; $this->pending = []; });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($db);
        $em->method('isOpen')->willReturn(true);
        $em->method('getUnitOfWork')->willReturn($this->createMock(UnitOfWork::class));
        $em->method('find')->with(Mp3TrashOperation::class, $this->operation->id(), LockMode::PESSIMISTIC_WRITE)
            ->willReturn($this->operation);
        $em->method('flush')->willReturnCallback(function () use ($fault): void {
            if (($fault === 'flush' && $this->commits === 1) || ($fault === 'final flush' && $this->commits === 2)) {
                throw new \RuntimeException('flush failed');
            }
        });
        $service = new Mp3RestoreService($this->lock, new Mp3TrashDisk($library, $paths, $io), new Mp3AdministrativeJournal(), new \App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver([dirname(self::URL), 'https://legacy.example.test/audio']));
        $call = fn () => $service->restore($em, $this->operation->id(), 9,
            $fault === 'wrong phase' ? Phase::RESTAUREE : Phase::EN_CORBEILLE, $expectedLastAttemptId, self::REQUEST);
        if ($fault === 'lock') {
            $this->lock->synchronized(static fn () => null);
            $holder = fopen($this->root . '/trash/.storage.lock', 'r+b');
            self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
            try { return $call(); } finally { fclose($holder); }
        }
        return $call();
    }

    public function testRestorationConfirmsExactAssociationBeforeRemovingPrivateName(): void
    {
        $operation = $this->restore('success');
        self::assertSame(Phase::RESTAUREE, $this->durable);
        self::assertSame(self::PATH, $this->row['thumbnail_mp3']);
        self::assertSame(self::URL, $this->row['url']);
        self::assertSame('restored', $operation->restorationAttempts()[$operation->lastAttemptId()]['outcome']);
        self::assertSame(['request', 'transition_confirmed', 'transition_confirmed', 'transition_confirmed'], array_column($this->events, 'event_type'));
        self::assertSame([null, 'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE'], array_column($this->events, 'observed_phase'));
        self::assertSame(array_fill(0, 4, $operation->lastAttemptId()), array_column($this->events, 'attempt_id'));
        self::assertNotContains($operation->lastAttemptId(), [self::REQUEST, $operation->id()]);
        self::assertSame(self::DATA, file_get_contents($this->public));
        self::assertFileDoesNotExist($this->private);
        self::assertFalse($this->lock->isHeldByCurrentProcess());
    }

    public function testRestorationAfterCancellationAcceptsTheExactLastAttempt(): void
    {
        $this->addCancelledAttempt();
        $operation = $this->restore('success', self::HISTORY);
        self::assertSame(Phase::RESTAUREE, $operation->phase());
        self::assertSame('cancelled', $operation->restorationAttempts()[self::HISTORY]['outcome']);
        self::assertNotSame(self::HISTORY, $operation->lastAttemptId());
    }

    private function addCancelledAttempt(): void
    {
        $at = new \DateTimeImmutable();
        $this->operation->prepareRestoration(self::HISTORY, 8, $at);
        $this->operation->cancelRestoration(self::HISTORY, $at);
    }

    /** @dataProvider failures */
    public function testFailureConservesDurableStateAndSafeName(
        string $fault, Phase $phase, ?string $public, ?string $private, bool $uncertain = false, ?string $reason = null, ?string $outcome = null
    ): void {
        if ($fault === 'deleted') { $this->row['deleted_at'] = '2026-11-19 00:00:00'; }
        if ($fault === 'new mp3') { $this->row['thumbnail_mp3'] = 'other.mp3'; }
        if ($fault === 'new url') { $this->row['url'] = 'https://elsewhere.test/new.mp3'; }
        if ($fault === 'collision') { file_put_contents($this->public, 'occupant'); }
        if ($fault === 'missing private') { unlink($this->private); }
        if ($fault === 'changed private') { file_put_contents($this->private, 'wrong'); }
        if ($fault === 'already prepared') {
            $this->operation->prepareRestoration(str_repeat('b', 32), 8, new \DateTimeImmutable());
            $this->durable = Phase::RESTAURATION_PREPAREE;
        }
        if ($fault === 'stale historical') { $this->addCancelledAttempt(); }
        $originalRow = $this->row;
        try {
            $this->restore($fault);
            self::fail('Failure expected.');
        } catch (Mp3RestoreFailure $error) {
            self::assertSame($uncertain, $error->commitUncertain);
            self::assertSame($this->operation->id(), $error->operationId);
            self::assertSame(self::REQUEST, $error->requestId);
            if ($reason !== null) {
                self::assertSame($reason, $error->reason);
            }
            if ($fault === 'duplicate') {
                self::assertNull($error->attemptId);
            }
            if ($fault === 'refusal write') {
                self::assertInstanceOf(\DomainException::class, $error->getPrevious());
                self::assertSame('Refusal write failed', $error->traceFailure?->getMessage());
            }
        }
        if ($outcome !== null) {
            self::assertSame($outcome, $this->events[array_key_last($this->events)]['event_type']);
        }
        if (in_array($fault, ['wrong phase', 'duplicate', 'request write', 'lock'], true)) {
            self::assertSame([], $this->events);
        }
        if ($fault === 'trace confirmation') {
            self::assertSame(['request', 'transition_confirmed', 'failure'], array_column($this->events, 'event_type'));
        }
        self::assertSame($phase, $this->durable);
        $associationCommitted = $phase === Phase::RETRAIT_PRIVE_EN_ATTENTE;
        self::assertSame($associationCommitted ? self::PATH : $originalRow['thumbnail_mp3'], $this->row['thumbnail_mp3']);
        self::assertSame($associationCommitted ? self::URL : $originalRow['url'], $this->row['url']);
        self::assertSame($public, is_file($this->public) ? file_get_contents($this->public) : null);
        self::assertSame($private, is_file($this->private) ? file_get_contents($this->private) : null);
        self::assertSame(0, $this->level);
        self::assertTrue($this->lock->synchronized(static fn () => true));
    }

    public static function failures(): iterable
    {
        foreach (['missing emission', 'deleted', 'new mp3', 'new url', 'url', 'alias', 'reserved', 'lock'] as $fault) {
            yield $fault => [$fault, Phase::EN_CORBEILLE, null, self::DATA];
        }
        yield 'wrong phase' => ['wrong phase', Phase::EN_CORBEILLE, null, self::DATA, false, 'refused'];
        yield 'stale null after cancellation' => ['stale historical', Phase::EN_CORBEILLE, null, self::DATA, false, 'confirmation_obsolete', 'refusal'];
        yield 'duplicate request' => ['duplicate', Phase::EN_CORBEILLE, null, self::DATA, false, 'duplicate_request'];
        yield 'unavailable trace' => ['request write', Phase::EN_CORBEILLE, null, self::DATA, false, 'trace_unavailable'];
        yield 'request commit uncertain' => ['request commit lost', Phase::EN_CORBEILLE, null, self::DATA, true, 'commit_uncertain', 'uncertain'];
        yield 'confirmation rolls back' => ['trace confirmation', Phase::RESTAURATION_PREPAREE, self::DATA, self::DATA, false, 'trace_unavailable', 'failure'];
        yield 'secondary trace failure' => ['refusal write', Phase::EN_CORBEILLE, null, self::DATA, false, 'conflict', 'request'];
        yield 'collision' => ['collision', Phase::EN_CORBEILLE, 'occupant', self::DATA];
        yield 'missing private' => ['missing private', Phase::EN_CORBEILLE, null, null];
        yield 'changed private' => ['changed private', Phase::EN_CORBEILLE, null, 'wrong'];
        yield 'already prepared' => ['already prepared', Phase::RESTAURATION_PREPAREE, null, self::DATA];
        yield 'link' => ['link', Phase::RESTAURATION_PREPAREE, null, self::DATA, false, 'interrupted', 'failure'];
        yield 'reservation commit lost' => ['reservation lost', Phase::RESTAURATION_PREPAREE, null, self::DATA, true];
        yield 'flush' => ['flush', Phase::RESTAURATION_PREPAREE, self::DATA, self::DATA];
        yield 'commit lost' => ['commit lost', Phase::RESTAURATION_PREPAREE, self::DATA, self::DATA, true, 'commit_uncertain', 'uncertain'];
        yield 'committed lost' => ['committed lost', Phase::RETRAIT_PRIVE_EN_ATTENTE, self::DATA, self::DATA, true];
        yield 'unlink' => ['unlink', Phase::RETRAIT_PRIVE_EN_ATTENTE, self::DATA, self::DATA];
        yield 'final flush' => ['final flush', Phase::RETRAIT_PRIVE_EN_ATTENTE, self::DATA, null];
    }
}
