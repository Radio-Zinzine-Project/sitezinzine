<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Mp3TrashPhase;

final class Mp3TrashRecoveryFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $step,
        public readonly string $operationId,
        public readonly int $actorId,
        public readonly Mp3TrashPhase $expectedPhase,
        \Throwable $cause,
        public readonly ?string $attemptId = null,
        public readonly ?string $requestId = null,
        public readonly ?\Throwable $traceFailure = null,
    ) {
        parent::__construct($attemptId === null
            ? 'La reprise de la suppression MP3 n’a pas été confirmée.'
            : 'La reprise de la restauration MP3 n’a pas été confirmée.', 0, $cause);
    }
}
