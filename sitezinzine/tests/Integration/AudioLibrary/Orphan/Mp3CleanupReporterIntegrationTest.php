<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueDbalAdapter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueWriter;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\Service\Mp3Replacement;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpKernel\KernelInterface;

/** Actual Symfony graph and MySQL sessions, one synthetic identity, no MP3 or ORM hydration. */
final class Mp3CleanupReporterIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    private ?Connection $business = null;
    private ?Connection $dedicated = null;
    private ?string $fixturePath = null;
    private array $verifiedParams = [];
    private array $evidence = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        // Check resources for the newly wired YAML; do not reuse a stale non-debug container or clear caches.
        return parent::createKernel(array_replace($options, ['environment' => 'test', 'debug' => true]));
    }

    public function testConfiguredReporterCommitsIndependentlyOfBusinessRollback(): void
    {
        $container = self::getContainer();
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertSame('test', $container->getParameter('kernel.environment'));
        $this->business = $container->get('doctrine.dbal.default_connection');
        // Check the resolved schema before opening either physical application connection.
        self::assertSame('symfony_test', $this->business->getParams()['dbname'] ?? null);
        $this->dedicated = $container->get('app.mp3_cleanup.connection');
        self::assertSame('symfony_test', $this->dedicated->getParams()['dbname'] ?? null);
        self::assertNotSame($this->business, $this->dedicated);
        self::assertSame(0, $this->business->getTransactionNestingLevel());
        self::assertSame(0, $this->dedicated->getTransactionNestingLevel());
        self::assertSame('symfony_test', $this->business->fetchOne('SELECT DATABASE()'));
        self::assertSame('symfony_test', $this->dedicated->fetchOne('SELECT DATABASE()'));
        $tables = $this->business->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?) AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            ['symfony_test', 'mp3_cleanup_issue', 'mp3_cleanup_event', 'BASE TABLE'],
        );
        self::assertSame(['mp3_cleanup_event', 'mp3_cleanup_issue'], $tables, 'Required cleanup tables must exist before any fixture write.');

        $observer = $this->newConnection();
        $sessions = [
            'business' => (int) $this->business->fetchOne('SELECT CONNECTION_ID()'),
            'dedicated' => (int) $this->dedicated->fetchOne('SELECT CONNECTION_ID()'),
            'observer' => $this->connectionId($observer),
        ];
        foreach ($sessions as $session) { self::assertGreaterThan(0, $session); }
        self::assertCount(3, array_unique($sessions));
        $this->evidence = ['database' => 'symfony_test', 'sessions' => $sessions];

        $replacement = $container->get(Mp3Replacement::class);
        $reporter = $this->property($replacement, 'cleanupReporter');
        self::assertInstanceOf(Mp3CleanupReporter::class, $reporter);
        $recipient = $this->property($reporter, 'recipient');
        $providers = (new \ReflectionFunction($recipient))->getStaticVariables();
        self::assertSame($this->dedicated, ($providers['connectionProvider'])());
        $writer = ($providers['writerProvider'])();
        self::assertInstanceOf(Mp3CleanupIssueWriter::class, $writer);
        self::assertSame($this->dedicated, $this->property($writer, 'connection'));
        $grouper = $this->property($writer, 'grouper');
        $adapter = (new \ReflectionFunction($this->property($grouper, 'appendEvent')))->getClosureThis();
        self::assertInstanceOf(Mp3CleanupIssueDbalAdapter::class, $adapter);
        self::assertSame($this->dedicated, $this->property($adapter, 'connection'));
        self::assertNotSame($this->business, $this->property($adapter, 'connection'));
        self::assertSame(0, $this->business->getTransactionNestingLevel());
        $this->evidence['writer_adapter_share_dedicated'] = true;

        // Register the identity before the real reporter is allowed to write.
        $this->verifiedParams = $this->business->getParams();
        $this->fixturePath = $this->newFixturePath();
        $newPath = dirname($this->fixturePath) . '/replacement.mp3';
        self::assertSame(0, (int) $observer->fetchOne(
            'SELECT COUNT(*) FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?',
            ['emissionsMp3', $this->fixturePath],
        ));
        $this->business->beginTransaction();
        $this->business->createSavepoint('mp3_cleanup_business_guard');
        self::assertSame(1, $this->business->getTransactionNestingLevel());
        self::assertTrue($this->business->getNativeConnection()->inTransaction());

        // The sole anomaly write goes through the configured reporter, recipient, writer and adapter.
        $reporter->reportReferenceCheckFailure(42, $this->fixturePath, $newPath, new \RuntimeException('Synthetic cleanup diagnostic', 19));
        self::assertFalse($this->dedicated->isConnected(), 'The configured recipient must close its dedicated session.');
        self::assertSame(0, $this->dedicated->getTransactionNestingLevel());
        self::assertTrue($this->business->isConnected());
        self::assertSame(1, $this->business->getTransactionNestingLevel());
        self::assertTrue($this->business->getNativeConnection()->inTransaction());
        self::assertSame($sessions['business'], (int) $this->business->fetchOne('SELECT CONNECTION_ID()'));
        // A commit or full rollback, even followed by a new begin, would have destroyed this savepoint.
        $this->business->rollbackSavepoint('mp3_cleanup_business_guard');
        $this->business->releaseSavepoint('mp3_cleanup_business_guard');
        $this->evidence['business_savepoint_preserved'] = true;

        $issues = $observer->fetchAllAssociative(
            'SELECT id, storage, obsolete_relative_path, status, active_marker, created_at, resolved_at
             FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?',
            ['emissionsMp3', $this->fixturePath],
        );
        self::assertCount(1, $issues, 'The anomaly must be committed and visible to an independent observer before business rollback.');
        $issue = $issues[0];
        $id = (int) $issue['id'];
        self::assertGreaterThan(0, $id);
        self::assertSame('emissionsMp3', $issue['storage']);
        self::assertSame($this->fixturePath, $issue['obsolete_relative_path']);
        self::assertSame('open', $issue['status']);
        self::assertSame(1, (int) $issue['active_marker']);
        self::assertNotEmpty($issue['created_at']);
        self::assertNull($issue['resolved_at']);
        $events = $observer->fetchAllAssociative(
            'SELECT id, issue_id, occurred_at, reason, emission_id, obsolete_relative_path, context, diagnostic
             FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id', [$id],
        );
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertGreaterThan(0, (int) $event['id']);
        self::assertSame($id, (int) $event['issue_id']);
        self::assertNotEmpty($event['occurred_at']);
        self::assertSame('reference_check_failed', $event['reason']);
        self::assertSame(42, (int) $event['emission_id']);
        self::assertSame($this->fixturePath, $event['obsolete_relative_path']);
        $context = json_decode($event['context'], true, 512, JSON_THROW_ON_ERROR);
        $diagnostic = json_decode($event['diagnostic'], true, 512, JSON_THROW_ON_ERROR);
        ksort($context);
        ksort($diagnostic);
        self::assertSame(['commit_confirmed' => true, 'reference_count' => null, 'replacement_relative_path' => $newPath], $context);
        self::assertSame(['class' => \RuntimeException::class, 'code' => 19, 'sql_state' => null], $diagnostic);

        $this->business->rollBack();
        self::assertSame(0, $this->business->getTransactionNestingLevel());
        self::assertFalse($this->business->getNativeConnection()->inTransaction());
        self::assertSame($issues, $observer->fetchAllAssociative(
            'SELECT id, storage, obsolete_relative_path, status, active_marker, created_at, resolved_at
             FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', ['emissionsMp3', $this->fixturePath],
        ));
        self::assertSame($events, $observer->fetchAllAssociative(
            'SELECT id, issue_id, occurred_at, reason, emission_id, obsolete_relative_path, context, diagnostic
             FROM mp3_cleanup_event WHERE issue_id = ? ORDER BY id', [$id],
        ));
        self::assertSame($sessions['observer'], $this->connectionId($observer));
        self::assertSame(0, $observer->getTransactionNestingLevel());
        self::assertFalse($this->dedicated->isConnected());
        $this->evidence += ['issue_id' => $id, 'events' => count($events), 'survives_business_rollback' => true,
            'dedicated_closed' => true];
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object::class, $name))->getValue($object);
    }

    protected function tearDown(): void
    {
        $failures = [];
        foreach ([$this->dedicated, $this->business] as $connection) {
            if ($connection === null) { continue; }
            try {
                while ($connection->isConnected() && ($level = $connection->getTransactionNestingLevel()) > 0) {
                    $connection->rollBack();
                    if ($connection->getTransactionNestingLevel() >= $level) { throw new \LogicException('Cleanup rollback made no progress.'); }
                }
            } catch (\Throwable $error) {
                $failures[] = $error;
            } finally {
                try { $connection->close(); } catch (\Throwable $error) { $failures[] = $error; }
            }
        }
        try { parent::tearDown(); } catch (\Throwable $error) { $failures[] = $error; }
        $verification = null;
        try {
            if ($this->fixturePath !== null && ($this->verifiedParams['dbname'] ?? null) === 'symfony_test') {
                $verification = DriverManager::getConnection($this->verifiedParams);
                self::assertSame('symfony_test', $verification->fetchOne('SELECT DATABASE()'));
                self::assertSame(0, (int) $verification->fetchOne(
                    'SELECT COUNT(*) FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', ['emissionsMp3', $this->fixturePath],
                ));
                self::assertSame(0, (int) $verification->fetchOne(
                    'SELECT COUNT(*) FROM mp3_cleanup_event WHERE obsolete_relative_path = ?', [$this->fixturePath],
                ));
                $this->evidence['targeted_cleanup_verified'] = true;
            }
        } catch (\Throwable $error) {
            $failures[] = $error;
        } finally {
            try { $verification?->close(); } catch (\Throwable $error) { $failures[] = $error; }
            $this->evidence['connections_closed'] = !($this->dedicated?->isConnected() ?? false)
                && !($this->business?->isConnected() ?? false) && !($verification?->isConnected() ?? false);
            $this->business = $this->dedicated = null;
            $this->verifiedParams = [];
        }
        $this->evidence['cleanup_errors'] = array_map(static fn (\Throwable $error): string => $error::class, $failures);
        if (($report = getenv('MP3_CLEANUP_REPORTER_EVIDENCE')) !== false && $report !== '') {
            try {
                if (file_put_contents($report, json_encode($this->evidence, JSON_THROW_ON_ERROR)) === false) {
                    throw new \RuntimeException('Cannot save integration evidence.');
                }
            } catch (\Throwable $error) { $failures[] = $error; }
        }
        $this->evidence = [];
        if ($failures !== []) {
            foreach ($failures as $error) { fwrite(STDERR, 'MP3 reporter integration cleanup failed: ' . $error::class . PHP_EOL); }
            if (!$this->hasFailed()) { throw $failures[0]; }
        }
    }
}
