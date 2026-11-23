<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport;

use App\Service\RadioWeek;
use Psr\Clock\ClockInterface;

final readonly class RadioExportWeekLifetime
{
    public function __construct(private ClockInterface $clock) {}

    public static function cutoff(\DateTimeImmutable $now): string
    {
        return RadioWeek::start($now->setTimezone(new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
    }

    public function isExpired(\DateTimeImmutable $week): bool
    {
        return $week->format('Y-m-d') < self::cutoff($this->clock->now());
    }

    public function requireOpen(\DateTimeImmutable $week): void
    {
        if ($this->isExpired($week)) {
            throw new \DomainException('The radio export week has expired.');
        }
    }
}
