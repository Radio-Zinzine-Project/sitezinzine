<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class Mp3CleanupSchemaIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    public function testOnlyOneActiveIssueIsAllowedAndResolutionPermitsANewIssue(): void
    {
        $path = $this->newFixturePath();
        $connection = $this->newConnection();
        $data = ['storage' => 'emissionsMp3', 'obsolete_relative_path' => $path, 'status' => 'open'];
        $connection->insert('mp3_cleanup_issue', $data);
        $firstId = (int) $connection->lastInsertId();
        self::assertGreaterThan(0, $firstId);
        self::assertSame(1, (int) $connection->fetchOne('SELECT active_marker FROM mp3_cleanup_issue WHERE id = ?', [$firstId]));

        self::assertSame(1, (int) $connection->update('mp3_cleanup_issue', ['status' => 'investigating'], ['id' => $firstId]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT active_marker FROM mp3_cleanup_issue WHERE id = ?', [$firstId]));

        try {
            $connection->insert('mp3_cleanup_issue', $data);
            self::fail('MySQL must reject a second active issue for the exact same file.');
        } catch (UniqueConstraintViolationException $error) {
            self::assertStringContainsString('uniq_mp3_cleanup_active', $error->getMessage());
        }
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?',
            ['emissionsMp3', $path],
        ));

        $resolvedAt = '2026-11-15 12:34:56.123456';
        self::assertSame(1, (int) $connection->update(
            'mp3_cleanup_issue', ['status' => 'resolved', 'resolved_at' => $resolvedAt], ['id' => $firstId],
        ));
        self::assertNull($connection->fetchOne('SELECT active_marker FROM mp3_cleanup_issue WHERE id = ?', [$firstId]));
        $connection->insert('mp3_cleanup_issue', $data);
        $secondId = (int) $connection->lastInsertId();
        self::assertNotSame($firstId, $secondId);

        $issues = $connection->fetchAllAssociative(
            'SELECT id, status, active_marker, resolved_at FROM mp3_cleanup_issue
             WHERE storage = ? AND obsolete_relative_path = ? ORDER BY id',
            ['emissionsMp3', $path],
        );
        self::assertCount(2, $issues);
        self::assertSame($firstId, (int) $issues[0]['id']);
        self::assertSame('resolved', $issues[0]['status']);
        self::assertNull($issues[0]['active_marker']);
        self::assertSame($resolvedAt, $issues[0]['resolved_at']);
        self::assertSame($secondId, (int) $issues[1]['id']);
        self::assertSame('open', $issues[1]['status']);
        self::assertSame(1, (int) $issues[1]['active_marker']);
        self::assertNull($issues[1]['resolved_at']);
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testPathsDifferingOnlyByCaseAreDistinctActiveFileIdentities(): void
    {
        $path = $this->newFixturePath();
        $otherPath = substr($path, 0, -strlen('obsolete.mp3')) . 'Obsolete.mp3';
        $this->registerFixture('emissionsMp3', $otherPath);
        $connection = $this->newConnection();
        foreach ([$path, $otherPath] as $fixturePath) {
            $connection->insert('mp3_cleanup_issue', [
                'storage' => 'emissionsMp3', 'obsolete_relative_path' => $fixturePath, 'status' => 'open',
            ]);
        }
        $ids = [];
        foreach ([$path, $otherPath] as $fixturePath) {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, obsolete_relative_path, status, active_marker FROM mp3_cleanup_issue
                 WHERE storage = ? AND obsolete_relative_path = ?',
                ['emissionsMp3', $fixturePath],
            );
            self::assertCount(1, $rows);
            self::assertSame($fixturePath, $rows[0]['obsolete_relative_path']);
            self::assertSame('open', $rows[0]['status']);
            self::assertSame(1, (int) $rows[0]['active_marker']);
            $ids[] = (int) $rows[0]['id'];
        }
        self::assertNotSame($ids[0], $ids[1]);
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }
}
