<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport;

use App\Service\AudioLibrary\RadioExport\RadioExportDestination;
use App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RadioExportWeekLifetimeTest extends TestCase
{
    public function testTuesdayBoundaryAfterBothClockChanges(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        foreach (['2026-03-31', '2026-10-27'] as $date) {
            $tuesday = new \DateTimeImmutable($date, $paris);
            $clock = new MockClock($tuesday->modify('-1 second'));
            $lifetime = new RadioExportWeekLifetime($clock);
            $previous = $tuesday->modify('-7 days');
            self::assertFalse($lifetime->isExpired($previous));
            $clock->sleep(1);
            self::assertTrue($lifetime->isExpired($previous));
            self::assertFalse($lifetime->isExpired($tuesday));
            self::assertFalse($lifetime->isExpired($tuesday->modify('+7 days')));
        }
    }

    public function testDestinationIsNeverSilentlyNormalized(): void
    {
        self::assertFalse(RadioExportDestination::accepts(''));
        self::assertFalse(RadioExportDestination::accepts(' radio '));
        self::assertFalse(RadioExportDestination::accepts('radio.example'));
        self::assertFalse(RadioExportDestination::accepts(str_repeat('a', 65)));
        self::assertTrue(RadioExportDestination::accepts('Radio_1-dev'));
    }
}
