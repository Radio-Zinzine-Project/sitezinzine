<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

enum Mp3CleanupIdentityDecision: string
{
    case Confirmed = 'confirmed';
    case Absent = 'absent';
    case Present = 'file_present';
    case Different = 'different';
    case MissingProof = 'missing_proof';
    case UnusableProof = 'unusable_proof';
    case ConflictingProofs = 'conflicting_proofs';
    case Indeterminate = 'indeterminate';
    case UnsafePath = 'unsafe_path';
}
