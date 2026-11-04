<?php

declare(strict_types=1);

namespace App\Service;

final class RadioWeek
{
    public static function start(\DateTimeImmutable $date): \DateTimeImmutable
    {
        // Même normalisation que la grille : lundi reste dans la semaine précédente.
        $daysSinceTuesday = ((int) $date->format('N') + 5) % 7;
        return $date->setTime(0, 0)->modify(sprintf('-%d days', $daysSinceTuesday));
    }
}
