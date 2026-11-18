<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Exception\Mp3CleanupTransactionFailure;
use App\Service\AudioLibrary\Inventory\AudioInventoryReferenceCollector;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

/** Protocol tests with real page projection, not a proof of an actual MySQL snapshot. */
final class AudioInventoryReferenceCollectorTest extends TestCase
{
    private int $level = 0;
    private array $steps = [];

    public function testEmptyCollectionOnlyCompletesAfterCommit(): void
    {
        $connection = $this->connection();
        $this->pages($connection, [[]], 500);
        $result = $this->collector($connection)->collect(function (array $page): bool {
            self::assertSame(1, $this->level);
            self::assertSame([], $page['associations']);
            self::assertTrue($page['exhausted']);
            return true;
        });
        self::assertSame($this->summary('completed', true, 1, 0, 0, 0), $result);
        self::assertSame(['engine', 'isolation', 'begin', 'page:0', 'commit', 'close'], $this->steps);
        self::assertSame(0, $this->level);
    }

    public function testAllPagesUseOneSnapshotAndCursorIncludesRowsWithoutReferences(): void
    {
        $connection = $this->connection();
        $this->pages($connection, [[$this->row(3), $this->row(19, false)], [$this->row(42)]], 2);
        $cursors = [];
        $result = $this->collector($connection)->collect(function (array $page) use (&$cursors): bool {
            self::assertSame(1, $this->level);
            $cursors[] = $page['next_after_id'];
            return true;
        }, 2);
        self::assertSame([19, 42], $cursors);
        self::assertSame($this->summary('completed', true, 2, 2, 0, 42), $result);
        self::assertSame(['engine', 'isolation', 'begin', 'page:0', 'page:19', 'commit', 'close'], $this->steps);
    }

    public function testFullLastPageRequiresAnAdditionalEmptyPage(): void
    {
        $connection = $this->connection();
        $this->pages($connection, [[$this->row(7)], []], 1);
        self::assertSame($this->summary('completed', true, 2, 1, 0, 7), $this->collector($connection)->collect(static fn (array $page): bool => true, 1));
    }

    /** @dataProvider interruptionPages */
    public function testInterruptionNeverReturnsCompleteEvenOnTerminalPage(array $rows, int $limit): void
    {
        $connection = $this->connection();
        $this->pages($connection, [$rows], $limit);
        $result = $this->collector($connection)->collect(static fn (array $page): bool => false, $limit);
        self::assertSame('interrupted', $result['status']);
        self::assertFalse($result['references_complete']);
        self::assertSame(1, $result['pages_read']);
        self::assertSame(['engine', 'isolation', 'begin', 'page:0', 'rollback', 'close'], $this->steps);
    }

    public static function interruptionPages(): array
    {
        return ['full' => [[['id' => 7, 'thumbnail_mp3' => 'file.mp3', 'url' => null]], 1], 'terminal empty' => [[], 500]];
    }

    public function testUncertainUrlOnAnEarlierPagePreventsReliableCompletion(): void
    {
        $connection = $this->connection();
        $uncertain = $this->row(7);
        $uncertain['url'] = 'https://radiozinzine.org/emissions/file.mp3?secret=hidden';
        $this->pages($connection, [[$uncertain], []], 1);
        $uncertainties = 0;
        $result = $this->collector($connection)->collect(function (array $page) use (&$uncertainties): bool {
            $uncertainties += count($page['uncertainties']);
            return true;
        }, 1);
        self::assertSame(1, $uncertainties);
        self::assertSame($this->summary('completed', false, 2, 1, 1, 7), $result);
    }

    public function testSqlFailureAfterOnePagePropagatesWithoutACompletionResultOrRetry(): void
    {
        $failure = new class('SQL unavailable') extends \RuntimeException implements \Doctrine\DBAL\Exception {};
        $connection = $this->connection();
        $this->pages($connection, [[$this->row(7)], $failure], 1);
        $consumed = 0;
        $result = null;
        try {
            $result = $this->collector($connection)->collect(static function (array $page) use (&$consumed): bool { ++$consumed; return true; }, 1);
            self::fail('A failed SQL collection must not return a result.');
        } catch (\Doctrine\DBAL\Exception $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertNull($result);
        self::assertSame(1, $consumed);
        self::assertSame(['engine', 'isolation', 'begin', 'page:0', 'page:7', 'rollback', 'close'], $this->steps);
    }

    public function testConsumerFailureAlsoRollsBackAndPropagates(): void
    {
        $failure = new \RuntimeException('Consumer failed');
        $connection = $this->connection();
        $this->pages($connection, [[]], 500);
        $this->expectExceptionObject($failure);
        try {
            $this->collector($connection)->collect(static function () use ($failure): bool { throw $failure; });
        } finally {
            self::assertSame(['engine', 'isolation', 'begin', 'page:0', 'rollback', 'close'], $this->steps);
        }
    }

    public function testConsumerMustExplicitlyAcceptOrInterruptEachPage(): void
    {
        $connection = $this->connection();
        $this->pages($connection, [[]], 500);
        $this->expectException(\UnexpectedValueException::class);
        $this->collector($connection)->collect(static function (): void {});
    }

    public function testForeignTransactionIsRefusedWithoutTouchingIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        foreach (['fetchOne', 'fetchAllAssociative', 'executeStatement', 'beginTransaction', 'commit', 'rollBack', 'close', 'getDatabasePlatform', 'isAutoCommit'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $this->expectException(\LogicException::class);
        $this->collector($connection)->collect(static function (): bool { self::fail('No page may be delivered.'); });
    }

    public function testAutoCommitDisabledIsRejectedWithoutChangingConnectionConfiguration(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(0);
        $connection->method('isAutoCommit')->willReturn(false);
        foreach (['setAutoCommit', 'fetchOne', 'fetchAllAssociative', 'executeStatement', 'beginTransaction', 'commit', 'rollBack', 'close'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $this->expectException(\LogicException::class);
        $this->collector($connection)->collect(static fn (): bool => true);
    }

    public function testNonTransactionalTableCannotProduceACoherentCollection(): void
    {
        $connection = $this->connection(engine: 'MyISAM');
        $connection->expects(self::never())->method('fetchAllAssociative');
        $this->expectException(\LogicException::class);
        try {
            $this->collector($connection)->collect(static fn (): bool => true);
        } finally {
            self::assertSame(['engine', 'close'], $this->steps);
        }
    }

    public function testIsolationPreparationFailureNeverStartsOrReadsATransaction(): void
    {
        $failure = new \RuntimeException('Isolation preparation failed');
        $connection = $this->connection(isolationFailure: $failure);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $this->expectExceptionObject($failure);
        try {
            $this->collector($connection)->collect(static fn (): bool => true);
        } finally {
            self::assertSame(['engine', 'isolation', 'close'], $this->steps);
        }
    }

    /** @dataProvider commitFailureLevels */
    public function testCommitFailureNeverReturnsSuccessOrRetries(int $levelAfterFailure): void
    {
        $failure = new \RuntimeException('Commit outcome unknown');
        $connection = $this->connection(commitFailure: $failure, commitFailureLevel: $levelAfterFailure);
        $this->pages($connection, [[]], 500);
        $this->expectExceptionObject($failure);
        try {
            $this->collector($connection)->collect(static fn (): bool => true);
        } finally {
            self::assertSame($levelAfterFailure ? ['engine', 'isolation', 'begin', 'page:0', 'commit', 'rollback', 'close'] : ['engine', 'isolation', 'begin', 'page:0', 'commit', 'close'], $this->steps);
        }
    }

    public static function commitFailureLevels(): array { return [[0], [1]]; }

    public function testDoubleFailurePreservesBothExceptions(): void
    {
        $initial = new \RuntimeException('Read failed');
        $rollback = new \RuntimeException('Rollback failed');
        $connection = $this->connection(rollbackFailure: $rollback);
        $this->pages($connection, [$initial], 500);
        try {
            $this->collector($connection)->collect(static fn (): bool => true);
            self::fail('Double failure must propagate.');
        } catch (Mp3CleanupTransactionFailure $actual) {
            self::assertSame($initial, $actual->getPrevious());
            self::assertSame($rollback, $actual->getRollbackFailure());
        }
        self::assertSame(['engine', 'isolation', 'begin', 'page:0', 'rollback', 'close'], $this->steps);
    }

    /** @dataProvider failedOpeningStates */
    public function testFailedBeginDoesNotReadOrRetryAndCleansUpConservatively(string $nativeState, int $localLevel): void
    {
        $failure = new \RuntimeException('Begin failed');
        $connection = $this->connection(beginFailure: $failure, beginFailureLevel: $localLevel, nativeState: $nativeState);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $this->expectExceptionObject($failure);
        try {
            $this->collector($connection)->collect(static fn (): bool => true);
        } finally {
            $expected = ['engine', 'isolation', 'begin'];
            if ($localLevel > 0) { $expected[] = 'native'; }
            if ($nativeState === 'active' && $localLevel > 0) { $expected[] = 'rollback'; }
            $expected[] = 'close';
            self::assertSame($expected, $this->steps);
        }
    }

    public static function failedOpeningStates(): array { return [['inactive', 0], ['active', 1], ['inactive', 1], ['unsupported', 1], ['throws', 1]]; }

    public function testLostConnectionDuringOpeningIsNeverReconnectedForInspection(): void
    {
        $driver = new class('Lost connection') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string { return 'HY000'; }
        };
        $failure = new ConnectionLost($driver, null);
        $connection = $this->connection(beginFailure: $failure, beginFailureLevel: 1, nativeState: 'lost');
        $connection->expects(self::never())->method('fetchAllAssociative');
        $this->expectExceptionObject($failure);
        try { $this->collector($connection)->collect(static fn (): bool => true); }
        finally { self::assertSame(['engine', 'isolation', 'begin', 'close'], $this->steps); }
    }

    public function testRollbackFailureAfterFailedBeginKeepsOpeningCause(): void
    {
        $initial = new \RuntimeException('Begin failed');
        $rollback = new \RuntimeException('Rollback failed');
        $connection = $this->connection(beginFailure: $initial, beginFailureLevel: 1, nativeState: 'active', rollbackFailure: $rollback);
        $connection->expects(self::never())->method('fetchAllAssociative');
        try {
            $this->collector($connection)->collect(static fn (): bool => true);
            self::fail('Expected both causes.');
        } catch (Mp3CleanupTransactionFailure $actual) {
            self::assertSame($initial, $actual->getPrevious());
            self::assertSame($rollback, $actual->getRollbackFailure());
        }
        self::assertSame(['engine', 'isolation', 'begin', 'native', 'rollback', 'close'], $this->steps);
    }

    public function testCloseFailureDoesNotMaskAnEarlierReadError(): void
    {
        $failure = new \RuntimeException('Read failed');
        $connection = $this->connection(closeFailure: new \RuntimeException('Close failed'));
        $this->pages($connection, [$failure], 500);
        $this->expectExceptionObject($failure);
        $this->collector($connection)->collect(static fn (): bool => true);
    }

    /** @dataProvider invalidLimits */
    public function testInvalidPageSizeHasNoConnectionSideEffects(int $limit): void
    {
        $connection = $this->createMock(Connection::class);
        foreach (['getTransactionNestingLevel', 'fetchOne', 'executeStatement', 'beginTransaction', 'close', 'fetchAllAssociative'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->collector($connection)->collect(static fn (): bool => true, $limit);
    }

    public static function invalidLimits(): array { return [[0], [-1], [501]]; }

    public function testMoreThanTenThousandReferencesAreDeliveredInBoundedPagesWithoutAggregation(): void
    {
        $connection = $this->connection();
        $connection->expects(self::exactly(21))->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params, array $types): array {
                self::assertSame(500, $params['limit']);
                self::assertSame(1, $this->level);
                $rows = [];
                for ($id = $params['afterId'] + 1; $id <= min(10001, $params['afterId'] + 500); ++$id) { $rows[] = $this->row($id); }
                return $rows;
            },
        );
        $count = $maxPage = $maxMemory = 0;
        $baseline = memory_get_usage();
        $result = $this->collector($connection)->collect(static function (array $page) use (&$count, &$maxPage, &$maxMemory, $baseline): bool {
            $count += count($page['associations']);
            $maxPage = max($maxPage, count($page['associations']));
            $maxMemory = max($maxMemory, memory_get_usage() - $baseline);
            return true;
        });
        self::assertSame(10001, $count);
        self::assertSame(500, $maxPage);
        self::assertLessThan(2 * 1024 * 1024, $maxMemory, 'Synthetic collection must not retain all projected pages.');
        self::assertSame($this->summary('completed', true, 21, 10001, 0, 10001), $result);
        self::assertArrayNotHasKey('associations', $result);
    }

    private function collector(Connection $connection): AudioInventoryReferenceCollector
    {
        return new AudioInventoryReferenceCollector($connection, new AudioLocalUrlResolver(['http://radiozinzine.org/emissions/']));
    }

    private function pages(Connection $connection, array $pages, int $limit): void
    {
        $index = 0;
        $cursor = 0;
        $connection->expects(self::exactly(count($pages)))->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params, array $types) use ($pages, $limit, &$index, &$cursor): array {
                self::assertSame(1, $this->level);
                self::assertSame('SELECT id, thumbnail_mp3, url FROM emission WHERE id > :afterId ORDER BY id ASC LIMIT :limit', $sql);
                self::assertSame(['afterId' => $cursor, 'limit' => $limit], $params);
                self::assertSame(['afterId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER], $types);
                $this->steps[] = 'page:' . $cursor;
                $page = $pages[$index++];
                if ($page instanceof \Throwable) { throw $page; }
                if ($page !== []) { $cursor = (int) $page[count($page) - 1]['id']; }
                return $page;
            },
        );
    }

    private function connection(
        string $engine = 'InnoDB',
        ?\Throwable $isolationFailure = null,
        ?\Throwable $beginFailure = null,
        int $beginFailureLevel = 0,
        string $nativeState = 'inactive',
        ?\Throwable $rollbackFailure = null,
        ?\Throwable $commitFailure = null,
        int $commitFailureLevel = 0,
        ?\Throwable $closeFailure = null,
    ): Connection {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(fn (): int => $this->level);
        $connection->method('isAutoCommit')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('isConnected')->willReturn($nativeState !== 'lost');
        foreach (['insert', 'update', 'delete', 'setAutoCommit', 'setTransactionIsolation'] as $method) { $connection->expects(self::never())->method($method); }
        $connection->expects(self::once())->method('fetchOne')->with(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName',
            ['tableName' => 'emission'], ['tableName' => ParameterType::STRING],
        )->willReturnCallback(function () use ($engine): string { $this->steps[] = 'engine'; return $engine; });
        $canPrepare = $engine === 'InnoDB';
        $connection->expects($canPrepare ? self::once() : self::never())->method('executeStatement')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturnCallback(
            function () use ($isolationFailure): int { $this->steps[] = 'isolation'; if ($isolationFailure) { throw $isolationFailure; } return 0; },
        );
        $canBegin = $canPrepare && !$isolationFailure;
        $connection->expects($canBegin ? self::once() : self::never())->method('beginTransaction')->willReturnCallback(function () use ($beginFailure, $beginFailureLevel): void {
            $this->steps[] = 'begin'; $this->level = $beginFailure ? $beginFailureLevel : 1;
            if ($beginFailure) { throw $beginFailure; }
        });
        $connection->method('commit')->willReturnCallback(function () use ($commitFailure, $commitFailureLevel): void {
            $this->steps[] = 'commit'; $this->level = $commitFailure ? $commitFailureLevel : 0;
            if ($commitFailure) { throw $commitFailure; }
        });
        $connection->method('rollBack')->willReturnCallback(function () use ($rollbackFailure): void {
            $this->steps[] = 'rollback'; if ($rollbackFailure) { throw $rollbackFailure; } $this->level = 0;
        });
        $connection->expects(self::once())->method('close')->willReturnCallback(function () use ($closeFailure): void {
            $this->steps[] = 'close'; $this->level = 0; if ($closeFailure) { throw $closeFailure; }
        });
        $inspect = $beginFailure && $beginFailureLevel > 0 && $nativeState !== 'lost';
        $connection->expects($inspect ? self::once() : self::never())->method('getNativeConnection')->willReturnCallback(function () use ($nativeState): mixed {
            $this->steps[] = 'native';
            if ($nativeState === 'throws') { throw new \RuntimeException('Cannot inspect native state'); }
            if ($nativeState === 'unsupported') { return new \stdClass(); }
            $pdo = $this->createMock(\PDO::class);
            $pdo->expects(self::once())->method('inTransaction')->willReturn($nativeState === 'active');
            return $pdo;
        });
        return $connection;
    }

    private function row(int $id, bool $reference = true): array
    {
        return ['id' => $id, 'thumbnail_mp3' => $reference ? 'TST/' . $id . '.mp3' : null, 'url' => null];
    }

    private function summary(string $status, bool $complete, int $pages, int $associations, int $uncertainties, int $cursor): array
    {
        return ['status' => $status, 'references_complete' => $complete, 'temporal_consistency' => 'repeatable_read',
            'pages_read' => $pages, 'association_count' => $associations, 'uncertainty_count' => $uncertainties, 'next_after_id' => $cursor];
    }
}
