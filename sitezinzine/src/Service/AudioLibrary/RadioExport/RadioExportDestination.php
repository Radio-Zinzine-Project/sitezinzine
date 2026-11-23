<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport;

final class RadioExportDestination
{
    public static function accepts(string $key): bool
    {
        return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $key) === 1;
    }
}
