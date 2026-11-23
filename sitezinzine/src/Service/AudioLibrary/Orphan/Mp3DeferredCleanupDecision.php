<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

enum Mp3DeferredCleanupDecision: string
{
    case PreconditionsVerified = 'preconditions_verified';
    case ReferenceActive = 'reference_active';
    case ReservationActive = 'reservation_active';
    case AlreadyResolved = 'already_resolved';
    case StaleView = 'stale_view';
    case NotFound = 'not_found';
    case LockUnavailable = 'lock_unavailable';
    case InvalidPath = 'invalid_path';
    case Indeterminate = 'indeterminate';
}
