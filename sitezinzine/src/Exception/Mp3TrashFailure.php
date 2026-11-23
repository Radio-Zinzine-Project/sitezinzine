<?php

declare(strict_types=1);

namespace App\Exception;

/** Technical context for an interrupted trash operation. */
final class Mp3TrashFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $step,
        public readonly ?string $operationId,
        public readonly bool $commitUncertain,
        \Throwable $cause,
    ) {
        parent::__construct('La mise en corbeille MP3 a été interrompue.', 0, $cause);
    }
}
