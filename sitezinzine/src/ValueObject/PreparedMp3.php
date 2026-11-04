<?php

declare(strict_types=1);

namespace App\ValueObject;

/** Receipt for one exclusively owned private preparation directory. */
final readonly class PreparedMp3
{
    public function __construct(
        public string $directory,
        public string $path,
        public string $code,
        public string $year,
        public string $basename,
    ) {}
}
