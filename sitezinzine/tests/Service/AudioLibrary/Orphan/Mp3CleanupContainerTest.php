<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueGrouper;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\Service\Mp3Replacement;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;

/** One compiled TEST kernel; inspect actual wiring without executing SQL callbacks. */
final class Mp3CleanupContainerTest extends KernelTestCase
{
    private ?Connection $dedicated = null;

    public function testCompiledContainerWiresDeferredReporterAndOneDedicatedConnection(): void
    {
        // Debug resource checking recompiles changed YAML between RED and GREEN without cache deletion.
        $kernel = self::bootKernel(['environment' => 'test', 'debug' => true]);
        $container = self::getContainer();
        self::assertTrue($container->isCompiled());
        $replacement = $container->get(Mp3Replacement::class);
        self::assertInstanceOf(Mp3Replacement::class, $replacement);
        $reporter = $this->property($replacement, 'cleanupReporter');
        self::assertInstanceOf(Mp3CleanupReporter::class, $reporter, 'The container must call setCleanupReporter().');

        // Container::initialized() does not account for private services in this Symfony version.
        $compiled = $kernel->getContainer();
        $instances = (new \ReflectionProperty(Container::class, 'services'))->getValue($compiled)
            + (new \ReflectionProperty(Container::class, 'privates'))->getValue($compiled);
        self::assertArrayNotHasKey('app.mp3_cleanup.connection', $instances);
        self::assertArrayNotHasKey(Mp3CleanupIssueWriter::class, $instances);
        self::assertArrayNotHasKey(Mp3CleanupIssueDbalAdapter::class, $instances);
        self::assertSame([], array_keys(array_filter($instances, static fn (mixed $instance): bool =>
            $instance instanceof Mp3CleanupIssueWriter || $instance instanceof Mp3CleanupIssueDbalAdapter)));

        $recipient = $this->property($reporter, 'recipient');
        self::assertInstanceOf(\Closure::class, $recipient);
        $providers = (new \ReflectionFunction($recipient))->getStaticVariables();
        self::assertInstanceOf(\Closure::class, $providers['connectionProvider']);
        self::assertInstanceOf(\Closure::class, $providers['writerProvider']);
        self::assertFalse($providers['running']);
        $main = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $main);
        self::assertFalse($main->isConnected());
        self::assertSame(0, $main->getTransactionNestingLevel());
        self::assertSame($main, $container->get(Connection::class));

        // Resolve providers explicitly, not by reporting an anomaly: no transaction or SQL is run.
        $this->dedicated = ($providers['connectionProvider'])();
        self::assertSame($this->dedicated, ($providers['connectionProvider'])());
        self::assertSame($this->dedicated, $container->get('app.mp3_cleanup.connection'));
        self::assertNotSame($main, $this->dedicated);
        self::assertSame($main->getParams(), $this->dedicated->getParams());
        self::assertSame('symfony_test', $this->dedicated->getParams()['dbname']);
        self::assertFalse($this->dedicated->isConnected());
        self::assertSame(0, $this->dedicated->getTransactionNestingLevel());

        $writer = ($providers['writerProvider'])();
        self::assertInstanceOf(Mp3CleanupIssueWriter::class, $writer);
        self::assertSame($writer, ($providers['writerProvider'])());
        self::assertSame($this->dedicated, $this->property($writer, 'connection'));
        $grouper = $this->property($writer, 'grouper');
        self::assertInstanceOf(Mp3CleanupIssueGrouper::class, $grouper);
        $adapter = null;
        foreach (['findActiveIssues', 'createIssue', 'appendEvent'] as $method) {
            $callback = $this->property($grouper, $method);
            self::assertInstanceOf(\Closure::class, $callback);
            $reflection = new \ReflectionFunction($callback);
            self::assertSame($method, $reflection->getName());
            $target = $reflection->getClosureThis();
            self::assertInstanceOf(Mp3CleanupIssueDbalAdapter::class, $target);
            if ($adapter !== null) { self::assertSame($adapter, $target); }
            $adapter = $target;
            self::assertSame($this->dedicated, $this->property($target, 'connection'));
        }
        $logger = $this->property($reporter, 'fallbackLogger');
        self::assertSame($container->get('monolog.logger.mp3'), $logger);
        self::assertSame('mp3', $logger->getName());
        self::assertFalse($main->isConnected());
        self::assertFalse($this->dedicated->isConnected());
        self::assertSame(0, $main->getTransactionNestingLevel());
        self::assertSame(0, $this->dedicated->getTransactionNestingLevel());
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object::class, $name))->getValue($object);
    }

    protected function tearDown(): void
    {
        try {
            $this->dedicated?->close();
        } finally {
            $this->dedicated = null;
            parent::tearDown();
        }
    }
}
