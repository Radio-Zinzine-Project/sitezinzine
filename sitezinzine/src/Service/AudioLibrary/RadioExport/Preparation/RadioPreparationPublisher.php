<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\Mp3StoragePath;
use App\Service\RadioWeek;
use ZipArchive;

class RadioPreparationPublisher
{
    /** Publishes the completed copy report. The final JSON is the availability marker. */
    public function publish(RadioPreparationWorkspace $workspace, ?string $revision = null): array
    {
        if ($workspace->state() !== 'copies_ready') {
            throw new \LogicException('Only a completed copy phase can be published.');
        }

        if ($revision !== null && preg_match('/\A[a-f0-9]{64}\z/', $revision) !== 1) {
            throw new \InvalidArgumentException('Invalid captured grid revision.');
        }

        $diagnostic = 'Impossible de verifier ou de creer le ZIP MP3.';
        try {
            $report = $workspace->report();
            $differential = $report['differential'] ?? null;
            if ($differential !== null) {
                $report['version'] = 3;
                $report['revision'] = $revision;
                $report['week'] = $report['weekStart']->format('Y-m-d');
                $report['attemptId'] = $workspace->attemptId();
            }
            $expected = $this->indexArtifacts($report);
            $directory = $workspace->ownedDirectory();
            $entries = @scandir($directory);
            if ($entries === false || array_diff($entries, ['.', '..', ...array_keys($expected)]) !== []) {
                throw new \RuntimeException('The preparation contains unexpected entries.');
            }

            $archive = null;
            if ($expected !== []) {
                $temporary = $workspace->archiveTemporaryPath();
                $this->createArchive($workspace, $temporary, $expected);
                $temporary = $workspace->adoptClosedArchive();
                $verified = $this->verifyArchive($temporary, $expected);
                $diagnostic = 'Impossible de publier le ZIP MP3 sans remplacement.';
                $path = $workspace->publishArchive();
                $archive = self::fingerprint($path);
                if ($archive !== $verified) {
                    throw new \RuntimeException('The ZIP changed before publication.');
                }
                $archive['path'] = 'audio.zip';
            }

            $record = [
                'version' => $differential !== null ? 3 : ($revision === null ? 1 : 2),
                'week' => $report['weekStart']->format('Y-m-d'),
                'weekStart' => $report['weekStart']->format(DATE_ATOM),
                'weekEnd' => $report['weekEnd']->format(DATE_ATOM),
                'attemptId' => $workspace->attemptId(),
                'status' => $archive === null ? 'report_only' : 'ready',
                'entries' => array_map(static function (array $entry): array {
                    $entry['startsAt'] = $entry['startsAt']->format('Y-m-d\TH:i:s.uP');
                    return $entry;
                }, $report['entries']),
                'artifacts' => $report['artifacts'],
                'sourceIssues' => $report['sourceIssues'],
                'archive' => $archive,
            ];

            if (($report['sourceSignatures'] ?? []) !== []) {
                $record['sourceSignatures'] = $report['sourceSignatures'];
            }
            if ($revision !== null) {
                $record['revision'] = $revision;
            }

            if ($differential !== null) {
                $record['differential'] = $differential;
            }

            $diagnostic = 'Impossible de publier le rapport final de preparation.';
            $workspace->publishReport($record);

            return $record;
        } catch (\Throwable $error) {
            try {
                $workspace->fail($diagnostic);
            } catch (\Throwable) {
                throw new \RuntimeException('Publication failed and cleanup or diagnostic could not be safely completed.', 0, $error);
            }
            throw $error;
        }
    }

    /** Revalidates a committed result without requiring the original PHP workspace. */
    public function read(string $root, \DateTimeImmutable $week, string $attemptId): ?array
    {
        $bytes = null;

        return $this->readPublication($root, $week, $attemptId, $bytes);
    }

    /**
     * Returns only bytes tied to the validated publication. The caller owns the returned stream.
     *
     * @return array{stream: resource, size: int}|null
     */
    public function openDownload(string $root, \DateTimeImmutable $week, string $attemptId, string $artifact): ?array
    {
        if (!in_array($artifact, ['zip', 'report'], true)) {
            throw new \InvalidArgumentException('Invalid radio download artifact.');
        }
        $bytes = null;
        $report = $this->readPublication($root, $week, $attemptId, $bytes);
        if ($report === null || ($artifact === 'zip' && $report['status'] !== 'ready')
            || ($artifact === 'report' && !self::isReportDownloadable($report))) {
            return null;
        }

        $copy = @tmpfile();
        if ($copy === false) {
            throw new \RuntimeException('Unable to allocate a private download snapshot.');
        }
        $keep = false;
        try {
            if ($artifact === 'report') {
                $size = strlen($bytes);
                $keep = fwrite($copy, $bytes) === $size && fflush($copy) && rewind($copy);
            } else {
                $archive = $report['archive'];
                $path = Mp3StoragePath::resolve($root, $week->format('Y-m-d') . '/' . $attemptId . '/audio.zip');
                $size = $archive['size'];
                $keep = self::snapshotArchive($path, $copy, $archive);
            }

            return $keep ? ['stream' => $copy, 'size' => $size] : null;
        } catch (\RuntimeException) {
            return null;
        } finally {
            if (!$keep) {
                fclose($copy);
            }
        }
    }

    /** Historical reports retain raw source references; unsafe ones cannot be sent verbatim. */
    public static function isReportDownloadable(array $report): bool
    {
        foreach ($report['entries'] as $entry) {
            foreach (['mp3Path', 'localUrlPath'] as $key) {
                $value = $entry[$key] ?? null;
                if ($value !== null && (!is_string($value)
                    || preg_match('~(^/|[\\\\:\x00]|(^|/)\.\.?(/|$))~', $value) === 1)) {
                    return false;
                }
            }
            $url = $entry['url'] ?? null;
            if ($url !== null && $url !== '' && (!is_string($url)
                || preg_match('~\Ahttps?://[^/\s]+(?:[/?#]|\z)~i', $url) !== 1
                || strpbrk($url, '?#') !== false || parse_url($url, PHP_URL_USER) !== null)) {
                return false;
            }
        }

        return true;
    }

    /** @param resource $copy */
    private static function snapshotArchive(string $path, $copy, array $archive): bool
    {
        $stat = self::privateFile($path);
        if ($stat['nlink'] !== 1 || $stat['dev'] !== $archive['dev'] || $stat['ino'] !== $archive['ino']
            || $stat['size'] !== $archive['size']) {
            return false;
        }
        $source = @fopen($path, 'rb');
        if ($source === false) {
            return false;
        }
        try {
            $identity = self::snapshot($stat);
            if (self::snapshot(fstat($source) ?: []) !== $identity
                || @stream_copy_to_stream($source, $copy, $archive['size'] + 1) !== $archive['size']
                || self::snapshot(fstat($source) ?: []) !== $identity
                || self::snapshot(self::privateFile($path)) !== $identity
                || !fflush($copy) || !rewind($copy)) {
                return false;
            }
            $hash = hash_init('sha256');

            return hash_update_stream($hash, $copy) === $archive['size']
                && hash_equals($archive['sha256'], hash_final($hash)) && rewind($copy);
        } finally {
            fclose($source);
        }
    }

    private function readPublication(string $root, \DateTimeImmutable $week, string $attemptId, ?string &$bytes): ?array
    {
        if ($week->getTimezone()->getName() !== 'Europe/Paris' || $week != RadioWeek::start($week)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $attemptId) !== 1) {
            throw new \InvalidArgumentException('Invalid radio preparation address.');
        }

        if (!function_exists('posix_geteuid')) {
            return null;
        }

        try {
            $root = Mp3StoragePath::directory($root);
            $relative = $week->format('Y-m-d') . '/' . $attemptId;
            $dir = Mp3StoragePath::resolve($root, $relative);
            foreach ([$root, dirname($dir), $dir] as $parent) {
                clearstatcache(true, $parent);
                $stat = @lstat($parent);
                if ($stat === false || ($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== posix_geteuid()) {
                    return null;
                }
            }
            $marker = Mp3StoragePath::resolve($dir, 'result.json');
            $markerStat = self::privateFile($marker);
            if ($markerStat['size'] > 16 * 1024 * 1024 || @lstat($dir . '/failure.json') !== false) {
                return null;
            }
            // The reader accepts the second name if the process stopped just after the commit link.
            if ($markerStat['nlink'] !== 1) {
                $pending = self::privateFile($dir . '/.result.pending');
                if ($markerStat['nlink'] !== 2 || [$pending['dev'], $pending['ino']] !== [$markerStat['dev'], $markerStat['ino']]) {
                    return null;
                }
            }
            $input = @fopen($marker, 'rb');
            if ($input === false) {
                return null;
            }
            try {
                $identity = self::snapshot($markerStat);
                if (self::snapshot(fstat($input) ?: []) !== $identity) {
                    return null;
                }
                $raw = stream_get_contents($input, $markerStat['size'] + 1);
                if ($raw === false || strlen($raw) !== $markerStat['size']
                    || self::snapshot(fstat($input) ?: []) !== $identity
                    || self::snapshot(self::privateFile($marker)) !== $identity) {
                    return null;
                }
                $bytes = $raw;
            } finally {
                fclose($input);
            }
            $report = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($report) || !in_array($report['version'] ?? null, [1, 2, 3], true) || ($report['attemptId'] ?? null) !== $attemptId
                || ($report['week'] ?? null) !== $week->format('Y-m-d')
                || ($report['weekStart'] ?? null) !== $week->format(DATE_ATOM)
                || ($report['weekEnd'] ?? null) !== $week->modify('+7 days')->format(DATE_ATOM)
                || !array_key_exists('archive', $report)) {
                return null;
            }
            if ($report['version'] >= 2 && (!is_string($report['revision'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $report['revision']) !== 1)) {
                return null;
            }
            $expected = $this->indexArtifacts($report);
            foreach ($report['entries'] as $entry) {
                $date = $entry['startsAt'] ?? null;
                $start = is_string($date) && strlen($date) === 32 && !str_contains($date, "\0") ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $date) : false;
                if ($start === false || $start->format('Y-m-d\TH:i:s.uP') !== $date
                    || $start < $week || $start >= $week->modify('+7 days')) {
                    return null;
                }
            }
            if (($report['status'] ?? null) === 'report_only') {
                return $expected === [] && $report['archive'] === null && @lstat($dir . '/audio.zip') === false ? $report : null;
            }
            if (($report['status'] ?? null) !== 'ready' || $expected === [] || ($report['archive']['path'] ?? null) !== 'audio.zip') {
                return null;
            }
            $archive = Mp3StoragePath::resolve($dir, 'audio.zip');
            $actual = $this->verifyArchive($archive, $expected) + ['path' => 'audio.zip'];

            return $actual === $report['archive'] ? $report : null;
        } catch (\RuntimeException|\InvalidArgumentException|\JsonException) {
            return null;
        }
    }

    private function indexArtifacts(array $report): array
    {
        if (!is_array($report['entries'] ?? null) || !array_is_list($report['entries'])
            || !is_array($report['artifacts'] ?? null) || !array_is_list($report['artifacts'])
            || !is_array($report['sourceIssues'] ?? null)) {
            throw new \RuntimeException('Invalid preparation report structure.');
        }
        $candidates = $ids = $expected = [];
        foreach ($report['entries'] as $entry) {
            $id = $entry['diffusionId'] ?? null;
            if (!is_array($entry) || !is_int($id) || $id < 1 || isset($ids[$id])
                || !in_array($entry['admission'] ?? null, ['candidate', 'excluded', 'unavailable', 'naming'], true)
                || !is_array($entry['issues'] ?? null)) {
                throw new \RuntimeException('Invalid or repeated preparation occurrence.');
            }
            $ids[$id] = true;
            if ($entry['admission'] === 'candidate') {
                $candidates[$id] = $entry['filename'] ?? null;
            }
        }
        foreach ($report['artifacts'] as $artifact) {
            $name = $artifact['filename'] ?? null;
            $id = $artifact['diffusionId'] ?? null;
            if (!is_array($artifact) || !is_string($name) || strlen($name) > 255 || preg_match("/\A[A-Za-z0-9][A-Za-z0-9'-]*\.mp3\z/", $name) !== 1
                || isset($expected[$name]) || !is_int($id) || ($candidates[$id] ?? null) !== $name
                || !is_int($artifact['size'] ?? null) || $artifact['size'] < 0
                || !is_string($artifact['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) !== 1) {
                throw new \RuntimeException('Invalid, dangerous or duplicate ZIP entry.');
            }
            $expected[$name] = $artifact;
            unset($candidates[$id]);
        }
        foreach ($report['sourceIssues'] as $id => $issue) {
            if (!isset($candidates[$id]) || !is_string($issue) || $issue === '') {
                throw new \RuntimeException('Invalid per-occurrence source outcome.');
            }
            unset($candidates[$id]);
        }
        if (($report['version'] ?? null) === 3) {
            $this->checkDifferential($report, $candidates);
        }
        if ($candidates !== []) {
            throw new \RuntimeException('The preparation report omits candidate outcomes.');
        }

        return $expected;
    }

    private function checkDifferential(array $report, array &$remaining): void
    {
        $plan = $report['differential'] ?? null;
        $copyIds = array_column($report['artifacts'], 'diffusionId');
        sort($copyIds, SORT_NUMERIC);
        if (!is_array($plan) || ($plan['revision'] ?? null) === null
            || $plan['revision'] !== $report['revision'] || ($plan['week'] ?? null) !== $report['week']
            || ($plan['attemptId'] ?? null) !== $report['attemptId'] || ($plan['copyIds'] ?? null) !== $copyIds
            || !is_array($plan['removed'] ?? null) || !array_is_list($plan['removed'])
            || !is_array($plan['expected'] ?? null) || !array_is_list($plan['expected'])
            || count($plan['expected']) !== count($report['entries'])) {
            throw new \RuntimeException('Invalid differential preparation report.');
        }
        $entries = array_column($report['entries'], null, 'diffusionId');
        $copies = array_column($report['artifacts'], null, 'diffusionId');
        foreach ($plan['expected'] as $row) {
            $id = $row['diffusionId'] ?? null;
            $entry = is_int($id) ? ($entries[$id] ?? null) : null;
            if ($entry === null || !array_key_exists('filename', $row) || $row['filename'] !== $entry['filename']
                || ($row['admission'] ?? null) !== $entry['admission'] || !is_array($row['changes'] ?? null)) {
                throw new \RuntimeException('Differential occurrence differs from the published manifest.');
            }
            unset($entries[$id]);
            $origin = $row['origin'] ?? null;
            $artifact = $row['artifact'] ?? null;
            if ($origin === null) {
                if ($artifact !== ($copies[$id] ?? null)) {
                    throw new \RuntimeException('Differential artifact differs from the ZIP contents.');
                }
                continue;
            }
            if (!is_array($origin) || !is_string($origin['attemptId'] ?? null)
                || $origin['attemptId'] === $report['attemptId']
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $origin['attemptId']) !== 1
                || !isset($remaining[$id]) || !is_array($artifact) || ($origin['artifact'] ?? null) !== $artifact
                || ($artifact['diffusionId'] ?? null) !== $id || ($artifact['filename'] ?? null) !== $remaining[$id]
                || !is_int($artifact['size'] ?? null) || $artifact['size'] < 0
                || !is_string($artifact['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) !== 1
                || $row['changes'] !== ['unchanged']) {
                throw new \RuntimeException('Invalid provenance for a reused artifact.');
            }
            unset($remaining[$id]);
        }
    }

    private function createArchive(RadioPreparationWorkspace $workspace, string $temporary, array $expected): void
    {
        $archive = new ZipArchive();
        $mask = umask(0077);
        $opened = false;
        try {
            if ($archive->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Cannot exclusively create a temporary ZIP.');
            }
            $opened = true;
            foreach ($expected as $name => $artifact) {
                $file = $workspace->ownedFile($name);
                if (filesize($file) !== $artifact['size'] || !$archive->addFile($file, $name, 0, 0, 0)) {
                    throw new \RuntimeException('Cannot add the verified MP3 output to the ZIP.');
                }
            }
            if (!$this->closeArchive($archive)) {
                throw new \RuntimeException('Cannot close the MP3 ZIP.');
            }
            $opened = false;
        } finally {
            if ($opened) {
                unset($archive);
            }
            umask($mask);
        }
    }

    protected function closeArchive(ZipArchive $archive): bool
    {
        return @$archive->close();
    }

    protected function verifyArchive(string $path, array $expected): array
    {
        $before = self::snapshot(self::privateFile($path));
        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Cannot reopen the MP3 ZIP.');
        }
        try {
            if ($archive->numFiles !== count($expected)) {
                throw new \RuntimeException('The ZIP entry count differs from the preparation.');
            }
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $stat = $archive->statIndex($index);
                $name = $stat['name'] ?? '';
                $artifact = $expected[$name] ?? null;
                if ($artifact === null || $stat['size'] !== $artifact['size']) {
                    throw new \RuntimeException('Unexpected ZIP entry or uncompressed size.');
                }
                $stream = $archive->getStreamIndex($index);
                if ($stream === false) {
                    throw new \RuntimeException('Cannot read a ZIP entry.');
                }
                try {
                    $hash = hash_init('sha256');
                    $size = @hash_update_stream($hash, $stream, $artifact['size'] + 1);
                    if ($size !== $artifact['size'] || !hash_equals($artifact['sha256'], hash_final($hash))) {
                        throw new \RuntimeException('A ZIP entry differs from its prepared MP3.');
                    }
                } finally {
                    fclose($stream);
                }
                unset($expected[$name]);
            }
        } finally {
            $archive->close();
        }
        $fingerprint = self::fingerprint($path);
        if ($before !== self::snapshot(self::privateFile($path))) {
            throw new \RuntimeException('The ZIP changed during verification.');
        }

        return $fingerprint;
    }

    private static function fingerprint(string $path): array
    {
        $before = self::privateFile($path);
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot read the private ZIP.');
        }
        try {
            if (self::snapshot(fstat($stream) ?: []) !== self::snapshot($before)) {
                throw new \RuntimeException('The ZIP descriptor does not match its path.');
            }
            $hash = hash_init('sha256');
            $size = @hash_update_stream($hash, $stream, $before['size'] + 1);
            $after = self::privateFile($path);
            if ($size !== $before['size'] || self::snapshot($before) !== self::snapshot($after)
                || self::snapshot($before) !== self::snapshot(fstat($stream) ?: []) || $before['nlink'] !== 1) {
                throw new \RuntimeException('The ZIP identity changed while hashing.');
            }

            return ['dev' => $before['dev'], 'ino' => $before['ino'], 'size' => $size, 'sha256' => hash_final($hash)];
        } finally {
            fclose($stream);
        }
    }

    private static function snapshot(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'size', 'mtime', 'ctime', 'nlink']));
    }

    private static function privateFile(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0100600 || $stat['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Expected a private regular result file.');
        }

        return $stat;
    }
}
