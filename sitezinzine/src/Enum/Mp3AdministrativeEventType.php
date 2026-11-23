<?php

declare(strict_types=1);

namespace App\Enum;

enum Mp3AdministrativeEventType: string
{
    case REQUEST = 'request';
    case TRANSITION_CONFIRMED = 'transition_confirmed';
    case REFUSAL = 'refusal';
    case FAILURE = 'failure';
    case UNCERTAIN = 'uncertain';
}
