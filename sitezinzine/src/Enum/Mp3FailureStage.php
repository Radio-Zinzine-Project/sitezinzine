<?php

declare(strict_types=1);

namespace App\Enum;

enum Mp3FailureStage: string
{
    case VALIDATION = 'validation';
    case PREPARATION = 'preparation';
    case METADATA = 'metadata';
    case MOVE = 'move';
    case DOCTRINE = 'doctrine';
    case COMMIT = 'commit';
    case CONFLICT = 'conflict';

    public function publicDescription(): string
    {
        return match ($this) {
            self::VALIDATION => 'Le fichier MP3 est invalide, introuvable ou illisible.',
            self::PREPARATION => 'Impossible de préparer le fichier MP3 ou son classement.',
            self::METADATA => 'Impossible d’écrire ou de vérifier les métadonnées du MP3.',
            self::MOVE => 'Impossible de déplacer ou de publier le fichier MP3.',
            self::DOCTRINE => 'Une erreur technique empêche l’enregistrement des modifications.',
            self::COMMIT => 'Le résultat de l’enregistrement n’a pas pu être confirmé.',
            self::CONFLICT => 'Cette émission a été modifiée par une autre requête. Rechargez la fiche avant de réessayer.',
        };
    }
}
