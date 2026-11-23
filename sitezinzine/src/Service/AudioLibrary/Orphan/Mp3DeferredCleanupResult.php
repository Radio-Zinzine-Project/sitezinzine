<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

enum Mp3DeferredCleanupResult: string
{
    case Removed = 'removed';
    case AlreadyAbsent = 'already_absent';
    case ReferenceActive = 'reference_active';
    case ReservationActive = 'reservation_active';
    case AlreadyResolved = 'already_resolved';
    case StaleView = 'stale_view';
    case NotFound = 'not_found';
    case LockUnavailable = 'lock_unavailable';
    case InvalidPath = 'invalid_path';
    case Different = 'different';
    case MissingProof = 'missing_proof';
    case UnusableProof = 'unusable_proof';
    case ConflictingProofs = 'conflicting_proofs';
    case RemovalRefused = 'removal_refused';
    case Indeterminate = 'indeterminate';
    case RequestNotRecorded = 'request_not_recorded';
    case RequestUncertain = 'request_uncertain';
    case DuplicateOrActiveRequest = 'duplicate_or_active_request';
    case OutcomeUncertain = 'outcome_uncertain';
    case IdentityPresent = 'identity_present';
}
