<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupActiveIssueCollision;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

/** SQL boundary contract, without a database: collation and actual locks require integration tests. */
final class Mp3CleanupIssueDbalAdapterTest extends TestCase
{
    public function testActiveLookupIsParameterizedExactAndLocking(): void
    {
        $connection = $this->connection();
        $rows = [$this->issue()];
        $connection->expects(self::exactly(2))->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use ($rows): array {
                $this->assertLookup($sql, $params);
                return $params[1] === 'TST/2026/Old.mp3' ? $rows : [];
            }
        );
        $adapter = new Mp3CleanupIssueDbalAdapter($connection);

        self::assertSame($rows, $adapter->findActiveIssues('emissionsMp3', 'TST/2026/Old.mp3'));
        self::assertSame([], $adapter->findActiveIssues('emissionsMp3', 'TST/2026/old.mp3'));
        // The double distinguishes bytes; only a MySQL test can prove the schema comparison.
    }

    public function testCreationInsertsOpenIssueAndReturnsItsGeneratedIdentifier(): void
    {
        $connection = $this->connection();
        $inserted = false;
        $connection->expects(self::once())->method('insert')->with('mp3_cleanup_issue', self::callback(
            function (array $data) use (&$inserted): bool {
                self::assertSame('emissionsMp3', $data['storage']);
                self::assertSame('TST/2026/Old.mp3', $data['obsolete_relative_path']);
                self::assertSame('open', $data['status']);
                self::assertArrayNotHasKey('id', $data);
                $inserted = true;
                return true;
            }
        ))->willReturn(1);
        $connection->expects(self::once())->method('lastInsertId')->willReturnCallback(
            function () use (&$inserted): string {
                self::assertTrue($inserted);
                return '17';
            }
        );
        $connection->expects(self::never())->method('fetchAllAssociative');

        self::assertSame(17, (new Mp3CleanupIssueDbalAdapter($connection))->createIssue($this->creation()));
    }

    /** @dataProvider anomalyReasons */
    public function testEventPersistsOnlyAllowedContextAndSanitizedDiagnostic(string $reason, ?int $references): void
    {
        $connection = $this->connection();
        $anomaly = new Mp3CleanupAnomaly($reason, 42, 'TST/2026/Ancien Épisode.mp3', [
            'replacement_relative_path' => 'TST/2026/New.mp3',
            'commit_confirmed' => true, 'reference_count' => $references,
            'secret' => 'must-not-be-stored',
        ], [
            'class' => \RuntimeException::class, 'code' => 'HY000', 'sql_state' => 'HY000',
            'message' => 'raw-secret', 'trace' => 'sensitive-trace',
        ]);
        $connection->expects(self::once())->method('insert')->with('mp3_cleanup_event', self::callback(
            function (array $data) use ($reason, $references): bool {
                self::assertSame(17, $data['issue_id']);
                self::assertSame($reason, $data['reason']);
                self::assertSame(42, $data['emission_id']);
                self::assertSame('TST/2026/Ancien Épisode.mp3', $data['obsolete_relative_path']);
                self::assertSame([
                    'replacement_relative_path' => 'TST/2026/New.mp3',
                    'commit_confirmed' => true, 'reference_count' => $references,
                ], json_decode($data['context'], true, 512, JSON_THROW_ON_ERROR));
                self::assertSame([
                    'class' => \RuntimeException::class, 'code' => 'HY000', 'sql_state' => 'HY000',
                ], json_decode($data['diagnostic'], true, 512, JSON_THROW_ON_ERROR));
                $encoded = json_encode($data, JSON_THROW_ON_ERROR);
                foreach (['must-not-be-stored', 'raw-secret', 'sensitive-trace'] as $secret) {
                    self::assertStringNotContainsString($secret, $encoded);
                }
                self::assertArrayNotHasKey('delete_authorized', $data);
                return true;
            }
        ))->willReturn(1);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $connection->expects(self::never())->method('lastInsertId');

        (new Mp3CleanupIssueDbalAdapter($connection))->appendEvent(17, $anomaly);
    }

    public static function anomalyReasons(): array
    {
        return ['unknown references' => ['reference_check_failed', null], 'zero references' => ['obsolete_cleanup_failed', 0]];
    }

    /** @dataProvider activeStatuses */
    public function testUniqueViolationBecomesCollisionOnlyAfterExactActiveLookup(string $status): void
    {
        $connection = $this->connection();
        $original = $this->uniqueError();
        $attempted = false;
        $connection->expects(self::once())->method('insert')->willReturnCallback(
            static function () use (&$attempted, $original): int {
                $attempted = true;
                throw $original;
            }
        );
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use (&$attempted, $status): array {
                self::assertTrue($attempted, 'Collision must be verified after the failed insert.');
                $this->assertLookup($sql, $params);
                return [$this->issue($status)];
            }
        );
        $connection->expects(self::never())->method('lastInsertId');
        $adapter = new Mp3CleanupIssueDbalAdapter($connection);
        $caught = $this->capture(static fn () => $adapter->createIssue([
            'storage' => 'emissionsMp3', 'relative_path' => 'TST/2026/Old.mp3', 'status' => 'open',
        ]));

        self::assertInstanceOf(Mp3CleanupActiveIssueCollision::class, $caught);
        self::assertSame($original, $caught->getPrevious());
    }

    public static function activeStatuses(): array
    {
        return [['open'], ['investigating']];
    }

    /** @dataProvider unverifiedCollisions */
    public function testNegativeOrImpossibleCollisionCheckPreservesOriginalException(string $case): void
    {
        $connection = $this->connection($case !== 'ended', $case !== 'disconnected');
        $original = $this->uniqueError();
        $connection->expects(self::once())->method('insert')->willThrowException($original);
        $connection->expects(self::never())->method('lastInsertId');
        if (in_array($case, ['ended', 'disconnected'], true)) {
            $connection->expects(self::never())->method('fetchAllAssociative');
        } elseif ($case === 'lookup failed') {
            $connection->expects(self::once())->method('fetchAllAssociative')->willThrowException(new \RuntimeException('Check unavailable'));
        } else {
            $row = $this->issue();
            if ($case === 'different storage') { $row['storage'] = 'other'; }
            if ($case === 'different case') { $row['relative_path'] = 'TST/2026/old.mp3'; }
            if ($case === 'resolved') { $row['status'] = 'resolved'; }
            $rows = $case === 'absent' ? [] : ($case === 'multiple' ? [$row, $row] : [$row]);
            $connection->expects(self::once())->method('fetchAllAssociative')->willReturn($rows);
        }
        $adapter = new Mp3CleanupIssueDbalAdapter($connection);

        self::assertSame($original, $this->capture(fn () => $adapter->createIssue($this->creation())));
    }

    public static function unverifiedCollisions(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'absent', 'different storage', 'different case', 'resolved', 'multiple', 'lookup failed', 'ended', 'disconnected',
        ]);
    }

    private function connection(bool $active = true, bool $connected = true): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn($active ? 1 : 0);
        $connection->method('isTransactionActive')->willReturn($active);
        $connection->method('isConnected')->willReturn($connected);
        foreach (['beginTransaction', 'commit', 'rollBack', 'close'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        return $connection;
    }

    private function assertLookup(string $sql, array $params): void
    {
        self::assertSame('emissionsMp3', $params[0]);
        self::assertContains($params[1], ['TST/2026/Old.mp3', 'TST/2026/old.mp3']);
        $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim($sql)));
        self::assertStringContainsString('from mp3_cleanup_issue', $normalized);
        self::assertStringContainsString('storage = ?', $normalized);
        self::assertStringContainsString('obsolete_relative_path = ?', $normalized);
        self::assertStringContainsString('for update', $normalized);
        self::assertStringNotContainsString('lower(', $normalized);
        self::assertStringNotContainsString('resolved', $normalized);
        $boundStatuses = array_slice($params, 2);
        sort($boundStatuses);
        self::assertSame(['investigating', 'open'], $boundStatuses);
        self::assertMatchesRegularExpression('/status\s+in\s*\(\s*\?\s*,\s*\?\s*\)/', $normalized);
        self::assertStringNotContainsString('TST/', $sql, 'Paths belong in parameters, not SQL text.');
    }

    private function creation(): array
    {
        return ['storage' => 'emissionsMp3', 'relative_path' => 'TST/2026/Old.mp3', 'status' => 'open'];
    }

    private function issue(string $status = 'open'): array
    {
        // Adapter snapshots use the existing grouper contract, not the SQL column name.
        return ['id' => 17, 'storage' => 'emissionsMp3', 'relative_path' => 'TST/2026/Old.mp3', 'status' => $status];
    }

    private function uniqueError(): UniqueConstraintViolationException
    {
        $driver = new class('Opaque diagnostic: not a constraint identification mechanism', 1062) extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string { return '23000'; }
        };
        return new UniqueConstraintViolationException($driver, null);
    }

    private function capture(callable $operation): ?\Throwable
    {
        try { $operation(); } catch (\Throwable $error) { return $error; }
        return null;
    }
}
