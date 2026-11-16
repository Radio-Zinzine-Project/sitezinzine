<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupServiceFactory;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Lazy DBAL objects and real writer/reporter; no kernel, SQL or physical session. */
final class Mp3CleanupServiceFactoryTest extends TestCase
{
    public function testConnectionCopiesResolvedParamsWithoutTouchingBusinessState(): void
    {
        $params = $this->params();
        $main = $this->mainConnection($params);
        $dedicated = Mp3CleanupServiceFactory::createConnection($main);
        try {
            self::assertNotSame($main, $dedicated);
            self::assertSame(Connection::class, $dedicated::class);
            self::assertSame($params, $dedicated->getParams());
            self::assertSame('symfony_test', $dedicated->getParams()['dbname']);
            self::assertArrayNotHasKey('pdo', $dedicated->getParams());
            self::assertFalse($dedicated->isConnected());
            self::assertSame(0, $dedicated->getTransactionNestingLevel());
            self::assertTrue($dedicated->isAutoCommit());
        } finally {
            $dedicated->close();
        }
    }

    public function testEachFactoryCallCreatesItsOwnDisconnectedObject(): void
    {
        $main = $this->mainConnection($this->params());
        $first = Mp3CleanupServiceFactory::createConnection($main);
        $second = Mp3CleanupServiceFactory::createConnection($main);
        try {
            self::assertNotSame($first, $second);
            self::assertFalse($first->isConnected());
            self::assertFalse($second->isConnected());
            self::assertSame($first->getParams(), $second->getParams());
        } finally {
            $first->close();
            $second->close();
        }
    }

    public static function unsupportedParams(): iterable
    {
        yield 'missing database' => ['dbname', null];
        yield 'empty database' => ['dbname', ''];
        yield 'unsupported driver' => ['driver', 'pdo_sqlite'];
        yield 'persistent option' => ['persistent', true];
        yield 'persistent PDO option' => ['driverOptions', [\PDO::ATTR_PERSISTENT => true]];
        yield 'shared native handle' => ['pdo', new \stdClass()];
        yield 'primary configuration' => ['primary', []];
        yield 'replica configuration' => ['replica', []];
        yield 'custom driver' => ['driverClass', 'CustomDriver'];
        yield 'custom wrapper' => ['wrapperClass', 'CustomConnection'];
        yield 'unresolved URL' => ['url', 'mysql://private:secret@db/symfony'];
        yield 'native autocommit disabled' => ['driverOptions', [\PDO::ATTR_AUTOCOMMIT => false]];
    }

    /** @dataProvider unsupportedParams */
    public function testUnsupportedParamsAreRejectedBeforeConnecting(string $key, mixed $value): void
    {
        $params = $this->params();
        $params[$key] = $value;
        $main = $this->mainConnection($params);
        $this->expectException(\LogicException::class);
        Mp3CleanupServiceFactory::createConnection($main);
    }

    public function testReporterConstructionDoesNotResolveProviders(): void
    {
        $calls = [];
        $recipient = Mp3CleanupServiceFactory::createRecipient(
            static function () use (&$calls): never { $calls[] = 'writer'; throw new \RuntimeException(); },
            static function () use (&$calls): never { $calls[] = 'connection'; throw new \RuntimeException(); },
        );
        $reporter = new Mp3CleanupReporter($recipient);
        self::assertInstanceOf(Mp3CleanupReporter::class, $reporter);
        self::assertSame([], $calls);
    }

    public function testRecipientPassesOriginalAnomalyAndExactStorageThenClosesDedicatedConnection(): void
    {
        $trace = $lookups = $received = [];
        $main = $this->mainConnection($this->params());
        $connection = $this->ownedConnection($trace);
        $anomaly = $this->anomaly();
        $recipient = Mp3CleanupServiceFactory::createRecipient(
            function () use ($connection, &$trace, &$lookups, &$received): Mp3CleanupIssueWriter {
                $trace[] = 'writer_provider';
                return $this->writer($connection, $lookups, static function (int $id, Mp3CleanupAnomaly $value) use (&$received): void {
                    $received[] = [$id, $value];
                });
            },
            static function () use ($connection, &$trace): Connection { $trace[] = 'connection_provider'; return $connection; },
        );
        $recipient($anomaly);
        self::assertSame([['emissionsMp3', $anomaly->obsoleteRelativePath]], $lookups);
        self::assertSame([[17, $anomaly]], $received);
        self::assertSame(['connection_provider', 'writer_provider', 'begin', 'commit', 'close'], $trace);
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertFalse($main->isConnected());
    }

    public function testWriterFailureReachesCallerUnchangedAfterRollbackAndClose(): void
    {
        $trace = $lookups = [];
        $connection = $this->ownedConnection($trace);
        $origin = new \RuntimeException('PRIVATE_WRITER_FAILURE', 17);
        $recipient = $this->failingRecipient($connection, $lookups, $origin);
        try {
            $recipient($this->anomaly());
            self::fail('The reporter must receive the original writer failure.');
        } catch (\RuntimeException $error) {
            self::assertSame($origin, $error);
        }
        self::assertSame(['begin', 'rollback', 'close'], $trace);
    }

    public static function initializationFailures(): iterable
    {
        yield 'writer provider' => [false];
        yield 'connection provider' => [true];
    }

    /** @dataProvider initializationFailures */
    public function testDeferredInitializationFailureReachesReporterFallback(bool $connectionFails): void
    {
        $trace = $logs = [];
        $writerCalls = 0;
        $connection = $this->ownedConnection($trace);
        $origin = new \LogicException('PRIVATE_INITIALIZATION_FAILURE', 23);
        $recipient = Mp3CleanupServiceFactory::createRecipient(
            static function () use (&$writerCalls, $origin): never { ++$writerCalls; throw $origin; },
            static function () use ($connection, $connectionFails, $origin): Connection {
                if ($connectionFails) { throw $origin; }
                return $connection;
            },
        );
        $reporter = new Mp3CleanupReporter($recipient, $this->logger($logs));
        $reporter->reportReferenceCheckFailure(42, 'TST/2026/old.mp3', 'TST/2026/new.mp3', new \RuntimeException());
        self::assertSame($connectionFails ? 0 : 1, $writerCalls);
        self::assertSame($connectionFails ? [] : ['close'], $trace);
        self::assertCount(1, $logs);
        self::assertSame(['class' => $origin::class, 'code' => 23, 'sql_state' => null], $logs[0]['recipient_failure']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public function testReporterHandlesWriterFailureAfterConnectionCleanup(): void
    {
        $trace = $lookups = $logs = [];
        $connection = $this->ownedConnection($trace);
        $origin = new \RuntimeException('PRIVATE_WRITER_FAILURE', 17);
        $reporter = new Mp3CleanupReporter($this->failingRecipient($connection, $lookups, $origin), $this->logger($logs));
        $reporter->reportObsoleteCleanupFailure(42, 'TST/2026/old.mp3', 'TST/2026/new.mp3', new \RuntimeException());
        self::assertSame(['begin', 'rollback', 'close'], $trace);
        self::assertCount(1, $logs);
        self::assertSame(['class' => $origin::class, 'code' => 17, 'sql_state' => null], $logs[0]['recipient_failure']);
    }

    public function testCloseFailureNeverMasksOriginalWriterFailure(): void
    {
        $trace = $lookups = [];
        $connection = $this->ownedConnection($trace, new \LogicException('Close failed'));
        $origin = new \RuntimeException('Writer failed');
        $recipient = $this->failingRecipient($connection, $lookups, $origin);
        try {
            $recipient($this->anomaly());
            self::fail('The original failure must escape to the reporter.');
        } catch (\Throwable $error) {
            self::assertSame($origin, $error);
        }
        self::assertSame(['begin', 'rollback', 'close'], $trace);
    }

    public function testCloseFailureAfterSuccessfulWriteIsPropagatedWithoutRepeatingTheWrite(): void
    {
        $trace = $lookups = [];
        $writes = 0;
        $origin = new \LogicException('Close failed');
        $connection = $this->ownedConnection($trace, $origin);
        $writer = $this->writer($connection, $lookups, static function () use (&$writes): void { ++$writes; });
        $recipient = Mp3CleanupServiceFactory::createRecipient(static fn () => $writer, static fn () => $connection);
        try {
            $recipient($this->anomaly());
            self::fail('The reporter must receive the close failure.');
        } catch (\Throwable $error) {
            self::assertSame($origin, $error);
        }
        self::assertSame(1, $writes);
        self::assertSame(['begin', 'commit', 'close'], $trace);
    }

    public function testForeignTransactionIsRefusedWithoutClosingItOrResolvingWriter(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        foreach (['beginTransaction', 'commit', 'rollBack', 'close'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $writerCalls = 0;
        $recipient = Mp3CleanupServiceFactory::createRecipient(
            static function () use (&$writerCalls): never { ++$writerCalls; throw new \RuntimeException(); },
            static fn () => $connection,
        );
        try {
            $recipient($this->anomaly());
            self::fail('A foreign transaction must be preserved.');
        } catch (\LogicException) {
            self::assertSame(0, $writerCalls);
        }
    }

    public function testReentrantRecipientCannotCloseTheOuterTransaction(): void
    {
        $trace = $lookups = [];
        $connection = $this->ownedConnection($trace);
        $recipient = null;
        $nestedFailure = null;
        $duringNestedCall = null;
        $providerCalls = 0;
        $writer = $this->writer($connection, $lookups, function () use (&$recipient, &$nestedFailure, &$duringNestedCall, &$trace): void {
            try { $recipient($this->anomaly()); } catch (\Throwable $error) { $nestedFailure = $error; }
            $duringNestedCall = $trace;
        });
        $recipient = Mp3CleanupServiceFactory::createRecipient(static fn () => $writer,
            static function () use ($connection, &$providerCalls): Connection { ++$providerCalls; return $connection; });
        $recipient($this->anomaly());
        self::assertInstanceOf(\LogicException::class, $nestedFailure);
        self::assertSame(1, $providerCalls);
        self::assertSame(['begin'], $duringNestedCall);
        self::assertSame(['begin', 'commit', 'close'], $trace);
    }

    public function testRecipientCanBeUsedAgainAfterACompletedCall(): void
    {
        $trace = $lookups = [];
        $writes = 0;
        $connection = $this->ownedConnection($trace);
        $writer = $this->writer($connection, $lookups, static function () use (&$writes): void { ++$writes; });
        $recipient = Mp3CleanupServiceFactory::createRecipient(static fn () => $writer, static fn () => $connection);
        $recipient($this->anomaly());
        $recipient($this->anomaly());
        self::assertSame(2, $writes);
        self::assertSame(['begin', 'commit', 'close', 'begin', 'commit', 'close'], $trace);
    }

    private function params(): array
    {
        return ['driver' => 'pdo_mysql', 'host' => 'db', 'port' => 3306, 'dbname' => 'symfony_test',
            'user' => 'test', 'password' => 'PRIVATE_PASSWORD', 'charset' => 'utf8mb4',
            'serverVersion' => '8.0.42', 'driverOptions' => [\PDO::ATTR_TIMEOUT => 2], 'persistent' => false];
    }

    private function mainConnection(array $params): Connection
    {
        $main = $this->createMock(Connection::class);
        $main->method('getParams')->willReturn($params);
        foreach (['getNativeConnection', 'beginTransaction', 'commit', 'rollBack', 'close', 'executeQuery', 'executeStatement'] as $method) {
            $main->expects(self::never())->method($method);
        }
        return $main;
    }

    private function ownedConnection(array &$trace, ?\Throwable $closeFailure = null): Connection
    {
        $connection = $this->createMock(Connection::class);
        $level = 0;
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$level, &$trace): void { ++$level; $trace[] = 'begin'; });
        $connection->method('commit')->willReturnCallback(static function () use (&$level, &$trace): void { --$level; $trace[] = 'commit'; });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$level, &$trace): void { --$level; $trace[] = 'rollback'; });
        $connection->method('close')->willReturnCallback(static function () use (&$trace, $closeFailure): void {
            $trace[] = 'close';
            if ($closeFailure !== null) { throw $closeFailure; }
        });
        foreach (['getNativeConnection', 'executeQuery', 'executeStatement'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        return $connection;
    }

    private function writer(Connection $connection, array &$lookups, \Closure $append): Mp3CleanupIssueWriter
    {
        return new Mp3CleanupIssueWriter($connection, new Mp3CleanupIssueGrouper(
            static function (string $storage, string $path) use (&$lookups): array {
                $lookups[] = [$storage, $path];
                return [['id' => 17, 'storage' => $storage, 'relative_path' => $path, 'status' => 'open']];
            },
            static function (): never { throw new \LogicException('Existing issue must be reused.'); },
            $append,
        ));
    }

    private function failingRecipient(Connection $connection, array &$lookups, \Throwable $origin): \Closure
    {
        $writer = $this->writer($connection, $lookups, static function () use ($origin): never { throw $origin; });
        return Mp3CleanupServiceFactory::createRecipient(static fn () => $writer, static fn () => $connection);
    }

    private function logger(array &$logs): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message, array $context) use (&$logs): void { $logs[] = $context; });
        return $logger;
    }

    private function anomaly(): Mp3CleanupAnomaly
    {
        return new Mp3CleanupAnomaly('reference_check_failed', 42, 'TST/2026/Épisode.mp3', [
            'replacement_relative_path' => 'TST/2026/new.mp3', 'commit_confirmed' => true, 'reference_count' => null,
        ], ['class' => \RuntimeException::class, 'code' => 0, 'sql_state' => null]);
    }
}
