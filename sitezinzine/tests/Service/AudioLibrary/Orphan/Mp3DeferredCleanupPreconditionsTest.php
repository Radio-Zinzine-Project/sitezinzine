<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\AudioLibrary\Orphan\Mp3CleanupResolutionDecision;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupPreconditions;
use App\Service\Mp3StorageLock;
use App\Service\Mp3TrashPath;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\TestCase;

final class Mp3DeferredCleanupPreconditionsTest extends TestCase
{
    /** @dataProvider cases */
    public function testDecision(string $case, string $expected): void
    {
        $root = Mp3TestStorage::root();
        $library = Mp3TestStorage::library();
        $lock = new Mp3StorageLock(new Mp3TrashPath($root . '/trash', $library, $root . '/work', dirname(__DIR__, 4) . '/public'), 0);
        $db = $this->createMock(Connection::class);
        $db->expects($case === 'busy' ? self::never() : self::once())->method('transactional')
            ->willReturnCallback(static function (\Closure $read) use ($lock, $db): mixed {
                self::assertTrue($lock->isHeldByCurrentProcess());

                return $read($db);
            });
        foreach (['insert', 'update', 'delete'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $db->expects($case === 'busy' ? self::never() : self::once())->method('executeStatement')
            ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $db->expects($case === 'busy' ? self::never() : self::once())->method('fetchAssociative')
            ->willReturn([
                'id' => '17',
                'status' => $case === 'resolved' ? 'resolved' : 'open',
                'last_event_id' => $case === 'stale' ? '43' : '42',
                'storage' => 'emissionsMp3',
                'obsolete_relative_path' => match ($case) { 'invalid path' => '../old.mp3', 'directory' => 'not-a-file.mp3', default => 'TST/old.mp3' },
            ]);
        $early = in_array($case, ['busy', 'resolved', 'stale', 'invalid path', 'directory'], true);
        $directRef = in_array($case, ['path reference', 'deleted emission'], true);
        $db->expects($early ? self::never() : self::exactly($directRef || $case === 'sql' ? 1 : 2))
            ->method('fetchOne')->willReturnCallback(static function (string $sql, array $params) use ($case, $directRef): mixed {
                self::assertSame(['TST/old.mp3'], $params);
                if (str_contains($sql, 'FROM emission')) {
                    self::assertStringContainsString('WHERE thumbnail_mp3 = ?', $sql);
                    self::assertStringNotContainsString('deleted_at', $sql);
                    if ($case === 'sql') {
                        throw new Exception('Synthetic SQL failure');
                    }

                    return $directRef ? '1' : '0';
                }
                self::assertStringContainsString('active_marker = 1 AND original_thumbnail_mp3 = ?', $sql);

                return match ($case) {'reserved' => '1', 'invalid count' => false, default => '0'};
            });
        $db->expects(in_array($case, ['fresh', 'url reference', 'ambiguous url', 'malformed url result'], true)
            ? self::once() : self::never())->method('iterateColumn')
            ->with('SELECT url FROM emission WHERE url IS NOT NULL AND url <> ?')
            ->willReturn(new \ArrayIterator(match ($case) {
                'url reference' => ['https://legacy.example.test/archive/TST/old.mp3'],
                'ambiguous url' => ['https://radio.example.test/uploads/emissionsMp3/TST/old.mp3?download=1'],
                'malformed url result' => [false],
                default => ['https://external.example.test/old.mp3', 'https://radio.example.test/uploads/emissionsMp3/TST/other.mp3'],
            }));
        $check = fn () => (new Mp3DeferredCleanupPreconditions($lock, new Mp3CleanupResolutionDecision(), $library))
            ->check($db, 17, ['issue_id' => 17, 'last_event_id' => 42], new AudioLocalUrlResolver([
                'https://radio.example.test/uploads/emissionsMp3', 'https://legacy.example.test/archive',
            ]));

        $directory = $library . '/not-a-file.mp3';
        if ($case === 'directory') {
            self::assertTrue(mkdir($directory));
        }
        try {
            $before = scandir($library);
            if ($case === 'busy') {
                $lock->synchronized(static fn () => null);
                $holder = fopen($root . '/trash/.storage.lock', 'rb');
                self::assertIsResource($holder);
                try {
                    self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
                    self::assertSame($expected, $check()->value);
                } finally {
                    fclose($holder);
                }
            } else {
                self::assertSame($expected, $check()->value);
            }
            self::assertSame($before, scandir($library));
        } finally {
            if ($case === 'directory') {
                rmdir($directory);
            }
        }
    }

    public static function cases(): iterable
    {
        yield ['fresh', 'preconditions_verified'];
        yield ['resolved', 'already_resolved'];
        yield ['stale', 'stale_view'];
        yield ['path reference', 'reference_active'];
        yield ['url reference', 'reference_active'];
        yield ['deleted emission', 'reference_active'];
        yield ['reserved', 'reservation_active'];
        yield ['sql', 'indeterminate'];
        yield ['invalid count', 'indeterminate'];
        yield ['ambiguous url', 'indeterminate'];
        yield ['malformed url result', 'indeterminate'];
        yield ['busy', 'lock_unavailable'];
        yield ['invalid path', 'invalid_path'];
        yield ['directory', 'invalid_path'];
    }
}
