<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\Mp3StorageLockTimeout;

/** Cooperative process lock. Keep the private directory and lock entry stable. */
final class Mp3StorageLock
{
    private const FILE = '.storage.lock';

    /** @var array<string, array|false> */
    private static array $held = [];

    public function __construct(private readonly Mp3TrashPath $trashPath, private readonly int $timeoutMs)
    {
        if ($timeoutMs < 0) {
            throw new \InvalidArgumentException('Le délai du verrou MP3 doit être positif ou nul.');
        }
    }

    /**
     * Acquire before starting a SQL transaction. The callback must complete
     * synchronously, including all work that needs protection after a commit.
     */
    public function synchronized(callable $criticalSection, bool $allowCreation = true): mixed
    {
        $path = $this->securePath();
        if (isset(self::$held[$path])) {
            throw new \LogicException('Acquisition imbriquée du verrou MP3 interdite.');
        }
        self::$held[$path] = false;
        $stream = null;
        try {
            $previous = $this->stat($path);
            if (!$allowCreation && $previous === false) {
                throw new \RuntimeException('The MP3 storage lock does not yet exist.');
            }
            $stream = $this->open($path, $previous);
            $opened = $this->verifyOpenFile($path, $stream, $previous);
            $this->acquire($stream);
            $this->verifyOpenFile($path, $stream, $opened);
            self::$held[$path] = $opened;
            $result = $criticalSection();
            $this->verifyOpenFile($path, $stream, $opened);

            return $result;
        } finally {
            // Closing the descriptor releases flock(), also on callback errors.
            if (is_resource($stream)) {
                fclose($stream);
            }
            unset(self::$held[$path]);
        }
    }

    /** For a caller whose outer transaction is already enclosed in synchronized(). */
    public function isHeldByCurrentProcess(): bool
    {
        $path = $this->securePath();
        $previous = self::$held[$path] ?? false;

        return $previous !== false && $this->sameInode($previous, $this->requirePrivateFile($this->stat($path)));
    }

    private function securePath(): string
    {
        if (!function_exists('posix_geteuid')) {
            throw new \RuntimeException('Le verrou MP3 exige un environnement PHP POSIX.');
        }
        $path = $this->trashPath->resolve(self::FILE);
        $parent = $this->stat(dirname($path));
        if ($parent === false || ($parent['mode'] & 0170000) !== 0040000
            || ($parent['mode'] & 07777) !== 0700 || $parent['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Le verrou MP3 exige un répertoire privé 0700 appartenant à son utilisateur.');
        }

        return $path;
    }

    /** @return resource */
    private function open(string $path, array|false $previous)
    {
        if ($previous === false) {
            $mask = umask(0077);
            try {
                $stream = @fopen($path, 'x+b');
            } finally {
                umask($mask);
            }
            if ($stream !== false) {
                return $stream;
            }
        }
        $this->requirePrivateFile($this->stat($path));
        // PHP has no portable O_NOFOLLOW option for fopen(). Never truncate,
        // and verify the descriptor against the resolved entry before use.
        $stream = @fopen($path, 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Impossible d’ouvrir le fichier de verrou MP3.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private function verifyOpenFile(string $path, $stream, array|false $previous): array
    {
        if ($this->securePath() !== $path) {
            throw new \RuntimeException('Le chemin du verrou MP3 a changé.');
        }
        $opened = $this->requirePrivateFile(fstat($stream));
        $entry = $this->requirePrivateFile($this->stat($path));
        if (!$this->sameInode($opened, $entry) || ($previous !== false && !$this->sameInode($opened, $previous))) {
            throw new \RuntimeException('Le fichier de verrou MP3 a été remplacé.');
        }

        return $opened;
    }

    private function requirePrivateFile(array|false $stat): array
    {
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0600
            || $stat['uid'] !== posix_geteuid() || $stat['nlink'] !== 1 || $stat['ino'] <= 0) {
            throw new \RuntimeException('Le verrou MP3 doit être un fichier ordinaire privé 0600, sans autre lien.');
        }

        return $stat;
    }

    private function sameInode(array $left, array $right): bool
    {
        return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    private function stat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    /** @param resource $stream */
    private function acquire($stream): void
    {
        $deadline = hrtime(true) + $this->timeoutMs * 1_000_000;
        do {
            if (@flock($stream, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return;
            }
            if ($wouldBlock !== 1) {
                throw new \RuntimeException('Impossible d’acquérir le verrou MP3 sur ce stockage.');
            }
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                break;
            }
            usleep((int) min(10_000, ceil($remaining / 1000)));
        } while (hrtime(true) < $deadline);

        throw new Mp3StorageLockTimeout('Délai d’attente du verrou MP3 dépassé.');
    }
}
