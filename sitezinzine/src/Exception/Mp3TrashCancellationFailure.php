<?php

declare(strict_types=1);

namespace App\Exception;

final class Mp3TrashCancellationFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $step,
        public readonly string $requestId,
        \Throwable $cause,
        public readonly ?\Throwable $traceFailure = null,
    ) {
        parent::__construct('L’annulation MP3 n’a pas été confirmée.', 0, $cause);
    }
}
