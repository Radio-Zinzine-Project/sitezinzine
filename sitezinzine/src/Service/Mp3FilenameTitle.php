<?php

declare(strict_types=1);

namespace App\Service;

final class Mp3FilenameTitle
{
    public static function normalize(string $s): string
    {
        $s = trim($s);

        // translittération (é → e, etc.)
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;

        return self::fromTransliterated($s);
    }

    public static function fromTransliterated(string $s): string
    {
        // remplace tout ce qui n’est pas lettre/chiffre par espace
        $s = preg_replace('/[^A-Za-z0-9]+/', ' ', $s) ?? '';

        // met en mots
        $words = explode(' ', strtolower($s));

        // supprime les valeurs vides éventuelles
        $words = array_filter($words, static fn($w) => $w !== '');

        // met en CamelCase
        $words = array_map(static fn($w) => ucfirst($w), $words);

        return substr(implode('', $words), 0, 50) ?: 'Emission';
    }
}
