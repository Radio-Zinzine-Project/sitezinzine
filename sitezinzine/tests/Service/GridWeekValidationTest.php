<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GridWeekValidation;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class GridWeekValidationTest extends TestCase
{
    public function testManualMutationWritesOnlyAfterTheGridOperationSucceeds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('transactional')->willReturnCallback(
            static fn (callable $work) => $work($connection)
        );
        $steps = [];
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$steps): int {
                $steps[] = str_starts_with($sql, 'INSERT') ? 'reserve' : 'record';
                self::assertContains('2026-11-17', $params);
                return 1;
            }
        );

        $result = (new GridWeekValidation($connection))->changeManually(
            new \DateTimeImmutable('2026-11-23', new \DateTimeZone('Europe/Paris')),
            true,
            static function (\DateTimeImmutable $week) use (&$steps): array {
                self::assertSame('2026-11-17', $week->format('Y-m-d'));
                $steps[] = 'publish';
                return ['createdCount' => 1];
            }
        );

        self::assertSame(['reserve', 'publish', 'record'], $steps);
        self::assertSame(['createdCount' => 1], $result);
    }

    public function testFailedPublicationDoesNotRecordValidation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('transactional')->willReturnCallback(
            static fn (callable $work) => $work($connection)
        );
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);
        $this->expectException(\DomainException::class);
        (new GridWeekValidation($connection))->changeManually(
            new \DateTimeImmutable('2026-11-17'),
            true,
            static fn () => throw new \DomainException('Existing grid conflict')
        );
    }

    public function testExportCaptureRejectsAnExistingTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $this->expectException(\LogicException::class);
        (new GridWeekValidation($connection))->captureForExport(new \DateTimeImmutable('2026-11-17'));
    }

    public function testExportableChangesAndManualProofAreIndependent(): void
    {
        $connection = $this->createMock(Connection::class);
        $row = ['validated' => 1, 'manual_revision' => '1', 'diffusion_id' => 12, 'emission_title' => 'Original'];
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params) use (&$row): array {
                self::assertSame(['2026-11-17', '2026-11-17 00:00:00', '2026-11-24 00:00:00', 'published'], $params);
                return [$row];
            }
        );
        $service = new GridWeekValidation($connection);
        $week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
        $original = $service->read($week);
        self::assertTrue($original['validated']);
        self::assertTrue($service->isCurrent($week, $original['revision']));
        $row['emission_title'] = 'Effective automatic modification';
        $changed = $service->read($week);
        self::assertTrue($changed['validated']);
        self::assertFalse($service->isCurrent($week, $original['revision']));
        self::assertTrue($service->isCurrent($week, $changed['revision']));
        $row['validated'] = 0;
        $row['manual_revision'] = '2';
        self::assertFalse($service->read($week)['validated']);
        self::assertFalse($service->isCurrent($week, $changed['revision']));
        $row['validated'] = 1;
        $row['manual_revision'] = '3';
        self::assertFalse($service->isCurrent($week, $changed['revision']));
        $row['validated'] = null;
        $row['manual_revision'] = null;
        self::assertFalse($service->read($week)['validated']);
    }
}
