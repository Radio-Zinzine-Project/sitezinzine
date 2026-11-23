<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AudioLibrary\Mp3TrashHistory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class Mp3TrashHistoryTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    public function testUnknownOperationDoesNotReadEvents(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')
            ->with('SELECT id FROM mp3_trash_operation WHERE id = ?', [self::ID])->willReturn(false);
        $db->expects(self::never())->method('fetchAllAssociative');
        self::assertNull((new Mp3TrashHistory($db))->read(self::ID, null, null));
    }

    public function testPagingUsesTheOperationAndAnExclusiveUnsignedCursor(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willReturn(self::ID);
        $db->expects(self::never())->method('beginTransaction');
        $db->expects(self::never())->method('executeStatement');
        $db->expects(self::once())->method('fetchAllAssociative')->with(self::callback(
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'SELECT')
                && str_contains($sql, 'LEFT JOIN `user` u ON u.id = a.actor_id')
                && str_contains($sql, 'a.operation_id = :operation')
                && str_contains($sql, 'a.id < CAST(:cursor AS UNSIGNED)')
                && str_contains($sql, 'ORDER BY a.id DESC LIMIT :limit'),
        ), ['operation' => self::ID, 'cursor' => '18446744073709551615', 'limit' => 26])
            ->willReturn(array_map(static fn (int $id): array => [
                'id' => $id, 'recorded_at' => '2026-11-19 14:00:00.000000',
            ], range(30, 5)));
        $page = (new Mp3TrashHistory($db))->read(self::ID, '18446744073709551615', null);
        self::assertCount(25, $page['rows']);
        self::assertSame([30, 29, 28], array_column(array_slice($page['rows'], 0, 3), 'id'));
        self::assertSame('30', $page['newer']);
        self::assertSame('6', $page['older']);
        self::assertSame('UTC', $page['rows'][0]['recorded_at']->getTimezone()->getName());
    }

    public function testNewerPageIsPresentedInTheSameDescendingOrder(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(self::ID);
        $db->expects(self::once())->method('fetchAllAssociative')->with(self::callback(
            static fn (string $sql): bool => str_contains($sql, 'a.id > CAST(:cursor AS UNSIGNED)')
                && str_contains($sql, 'ORDER BY a.id ASC LIMIT :limit'),
        ), ['operation' => self::ID, 'cursor' => '1', 'limit' => 26])
            ->willReturn([
                ['id' => 2, 'recorded_at' => '2026-11-19 14:00:00.000000'],
                ['id' => 3, 'recorded_at' => '2026-11-19 14:00:00.000000'],
            ]);
        $page = (new Mp3TrashHistory($db))->read(self::ID, null, '1');
        self::assertSame([3, 2], array_column($page['rows'], 'id'));
        self::assertNull($page['newer']);
        self::assertSame('2', $page['older']);
    }
}
