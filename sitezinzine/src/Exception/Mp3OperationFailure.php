<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Mp3FailureStage;

final class Mp3OperationFailure extends \RuntimeException
{
    public function __construct(
        public readonly Mp3FailureStage $stage,
        \Throwable $origin,
        public readonly bool $rollbackConfirmed = false,
        public readonly bool $originalPreserved = false,
    ) {
        // Retain the original diagnostic for callers/logs, never for public rendering.
        parent::__construct($origin->getMessage(), 0, $origin);
    }

    public static function at(Mp3FailureStage $stage, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Mp3OperationFailure $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new self($stage, $error);
        }
    }
}
