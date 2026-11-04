<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Mp3IncidentChannelTest extends KernelTestCase
{
    public function testIncidentChannelDoesNotFlushOtherChannelsBufferedContext(): void
    {
        self::bootKernel();
        $logger = self::getContainer()->get('monolog.logger.mp3');
        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame('mp3', $logger->getName());
        self::assertNotSame(self::getContainer()->get('logger'), $logger);
        self::assertNotEmpty($logger->getHandlers());
        $streams = 0;
        foreach ($logger->getHandlers() as $handler) {
            self::assertNotInstanceOf(FingersCrossedHandler::class, $handler);
            $streams += (int) ($handler instanceof StreamHandler);
        }
        self::assertSame(1, $streams);
    }
}
