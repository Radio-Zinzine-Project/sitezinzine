<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3TrashFailure;
use App\Repository\CategorieTagImageRepository;
use App\Service\{Mp3DiskIo, Mp3Processor, Mp3StorageLock, Mp3TrashDisk, Mp3TrashPath, Mp3TrashService};
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Vich\UploaderBundle\Storage\StorageInterface;

final class Mp3TrashServiceTest extends TestCase
{
    private const NAME = 'TST/2026/source.mp3';
    private const BASE = 'https://example.test/uploads/emissionsMp3';
    private const CONTENT = 'synthetic journal witness';
    private string $root;
    private Mp3StorageLock $lock;
    private ?Mp3TrashOperation $operation = null;
    private ?Phase $committed = null;
    private int $level = 0;
    private int $commits = 0;
    private array $row = ['thumbnail_mp3' => self::NAME, 'url' => self::BASE . '/' . self::NAME, 'deleted_at' => null];
    private array $pendingRow;

    protected function setUp(): void
    {
        $this->root = Mp3TestStorage::root() . '/trash-service-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir(array_map(fn (string $path) => $this->root . '/' . $path,
            ['library/TST/2026', 'work', 'trash', 'public']), 0700);
        file_put_contents($this->publicFile(), self::CONTENT);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function publicFile(): string
    {
        return $this->root . '/library/' . self::NAME;
    }

    private function runOperation(string $fault): Mp3TrashOperation
    {
        $library = $this->root . '/library';
        $paths = new Mp3TrashPath($this->root . '/trash', $library, $this->root . '/work', $this->root . '/public');
        $this->lock = new Mp3StorageLock($paths, 0);
        $processor = new Mp3Processor($this->createMock(StorageInterface::class), new Filesystem(),
            $this->createMock(CategorieTagImageRepository::class), $library, self::BASE, dirname(__DIR__, 2));
        $io = $this->createMock(Mp3DiskIo::class);
        $io->method('link')->willReturnCallback(function (string $from, string $to) use ($fault): bool {
            self::assertTrue($this->lock->isHeldByCurrentProcess());
            self::assertSame(Phase::SUPPRESSION_PREPAREE, $this->committed);

            return $fault !== 'prepare' && link($from, $to);
        });
        $io->method('unlink')->willReturnCallback(function (string $path) use ($fault): bool {
            self::assertTrue($this->lock->isHeldByCurrentProcess());
            self::assertSame(Phase::RETRAIT_PUBLIC_EN_ATTENTE, $this->committed);
            self::assertNull($this->row['thumbnail_mp3']);

            return $fault !== 'unlink' && unlink($path);
        });
        $db = $this->createMock(Connection::class);
        $db->method('getTransactionNestingLevel')->willReturnCallback(fn () => $this->level);
        if ($fault === 'lock') {
            $db->expects(self::never())->method('beginTransaction');
        }
        $db->method('beginTransaction')->willReturnCallback(function (): void {
            self::assertTrue($this->lock->isHeldByCurrentProcess());
            $this->level = 1;
            $this->pendingRow = $this->row;
        });
        $db->method('fetchAssociative')->willReturnCallback(function (string $sql): array {
            self::assertStringContainsString('FOR UPDATE', $sql);
            return str_contains($sql, 'mp3_trash_operation')
                ? ['phase' => $this->committed?->value, 'blocked' => 0]
                : $this->row;
        });
        $db->method('fetchOne')->willReturnCallback(function (string $sql) use ($fault): int {
            return (int) ((($fault === 'shared' || ($fault === 'shared_after_commit' && $this->commits === 2))
                    && str_contains($sql, 'FROM emission'))
                || ($fault === 'reserved' && str_contains($sql, 'active_marker')));
        });
        $db->method('iterateColumn')->willReturnCallback(static function (string $sql, array $params) use ($fault): iterable {
            self::assertSame("SELECT url FROM emission WHERE url IS NOT NULL AND url <> ? AND id <> ?", $sql);
            self::assertSame(['', 42], $params);

            return new \ArrayIterator(match ($fault) {
                'alias', 'deleted_alias' => ['https://legacy.example.test/audio/' . self::NAME],
                'safe_urls' => ['https://external.example.test/audio/' . self::NAME, 'https://legacy.example.test/audio/other.mp3'],
                default => [],
            });
        });
        $db->method('update')->willReturnCallback(function (string $table, array $values, array $criteria): int {
            self::assertSame('emission', $table);
            self::assertSame(['id' => 42], $criteria);
            $this->pendingRow = array_replace($this->row, $values);
            return 1;
        });
        $db->method('commit')->willReturnCallback(function () use ($fault): void {
            if (++$this->commits === 2 && $fault === 'commit') {
                throw new \RuntimeException('commit outcome unknown');
            }
            $this->row = $this->pendingRow;
            $this->committed = $this->operation?->phase();
            $this->level = 0;
            if ($this->commits === 2 && $fault === 'commit_recorded') {
                throw new \RuntimeException('commit recorded but response lost');
            }
        });
        $db->method('rollBack')->willReturnCallback(function (): void { $this->level = 0; });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($db);
        $em->method('isOpen')->willReturn(true);
        $em->method('getUnitOfWork')->willReturn($this->createMock(UnitOfWork::class));
        $em->method('persist')->willReturnCallback(function (Mp3TrashOperation $operation): void { $this->operation = $operation; });
        $em->method('flush')->willReturnCallback(function () use ($fault): void {
            if (($fault === 'flush' && $this->commits === 1) || ($fault === 'final_flush' && $this->commits === 2)) {
                throw new \RuntimeException('flush failed');
            }
        });
        $service = new Mp3TrashService($this->lock, new Mp3TrashDisk($library, $paths, $io), $processor,
            new AudioLocalUrlResolver([self::BASE, 'https://legacy.example.test/audio']));
        $call = fn () => $service->trash($em, 42, $this->row['thumbnail_mp3'], $this->row['url'], 7);
        if ($fault === 'lock') {
            $this->lock->synchronized(static fn () => null);
            $holder = fopen($this->root . '/trash/.storage.lock', 'r+b');
            self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
            try { return $call(); } finally { fclose($holder); }
        }
        return $call();
    }

    /** @dataProvider safeReferences */
    public function testSuccessCommitsJournalAndAssociationBeforePublicRemoval(string $scenario): void
    {
        $operation = $this->runOperation('none');
        self::assertSame(Phase::EN_CORBEILLE, $this->committed);
        self::assertSame(self::NAME, $operation->originalThumbnailMp3());
        self::assertSame(self::BASE . '/' . self::NAME, $operation->originalUrl());
        self::assertNull($this->row['thumbnail_mp3']);
        self::assertNull($this->row['url']);
        self::assertFileDoesNotExist($this->publicFile());
        self::assertSame(self::CONTENT, file_get_contents($this->root . '/trash/' . $operation->trashRelativePath()));
        self::assertFalse($this->lock->isHeldByCurrentProcess());
    }

    public static function safeReferences(): iterable
    {
        yield 'no other reference' => [''];
        yield 'external URL and different local file' => ['safe_urls'];
    }

    /** @dataProvider failures */
    public function testFailureRetainsTheSafeNameAndDurablePhase(
        string $fault, ?Phase $phase, bool $public, bool $private, bool $uncertain = false
    ): void {
        if ($fault === 'external') { $this->row['url'] = 'https://elsewhere.test/source.mp3'; }
        if ($fault === 'missing') { unlink($this->publicFile()); }
        if ($fault === 'no local') { $this->row['thumbnail_mp3'] = null; }
        if ($fault === 'deleted') { $this->row['deleted_at'] = '2026-11-19 00:00:00'; }
        try {
            $this->runOperation($fault);
            self::fail('Failure expected.');
        } catch (Mp3TrashFailure $error) {
            self::assertSame($uncertain, $error->commitUncertain);
            self::assertSame($this->operation?->id(), $error->operationId);
        }
        self::assertSame($phase, $this->committed);
        if (in_array($fault, ['shared', 'alias', 'deleted_alias'], true)) {
            self::assertSame(self::NAME, $this->row['thumbnail_mp3']);
            self::assertSame(self::BASE . '/' . self::NAME, $this->row['url']);
            self::assertNull($this->operation);
        }
        self::assertSame($public, is_file($this->publicFile()));
        self::assertSame($private, $this->operation !== null && is_file($this->root . '/trash/' . $this->operation->trashRelativePath()));
        self::assertSame(0, $this->level);
        self::assertTrue($this->lock->synchronized(static fn () => true));
    }

    public static function failures(): iterable
    {
        yield ['external', null, true, false];
        yield ['missing', null, false, false];
        yield ['no local', null, true, false];
        yield ['deleted', null, true, false];
        yield ['shared', null, true, false];
        yield ['alias', null, true, false];
        yield ['deleted_alias', null, true, false];
        yield ['reserved', null, true, false];
        yield ['lock', null, true, false];
        yield ['prepare', Phase::SUPPRESSION_PREPAREE, true, false];
        yield ['flush', Phase::SUPPRESSION_PREPAREE, true, true];
        yield ['commit', Phase::SUPPRESSION_PREPAREE, true, true, true];
        yield ['commit_recorded', Phase::RETRAIT_PUBLIC_EN_ATTENTE, true, true, true];
        yield ['shared_after_commit', Phase::RETRAIT_PUBLIC_EN_ATTENTE, true, true];
        yield ['unlink', Phase::RETRAIT_PUBLIC_EN_ATTENTE, true, true];
        yield ['final_flush', Phase::RETRAIT_PUBLIC_EN_ATTENTE, false, true];
    }
}
