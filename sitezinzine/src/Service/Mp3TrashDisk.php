<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Exception\Mp3TrashDiskMutationFailure;

/**
 * Disk-only operations on the names owned by a journal entry.
 *
 * Callers must serialize changes to both names and keep their directories
 * trusted. PHP path checks and unlink() do not form an atomic compare-and-remove.
 * A successful link is visible, but this API does not sync directory entries.
 */
final readonly class Mp3TrashDisk
{
    public function __construct(
        private string $libraryDir,
        private Mp3TrashPath $trashPath,
        private Mp3DiskIo $io,
    ) {}

    public function prepareTrash(Mp3TrashOperation $operation): void
    {
        [$public, $private] = $this->paths($operation);
        $this->prepare($public, $private, $operation);
    }

    public function finalizeTrash(Mp3TrashOperation $operation): void
    {
        [$public, $private] = $this->paths($operation);
        $this->finalize($public, $private, $operation);
    }

    public function assertRestoreAvailable(Mp3TrashOperation $operation): void
    {
        [$public, $private] = $this->paths($operation);
        $this->verifyContent($private, $operation);
        if ($this->fileStat($public) !== null) {
            throw new \RuntimeException('Le nom public du MP3 est déjà occupé.');
        }
    }

    public function prepareRestore(Mp3TrashOperation $operation): void
    {
        [$public, $private] = $this->paths($operation);
        $this->prepare($private, $public, $operation);
    }

    public function assertRestorePrepared(Mp3TrashOperation $operation): void
    {
        [$public, $private] = $this->paths($operation);
        $this->verifyPair($private, $public, $this->verifyContent($private, $operation));
    }

    public function finalizeRestore(Mp3TrashOperation $operation): void
    {
        [$public, $private] = $this->paths($operation);
        $this->finalize($private, $public, $operation);
    }

    /** Observations only. Never use this result as permission to mutate a name. */
    public function inspect(string $publicRelative, string $privateRelative, int $size, string $sha256): array
    {
        [$publicPath, $publicStat, $public] = $this->inspectEntry(
            fn () => Mp3StoragePath::resolveEntryForInspection($this->libraryDir, $publicRelative), $size, $sha256,
        );
        [$privatePath, $privateStat, $private] = $this->inspectEntry(
            fn () => $this->trashPath->resolveEntryForInspection($privateRelative), $size, $sha256,
        );
        $sameInode = null;
        $pairError = null;
        if ($public['inode'] !== null && $private['inode'] !== null) {
            $sameInode = $public['device'] === $private['device'] && $public['inode'] === $private['inode'];
            if ($sameInode) {
                try {
                    $this->verifyPair($publicPath, $privatePath, $publicStat);
                    $this->requireSameFile($publicStat, $privateStat);
                } catch (\RuntimeException) {
                    $pairError = 'pair_unconfirmed';
                }
            }
        }

        return ['public' => $public, 'private' => $private, 'same_inode' => $sameInode,
            'collision' => $sameInode === null ? null : !$sameInode, 'pair_error' => $pairError];
    }

    private function inspectEntry(callable $resolve, int $size, string $sha256): array
    {
        $result = ['present' => null, 'type' => 'unknown', 'size' => null, 'sha256' => null,
            'device' => null, 'inode' => null, 'matches_journal' => null, 'error' => null];
        try {
            $path = $resolve();
            $stat = $this->entryStat($path);
        } catch (\RuntimeException) {
            $result['error'] = 'path_unavailable';
            return [null, null, $result];
        }
        if ($stat === null) {
            $result['present'] = false;
            $result['type'] = 'missing';
            return [$path, null, $result];
        }
        $result['present'] = true;
        $result['type'] = match ($stat['mode'] & 0170000) {
            0100000 => 'regular',
            0120000 => 'symlink',
            0040000 => 'directory',
            default => 'special',
        };
        if ($result['type'] !== 'regular') {
            return [$path, $stat, $result];
        }
        $result['size'] = $stat['size'];
        if ($stat['ino'] <= 0) {
            $result['error'] = 'identity_unavailable';
            return [$path, $stat, $result];
        }
        $result['device'] = $stat['dev'];
        $result['inode'] = $stat['ino'];
        try {
            $result['sha256'] = $this->readFingerprint($path, $stat, $resolve);
            $result['matches_journal'] = $stat['size'] === $size && hash_equals($sha256, $result['sha256']);
        } catch (\RuntimeException) {
            $result['error'] = 'content_unreadable_or_changed';
        }
        return [$path, $stat, $result];
    }

    /** @return array{string, string} */
    private function paths(Mp3TrashOperation $operation): array
    {
        return [
            Mp3StoragePath::resolve($this->libraryDir, $operation->originalThumbnailMp3()),
            $this->trashPath->resolve($operation->trashRelativePath()),
        ];
    }

    private function prepare(string $source, string $destination, Mp3TrashOperation $operation): void
    {
        $expected = $this->verifyContent($source, $operation);
        $existing = $this->fileStat($destination);
        if ($existing === null && !$this->io->link($source, $destination)) {
            throw new Mp3TrashDiskMutationFailure('Impossible de créer le lien dur MP3 sans écrasement.');
        }
        $this->verifyPair($source, $destination, $expected);
    }

    private function finalize(string $remove, string $keep, Mp3TrashOperation $operation): void
    {
        $expected = $this->verifyContent($keep, $operation);
        if ($this->fileStat($remove) === null) {
            return;
        }
        $this->verifyPair($keep, $remove, $expected);
        if (!$this->io->unlink($remove)) {
            throw new Mp3TrashDiskMutationFailure('Impossible de retirer le nom MP3 autorisé.');
        }
        $this->requireSameFile($expected, $this->fileStat($keep));
        if ($this->fileStat($remove) !== null) {
            throw new \RuntimeException('Le nom MP3 retiré est de nouveau occupé.');
        }
    }

    private function verifyContent(string $path, Mp3TrashOperation $operation): array
    {
        $expected = $this->fileStat($path);
        if ($expected === null || $expected['size'] !== $operation->size()) {
            throw new \RuntimeException('Fichier MP3 attendu absent ou de taille différente.');
        }
        if (!hash_equals($operation->sha256(), $this->readFingerprint($path, $expected))) {
            throw new \RuntimeException('Le contenu MP3 ne correspond pas au journal.');
        }

        return $expected;
    }

    private function readFingerprint(string $path, array $expected, ?callable $revalidatePath = null): string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Impossible de lire le fichier MP3 attendu.');
        }
        try {
            $this->requireSameFile($expected, fstat($stream) ?: null);
            if ($revalidatePath !== null) {
                if ($revalidatePath() !== $path) {
                    throw new \RuntimeException('Le chemin MP3 a changé.');
                }
                $this->requireSameFile($expected, $this->fileStat($path));
            }
            $hash = hash_init('sha256');
            $size = @hash_update_stream($hash, $stream);
            $after = fstat($stream);
            $this->requireSameFile($expected, $after ?: null);
            if ($size !== $expected['size'] || $after['ctime'] !== $expected['ctime']) {
                throw new \RuntimeException('Le contenu MP3 a changé pendant sa lecture.');
            }
            $this->requireSameFile($expected, $this->fileStat($path));
            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function verifyPair(string $first, string $second, array $expected): void
    {
        $left = $this->fileStat($first);
        $right = $this->fileStat($second);
        $this->requireSameFile($expected, $left);
        $this->requireSameFile($expected, $right);

        if ($left['nlink'] < 2 || $right['nlink'] < 2) {
            throw new \RuntimeException('Deux noms distincts du MP3 n’ont pas été confirmés.');
        }
        if (basename($first) === basename($second)) {
            // Two bind-mounted aliases could otherwise refer to the same directory entry.
            $firstParent = $this->pathStat(dirname($first));
            $secondParent = $this->pathStat(dirname($second));
            if ($firstParent === false || $secondParent === false
                || ($firstParent['dev'] === $secondParent['dev'] && $firstParent['ino'] === $secondParent['ino'])) {
                throw new \RuntimeException('Les noms MP3 peuvent désigner la même entrée de répertoire.');
            }
        }
    }

    private function requireSameFile(array $expected, ?array $actual): void
    {
        if ($actual === null || ($actual['mode'] & 0170000) !== 0100000
            || $actual['dev'] !== $expected['dev'] || $actual['ino'] !== $expected['ino']
            || $actual['size'] !== $expected['size'] || $actual['mtime'] !== $expected['mtime']) {
            throw new \RuntimeException('Collision ou identité incertaine entre les noms du MP3.');
        }
    }

    private function fileStat(string $path): ?array
    {
        $stat = $this->entryStat($path);
        if ($stat !== null && (($stat['mode'] & 0170000) !== 0100000 || $stat['ino'] <= 0)) {
            throw new \RuntimeException('Le nom MP3 doit désigner un fichier ordinaire identifiable.');
        }
        return $stat;
    }

    private function entryStat(string $path): ?array
    {
        $stat = $this->pathStat($path);
        if ($stat === false) {
            // An lstat() error alone cannot establish absence for an idempotent retry.
            $entries = @scandir(dirname($path));
            if ($entries === false || in_array(basename($path), $entries, true)) {
                throw new \RuntimeException('Impossible de confirmer l’absence du nom MP3.');
            }
            return null;
        }
        return $stat;
    }

    private function pathStat(string $path): array|false
    {
        clearstatcache(true, $path);
        return @lstat($path);
    }
}
