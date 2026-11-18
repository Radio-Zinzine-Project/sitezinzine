<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

use Doctrine\DBAL\Connection;

/**
 * Per-execution composition only. Profiles are trusted deployment configuration,
 * never CLI paths. No volume identity, generation or certification is inferred.
 * A provider must supply an exclusively dedicated connection (not an ORM alias).
 * The existing registry/cumulative run budget and lack of a disk quota still apply.
 * Parents must not be replaceable by untrusted actors; PHP cannot prevent TOCTOU.
 */
final class AudioInventoryCommandRunner
{
    private const BUDGETS = ['max_open_streams', 'max_runs', 'max_input_runs', 'max_records_per_run',
        'max_path_bytes_per_run', 'max_association_bytes_per_run', 'max_path_bytes', 'max_record_bytes',
        'max_identifier_bytes', 'max_diagnostics', 'max_passes', 'sql_page_size', 'max_associations_per_path',
        'report_page_size', 'max_report_page_bytes', 'max_manifest_bytes', 'max_report_pages'];

    private array $secondaryErrors = [];

    public function __construct(private readonly array $profiles, private readonly \Closure $connectionProvider,
        private readonly string $publicRoot, private readonly string $uploadWorkRoot) {}

    /** Secondary cleanup failures are exposed without replacing the primary error. */
    public function getSecondaryErrors(): array { return $this->secondaryErrors; }

    public function run(string $profile, string $scope, ?\Closure $continue = null): array
    {
        $this->secondaryErrors = [];
        [$p, $resolver] = $this->validate($profile, $scope);
        $b = $p['budgets'];
        $workspace = null; $connection = null; $owned = false; $primary = null;
        try {
            $connection = ($this->connectionProvider)();
            if (!$connection instanceof Connection || $connection->getTransactionNestingLevel() !== 0 || !$connection->isAutoCommit()) {
                throw new \LogicException('Inventory requires an exclusively dedicated idle connection.');
            }
            $owned = true;
            $workspace = new AudioInventoryTemporaryWorkspace($p['workspace_root'], $b['max_open_streams'], $b['max_runs']);
            $catalog = new AudioInventoryWorkspaceRunCatalog($workspace, new AudioInventoryRunCatalogCodec($b['max_identifier_bytes']));
            $associations = new AudioInventoryAssociationRunCodec($b['max_record_bytes']);
            $exporter = new AudioInventoryAssociationExporter(new AudioInventoryReferenceCollector($connection, $resolver), $workspace,
                $associations, new AudioInventoryIncrementalRunCatalogWriter($workspace, $b['max_identifier_bytes']), $catalog,
                $b['max_diagnostics'], $b['sql_page_size']);
            $preparation = new AudioInventoryInputPreparation($workspace, $exporter, new AudioInventoryScanner($p['mp3_root']),
                new AudioInventoryPathRunCodec($b['max_path_bytes']), $associations, $b['max_input_runs'], $b['max_records_per_run'],
                $b['max_path_bytes_per_run'], $b['max_association_bytes_per_run'], $b['max_diagnostics'], $b['max_passes']);
            $store = new AudioInventoryReportStore($p['report_root'], $this->publicRoot, $b['report_page_size'],
                $b['max_report_page_bytes'], $b['max_manifest_bytes'], $b['max_report_pages']);
            $generator = new AudioInventoryReportGenerator($preparation,
                new AudioInventoryReconciler(new AudioInventoryClassifier(), $b['max_associations_per_path']), $store);
            // This random ID identifies THIS observation, not the library or generation.
            return $generator->run($p['mp3_root'], ['scope' => $scope,
                'source' => 'audio_inventory_cli', 'evidence_id' => bin2hex(random_bytes(16))], null, $continue, 'observation_only');
        } catch (\Throwable $error) {
            $primary = $error;
            throw $error;
        } finally {
            // Always attempt both cleanups, including when construction failed.
            foreach ([static function () use ($connection, $owned): void { if ($owned) { $connection->close(); } },
                static function () use ($workspace): void { if ($workspace !== null) { $workspace->cleanup(); } }] as $cleanup) {
                try { $cleanup(); } catch (\Throwable $error) { $this->secondaryErrors[] = $error; }
            }
            if ($primary === null && $this->secondaryErrors !== []) {
                // A report may already be published: callers must not announce clean success.
                throw new \RuntimeException('Inventory finished with a resource cleanup failure.', 0, $this->secondaryErrors[0]);
            }
        }
    }

    private function validate(string $profile, string $scope): array
    {
        if ($profile === '' || !is_array($this->profiles[$profile] ?? null)
            || !in_array($scope, ['web_full', 'test_subset'], true)) {
            throw new \InvalidArgumentException('An authorized profile and explicit web_full or test_subset scope are required.');
        }
        $p = $this->profiles[$profile];
        if (!is_array($p['scopes'] ?? null) || $p['scopes'] === [] || !array_is_list($p['scopes'])
            || !in_array($scope, $p['scopes'], true)
            || !is_array($p['local_url_prefixes'] ?? null) || !array_is_list($p['local_url_prefixes'])
            || !is_array($p['budgets'] ?? null)) {
            throw new \InvalidArgumentException('Incomplete inventory profile.');
        }
        foreach ($p['scopes'] as $allowed) {
            if (!in_array($allowed, ['web_full', 'test_subset'], true)) { throw new \InvalidArgumentException('Unknown configured scope.'); }
        }
        $resolver = new AudioLocalUrlResolver($p['local_url_prefixes']);
        $b = $p['budgets'];
        foreach (self::BUDGETS as $key) {
            if (!is_int($b[$key] ?? null) || $b[$key] < 1) { throw new \InvalidArgumentException('Every resource budget must be an explicit positive integer.'); }
        }
        if ($b['max_input_runs'] < 2 || $b['max_input_runs'] > PHP_INT_MAX - 3
            || $b['max_open_streams'] < $b['max_input_runs'] + 3 || $b['max_identifier_bytes'] < 48
            || $b['sql_page_size'] > 500 || $b['max_report_pages'] > 99999999
            || max($b['max_path_bytes'], $b['max_record_bytes'], $b['max_identifier_bytes']) > 0xffffffff) {
            throw new \InvalidArgumentException('Incompatible inventory resource budgets.');
        }
        foreach (['mp3_root', 'workspace_root', 'report_root'] as $key) { $p[$key] = $this->directory($p[$key] ?? null); }
        $public = $this->directory($this->publicRoot);
        $projectPublic = $this->directory(dirname(__DIR__, 4).'/public');
        // Upload work need not exist yet, but its configured path must be safe.
        $uploads = $this->directory($this->uploadWorkRoot, false);
        foreach ([['mp3_root', 'workspace_root'], ['mp3_root', 'report_root'], ['workspace_root', 'report_root']] as [$a, $c]) {
            $this->separate($p[$a], $p[$c]);
        }
        foreach (['workspace_root', 'report_root'] as $key) {
            $this->separate($p[$key], $public);
            $this->separate($p[$key], $projectPublic);
            if (PHP_OS_FAMILY !== 'Windows' && (fileperms($p[$key]) & 0022) !== 0) {
                throw new \InvalidArgumentException('Private roots must not be writable by other users/groups.');
            }
        }
        foreach (['mp3_root', 'workspace_root', 'report_root'] as $key) { $this->separate($p[$key], $uploads); }
        if (!is_readable($p['mp3_root']) || !is_writable($p['workspace_root']) || !is_writable($p['report_root'])) {
            throw new \InvalidArgumentException('Inventory roots are not accessible.');
        }
        return [$p, $resolver];
    }

    /** Absolute local paths only; check every component before canonicalization. */
    private function directory(mixed $path, bool $mustExist = true): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new \InvalidArgumentException('An explicit local directory is required.');
        }
        $path = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows') {
            if (!preg_match('~^[A-Za-z]:/[^:]~D', $path)) { throw new \InvalidArgumentException('An absolute local drive path is required.'); }
        } elseif (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException('An absolute local path is required.');
        }
        $parts = explode('/', rtrim($path, '/'));
        foreach ($parts as $i => $part) {
            if ($part === '.' || $part === '..' || ($part === '' && $i !== 0)) { throw new \InvalidArgumentException('Ambiguous directory components are forbidden.'); }
        }
        clearstatcache();
        $current = rtrim($path, '/');
        while ($current !== '' && $current !== '.' && dirname($current) !== $current) {
            if (is_link($current)) { throw new \InvalidArgumentException('Symbolic directory components are forbidden.'); }
            $current = dirname($current);
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            if ($mustExist || file_exists($path)) { throw new \InvalidArgumentException('Configured directories must already exist.'); }
            // Resolve the existing parent for a not-yet-created upload directory.
            return rtrim(str_replace('\\', '/', $this->directory(dirname($path), false)), '/').'/'.basename($path);
        }
        if (dirname($resolved) === $resolved) { throw new \InvalidArgumentException('A filesystem root is not an inventory directory.'); }
        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    private function separate(string $a, string $b): void
    {
        if (PHP_OS_FAMILY === 'Windows') { $a = strtolower($a); $b = strtolower($b); }
        if ($a === $b || str_starts_with($a.'/', $b.'/') || str_starts_with($b.'/', $a.'/')) {
            throw new \InvalidArgumentException('Inventory roots must be disjoint from protected storage.');
        }
    }
}
