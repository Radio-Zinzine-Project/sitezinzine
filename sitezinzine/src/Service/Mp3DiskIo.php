<?php

declare(strict_types=1);

namespace App\Service;

/** Native name operations. Neither method overwrites or recursively removes a path. */
class Mp3DiskIo
{
    public function link(string $from, string $to): bool
    {
        return @link($from, $to);
    }

    public function unlink(string $path): bool
    {
        return @unlink($path);
    }
}
