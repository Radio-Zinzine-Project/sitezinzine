<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\Mp3StoragePath;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** An exclusively created private directory, owned by one preparation attempt. */
#[Exclude]
final class RadioPreparationWorkspace
{
    private string $state = 'running';
    private array $files = [];

    private function __construct(
        private readonly string $root,
        private readonly string $relative,
        private readonly array $identity,
        private array $report,
        private readonly string $library,
    ) {}

    /** @param array{library: string, work: string, trash: string, public: string} $protectedRoots */
    public static function open(string $root, array $protectedRoots, array $manifest, string $attemptId): self
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $attemptId) !== 1) {
            throw new \InvalidArgumentException('Invalid radio preparation attempt identifier.');
        }
        $report = (new RadioPreparationAdmission())->admit($manifest);
        [$root, $protectedDirectories] = self::validateRoot($root, $protectedRoots);

        $week = $report['weekStart']->format('Y-m-d');
        $weekDirectory = Mp3StoragePath::resolve($root, $week);
        $mask = umask(0077);
        try {
            // Another attempt may have created this week directory first.
            @mkdir($weekDirectory, 0700);
            self::privateDirectory($weekDirectory);
            $relative = $week . '/' . $attemptId;
            $directory = Mp3StoragePath::resolve($root, $relative);
            if (!@mkdir($directory, 0700)) {
                throw new \RuntimeException('Cannot exclusively create the radio preparation workspace.');
            }
            $workspaceIdentity = self::privateDirectory($directory);
        } finally {
            umask($mask);
        }

        return new self($root, $relative, self::fileId($workspaceIdentity), $report, $protectedDirectories['library']);
    }

    /** @return array{string, array} The canonical export and protected roots. */
    public static function validateRoot(string $root, array $protectedRoots): array
    {
        $root = Mp3StoragePath::directory($root);
        $identity = self::privateDirectory($root);
        foreach (['library', 'work', 'trash', 'public'] as $key) {
            $protected = $protectedDirectories[$key] = Mp3StoragePath::directory($protectedRoots[$key]);
            $protectedIdentity = lstat($protected);
            if ($protectedIdentity === false || self::overlaps($root, $protected)
                || self::fileId($identity) === self::fileId($protectedIdentity)) {
                throw new \RuntimeException('The export root overlaps a protected MP3 or HTTP directory.');
            }
        }

        return [$root, $protectedDirectories];
    }

    public function directory(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->relative);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function report(): array
    {
        return $this->report;
    }

    public function finishReportOnly(): array
    {
        $this->requireRunning();
        if ($this->report['candidateIds'] !== [] || scandir($this->ownedDirectory()) !== ['.', '..']) {
            throw new \LogicException('Only an empty workspace without candidates can finalize a report alone.');
        }
        $this->state = 'copies_ready';
        $this->report += ['artifacts' => [], 'sourceIssues' => []];

        return $this->report;
    }

    public function sourcePath(string $relative): string
    {
        $this->requireRunning();

        return Mp3StoragePath::resolve($this->library, $relative);
    }

    /** @return resource */
    public function createFile(string $name): mixed
    {
        $this->requireRunning();
        if (strlen($name) > 255 || preg_match("/\A(?:[A-Za-z0-9][A-Za-z0-9'-]*\.mp3|\.source-[1-9][0-9]*\.part)\z/", $name) !== 1) {
            throw new \InvalidArgumentException('Invalid preparation filename.');
        }
        $path = $this->ownedDirectory() . DIRECTORY_SEPARATOR . $name;
        return $this->createTracked($name, $path);
    }

    /** @return resource */
    private function createTracked(string $name, string $path): mixed
    {
        $mask = umask(0077);
        try {
            $stream = @fopen($path, 'xb');
        } finally {
            umask($mask);
        }
        if ($stream === false) {
            throw new \RuntimeException('Cannot exclusively create a radio preparation file.');
        }
        try {
            $stat = fstat($stream);
            if ($stat === false) {
                throw new \RuntimeException('Cannot establish preparation file ownership.');
            }
            $this->files[$name] = self::fileId($stat);
            $this->ownedFile($name);

            return $stream;
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    public function ownedFile(string $name): string
    {
        $path = $this->ownedDirectory() . DIRECTORY_SEPARATOR . $name;
        clearstatcache(true, $path);
        $stat = isset($this->files[$name]) ? @lstat($path) : false;
        if ($stat === false || self::fileId($stat) !== $this->files[$name]
            || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('The radio preparation file identity changed.');
        }

        return $path;
    }

    public function discardFile(string $name): void
    {
        $this->requireRunning();
        $this->removeOwnedFile($name);
    }

    private function removeOwnedFile(string $name): void
    {
        if (!@unlink($this->ownedFile($name))) {
            throw new \RuntimeException('Cannot remove a file owned by the radio preparation.');
        }
        unset($this->files[$name]);
    }

    public function complete(array $artifacts, array $sourceIssues, ?array $differential = null, array $sourceSignatures = []): array
    {
        $this->requireRunning();
        $names = $ids = [];
        $candidates = array_column(array_filter($this->report['entries'],
            static fn (array $entry): bool => $entry['admission'] === 'candidate'), 'filename', 'diffusionId');
        foreach ($artifacts as $artifact) {
            $id = $artifact['diffusionId'];
            $name = $artifact['filename'];
            if (($candidates[$id] ?? null) !== $name || isset($ids[$id])
                || preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) !== 1
                || filesize($this->ownedFile($name)) !== $artifact['size']) {
                throw new \RuntimeException('An output does not match the admitted occurrence.');
            }
            $ids[$id] = true;
            $names[] = $name;
        }
        foreach ($differential['expected'] ?? [] as $row) {
            if ($row['origin'] !== null) {
                $id = $row['diffusionId'];
                if (($candidates[$id] ?? null) !== $row['filename'] || isset($ids[$id])) {
                    throw new \RuntimeException('A reused artifact does not match the admitted occurrence.');
                }
                $ids[$id] = true;
            }
        }
        $entries = scandir($this->ownedDirectory());
        if (array_diff_key($candidates, $ids + $sourceIssues) !== [] || array_intersect_key($ids, $sourceIssues) !== []
            || array_diff_key($sourceIssues, $candidates) !== []
            || $entries === false || array_diff($entries, ['.', '..', ...$names]) !== []) {
            throw new \RuntimeException('The radio preparation is incomplete or contains unexpected files.');
        }

        $this->report += ['artifacts' => $artifacts, 'sourceIssues' => $sourceIssues, 'sourceSignatures' => $sourceSignatures];
        if ($differential !== null) {
            $this->report['differential'] = $differential;
        }
        $this->state = 'copies_ready';

        return $this->report;
    }

    public function archiveTemporaryPath(): string
    {
        $this->requireCopiesReady();
        $path = $this->ownedDirectory() . DIRECTORY_SEPARATOR . '.audio.pending';
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            throw new \RuntimeException('The temporary ZIP destination is occupied.');
        }

        return $path;
    }

    public function adoptClosedArchive(): string
    {
        $this->requireCopiesReady();
        $name = '.audio.pending';
        $path = $this->ownedDirectory() . DIRECTORY_SEPARATOR . $name;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0100600
            || $stat['uid'] !== posix_geteuid() || $stat['nlink'] !== 1) {
            throw new \RuntimeException('The closed ZIP does not have a private regular entry.');
        }
        $this->files[$name] = self::fileId($stat);

        return $this->ownedFile($name);
    }

    public function publishArchive(): string
    {
        $this->requireCopiesReady();

        return $this->publishOwnedFile('.audio.pending', 'audio.zip');
    }

    public function publishReport(array $report): void
    {
        $this->requireCopiesReady();
        $expected = $this->report['artifacts'] === [] ? 'report_only' : 'ready';
        if (($report['status'] ?? null) !== $expected || ($expected === 'ready' && !isset($this->files['audio.zip']))) {
            throw new \LogicException('A final report must match the verified preparation.');
        }
        $this->writeRecord('.result.pending', 'result.json', $report);
        $this->state = $report['status'];
    }

    public function attemptId(): string
    {
        return basename($this->relative);
    }

    private function requireCopiesReady(): void
    {
        if ($this->state !== 'copies_ready') {
            throw new \LogicException('Only verified private copies can be packaged.');
        }
    }

    private function writeRecord(string $temporary, string $final, array $record): void
    {
        $bytes = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($bytes) > 16 * 1024 * 1024) {
            throw new \RuntimeException('The preparation record exceeds its size limit.');
        }
        $stream = $this->createTracked($temporary, $this->ownedDirectory() . DIRECTORY_SEPARATOR . $temporary);
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) {
                throw new \RuntimeException('Cannot write the preparation record.');
            }
        } finally {
            fclose($stream);
        }
        if (file_get_contents($this->ownedFile($temporary)) !== $bytes) {
            throw new \RuntimeException('The preparation record could not be verified.');
        }
        $this->publishOwnedFile($temporary, $final, commit: true);
    }

    private function publishOwnedFile(string $temporary, string $final, bool $commit = false): string
    {
        $source = $this->ownedFile($temporary);
        $target = $this->ownedDirectory() . DIRECTORY_SEPARATOR . $final;
        if (!@link($source, $target)) {
            throw new \RuntimeException('Cannot publish a preparation file without replacing another entry.');
        }
        $identity = $this->files[$temporary];
        if ($commit) {
            // The link is the commit point. An interrupted temporary-name cleanup cannot undo it.
            clearstatcache(true, $source);
            $stat = @lstat($source);
            if ($stat !== false && self::fileId($stat) === $identity) {
                @unlink($source);
            }

            return $target;
        }
        clearstatcache(true, $target);
        $stat = @lstat($target);
        if ($stat === false || self::fileId($stat) !== $identity) {
            throw new \RuntimeException('The published preparation file identity changed.');
        }
        $this->files[$final] = $identity;
        // Both names are briefly aliases. Remove only the verified temporary name.
        clearstatcache(true, $source);
        $stat = @lstat($source);
        if ($stat === false || self::fileId($stat) !== $identity || !@unlink($source)) {
            throw new \RuntimeException('Cannot retire the verified preparation temporary file.');
        }
        unset($this->files[$temporary]);

        return $this->ownedFile($final);
    }

    public function fail(?string $diagnostic = null): void
    {
        if (!in_array($this->state, ['running', 'copies_ready'], true)) {
            throw new \LogicException('Only an unpublished preparation can fail.');
        }
        $this->state = 'failed';
        $failure = null;
        foreach (array_keys($this->files) as $name) {
            try {
                $this->removeOwnedFile($name);
            } catch (\RuntimeException $error) {
                $failure ??= $error;
            }
        }
        if ($diagnostic !== null) {
            $this->writeRecord('.failure.pending', 'failure.json', [
                'version' => 1, 'status' => 'failed', 'week' => $this->report['weekStart']->format('Y-m-d'),
                'attemptId' => $this->attemptId(), 'failedAt' => gmdate(DATE_ATOM), 'message' => $diagnostic,
            ]);
        }
        if (($diagnostic === null && !@rmdir($this->ownedDirectory())) || $failure !== null) {
            throw new \RuntimeException('Cannot remove the failed radio preparation workspace safely.', 0, $failure);
        }
    }

    public function ownedDirectory(): string
    {
        $path = Mp3StoragePath::resolve($this->root, $this->relative);
        if ($this->identity !== self::fileId(self::privateDirectory($path))) {
            throw new \RuntimeException('The radio preparation workspace identity changed.');
        }

        return $path;
    }

    private function requireRunning(): void
    {
        if ($this->state !== 'running') {
            throw new \LogicException('The radio preparation attempt is no longer running.');
        }
    }

    private static function privateDirectory(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0040700
            || !function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Radio preparation requires a private 0700 directory owned by PHP.');
        }

        return $stat;
    }

    private static function fileId(array $stat): array
    {
        return [$stat['dev'], $stat['ino']];
    }

    private static function overlaps(string $first, string $second): bool
    {
        $first = rtrim($first, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $second = rtrim($second, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($first, $second) || str_starts_with($second, $first);
    }
}
