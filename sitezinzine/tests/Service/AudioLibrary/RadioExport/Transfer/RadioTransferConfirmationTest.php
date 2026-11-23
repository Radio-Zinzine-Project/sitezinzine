<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Transfer;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationPublisher;
use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferConfirmation;
use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferJournal;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class RadioTransferConfirmationTest extends TestCase
{
    public function testResolvesThePhysicalOriginAndKeepsTheExactDeclaration(): void
    {
        $file = ['diffusionId' => 5, 'filename' => 'Radio.mp3', 'sha256' => str_repeat('a', 64), 'size' => 13];
        $publisher = $this->createMock(RadioPreparationPublisher::class);
        $publisher->expects(self::exactly(2))->method('read')->willReturnMap([
            ['private', $this->week, 'C', ['version' => 3, 'artifacts' => [], 'differential' => [
                'expected' => [['diffusionId' => 5, 'artifact' => $file, 'origin' => ['attemptId' => 'A', 'artifact' => $file]]],
            ]]],
            ['private', $this->week, 'A', ['version' => 2, 'status' => 'ready', 'artifacts' => [$file]]],
        ]);
        $db = $this->createMock(Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $stored = false;
        $db->method('fetchAssociative')->willReturnCallback(static function () use (&$stored) { return $stored; });
        $db->expects(self::once())->method('insert')->willReturnCallback(function (string $table, array $event) use (&$stored): int {
            self::assertSame('radio_transfer_confirmation', $table);
            self::assertSame(['C', 'A', 5, 'opaque', 12], [
                $event['preparation_id'], $event['origin_attempt_id'], $event['diffusion_id'], $event['destination_key'], $event['actor_id'],
            ]);
            self::assertSame('2026-11-17 11:00:00.123456', $event['confirmed_at']);
            $stored = ['id' => 1] + $event;
            return 1;
        });
        $service = new RadioTransferConfirmation($publisher, new RadioTransferJournal($db, $this->clock()),
            new \App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime($this->clock()));
        $request = str_repeat('1', 32);
        $event = $service->confirm('private', $this->week, 'C', 5, 'opaque', 'Radio.mp3', $file['sha256'], 12, $request);
        self::assertSame($event, $service->confirm('private', $this->week, 'C', 5, 'opaque', 'Radio.mp3', $file['sha256'], 12, $request));
        $this->expectException(\DomainException::class);
        $service->confirm('private', $this->week, 'C', 5, 'another', 'Radio.mp3', $file['sha256'], 12, $request);
    }

    /** @dataProvider invalidFiles */
    public function testRefusesUnavailableOrMismatchedFiles(?array $report, string $name, string $hash): void
    {
        $publisher = $this->createMock(RadioPreparationPublisher::class);
        $publisher->method('read')->willReturn($report);
        $db = $this->createMock(Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $db->method('fetchAssociative')->willReturn(false);
        $db->expects(self::never())->method('insert');
        $service = new RadioTransferConfirmation($publisher, new RadioTransferJournal($db, $this->clock()),
            new \App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime($this->clock()));
        $this->expectException(\DomainException::class);
        $service->confirm('private', $this->week, 'A', 5, 'opaque', $name, $hash, 12, str_repeat('1', 32));
    }

    public static function invalidFiles(): iterable
    {
        $hash = str_repeat('a', 64);
        $file = ['diffusionId' => 5, 'filename' => 'Radio.mp3', 'sha256' => $hash, 'size' => 13];
        $report = ['version' => 2, 'status' => 'ready', 'artifacts' => [$file]];
        yield 'unpublished or corrupt' => [null, 'Radio.mp3', $hash];
        yield 'wrong name' => [$report, 'Other.mp3', $hash];
        yield 'wrong SHA' => [$report, 'Radio.mp3', str_repeat('b', 64)];
        yield 'expected only' => [['version' => 2, 'status' => 'report_only', 'artifacts' => []], 'Radio.mp3', $hash];
        yield 'missing historical origin' => [['version' => 3, 'status' => 'report_only', 'artifacts' => [], 'differential' => [
            'expected' => [['diffusionId' => 5, 'artifact' => $file, 'origin' => ['attemptId' => 'A', 'artifact' => $file]]],
        ]], 'Radio.mp3', $hash];
    }

    public function testDuplicateInsertReloadsTheWinningDeclaration(): void
    {
        $request = ['request_id' => str_repeat('9', 32), 'actor_id' => 12];
        $winner = ['id' => 42, 'actor_id' => '12'] + $request;
        $db = $this->createMock(Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $db->expects(self::once())->method('insert')->willThrowException(new \Doctrine\DBAL\Exception\UniqueConstraintViolationException(
            \Doctrine\DBAL\Driver\PDO\Exception::new(new \PDOException('duplicate')), null
        ));
        $db->expects(self::once())->method('fetchAssociative')->willReturn($winner);
        self::assertSame($winner, (new RadioTransferJournal($db, $this->clock()))->append($request, 'A', 2));
    }

    public function testPastWeeksCannotCreateOrReplayAConfirmation(): void
    {
        $publisher = $this->createMock(RadioPreparationPublisher::class);
        $publisher->expects(self::never())->method('read');
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');
        $db->expects(self::never())->method('insert');
        $lifetime = new \App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime(
            new \Symfony\Component\Clock\MockClock('2026-11-24T00:00:00+01:00')
        );
        $service = new RadioTransferConfirmation($publisher, new RadioTransferJournal($db, $this->clock()), $lifetime);
        $this->expectException(\DomainException::class);
        $service->confirm('private', $this->week, 'A', 5, 'opaque', 'Radio.mp3', str_repeat('a', 64), 12, str_repeat('1', 32));
    }

    private \DateTimeImmutable $week;

    protected function setUp(): void
    {
        $this->week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-11-17T12:00:00.123456+01:00'));
        return $clock;
    }
}
