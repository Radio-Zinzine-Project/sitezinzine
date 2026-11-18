<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AudioInventoryRunCommand;
use App\Service\AudioLibrary\Inventory\AudioInventoryCommandRunner;
use App\Service\AudioLibrary\Inventory\AudioInventoryReportStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AudioInventoryRunCommandTest extends TestCase
{
    private string $root;
    private int $providers = 0;
    private int $level = 0;
    private bool $connected = true;
    private bool $closed = false;
    private bool $sqlFailure = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-inventory-command-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        foreach (['public', 'library', 'work', 'reports', 'uploads'] as $name) { mkdir($this->root.'/'.$name, 0700); }
        file_put_contents($this->root.'/library/Historical Name.MP3', 'synthetic unchanged');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { $this->remove($path.'/'.$entry); } }
        rmdir($path);
    }

    private function profile(): array
    {
        return ['mp3_root' => $this->root.'/library', 'workspace_root' => $this->root.'/work',
            'report_root' => $this->root.'/reports', 'scopes' => ['web_full', 'test_subset'],
            'local_url_prefixes' => ['https://audio.example.test/mp3'],
            'budgets' => ['max_open_streams' => 5, 'max_runs' => 100, 'max_input_runs' => 2,
                'max_records_per_run' => 2, 'max_path_bytes_per_run' => 4096,
                'max_association_bytes_per_run' => 8192, 'max_path_bytes' => 4096,
                'max_record_bytes' => 8192, 'max_identifier_bytes' => 128,
                'max_diagnostics' => 10, 'max_passes' => 10, 'sql_page_size' => 2,
                'max_associations_per_path' => 10, 'report_page_size' => 2,
                'max_report_page_bytes' => 32768, 'max_manifest_bytes' => 131072, 'max_report_pages' => 20]];
    }

    private function runner(array $profiles): AudioInventoryCommandRunner
    {
        $db = $this->createMock(Connection::class);
        $db->method('getTransactionNestingLevel')->willReturnCallback(fn () => $this->level);
        $db->method('isAutoCommit')->willReturn(true);
        $db->method('isConnected')->willReturnCallback(fn () => $this->connected);
        $db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $db->method('fetchOne')->willReturn('InnoDB');
        $db->method('executeStatement')->willReturnCallback(static function (string $sql): int {
            self::assertSame('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $sql); return 0;
        });
        $db->method('beginTransaction')->willReturnCallback(function (): void { $this->level = 1; });
        $db->method('commit')->willReturnCallback(function (): void { $this->level = 0; });
        $db->method('rollBack')->willReturnCallback(function (): void { $this->level = 0; });
        $db->method('close')->willReturnCallback(function (): void { $this->closed = true; $this->connected = false; });
        $db->method('fetchAllAssociative')->willReturnCallback(function (): array {
            if ($this->sqlFailure) { throw new \RuntimeException('sensitive collection diagnostic'); }
            return [['id' => '1', 'thumbnail_mp3' => 'Historical Name.MP3', 'url' => null]];
        });
        foreach (['insert', 'update', 'delete'] as $method) { $db->expects(self::never())->method($method); }
        return new AudioInventoryCommandRunner($profiles, function () use ($db): Connection { ++$this->providers; return $db; },
            $this->root.'/public', $this->root.'/uploads');
    }

    /** @dataProvider scopes */
    public function testPublishesRealRestrictedReportWithoutChangingMp3(string $scope): void
    {
        $tester = new CommandTester(new AudioInventoryRunCommand($this->runner(['fixture' => $this->profile()])));
        self::assertSame(0, $tester->execute(['--inventory-profile' => 'fixture', '--scope' => $scope]));
        self::assertStringContainsString('terminée avec restrictions', $tester->getDisplay());
        self::assertStringContainsString('storage_certified=false', $tester->getDisplay());
        self::assertStringContainsString('absence_conclusions_allowed=false', $tester->getDisplay());
        preg_match('/inventory_id=([a-f0-9]{32})/', $tester->getDisplay(), $matches);
        self::assertCount(2, $matches);
        $manifest = (new AudioInventoryReportStore($this->root.'/reports', $this->root.'/public', 2, 32768, 131072, 20))->readManifest($matches[1]);
        self::assertSame('published', $manifest['publication']);
        self::assertSame('observation_only', $manifest['report']['collection_mode']);
        self::assertSame($scope, $manifest['report']['scope']);
        $physical = $manifest['report']['preparation']['physical_collection'];
        foreach (['library_id', 'generation', 'storage_kind', 'generation_before', 'generation_after'] as $field) { self::assertNull($physical[$field]); }
        self::assertNotEmpty($physical['evidence_id']);
        self::assertSame(1, $manifest['result_count']);
        self::assertSame(1, $manifest['classification_counts']['associated']);
        self::assertSame('synthetic unchanged', file_get_contents($this->root.'/library/Historical Name.MP3'));
        self::assertSame([], glob($this->root.'/work/inventory-*'));
        self::assertSame(0, $this->level); self::assertTrue($this->closed);
    }

    public static function scopes(): array { return [['web_full'], ['test_subset']]; }

    /** @dataProvider invalidCases */
    public function testRefusesUnsafeOrMissingConfigurationBeforeConnection(string $case): void
    {
        $p = $this->profile(); $name = 'fixture'; $scope = 'test_subset';
        switch ($case) {
            case 'unknown_profile': $name = 'unknown'; break;
            case 'absent_profile': $name = ''; break;
            case 'invalid_scope': $scope = 'other'; break;
            case 'absent_scope': $scope = ''; break;
            case 'scope_not_allowed': $p['scopes'] = ['web_full']; break;
            case 'missing_root': unset($p['mp3_root']); break;
            case 'missing_budget': unset($p['budgets']['max_runs']); break;
            case 'invalid_budget': $p['budgets']['max_runs'] = '100'; break;
            case 'stream_budget': $p['budgets']['max_open_streams'] = 4; break;
            case 'empty_urls': $p['local_url_prefixes'] = []; break;
            case 'invalid_urls': $p['local_url_prefixes'] = ['not a URL']; break;
            case 'public_workspace': $p['workspace_root'] = $this->root.'/public'; break;
            case 'public_reports': $p['report_root'] = $this->root.'/public'; break;
            case 'nested_roots': $p['report_root'] = $this->root.'/work'; break;
            case 'library_workspace': $p['workspace_root'] = $this->root.'/library'; break;
            case 'upload_workspace': $p['workspace_root'] = $this->root.'/uploads'; break;
            case 'relative_root': $p['mp3_root'] = 'library'; break;
            case 'missing_directory': $p['workspace_root'] = $this->root.'/missing'; break;
            case 'traversal': $p['workspace_root'] = $this->root.'/library/../work'; break;
        }
        $tester = new CommandTester(new AudioInventoryRunCommand($this->runner(['fixture' => $p])));
        self::assertSame(2, $tester->execute(['--inventory-profile' => $name, '--scope' => $scope]));
        self::assertSame(0, $this->providers);
        self::assertSame([], glob($this->root.'/reports/inventory-*'));
        self::assertStringNotContainsString('terminée avec restrictions', $tester->getDisplay());
    }

    public static function invalidCases(): array
    {
        return array_map(static fn ($v) => [$v], ['unknown_profile', 'absent_profile', 'invalid_scope', 'absent_scope', 'scope_not_allowed',
            'missing_root', 'missing_budget', 'invalid_budget', 'stream_budget', 'empty_urls', 'invalid_urls', 'public_workspace',
            'public_reports', 'nested_roots', 'library_workspace', 'upload_workspace', 'relative_root', 'missing_directory', 'traversal']);
    }

    public function testCollectionFailureNeverPublishesOrLeaksRawDiagnostic(): void
    {
        $this->sqlFailure = true;
        $tester = new CommandTester(new AudioInventoryRunCommand($this->runner(['fixture' => $this->profile()])));
        self::assertSame(1, $tester->execute(['--inventory-profile' => 'fixture', '--scope' => 'web_full']));
        self::assertStringNotContainsString('sensitive', $tester->getDisplay());
        self::assertStringNotContainsString('terminée avec restrictions', $tester->getDisplay());
        self::assertSame([], glob($this->root.'/reports/inventory-*/manifest.json'));
        self::assertSame([], glob($this->root.'/work/inventory-*'));
        self::assertTrue($this->closed); self::assertSame(0, $this->level);
    }

    public function testStorageBudgetFailureNeverPublishes(): void
    {
        $p = $this->profile(); $p['budgets']['max_report_page_bytes'] = 1;
        $tester = new CommandTester(new AudioInventoryRunCommand($this->runner(['fixture' => $p])));
        self::assertSame(1, $tester->execute(['--inventory-profile' => 'fixture', '--scope' => 'test_subset']));
        self::assertSame([], glob($this->root.'/reports/inventory-*/manifest.json'));
        self::assertStringNotContainsString('terminée avec restrictions', $tester->getDisplay());
        self::assertSame([], glob($this->root.'/work/inventory-*')); self::assertTrue($this->closed);
    }

    public function testSignalRequestsCooperativeInterruptionWithoutPublication(): void
    {
        $command = new AudioInventoryRunCommand($this->runner(['fixture' => $this->profile()]));
        $command->handleSignal(15);
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute(['--inventory-profile' => 'fixture', '--scope' => 'test_subset']));
        self::assertStringContainsString('interrompu', $tester->getDisplay());
        self::assertSame([], glob($this->root.'/reports/inventory-*/manifest.json'));
        self::assertSame([], glob($this->root.'/work/inventory-*'));
        self::assertSame(0, $this->level); self::assertTrue($this->closed);
    }

    public function testForeignTransactionIsNotClosedOrModified(): void
    {
        $this->level = 1;
        $tester = new CommandTester(new AudioInventoryRunCommand($this->runner(['fixture' => $this->profile()])));
        self::assertSame(1, $tester->execute(['--inventory-profile' => 'fixture', '--scope' => 'test_subset']));
        self::assertSame(1, $this->level); self::assertFalse($this->closed);
        self::assertSame([], glob($this->root.'/reports/inventory-*'));
    }

    public function testSymfonyYamlAssemblyCompilesWithoutKernelOrDatabaseAndRemainsLazy(): void
    {
        $project = dirname(__DIR__, 2);
        $loaded = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $loaded->setParameter('kernel.project_dir', $project);
        (new \Symfony\Component\DependencyInjection\Loader\YamlFileLoader($loaded,
            new \Symfony\Component\Config\FileLocator($project.'/config')))->load('services.yaml');
        self::assertSame([], $loaded->getParameter('app.audio_inventory.profiles'));
        // Compile only the real inventory declarations, without booting the application.
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->setParameter('kernel.project_dir', $project);
        $container->setParameter('app.mp3_work_dir', $this->root.'/uploads');
        $container->setParameter('app.audio_inventory.profiles', []);
        foreach ([AudioInventoryRunCommand::class, AudioInventoryCommandRunner::class, 'app.audio_inventory.connection'] as $id) {
            $container->setDefinition($id, clone $loaded->getDefinition($id));
        }
        $container->getDefinition(AudioInventoryRunCommand::class)->setPublic(true);
        $container->register('doctrine.dbal.default_connection', Connection::class)->setSynthetic(true)->setPublic(true);
        $container->compile();
        // No default connection has even been supplied: construction must remain lazy.
        $command = $container->get(AudioInventoryRunCommand::class);
        self::assertSame('app:audio-inventory:run', $command->getName());
        $tester = new CommandTester($command);
        self::assertSame(2, $tester->execute(['--inventory-profile' => 'missing', '--scope' => 'test_subset']));
    }

    public function testSymbolicParentIsRejectedBeforeConnection(): void
    {
        if (PHP_OS_FAMILY === 'Windows') { self::markTestSkipped('POSIX symbolic-link validation requires Linux.'); }
        symlink($this->root.'/work', $this->root.'/alias');
        $p = $this->profile(); $p['workspace_root'] = $this->root.'/alias';
        $tester = new CommandTester(new AudioInventoryRunCommand($this->runner(['fixture' => $p])));
        self::assertSame(2, $tester->execute(['--inventory-profile' => 'fixture', '--scope' => 'test_subset']));
        self::assertSame(0, $this->providers);
    }

    public function testRealFrameworkApplicationAcceptsInventoryOptionAndRejectsUnknownProfileWithoutCollecting(): void
    {
        $kernel = new \App\Kernel('test', false);
        try {
            $app = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $app->setAutoExit(false);
            $app->setCatchExceptions(false);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            // PHPUnit sets SHELL_VERBOSITY=-1; explicitly request visible output.
            self::assertSame(0, $app->run(new \Symfony\Component\Console\Input\ArrayInput([
                'command' => 'help', 'command_name' => 'app:audio-inventory:run', '--verbose' => true,
            ]), $output));
            self::assertStringContainsString('--inventory-profile', $output->fetch());
            $command = $app->find('app:audio-inventory:run');
            self::assertTrue($command->getNativeDefinition()->hasOption('inventory-profile'));
            self::assertFalse($command->getNativeDefinition()->hasOption('profile'));
            // The global profiler option remains available with its original semantics.
            self::assertTrue($app->getDefinition()->hasOption('profile'));
            self::assertFalse($app->getDefinition()->getOption('profile')->acceptValue());
            self::assertSame([], $kernel->getContainer()->getParameter('app.audio_inventory.profiles'));
            $db = $kernel->getContainer()->get('doctrine')->getConnection();
            self::assertFalse($db->isConnected());
            self::assertSame(2, $app->run(new \Symfony\Component\Console\Input\ArrayInput([
                'command' => 'app:audio-inventory:run', '--inventory-profile' => 'nonexistent', '--scope' => 'test_subset', '--verbose' => true,
            ]), $output));
            $display = $output->fetch();
            self::assertStringContainsString('Configuration ou options invalides', $display);
            self::assertStringNotContainsString('inventory_id=', $display);
            self::assertStringNotContainsString('terminée avec restrictions', $display);
            self::assertFalse($db->isConnected());
        } finally {
            $kernel->shutdown();
        }
    }
}
