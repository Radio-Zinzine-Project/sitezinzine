<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Real commits require identity-scoped cleanup, not an enclosing fixture transaction. */
abstract class Mp3CleanupDbalIntegrationTestCase extends KernelTestCase
{
    private array $resolvedParams = [];
    /** @var list<Connection> */
    private array $connections = [];
    /** @var list<array{storage:string, path:string}> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        try {
            static::bootKernel(['environment' => 'test', 'debug' => false]);
            $registry = static::getContainer()->get(ManagerRegistry::class);
            $params = $registry->getConnection()->getParams();
            $this->assertSafeParams($params);
            $this->resolvedParams = $params;
        } catch (\Throwable $initial) {
            // PHPUnit does not necessarily call tearDown after a partially failed setup.
            try {
                $this->tearDown();
            } catch (\Throwable $cleanup) {
                $this->reportCleanupFailure($cleanup);
            }
            throw $initial;
        }
    }

    /** @param class-string<Connection>|null $wrapperClass */
    protected function newConnection(?string $wrapperClass = null): Connection
    {
        $this->assertSafeParams($this->resolvedParams);
        $params = $this->resolvedParams;
        if ($wrapperClass !== null) { $params['wrapperClass'] = $wrapperClass; }
        $connection = DriverManager::getConnection($params);
        $this->connections[] = $connection;
        // Register before the first physical connection or assertion can fail.
        $id = $this->connectionId($connection);
        self::assertSame(0, $connection->getTransactionNestingLevel());
        foreach ($this->connections as $other) {
            if ($other !== $connection && $other->isConnected()) {
                self::assertNotSame($this->connectionId($other), $id, 'Test connections must use distinct MySQL sessions.');
            }
        }
        return $connection;
    }

    protected function connectionId(Connection $connection): int
    {
        if (!in_array($connection, $this->connections, true)) {
            throw new \LogicException('Only connections owned by this test may be inspected.');
        }
        self::assertSame('symfony_test', $connection->fetchOne('SELECT DATABASE()'));
        $id = (int) $connection->fetchOne('SELECT CONNECTION_ID()');
        self::assertGreaterThan(0, $id);
        return $id;
    }

    /** Register before any write, including writes whose commit outcome may become uncertain. */
    protected function registerFixture(string $storage, string $obsoleteRelativePath): void
    {
        if ($storage !== 'emissionsMp3' || $obsoleteRelativePath === '' || strlen($obsoleteRelativePath) > 1020) {
            throw new \InvalidArgumentException('Expected an emissionsMp3 fixture with a nonempty path of at most 1020 bytes.');
        }
        $fixture = ['storage' => $storage, 'path' => $obsoleteRelativePath];
        if (!in_array($fixture, $this->fixtures, true)) {
            $this->fixtures[] = $fixture;
        }
    }

    /** No real MP3 is created. The returned identity is already registered for cleanup. */
    protected function newFixturePath(): string
    {
        $path = '__test_mp3_cleanup__/' . bin2hex(random_bytes(16)) . '/obsolete.mp3';
        $this->registerFixture('emissionsMp3', $path);
        return $path;
    }

    protected function tearDown(): void
    {
        $failures = [];
        try {
            foreach ($this->connections as $connection) {
                try {
                    $this->rollbackOwnedConnection($connection);
                } catch (\Throwable $error) {
                    $failures[] = $error;
                } finally {
                    // Also abandon native state after an ambiguous commit with a zero local level.
                    try { $connection->close(); } catch (\Throwable $closeError) { $failures[] = $closeError; }
                }
            }

            if ($this->fixtures !== []) {
                try {
                    $cleanup = $this->newConnection();
                    $cleanup->beginTransaction();
                    foreach ($this->fixtures as $fixture) {
                        $ids = $cleanup->fetchFirstColumn(
                            'SELECT id FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ? FOR UPDATE',
                            [$fixture['storage'], $fixture['path']],
                        );
                        foreach ($ids as $id) {
                            $cleanup->executeStatement('DELETE FROM mp3_cleanup_event WHERE issue_id = ?', [(int) $id]);
                            $cleanup->executeStatement(
                                'DELETE FROM mp3_cleanup_issue WHERE id = ? AND storage = ? AND obsolete_relative_path = ?',
                                [(int) $id, $fixture['storage'], $fixture['path']],
                            );
                        }
                    }
                    $cleanup->commit();
                } catch (\Throwable $error) {
                    $failures[] = $error;
                }
            }
        } finally {
            foreach ($this->connections as $connection) {
                try { $this->rollbackOwnedConnection($connection); } catch (\Throwable $error) { $failures[] = $error; }
                finally {
                    try { $connection->close(); } catch (\Throwable $error) { $failures[] = $error; }
                }
            }
            $this->connections = $this->fixtures = $this->resolvedParams = [];
            try { parent::tearDown(); } catch (\Throwable $error) { $failures[] = $error; }
        }

        if ($failures !== []) {
            foreach ($failures as $error) { $this->reportCleanupFailure($error); }
            if (!$this->hasFailed()) {
                throw $failures[0];
            }
        }
    }

    private function assertSafeParams(array $params): void
    {
        if (($params['dbname'] ?? null) !== 'symfony_test') {
            throw new \LogicException('MP3 cleanup integration tests exclusively permit symfony_test.');
        }
        if (($params['driver'] ?? null) !== 'pdo_mysql'
            || isset($params['pdo']) || isset($params['primary']) || isset($params['replica'])
            || !empty($params['persistent']) || !empty($params['driverOptions'][\PDO::ATTR_PERSISTENT])) {
            throw new \LogicException('Expected a nonpersistent, direct PDO MySQL connection without a shared native connection.');
        }
    }

    private function rollbackOwnedConnection(Connection $connection): void
    {
        while ($connection->isConnected() && ($level = $connection->getTransactionNestingLevel()) > 0) {
            $connection->rollBack();
            if ($connection->getTransactionNestingLevel() >= $level) {
                throw new \LogicException('Rollback did not reduce the test connection transaction level.');
            }
        }
    }

    private function reportCleanupFailure(\Throwable $error): void
    {
        // Do not expose SQL parameters, credentials or exception messages in secondary diagnostics.
        fwrite(STDERR, 'MP3 cleanup integration cleanup failed: ' . $error::class . PHP_EOL);
    }
}
