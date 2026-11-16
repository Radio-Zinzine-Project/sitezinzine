<?php

declare(strict_types=1);

namespace App\Exception;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Emitted only after the persistence boundary has verified the active-file collision. */
final class Mp3CleanupActiveIssueCollision extends \RuntimeException
{
    public function __construct(UniqueConstraintViolationException $origin)
    {
        parent::__construct('An active MP3 cleanup issue already exists for this file.', 0, $origin);
    }
}
