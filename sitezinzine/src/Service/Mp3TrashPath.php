<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Filesystem\Path;

/** Resolves a file path without creating, moving or deleting anything. */
final readonly class Mp3TrashPath
{
    public function __construct(
        private ?string $trashDir,
        private string $libraryDir,
        private string $workDir,
        private string $publicDir,
    ) {}

    public function resolve(string $relative): string
    {
        // This check is a snapshot of the filesystem. Callers must use trusted
        // directories and atomic no-replace primitives for subsequent writes.
        $path = Mp3StoragePath::resolve($this->directory(), $relative);
        if (!is_dir(dirname($path)) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Le chemin de la corbeille MP3 doit viser un fichier dans un répertoire existant.');
        }

        return $path;
    }

    public function resolveEntryForInspection(string $relative): string
    {
        return Mp3StoragePath::resolveEntryForInspection($this->directory(), $relative);
    }

    private function directory(): string
    {
        if ($this->trashDir === null || !Path::isAbsolute($this->trashDir)) {
            throw new \RuntimeException('La racine de la corbeille MP3 doit être configurée explicitement.');
        }

        $trash = Mp3StoragePath::directory($this->trashDir);
        foreach ([$this->libraryDir, $this->workDir, $this->publicDir] as $protectedDir) {
            $protected = Mp3StoragePath::directory($protectedDir);
            if (Path::isBasePath($protected, $trash) || Path::isBasePath($trash, $protected)) {
                throw new \RuntimeException('La corbeille MP3 doit être séparée de la bibliothèque, de la préparation et de la racine publique.');
            }
        }

        return $trash;
    }
}
