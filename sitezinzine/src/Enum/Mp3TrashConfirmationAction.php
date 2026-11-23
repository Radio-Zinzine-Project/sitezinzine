<?php

declare(strict_types=1);

namespace App\Enum;

enum Mp3TrashConfirmationAction: string
{
    case RESUME_DELETION = 'resume-deletion';
    case RESUME_RESTORATION = 'resume-restoration';
    case CANCEL_DELETION = 'cancel-deletion';
    case CANCEL_RESTORATION = 'cancel-restoration';
    case RESTORE = 'restore';

    public const ROUTE_REQUIREMENT = 'resume-deletion|resume-restoration|cancel-deletion|cancel-restoration|restore';
    public const RECOVERY_ROUTE_REQUIREMENT = 'resume-deletion|resume-restoration';
    public const CANCELLATION_ROUTE_REQUIREMENT = 'cancel-deletion|cancel-restoration';

    public function isRecovery(): bool
    {
        return $this === self::RESUME_DELETION || $this === self::RESUME_RESTORATION;
    }

    public function acceptsPhase(Mp3TrashPhase $phase): bool
    {
        return match ($this) {
            self::RESUME_DELETION => in_array($phase, [Mp3TrashPhase::SUPPRESSION_PREPAREE, Mp3TrashPhase::RETRAIT_PUBLIC_EN_ATTENTE], true),
            self::RESUME_RESTORATION => in_array($phase, [Mp3TrashPhase::RESTAURATION_PREPAREE, Mp3TrashPhase::RETRAIT_PRIVE_EN_ATTENTE], true),
            self::CANCEL_DELETION => $phase === Mp3TrashPhase::SUPPRESSION_PREPAREE,
            self::CANCEL_RESTORATION => $phase === Mp3TrashPhase::RESTAURATION_PREPAREE,
            self::RESTORE => $phase === Mp3TrashPhase::EN_CORBEILLE,
        };
    }

    public function requiresAttempt(): bool
    {
        return $this === self::RESUME_RESTORATION || $this === self::CANCEL_RESTORATION;
    }
}
