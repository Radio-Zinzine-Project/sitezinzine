<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Inventory;

use App\Entity\User;
use App\Service\AudioLibrary\Inventory\AudioInventoryCommandRunner;
use App\Service\AudioLibrary\Inventory\AudioInventoryReportDiscovery;
use App\Service\AudioLibrary\Inventory\AudioInventoryReportStore;
use App\Service\AudioLibrary\Orphan\Mp3CleanupServiceFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Real command, SQL collector, filesystem, report store, router, security and Twig.
 * MySQL data is isolated in a session-local TEMPORARY emission projection, not in
 * persistent business rows. This does not validate the full emission schema.
 * Only user identity refresh is doubled; SQL/collection/reconciliation are real.
 */
final class AudioInventoryEndToEndTest extends WebTestCase
{
    private ?string $root = null;
    private ?array $rootIdentity = null;
    /** @var list<Connection> */
    private array $connections = [];
    private array $evidence = [];

    public function testSyntheticFilesThroughRealSqlAndCommandReachReadOnlyAdministration(): void
    {
        $client = static::createClient(['environment' => 'test', 'debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertSame([], $container->getParameter('app.audio_inventory.profiles'));
        $business = $container->get('doctrine')->getConnection();
        // This guard precedes every physical connection, temporary DDL and fixture write.
        if (($business->getParams()['dbname'] ?? null) !== 'symfony_test') {
            throw new \LogicException('End-to-end inventory exclusively permits symfony_test.');
        }
        self::assertFalse($business->isConnected());
        $collector = $this->connection($business);
        $observer = $this->connection($business);
        $session = (int)$collector->fetchOne('SELECT CONNECTION_ID()');
        $observerSession = (int)$observer->fetchOne('SELECT CONNECTION_ID()');
        self::assertNotSame($session, $observerSession);
        $version = (string)$collector->fetchOne('SELECT VERSION()');
        self::assertMatchesRegularExpression('/^8\./', $version);
        self::assertSame('InnoDB', $collector->fetchOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emission'"));
        $persistentDefinition = $observer->fetchAssociative('SHOW CREATE TABLE emission')['Create Table'];
        self::assertStringNotContainsString('CREATE TEMPORARY TABLE', $persistentDefinition);

        // Register this exclusively owned directory before any fixture file write.
        $this->root = sys_get_temp_dir().'/zinzine-inventory-e2e-'.bin2hex(random_bytes(12));
        if (!mkdir($this->root, 0700)) { throw new \RuntimeException('Cannot create isolated inventory fixtures.'); }
        $this->rootIdentity = lstat($this->root);
        foreach (['library', 'workspace', 'reports', 'uploads'] as $name) { mkdir($this->root.'/'.$name, 0700); }
        $fileHashes = [];
        foreach (['associated.mp3', 'orphan.mp3', 'shared.mp3'] as $path) {
            file_put_contents($this->root.'/library/'.$path, 'synthetic extension-only fixture; not audio');
            $fileHashes[$path] = hash_file('sha256', $this->root.'/library/'.$path);
        }

        // TEMPORARY is mandatory: no persistent table or real row is changed.
        $collector->executeStatement('CREATE TEMPORARY TABLE emission (
            id INT NOT NULL PRIMARY KEY, thumbnail_mp3 VARBINARY(1020) NULL,
            url TEXT NULL, deleted_at DATETIME NULL
        ) ENGINE=InnoDB');
        self::assertStringContainsString('CREATE TEMPORARY TABLE', $collector->fetchAssociative('SHOW CREATE TABLE emission')['Create Table']);
        self::assertSame(0, (int)$collector->fetchOne('SELECT COUNT(*) FROM emission'));
        $base = 'https://inventory.example.test/mp3';
        $fixtures = [
            [1, 'associated.mp3', $base.'/associated.mp3', null],
            [2, 'missing.mp3', null, null],
            [3, 'shared.mp3', null, null],
            [4, null, $base.'/shared.mp3', '2026-01-01 00:00:00'],
            [5, null, 'https://external.example.test/mp3/orphan.mp3', null],
        ];
        foreach ($fixtures as [$id, $path, $url, $deleted]) {
            self::assertSame('symfony_test', $collector->fetchOne('SELECT DATABASE()'));
            $collector->insert('emission', ['id' => $id, 'thumbnail_mp3' => $path, 'url' => $url, 'deleted_at' => $deleted]);
        }
        self::assertSame(5, (int)$collector->fetchOne('SELECT COUNT(*) FROM emission'));
        self::assertNotNull($collector->fetchOne('SELECT deleted_at FROM emission WHERE id = 4'));
        // A second physical session still sees only the original persistent table.
        self::assertSame($persistentDefinition, $observer->fetchAssociative('SHOW CREATE TABLE emission')['Create Table']);

        $profiles = ['synthetic' => ['mp3_root' => $this->root.'/library', 'workspace_root' => $this->root.'/workspace',
            'report_root' => $this->root.'/reports', 'scopes' => ['test_subset'], 'local_url_prefixes' => [$base],
            'budgets' => ['max_open_streams' => 5, 'max_runs' => 100, 'max_input_runs' => 2,
                'max_records_per_run' => 2, 'max_path_bytes_per_run' => 4096, 'max_association_bytes_per_run' => 8192,
                'max_path_bytes' => 4096, 'max_record_bytes' => 8192, 'max_identifier_bytes' => 128,
                'max_diagnostics' => 10, 'max_passes' => 10, 'sql_page_size' => 2, 'max_associations_per_path' => 10,
                'report_page_size' => 2, 'max_report_page_bytes' => 32768, 'max_manifest_bytes' => 131072, 'max_report_pages' => 100]]];
        $public = $container->getParameter('kernel.project_dir').'/public';
        // Per-test object overrides only; no configuration file/parameter is edited.
        $runner = new AudioInventoryCommandRunner($profiles, static fn (): Connection => $collector, $public, $this->root.'/uploads');
        $discovery = new AudioInventoryReportDiscovery($profiles, $public, 20);
        $container->set(AudioInventoryCommandRunner::class, $runner);
        $container->set(AudioInventoryReportDiscovery::class, $discovery);
        $application = new Application(self::$kernel);
        $application->setAutoExit(false); $application->setCatchExceptions(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:audio-inventory:run',
            '--inventory-profile' => 'synthetic', '--scope' => 'test_subset', '--verbose' => true]), $output));
        $display = $output->fetch();
        self::assertStringContainsString('terminée avec restrictions', $display);
        self::assertSame(1, preg_match('/inventory_id=([a-f0-9]{32})/', $display, $match));
        $inventoryId = $match[1];
        self::assertFalse($collector->isConnected()); self::assertSame(0, $collector->getTransactionNestingLevel());
        self::assertFalse($business->isConnected());
        self::assertSame(0, (int)$observer->fetchOne('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ?', [$session]));
        self::assertSame($persistentDefinition, $observer->fetchAssociative('SHOW CREATE TABLE emission')['Create Table']);

        $inspection = $discovery->readManifest('synthetic', $inventoryId);
        self::assertSame('published', $inspection['status']); $manifest = $inspection['manifest'];
        self::assertSame(4, $manifest['result_count']); self::assertSame(2, $manifest['page_count']);
        self::assertSame(3, $manifest['physical_paths']); self::assertSame(3, $manifest['referenced_paths']);
        self::assertSame(5, $manifest['association_count']);
        self::assertSame(['associated' => 1, 'multiple_associations' => 1, 'orphan_candidate' => 1,
            'broken_reference' => 0, 'indeterminate' => 1], $manifest['classification_counts']);
        self::assertSame('observation_only', $manifest['report']['collection_mode']);
        self::assertFalse($manifest['report']['restrictions']['storage_certified']);
        self::assertFalse($manifest['report']['restrictions']['absence_conclusions_allowed']);
        self::assertTrue($manifest['report']['restrictions']['references_complete']);
        self::assertSame('repeatable_read', $manifest['report']['preparation']['sql_export']['references']['temporal_consistency']);
        self::assertSame(3, $manifest['report']['preparation']['sql_export']['references']['pages_read']);
        $rows = [];
        foreach ([1, 2] as $page) { foreach ($discovery->readPage('synthetic', $inventoryId, $page)['results'] as $row) { $rows[$row['relative_path']] = $row; } }
        self::assertSame(['associated.mp3', 'missing.mp3', 'orphan.mp3', 'shared.mp3'], array_keys($rows));
        self::assertSame('associated', $rows['associated.mp3']['classification']); self::assertCount(2, $rows['associated.mp3']['associations']);
        self::assertSame('orphan_candidate', $rows['orphan.mp3']['classification']); self::assertSame([], $rows['orphan.mp3']['associations']);
        self::assertSame('indeterminate', $rows['missing.mp3']['classification']); self::assertNull($rows['missing.mp3']['present']);
        self::assertSame('multiple_associations', $rows['shared.mp3']['classification']); self::assertSame([3, 4], $rows['shared.mp3']['emission_ids']);
        self::assertTrue($rows['shared.mp3']['associations'][1]['thumbnail_missing']);
        self::assertStringNotContainsString('external.example.test', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertSame([], glob($this->root.'/workspace/inventory-*'));

        $provider = $this->createMock(EntityUserProvider::class);
        $provider->method('supportsClass')->willReturn(true);
        $provider->method('refreshUser')->willReturnCallback(static fn ($user) => $user);
        $container->set('security.user.provider.concrete.app_user_provider', $provider);
        $user = static fn (string $role): User => (new User())->setUsername('inventory-e2e')->setPassword('synthetic')
            ->setRoles([$role])->setVerified(true)->setApproved(true);
        $path = '/admin/audio-library/inventory/synthetic/'.$inventoryId;
        $client->loginUser($user('ROLE_ADMIN'));
        $client->request('GET', $path); self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
        self::assertStringNotContainsString($inventoryId, $client->getResponse()->getContent());
        $client->loginUser($user('ROLE_SUPER_ADMIN'));
        $client->request('GET', '/admin/audio-library/inventory?profile=synthetic');
        self::assertSame(200, $client->getResponse()->getStatusCode()); self::assertStringContainsString($inventoryId, $client->getResponse()->getContent());
        $crawler = $client->request('GET', $path.'?page=1');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('associated.mp3', $client->getResponse()->getContent());
        self::assertStringContainsString('missing.mp3', $client->getResponse()->getContent());
        self::assertStringContainsString('présence non déterminée', $client->getResponse()->getContent());
        self::assertStringNotContainsString('shared.mp3', $client->getResponse()->getContent());
        self::assertSame(1, $crawler->selectLink('Suivant')->count());
        $client->click($crawler->selectLink('Suivant')->link());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('orphelin potentiel', $html); self::assertStringContainsString('shared.mp3', $html);
        self::assertStringContainsString('aucune conclusion d\'absence autorisée', $html);
        self::assertStringContainsString('/admin/emission/3', $html); self::assertStringContainsString('/admin/emission/4', $html);
        self::assertStringContainsString('Précédent', $html); self::assertStringNotContainsString('>Suivant</a>', $html);
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertStringNotContainsString($this->root, $html);

        $store = new AudioInventoryReportStore($this->root.'/reports', $public, 2, 32768, 131072, 100);
        $provisional = $store->begin(); $store->abort();
        $client->request('GET', '/admin/audio-library/inventory/synthetic/'.$provisional);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Rapport non publié', $client->getResponse()->getContent());
        $client->request('POST', '/admin/audio-library/inventory'); self::assertSame(405, $client->getResponse()->getStatusCode());
        self::assertFalse($business->isConnected()); self::assertFalse($collector->isConnected());
        self::assertSame([], $container->getParameter('app.audio_inventory.profiles'));
        foreach ($fileHashes as $file => $hash) { self::assertSame($hash, hash_file('sha256', $this->root.'/library/'.$file)); }
        self::assertSame(2, count(glob($this->root.'/reports/inventory-*')));
        $this->evidence = ['environment' => 'test', 'database' => 'symfony_test', 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'mysql' => $version,
            'collector_session' => $session, 'observer_session' => $observerSession,
            'physical_files' => 3, 'temporary_sql_rows' => 5, 'result_rows' => 4, 'result_pages' => 2,
            'classifications' => $manifest['classification_counts'], 'persistent_business_writes' => 0];
    }

    private function connection(Connection $business): Connection
    {
        $connection = Mp3CleanupServiceFactory::createConnection($business);
        $this->connections[] = $connection; // Own it before the first physical access.
        self::assertSame('symfony_test', $connection->getParams()['dbname'] ?? null);
        self::assertSame('symfony_test', $connection->fetchOne('SELECT DATABASE()'));
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
        return $connection;
    }

    protected function tearDown(): void
    {
        $failures = [];
        foreach ($this->connections as $connection) {
            try { while ($connection->isConnected() && $connection->getTransactionNestingLevel() > 0) { $connection->rollBack(); } }
            catch (\Throwable $error) { $failures[] = $error; }
            finally { try { $connection->close(); } catch (\Throwable $error) { $failures[] = $error; } }
        }
        try {
            if ($this->root !== null) {
                $stat = lstat($this->root);
                if ($stat === false || is_link($this->root) || $this->rootIdentity === null
                    || $stat['dev'] !== $this->rootIdentity['dev'] || $stat['ino'] !== $this->rootIdentity['ino']) {
                    throw new \RuntimeException('Unsafe end-to-end fixture cleanup root.');
                }
                $this->remove($this->root);
                self::assertDirectoryDoesNotExist($this->root);
            }
        } catch (\Throwable $error) { $failures[] = $error; }
        finally { $this->connections = []; try { parent::tearDown(); } catch (\Throwable $error) { $failures[] = $error; } }
        if ($this->evidence !== []) { fwrite(STDERR, 'INVENTORY_E2E '.json_encode($this->evidence + ['cleanup_complete' => $failures === []], JSON_THROW_ON_ERROR).PHP_EOL); }
        foreach ($failures as $error) { fwrite(STDERR, 'Inventory end-to-end cleanup failed: '.$error::class.PHP_EOL); }
        if ($failures !== [] && !$this->hasFailed()) { throw $failures[0]; }
    }

    private function remove(string $path): void
    {
        if (is_link($path)) { unlink($path); return; }
        if (!is_dir($path)) { if (!unlink($path)) { throw new \RuntimeException('Cannot remove owned fixture file.'); } return; }
        $directory = opendir($path);
        if ($directory === false) { throw new \RuntimeException('Cannot enumerate owned fixture directory.'); }
        try { while (($entry = readdir($directory)) !== false) { if ($entry !== '.' && $entry !== '..') { $this->remove($path.DIRECTORY_SEPARATOR.$entry); } } }
        finally { closedir($directory); }
        if (!rmdir($path)) { throw new \RuntimeException('Cannot remove owned fixture directory.'); }
    }
}
