<?php

declare(strict_types=1);

namespace App\Enum;

enum Mp3StoredFileState: string
{
    case Absent = 'absent';
    case Verified = 'verified';
    case Unverifiable = 'unverifiable';
    case Indeterminate = 'indeterminate';
}
