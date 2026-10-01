<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\PendingRebroadcast;

interface PendingRebroadcastPopulatorInterface
{
    /**
     * @param Diffusion[] $diffusions
     *
     * @return PendingRebroadcast[]
     */
    public function populateFromPublishedDiffusions(
        array $diffusions,
        \DateTimeInterface $before
    ): array;
}