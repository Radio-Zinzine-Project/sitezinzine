<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Tests\Support\Mp3CleanupClosureFixture;
use App\Tests\Support\Mp3TestStorage;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class Mp3CleanupIssueResolutionServiceTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testClosureRevalidatesAndRecordsOnlyAnEstablishedOutcome(string $scenario, string $result): void
    {
        $path = '__test_mp3_cleanup__/' . bin2hex(random_bytes(16)) . '/obsolete.mp3';
        $service = Mp3CleanupClosureFixture::service($path);
        $file = Mp3TestStorage::library() . '/' . $path;
        if ($scenario === 'present') {
            file_put_contents($file, 'synthetic');
        }
        $db = $this->createMock(Connection::class);
        $level = $scenario === 'foreign_transaction' ? 1 : 0;
        $db->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $db->expects($scenario === 'foreign_transaction' ? self::never() : self::once())->method('transactional')->willReturnCallback(
            static function (\Closure $work) use ($db, &$level, $scenario): string {
                $level = 1;
                try {
                    $result = $work($db);
                    if ($scenario === 'commit_uncertain') {
                        throw new \RuntimeException('Lost commit acknowledgement');
                    }
                    return $result;
                } finally {
                    $level = 0;
                }
            },
        );
        $db->method('fetchAssociative')->willReturn([
            'id' => 17, 'status' => $scenario === 'resolved' ? 'resolved' : 'open',
            'storage' => 'emissionsMp3', 'obsolete_relative_path' => $path,
        ]);
        $db->method('fetchOne')->willReturnCallback(static function (string $sql) use ($scenario): string|int|false {
            return match (true) {
                str_contains($sql, 'MAX(id)') => $scenario === 'stale' ? 43 : 42,
                str_contains($sql, 'FROM emission') => match ($scenario) {
                    'reference' => 1,
                    'sql_error' => throw new \RuntimeException('synthetic SQL error'),
                    default => 0,
                },
                str_contains($sql, 'mp3_trash_operation') => $scenario === 'reservation' ? 1 : 0,
                str_contains($sql, 'active_marker') => in_array($scenario, ['pending', 'indeterminate'], true) ? str_repeat('b', 32) : false,
                str_contains($sql, "result_code = 'removed'") => $scenario === 'removed' ? str_repeat('a', 32) : false,
                default => throw new \LogicException('Unexpected query: ' . $sql),
            };
        });
        $db->method('iterateColumn')->willReturnCallback(static function (string $sql) use ($scenario, $path): \Traversable {
            if ($scenario === 'proof_conflict' && str_contains($sql, 'mp3_cleanup_event')) {
                $identity = ['dev' => 1, 'ino' => 2, 'size' => 5, 'mtime' => 10, 'ctime' => 10, 'sha256' => str_repeat('a', 64)];
                yield json_encode(Mp3CleanupHistoricalIdentity::capture('emissionsMp3', $path, null, $identity)->toArray(), JSON_THROW_ON_ERROR);
                $identity['ino']++;
                yield json_encode(Mp3CleanupHistoricalIdentity::capture('emissionsMp3', $path, null, $identity)->toArray(), JSON_THROW_ON_ERROR);
            }
        });
        $writes = in_array($scenario, ['absent', 'removed', 'trace_failure', 'update_failure', 'commit_uncertain'], true);
        $db->expects($writes ? self::once() : self::never())->method('insert')->with('mp3_cleanup_issue_closure', [
            'issue_id' => 17, 'actor_id' => 19, 'closed_at' => '2026-11-20 11:04:56.123456',
            'reason' => 'Verified absence', 'proof_type' => $scenario === 'removed' ? 'confirmed_removal' : 'currently_absent',
            'request_id' => $scenario === 'removed' ? str_repeat('a', 32) : null, 'expected_event_id' => 42,
        ])->willReturnCallback(static fn (): int => $scenario === 'trace_failure'
            ? throw new \RuntimeException('Synthetic journal failure') : 1);
        $updates = $writes && $scenario !== 'trace_failure';
        $db->expects($updates ? self::exactly(2) : ($scenario === 'foreign_transaction' ? self::never() : self::once()))
            ->method('executeStatement')->willReturnCallback(static function (string $sql) use ($scenario): int {
                self::assertTrue(str_starts_with($sql, 'SET TRANSACTION') || str_starts_with($sql, 'UPDATE mp3_cleanup_issue'));
                return str_starts_with($sql, 'UPDATE') && $scenario === 'update_failure' ? 0 : 1;
            });
        self::assertSame($result, $service->resolve($db, 17, ['issue_id' => 17, 'last_event_id' => 42],
            Mp3CleanupClosureFixture::urls(), 19, 'Verified absence', new \DateTimeImmutable('2026-11-20T12:04:56.123456+01:00')));
        if ($scenario === 'present') {
            self::assertSame('synthetic', file_get_contents($file));
        }
    }

    public static function scenarios(): iterable
    {
        foreach ([
            'removed' => 'allowed', 'absent' => 'allowed', 'present' => 'file_present', 'reference' => 'reference_active',
            'reservation' => 'reservation_active', 'sql_error' => 'indeterminate', 'pending' => 'active_attempt',
            'indeterminate' => 'active_attempt', 'stale' => 'stale_view', 'resolved' => 'already_resolved',
            'trace_failure' => 'indeterminate', 'update_failure' => 'indeterminate', 'commit_uncertain' => 'indeterminate',
            'foreign_transaction' => 'indeterminate', 'proof_conflict' => 'conflicting_proofs',
        ] as $scenario => $result) {
            yield $scenario => [$scenario, $result];
        }
    }
}
