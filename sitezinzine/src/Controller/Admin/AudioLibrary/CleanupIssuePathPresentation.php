<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

/** Filters stored paths for administrative display only. */
final class CleanupIssuePathPresentation
{
    public static function display(mixed $path): ?string
    {
        if (!is_string($path) || preg_match('~[\\\\:\x00-\x1f\x7f]~', $path)
            || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            return null;
        }

        return $path;
    }
}
