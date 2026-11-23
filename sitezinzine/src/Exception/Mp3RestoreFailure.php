<?php

declare(strict_types=1);

namespace App\Exception;

/** Technical context for a refused or interrupted restoration. */
final class Mp3RestoreFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $step,
        public readonly string $operationId,
        public readonly ?string $attemptId,
        public readonly bool $commitUncertain,
        \Throwable $cause,
        public readonly string $reason = 'interrupted',
        public readonly ?string $requestId = null,
        public readonly ?\Throwable $traceFailure = null,
    ) {
        parent::__construct('La restauration MP3 a été interrompue.', 0, $cause);
    }
}
