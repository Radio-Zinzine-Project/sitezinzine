<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Read-only, bounded discovery of published manifests, never result pages.
 * Profiles and publicRoot come exclusively from trusted server configuration.
 * No collector, DBAL connection, inventory execution or authorization side effect.
 * Future HTTP callers MUST require ROLE_SUPER_ADMIN before invoking this service.
 * Trusted directory parents/immutable published reports remain prerequisites:
 * PHP cannot prevent hostile concurrent renames, bind mounts or web-server aliases.
 * This is a directory observation, NOT an atomic snapshot or a storage certificate.
 */
class AudioInventoryReportDiscovery
{
    public function __construct(private readonly array $profiles, private readonly string $publicRoot,
        private readonly int $maxEntries)
    {
        if ($maxEntries < 1) { throw new \InvalidArgumentException('A positive discovery entry budget is required.'); }
    }

    /** Names only, no paths or filesystem access. Authorization belongs to caller. */
    public function getProfiles(): array { return array_map('strval', array_keys($this->profiles)); }

    /** Native persistent pagination, without reimplementing store integrity checks. */
    public function readPage(string $profile, string $inventoryId, int $page): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $inventoryId) !== 1 || $page < 1) {
            throw new \InvalidArgumentException('Expected an inventory ID and a positive page.');
        }
        $context = $this->context($profile);
        if ($context['store'] === null) { throw new \RuntimeException('Report storage unavailable.'); }
        return $context['store']->readPage($inventoryId, $page);
    }

    /**
     * At most maxEntries non-dot entries, one directory handle and one bounded
     * manifest read at a time. Reports/anomalies contain at most maxEntries items.
     * No chronological order, exhaustive pagination cursor or cache is promised.
     * Hitting the limit is conservatively partial even at the exact directory size.
     * completed means enumeration ended, not that all encountered reports are valid.
     */
    public function discover(string $profile): array
    {
        $context = $this->context($profile);
        $result = ['status' => 'completed', 'partial' => false, 'reason' => null,
            'entries_examined' => 0, 'reports' => [], 'anomalies' => [], 'unpublished_count' => 0];
        if ($context['store'] === null) { return $this->incomplete($result, 'unavailable', 'root_unavailable'); }
        $directory = $this->openDirectory($context['root']);
        if (!is_resource($directory)) { return $this->incomplete($result, 'unavailable', 'directory_open_failed'); }
        try {
            while ($result['entries_examined'] < $this->maxEntries) {
                if (!$this->rootIntact($context)) { throw new \RuntimeException('Root changed.'); }
                $entry = $this->nextEntry($directory);
                if ($entry === false) { break; }
                if ($entry === '.' || $entry === '..') { continue; }
                ++$result['entries_examined'];
                if (preg_match('/^inventory-([a-f0-9]{32})$/D', $entry, $match) !== 1) { continue; }
                $inspection = $this->inspect($context, $match[1]);
                if ($inspection['status'] === 'published') {
                    $m = $inspection['manifest'];
                    $result['reports'][] = ['inventory_id' => $match[1], 'inventory_date' => null,
                        'collection_mode' => $m['report']['collection_mode'] ?? null,
                        'scope' => $m['report']['scope'], 'page_count' => $m['page_count'], 'result_count' => $m['result_count'],
                        'physical_paths' => $m['physical_paths'], 'referenced_paths' => $m['referenced_paths'],
                        'association_count' => $m['association_count'], 'classification_counts' => $m['classification_counts'],
                        'restrictions' => $m['report']['restrictions']];
                } elseif ($inspection['status'] === 'unpublished') {
                    ++$result['unpublished_count'];
                } else {
                    $result['anomalies'][] = ['inventory_id' => $match[1], 'status' => $inspection['status'], 'reason' => $inspection['reason']];
                }
            }
            if ($result['entries_examined'] === $this->maxEntries) {
                $result = $this->incomplete($result, 'partial', 'entry_budget_reached');
            }
            if (!$this->rootIntact($context)) { throw new \RuntimeException('Root changed.'); }
        } catch (\RuntimeException) {
            $result = $this->incomplete($result, 'partial', 'directory_read_failed');
        } finally {
            try { $this->closeDirectory($directory); }
            catch (\RuntimeException) {
                $result = $this->incomplete($result, 'partial', $result['reason'] ?? 'directory_close_failed');
            }
        }
        return $result;
    }

    /**
     * Structured observation: published | unpublished | corrupt_or_unreadable |
     * unavailable. manifest is populated only after the real store validates it.
     * Missing manifest on an accessible directory means unpublished AT OBSERVATION,
     * not proof that it was never published. Dates are unknown, never filesystem mtime.
     * Invalid configuration/profile/ID throws InvalidArgumentException; no fallback.
     */
    public function readManifest(string $profile, string $inventoryId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $inventoryId) !== 1) {
            throw new \InvalidArgumentException('Expected an opaque inventory identifier.');
        }
        return $this->inspect($this->context($profile), $inventoryId);
    }

    private function inspect(array $context, string $id): array
    {
        $observation = ['inventory_id' => $id, 'status' => 'unavailable', 'reason' => 'report_unavailable',
            'inventory_date' => null, 'manifest' => null];
        if ($context['store'] === null || !$this->rootIntact($context)) { return $observation; }
        $directory = $context['root'].DIRECTORY_SEPARATOR.'inventory-'.$id;
        $stat = $this->stat($directory);
        if ($stat === false) { return $observation; }
        if (($stat['mode'] & 0170000) !== 0040000) { return $this->failed($observation, 'unsafe_entry'); }
        if (!is_readable($directory) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($directory))) {
            return $this->failed($observation, 'manifest_read_failed');
        }
        $path = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        $manifestStat = $this->stat($path);
        if ($manifestStat === false) {
            $after = $this->stat($directory);
            if (!$this->same($stat, $after) || !$this->rootIntact($context)) { return $observation; }
            $observation['status'] = 'unpublished'; $observation['reason'] = 'manifest_not_published';
            return $observation;
        }
        if (($manifestStat['mode'] & 0170000) !== 0100000) { return $this->failed($observation, 'unsafe_entry'); }
        try {
            $manifest = $this->readStoredManifest($context['store'], $id);
            $observation['status'] = 'published'; $observation['reason'] = null; $observation['manifest'] = $manifest;
            return $observation;
        } catch (\UnexpectedValueException) {
            return $this->failed($observation, 'invalid_manifest');
        } catch (\LengthException) {
            return $this->failed($observation, 'manifest_budget_exceeded');
        } catch (\RuntimeException) {
            if ($this->stat($path) === false || !$this->rootIntact($context)) { return $observation; }
            return $this->failed($observation, 'manifest_read_failed');
        }
    }

    private function failed(array $observation, string $reason): array
    {
        $observation['status'] = 'corrupt_or_unreadable'; $observation['reason'] = $reason;
        return $observation;
    }

    private function incomplete(array $result, string $status, string $reason): array
    {
        $result['status'] = $status; $result['partial'] = true; $result['reason'] = $reason;
        return $result;
    }

    private function context(string $profile): array
    {
        if ($profile === '' || !is_array($this->profiles[$profile] ?? null)) {
            throw new \InvalidArgumentException('Unknown authorized report profile.');
        }
        $p = $this->profiles[$profile]; $budgets = $p['budgets'] ?? null;
        foreach (['report_page_size', 'max_report_page_bytes', 'max_manifest_bytes', 'max_report_pages'] as $key) {
            if (!is_array($budgets) || !is_int($budgets[$key] ?? null) || $budgets[$key] < 1) {
                throw new \InvalidArgumentException('Explicit positive report read budgets are required.');
            }
        }
        if ($budgets['max_report_pages'] > 99999999) { throw new \InvalidArgumentException('Invalid report page budget.'); }
        $root = $this->safePath($p['report_root'] ?? null);
        $public = $this->safePath($this->publicRoot);
        $this->separate($root, $public);
        $this->separate($root, $this->safePath(dirname(__DIR__, 4).'/public'));
        $stat = $this->stat($root);
        $context = ['root' => $root, 'identity' => $stat, 'store' => null];
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || !is_readable($root)
            || !is_writable($root) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($root))) { return $context; }
        // Keep the existing store's permission contract; do not chmod or broaden it.
        $context['store'] = new AudioInventoryReportStore($root, $public, $budgets['report_page_size'],
            $budgets['max_report_page_bytes'], $budgets['max_manifest_bytes'], $budgets['max_report_pages']);
        return $context;
    }

    private function safePath(mixed $path): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new \InvalidArgumentException('An authorized absolute local report root is required.');
        }
        $path = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows' ? preg_match('~^[A-Za-z]:/[^:]~D', $path) !== 1
            : (!str_starts_with($path, '/') || str_starts_with($path, '//'))) {
            throw new \InvalidArgumentException('An absolute local report root is required.');
        }
        foreach (explode('/', rtrim($path, '/')) as $i => $part) {
            if ($part === '.' || $part === '..' || ($part === '' && $i !== 0)) { throw new \InvalidArgumentException('Ambiguous report root.'); }
        }
        $this->assertNoSymbols($path);
        $resolved = realpath($path);
        if ($resolved !== false) { $path = str_replace('\\', '/', $resolved); }
        if (dirname($path) === $path) { throw new \InvalidArgumentException('A filesystem root is not a report root.'); }
        return rtrim($path, '/');
    }

    private function assertNoSymbols(string $path): void
    {
        do {
            clearstatcache(true, $path);
            if (is_link($path)) { throw new \InvalidArgumentException('Symbolic report root components are forbidden.'); }
            $parent = dirname($path); if ($parent === $path) { break; } $path = $parent;
        } while ($path !== '.');
    }

    private function separate(string $root, string $public): void
    {
        if (PHP_OS_FAMILY === 'Windows') { $root = strtolower($root); $public = strtolower($public); }
        if ($root === $public || str_starts_with($root.'/', $public.'/') || str_starts_with($public.'/', $root.'/')) {
            throw new \InvalidArgumentException('Reports must be outside the public tree.');
        }
    }

    private function rootIntact(array $context): bool
    {
        try { $this->assertNoSymbols($context['root']); }
        catch (\InvalidArgumentException) { return false; }
        return $this->same($context['identity'], $this->stat($context['root']));
    }
    private function stat(string $path): array|false { clearstatcache(true, $path); return @lstat($path); }
    private function same(array|false $a, array|false $b): bool
    {
        return $a !== false && $b !== false && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino']
            && ($a['mode'] & 0170000) === ($b['mode'] & 0170000);
    }

    // Narrow I/O seams allow deterministic failure tests even under Docker root.
    protected function readStoredManifest(AudioInventoryReportStore $store, string $id): array { return $store->readManifest($id); }
    protected function openDirectory(string $root): mixed { return @opendir($root); }
    protected function nextEntry(mixed $directory): string|false { return readdir($directory); }
    protected function closeDirectory(mixed $directory): void { closedir($directory); }
}
