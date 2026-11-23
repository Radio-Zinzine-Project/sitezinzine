<?php

declare(strict_types=1);

namespace App\Exception;

/** The filesystem rejected a link or unlink after the path checks. */
final class Mp3TrashDiskMutationFailure extends \RuntimeException {}
