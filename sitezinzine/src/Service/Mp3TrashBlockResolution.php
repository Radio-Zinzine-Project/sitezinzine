<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;

/** Evaluate only under the storage lock and the emission and journal row locks. */
final class Mp3TrashBlockResolution
{
    public static function requireResolved(Mp3TrashOperation $operation, ?string $expectedVersion, callable $inspect): void
    {
        if (!$operation->isBlocked() && $expectedVersion === null) {
            return;
        }
        if (!$operation->isBlocked() || $expectedVersion === null || $operation->blockVersion() !== $expectedVersion) {
            throw new \DomainException('La décision de blocage MP3 a changé ou doit être confirmée.');
        }

        $name = match ($operation->lastDiagnosticCode()) {
            'public_file_missing' => 'public',
            'private_file_missing' => 'private',
            default => throw new \DomainException('La résolution du motif de blocage MP3 ne peut pas être démontrée.'),
        };
        $entries = $inspect();
        if ($entries[$name]['matches_journal'] !== true) {
            throw new \DomainException('Le fichier à l’origine du blocage MP3 reste absent ou non vérifié.');
        }
    }
}
