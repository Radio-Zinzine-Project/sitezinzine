<?php

declare(strict_types=1);

namespace App\Exception;

final class Mp3CleanupTransactionFailure extends \RuntimeException
{
    public function __construct(
        \Throwable $origin,
        private readonly \Throwable $rollbackFailure,
    ) {
        parent::__construct('MP3 cleanup transaction failed and rollback also failed.', 0, $origin);
    }

    public function getRollbackFailure(): \Throwable
    {
        return $this->rollbackFailure;
    }
}
