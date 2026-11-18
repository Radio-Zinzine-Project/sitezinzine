<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Private, paged inventory artifacts; never owns or changes MP3s/workspace files.
 * One writer lifecycle per instance; published inventories can be read by fresh
 * instances. Caller supplies a trusted private root and the actual public root;
 * deployment must also prevent web-server aliases exposing this storage. Parent
 * directories must not be replaced by untrusted actors (PHP has no openat guard).
 * POSIX operation/file modes are enforced; Windows requires suitable external ACLs.
 * No deletion, crash recovery, fsync or power-loss durability is implied by rename.
 * Hashes detect corruption, not authenticity. Published artifacts must be immutable.
 */
class AudioInventoryReportStore
{
    private const CLASSES = ['associated', 'multiple_associations', 'orphan_candidate', 'broken_reference', 'indeterminate'];
    private string $root;
    private array $rootIdentity;
    private string $state = 'new';
    private ?string $id = null;
    private ?array $directoryIdentity = null;
    private array $buffer = [];
    private int $pages = 0;
    private int $results = 0;
    private int $physical = 0;
    private int $referenced = 0;
    private int $associations = 0;
    private array $classes;
    private \HashContext $chain;
    private array $secondaryErrors = [];

    public function __construct(string $authorizedRoot, string $publicRoot, private readonly int $pageSize,
        private readonly int $maxPageBytes, private readonly int $maxManifestBytes, private readonly int $maxPages = 10000)
    {
        if (min($pageSize, $maxPageBytes, $maxManifestBytes, $maxPages) < 1 || $maxPages > 99999999) {
            throw new \InvalidArgumentException('Positive bounded page, byte and manifest limits are required.');
        }
        $root = realpath($authorizedRoot); $public = realpath($publicRoot);
        if ($root === false || $public === false || !is_dir($root) || !is_dir($public)
            || $this->symbolicComponent($authorizedRoot) || $this->symbolicComponent($publicRoot)
            || !is_readable($root) || !is_writable($root) || $this->within($root, $public) || $this->within($public, $root)) {
            throw new \InvalidArgumentException('Accessible private storage, separate from the public tree, is required.');
        }
        $projectPublic = realpath(dirname(__DIR__, 4).'/public');
        if ($projectPublic !== false && ($this->within($root, $projectPublic) || $this->within($projectPublic, $root))) {
            throw new \InvalidArgumentException('Results must be outside the project public tree.');
        }
        $this->root = $root; $this->rootIdentity = $this->identity($root, true);
        if (PHP_OS_FAMILY !== 'Windows' && ($this->rootIdentity['mode'] & 0022) !== 0) {
            throw new \InvalidArgumentException('Results root must not be writable by other users/groups.');
        }
        $this->classes = array_fill_keys(self::CLASSES, 0); $this->chain = hash_init('sha256');
    }

    public function begin(): string
    {
        if ($this->state !== 'new') { throw new \LogicException('Report writer is single-use.'); }
        $this->assertRoot(); $this->id = bin2hex(random_bytes(16));
        $directory = $this->root.DIRECTORY_SEPARATOR.'inventory-'.$this->id;
        if (!@mkdir($directory, 0700)) { $this->state = 'failed'; throw new \RuntimeException('Cannot exclusively create report directory.'); }
        try { $this->privateMode($directory, 0700); $this->directoryIdentity = $this->identity($directory, true); }
        catch (\Throwable $error) { $this->state = 'failed'; throw $error; }
        $this->state = 'open'; return $this->id;
    }

    /** One bounded page plus one candidate row. No global path/row/hash list. */
    public function append(array $row): void
    {
        $this->assertOpen();
        try {
            $this->validateRow($row);
            $candidate = [...$this->buffer, $row];
            $encoded = $this->pageEnvelope($this->pages + 1, $candidate);
            if (count($candidate) > $this->pageSize || strlen($encoded) > $this->maxPageBytes) {
                $this->flushPage(); $candidate = [$row]; $encoded = $this->pageEnvelope($this->pages + 1, $candidate);
            }
            if (strlen($encoded) > $this->maxPageBytes) { throw new \LengthException('A result exceeds the encoded page byte limit.'); }
            $this->buffer = $candidate;
            ++$this->results; ++$this->classes[$row['classification']];
            $this->physical += (int)$row['physical_observed']; $this->referenced += (int)($row['associations'] !== []);
            $this->associations += count($row['associations']);
            if (count($this->buffer) === $this->pageSize) { $this->flushPage(); }
        } catch (\Throwable $error) { $this->state = 'failed'; throw $error; }
    }

    /** Publish only an actual successful preparation/reconciliation report. */
    public function publish(array $report): array
    {
        $this->assertOpen();
        try {
            $this->validateReport($report, $this->results, $this->physical, $this->referenced, $this->associations);
            $this->flushPage();
            $expectedChain = hash_final(hash_copy($this->chain));
            $chain = hash_init('sha256'); $count = $physical = $referenced = $associations = 0; $classes = array_fill_keys(self::CLASSES, 0);
            for ($page = 1; $page <= $this->pages; ++$page) {
                $bytes = $this->readFile($this->id, $this->pageName($page), $this->maxPageBytes);
                $data = $this->decodeEnvelope($bytes, 'page'); $this->validatePage($data, $this->id, $page, $this->pageSize);
                hash_update($chain, hash('sha256', $bytes, true));
                foreach ($data['results'] as $row) {
                    ++$count; ++$classes[$row['classification']]; $physical += (int)$row['physical_observed'];
                    $referenced += (int)($row['associations'] !== []); $associations += count($row['associations']);
                }
            }
            if ($count !== $this->results || $classes !== $this->classes || [$physical, $referenced, $associations] !== [$this->physical, $this->referenced, $this->associations]
                || !hash_equals($expectedChain, hash_final($chain))) {
                throw new \UnexpectedValueException('Written page counters or integrity chain changed.');
            }
            $manifest = ['inventory_id' => $this->id, 'status' => 'completed', 'publication' => 'published',
                'page_size' => $this->pageSize, 'page_count' => $this->pages, 'result_count' => $this->results,
                'physical_paths' => $physical, 'referenced_paths' => $referenced, 'association_count' => $associations,
                'classification_counts' => $classes, 'page_chain_sha256' => $expectedChain, 'report' => $report];
            $encoded = $this->encodeEnvelope('manifest', $manifest);
            $this->writeFile('manifest.pending', $encoded, $this->maxManifestBytes);
            $checked = $this->decodeEnvelope($this->readFile($this->id, 'manifest.pending', $this->maxManifestBytes), 'manifest');
            $this->validateManifest($checked, $this->id);
            if ($checked !== $manifest) { throw new \UnexpectedValueException('Manifest verification differs from its prepared contents.'); }
            $directory = $this->operation($this->id); $final = $directory.DIRECTORY_SEPARATOR.'manifest.json';
            if (file_exists($final) || is_link($final) || !$this->publishManifest($directory.DIRECTORY_SEPARATOR.'manifest.pending', $final)) {
                throw new \RuntimeException('Cannot publish report manifest.');
            }
            $this->state = 'published'; return $manifest;
        } catch (\Throwable $error) { $this->state = 'failed'; throw $error; }
    }

    public function abort(): void
    {
        if ($this->state === 'published') { throw new \LogicException('Published reports cannot be aborted.'); }
        if ($this->state !== 'failed') { $this->state = 'aborted'; }
        $this->buffer = []; // No file removal; provisional artifacts remain private.
    }
    public function getSecondaryErrors(): array { return $this->secondaryErrors; }

    public function readManifest(string $inventoryId): array
    {
        $manifest = $this->decodeEnvelope($this->readFile($inventoryId, 'manifest.json', $this->maxManifestBytes), 'manifest');
        $this->validateManifest($manifest, $inventoryId); return $manifest;
    }
    public function readPage(string $inventoryId, int $page): array
    {
        $manifest = $this->readManifest($inventoryId);
        if ($page < 1 || $page > $manifest['page_count']) { throw new \OutOfBoundsException('Report page is outside the published inventory.'); }
        $data = $this->decodeEnvelope($this->readFile($inventoryId, $this->pageName($page), $this->maxPageBytes), 'page');
        $this->validatePage($data, $inventoryId, $page, $manifest['page_size']);
        return $data + ['next_page' => $page < $manifest['page_count'] ? $page + 1 : null];
    }

    private function assertOpen(): void { if ($this->state !== 'open') { throw new \LogicException('Report writer is not open.'); } }
    private function flushPage(): void
    {
        if ($this->buffer === []) { return; }
        if ($this->pages >= $this->maxPages) { throw new \LengthException('Report page creation budget exhausted.'); }
        $bytes = $this->pageEnvelope($this->pages + 1, $this->buffer);
        $this->writeFile($this->pageName($this->pages + 1), $bytes, $this->maxPageBytes);
        hash_update($this->chain, hash('sha256', $bytes, true)); ++$this->pages; $this->buffer = [];
    }
    private function pageName(int $page): string { return sprintf('page-%08d.json', $page); }
    private function pageEnvelope(int $page, array $rows): string { return $this->encodeEnvelope('page', ['inventory_id' => $this->id, 'page' => $page, 'results' => $rows]); }

    private function validateRow(array $row): void
    {
        $keys = ['storage', 'relative_path', 'classification', 'emission_ids', 'missing_thumbnail_emission_ids',
            'physical_observed', 'present', 'associations', 'references_complete', 'absence_conclusions_allowed', 'scope'];
        if (count($row) !== count($keys) || array_diff($keys, array_keys($row)) !== []
            || !is_string($row['storage']) || $row['storage'] === '' || !is_string($row['relative_path']) || $row['relative_path'] === ''
            || !in_array($row['classification'], self::CLASSES, true) || !is_bool($row['physical_observed'])
            || (!is_bool($row['present']) && $row['present'] !== null) || !is_array($row['associations']) || !array_is_list($row['associations'])
            || !is_array($row['emission_ids']) || !array_is_list($row['emission_ids']) || !is_array($row['missing_thumbnail_emission_ids']) || !array_is_list($row['missing_thumbnail_emission_ids'])
            || !is_bool($row['references_complete']) || !is_bool($row['absence_conclusions_allowed']) || !is_string($row['scope']) || $row['scope'] === '') {
            throw new \UnexpectedValueException('Invalid reconciler result structure.');
        }
        foreach ([$row['emission_ids'], $row['missing_thumbnail_emission_ids']] as $ids) {
            foreach ($ids as $id) { if (!is_int($id) || $id < 1) { throw new \UnexpectedValueException('Invalid emission identifier.'); } }
        }
    }
    private function validatePage(array $data, string $id, int $page, int $size): void
    {
        if (count($data) !== 3 || ($data['inventory_id'] ?? null) !== $id || ($data['page'] ?? null) !== $page
            || !is_array($data['results'] ?? null) || !array_is_list($data['results']) || count($data['results']) < 1 || count($data['results']) > $size) {
            throw new \UnexpectedValueException('Invalid report page identity, number or contents.');
        }
        foreach ($data['results'] as $row) { if (!is_array($row)) { throw new \UnexpectedValueException('Invalid report row.'); } $this->validateRow($row); }
    }
    private function validateReport(array $report, int $results, int $physical, int $referenced, int $associations): void
    {
        $p = $report['preparation'] ?? []; $r = $report['reconciliation'] ?? []; $restrictions = $report['restrictions'] ?? [];
        if (!is_array($p) || !is_array($r) || !is_array($restrictions)
            || ($p['status'] ?? null) !== 'completed' || ($r['status'] ?? null) !== 'completed'
            || ($p['consumed_paths'] ?? null) !== $physical || ($p['consumed_associations'] ?? null) !== $associations
            || ($r['processed_paths'] ?? null) !== $results || ($r['results_emitted'] ?? null) !== $results
            || ($r['physical_paths'] ?? null) !== $physical || ($r['referenced_paths'] ?? null) !== $referenced
            || ($report['associations_seen'] ?? null) !== $associations || !is_string($report['scope'] ?? null) || $report['scope'] === ''
            || !is_bool($restrictions['references_complete'] ?? null) || !is_bool($restrictions['physical_collection_complete'] ?? null)
            || ($restrictions['storage_certified'] ?? null) !== false || ($restrictions['absence_conclusions_allowed'] ?? null) !== false
            || ($restrictions['orphan_candidate_is_deletion_permission'] ?? null) !== false) {
            throw new \UnexpectedValueException('Report completion, counters or conservative restrictions are inconsistent.');
        }
    }
    private function validateManifest(array $m, string $id): void
    {
        if (count($m) !== 12 || ($m['inventory_id'] ?? null) !== $id || ($m['status'] ?? null) !== 'completed' || ($m['publication'] ?? null) !== 'published') {
            throw new \UnexpectedValueException('Manifest is not a published completed inventory.');
        }
        foreach (['page_size', 'page_count', 'result_count', 'physical_paths', 'referenced_paths', 'association_count'] as $key) {
            if (!is_int($m[$key] ?? null) || $m[$key] < 0) { throw new \UnexpectedValueException('Invalid manifest counter.'); }
        }
        if ($m['page_size'] < 1 || $m['page_count'] > $this->maxPages || ($m['page_count'] === 0) !== ($m['result_count'] === 0)
            || $m['page_count'] > $m['result_count'] || !is_array($m['classification_counts'] ?? null)
            || array_keys($m['classification_counts']) !== self::CLASSES || !is_string($m['page_chain_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $m['page_chain_sha256']) !== 1 || !is_array($m['report'] ?? null)) {
            throw new \UnexpectedValueException('Invalid manifest pagination, class counts or integrity root.');
        }
        foreach ($m['classification_counts'] as $count) { if (!is_int($count) || $count < 0) { throw new \UnexpectedValueException('Invalid class counter.'); } }
        if (array_sum($m['classification_counts']) !== $m['result_count']) { throw new \UnexpectedValueException('Classification counters do not sum to results.'); }
        $this->validateReport($m['report'], $m['result_count'], $m['physical_paths'], $m['referenced_paths'], $m['association_count']);
    }

    /** JSON envelope is storage-specific, not a replacement for inventory run codecs. */
    private function encodeEnvelope(string $kind, array $body): string
    {
        $json = json_encode($this->encodeValues($body), JSON_THROW_ON_ERROR);
        return json_encode(['version' => 1, 'kind' => $kind, 'body' => base64_encode($json), 'sha256' => hash('sha256', $json)], JSON_THROW_ON_ERROR);
    }
    private function decodeEnvelope(string $bytes, string $kind): array
    {
        try {
            $e = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($e) || count($e) !== 4 || ($e['version'] ?? null) !== 1 || ($e['kind'] ?? null) !== $kind
                || !is_string($e['body'] ?? null) || !is_string($e['sha256'] ?? null)) { throw new \UnexpectedValueException('Invalid report envelope.'); }
            $json = base64_decode($e['body'], true);
            if ($json === false || base64_encode($json) !== $e['body'] || !hash_equals(hash('sha256', $json), $e['sha256'])) { throw new \UnexpectedValueException('Corrupt report payload.'); }
            $body = $this->decodeValues(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
            if (!is_array($body)) { throw new \UnexpectedValueException('Invalid report body.'); } return $body;
        } catch (\JsonException $error) { throw new \UnexpectedValueException('Malformed report JSON.', 0, $error); }
    }
    private function encodeValues(mixed $value): mixed
    {
        if (is_string($value)) { return ['binary' => base64_encode($value)]; }
        if (is_array($value)) { $result = []; foreach ($value as $key => $item) { $result[$key] = $this->encodeValues($item); } return $result; }
        if (is_int($value) || is_bool($value) || $value === null) { return $value; }
        throw new \UnexpectedValueException('Reports accept only arrays and scalar inventory values.');
    }
    private function decodeValues(mixed $value): mixed
    {
        if (is_array($value)) {
            if (count($value) === 1 && is_string($value['binary'] ?? null)) {
                $bytes = base64_decode($value['binary'], true);
                if ($bytes === false || base64_encode($bytes) !== $value['binary']) { throw new \UnexpectedValueException('Invalid binary string representation.'); } return $bytes;
            }
            $result = []; foreach ($value as $key => $item) { $result[$key] = $this->decodeValues($item); } return $result;
        }
        if (is_int($value) || is_bool($value) || $value === null) { return $value; }
        throw new \UnexpectedValueException('Invalid typed report value.');
    }

    private function writeFile(string $name, string $bytes, int $limit): void
    {
        if (strlen($bytes) > $limit) { throw new \LengthException('Encoded report file exceeds its byte budget.'); }
        $directory = $this->operation($this->id); $path = $directory.DIRECTORY_SEPARATOR.$name;
        $stream = @fopen($path, 'xb'); if ($stream === false) { throw new \RuntimeException('Cannot exclusively create report file.'); }
        $primary = null;
        try {
            $this->privateMode($path, 0600); $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = $this->writeChunk($stream, substr($bytes, $offset, 8192));
                if ($written === false || $written === 0) { throw new \RuntimeException('Cannot complete report write.'); } $offset += $written;
            }
            if (!$this->flushStream($stream)) { throw new \RuntimeException('Cannot flush report file.'); }
        } catch (\Throwable $error) { $primary = $error; }
        $this->closePreserving($stream, $primary);
        $this->operation($this->id);
    }
    private function readFile(string $id, string $name, int $limit): string
    {
        $directory = $this->operation($id); $directoryIdentity = $this->identity($directory, true); $path = $directory.DIRECTORY_SEPARATOR.$name;
        $stat = $this->identity($path, false);
        if ($stat['size'] > $limit) { throw new \LengthException('Stored report file exceeds the read byte budget.'); }
        $stream = @fopen($path, 'rb'); if ($stream === false) { throw new \RuntimeException('Cannot open report file.'); }
        $primary = null; $bytes = '';
        try {
            $this->sameIdentity($stat, fstat($stream));
            while (!feof($stream)) {
                $part = @fread($stream, min(8192, $limit - strlen($bytes) + 1));
                if ($part === false || ($part === '' && !feof($stream))) { throw new \RuntimeException('Report read failed or made no progress.'); }
                $bytes .= $part; if (strlen($bytes) > $limit) { throw new \LengthException('Report grew beyond its read budget.'); }
            }
            $this->sameIdentity($stat, $this->identity($path, false));
            $this->sameIdentity($directoryIdentity, $this->identity($directory, true));
        } catch (\Throwable $error) { $primary = $error; }
        $this->closePreserving($stream, $primary); $this->assertRoot(); return $bytes;
    }
    private function closePreserving(mixed $stream, ?\Throwable $primary): void
    {
        try { $this->closeStream($stream); }
        catch (\Throwable $closing) { if ($primary === null) { $primary = $closing; } else { $this->secondaryErrors[] = $closing; } }
        if ($primary !== null) { throw $primary; }
    }
    private function operation(string $id): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) { throw new \InvalidArgumentException('Expected an opaque inventory identifier, not a path.'); }
        $this->assertRoot(); $path = $this->root.DIRECTORY_SEPARATOR.'inventory-'.$id;
        $identity = $this->identity($path, true);
        if ($id === $this->id && $this->directoryIdentity !== null) { $this->sameIdentity($this->directoryIdentity, $identity); }
        return $path;
    }
    private function assertRoot(): void { $this->sameIdentity($this->rootIdentity, $this->identity($this->root, true)); if ($this->symbolicComponent($this->root)) { throw new \RuntimeException('Results root became symbolic.'); } }
    private function identity(string $path, bool $directory): array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)) { throw new \RuntimeException('Report path is unavailable, symbolic or has the wrong type.'); } return $stat;
    }
    private function sameIdentity(array $expected, array|false $actual): void
    {
        if ($actual === false || $expected['dev'] !== $actual['dev'] || $expected['ino'] !== $actual['ino'] || ($expected['mode'] & 0170000) !== ($actual['mode'] & 0170000)) { throw new \RuntimeException('Report filesystem identity changed.'); }
    }
    private function privateMode(string $path, int $mode): void
    {
        if (PHP_OS_FAMILY !== 'Windows') { if (!@chmod($path, $mode)) { throw new \RuntimeException('Cannot establish private report permissions.'); } clearstatcache(true, $path); if ((fileperms($path) & 0777) !== $mode) { throw new \RuntimeException('Report permissions are not private.'); } }
    }
    private function within(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || str_starts_with($path, $root.'/');
    }
    private function symbolicComponent(string $path): bool
    {
        do { clearstatcache(true, $path); if (is_link($path)) { return true; } $parent = dirname($path); if ($parent === $path) { break; } $path = $parent; } while ($path !== '.'); return false;
    }
    protected function writeChunk(mixed $stream, string $bytes): int|false { return @fwrite($stream, $bytes); }
    protected function flushStream(mixed $stream): bool { return @fflush($stream); }
    protected function closeStream(mixed $stream): void { if (!@fclose($stream)) { throw new \RuntimeException('Cannot close report stream.'); } }
    protected function publishManifest(string $pending, string $final): bool { return @rename($pending, $final); }
}
