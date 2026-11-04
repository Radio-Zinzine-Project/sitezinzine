<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class Mp3Incident
{
    public function __construct(
        public string $id,
        public string $message,
        public bool $retryBlocked,
        public bool $showOriginal,
    ) {}
}
