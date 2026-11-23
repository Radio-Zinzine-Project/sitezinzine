<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Mp3TrashPhase as Phase;
use App\Service\{Mp3DiskIo, Mp3TrashDisk, Mp3TrashInspection, Mp3TrashPath};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class Mp3TrashInspectionTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const ATTEMPT = 'abcdef0123456789abcdef0123456789';
    private const PATH = 'TST/source.mp3';
    private const URL = 'https://example.test/uploads/emissionsMp3/TST/source.mp3';
    private const CONTENT = 'synthetic inspection witness';
    private string $root;
    private string $public;
    private string $private;

    protected function setUp(): void
    {
        $this->root = Mp3TestStorage::root() . '/inspect-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir(array_map(fn (string $dir) => $this->root . '/' . $dir,
            ['library/TST', 'work', 'trash', 'public']), 0700);
        $this->public = $this->root . '/library/' . self::PATH;
        $this->private = $this->root . '/trash/' . self::ID . '.mp3';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** @dataProvider observations */
    public function testDiagnosesTheConfirmedPhaseWithoutMutations(
        Phase $phase, string $audio, string $files, string $diagnostic, array $changes = []
    ): void {
        $restoring = in_array($phase, [Phase::RESTAURATION_PREPAREE, Phase::RETRAIT_PRIVE_EN_ATTENTE, Phase::RESTAUREE], true);
        $row = array_replace([
            'id' => self::ID, 'emission_id' => 42, 'phase' => $phase->value, 'blocked' => 0,
            'last_diagnostic_code' => null, 'last_diagnostic_at' => null,
            'original_thumbnail_mp3' => self::PATH, 'original_url' => self::URL,
            'size_bytes' => strlen(self::CONTENT), 'sha256' => hash('sha256', self::CONTENT),
            'last_attempt_id' => $restoring ? self::ATTEMPT : null,
            'restoration_attempts' => $restoring ? json_encode([
                self::ATTEMPT => ['outcome' => $phase === Phase::RESTAUREE ? 'restored' : 'pending', 'actor_id' => 7],
            ], JSON_THROW_ON_ERROR) : '[]',
            'current_id' => 42, 'deleted_at' => null,
            'current_path' => $audio === 'original' ? self::PATH : ($audio === 'other' ? 'other.mp3' : null),
            'current_url' => $audio === 'original' ? self::URL : null, 'reference_count' => 0, 'reservation_count' => 0,
        ], $changes);
        if (in_array($files, ['public', 'pair', 'collision', 'altered', 'unreadable file'], true)) {
            file_put_contents($this->public, $files === 'altered' ? 'altered' : self::CONTENT);
        }
        if ($files === 'pair') {
            self::assertTrue(link($this->public, $this->private));
        } elseif (in_array($files, ['private', 'collision'], true)) {
            file_put_contents($this->private, self::CONTENT);
        } elseif ($files === 'symbolic') {
            if (DIRECTORY_SEPARATOR !== '/') { self::markTestSkipped('Requires symbolic links.'); }
            self::assertTrue(symlink(dirname(__DIR__, 2) . '/composer.json', $this->public));
        } elseif ($files === 'unreadable parent') {
            $row['original_thumbnail_mp3'] = 'missing-directory/source.mp3';
            $row['current_path'] = $row['original_thumbnail_mp3'];
        } elseif ($files === 'unreadable file') {
            if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
                self::markTestSkipped('An unprivileged account is required to check read permissions.');
            }
            self::assertTrue(chmod($this->public, 0000));
        }

        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($row): array {
                self::assertStringStartsWith('SELECT', ltrim($sql));
                self::assertStringNotContainsString('FOR UPDATE', $sql);
                self::assertContains(self::ID, $params);
                return $row;
            }
        );
        $db->expects(self::never())->method('beginTransaction');
        $db->expects(self::never())->method('executeStatement');
        $io = $this->createMock(Mp3DiskIo::class);
        $io->expects(self::never())->method('link');
        $io->expects(self::never())->method('unlink');
        $library = $this->root . '/library';
        $paths = new Mp3TrashPath($this->root . '/trash', $library, $this->root . '/work', $this->root . '/public');
        $before = scandir($this->root . '/trash');
        $inspection = new Mp3TrashInspection(new Mp3TrashDisk($library, $paths, $io), 'https://example.test/uploads/emissionsMp3');
        $result = $inspection->inspect($db, self::ID);

        self::assertSame($diagnostic, $result['diagnostic']['status']);
        self::assertSame($phase->value, $result['journal']['phase']);
        self::assertSame((bool) $row['blocked'] ? $row['last_diagnostic_at'] : null, $result['journal']['block_version']);
        self::assertSame($before, scandir($this->root . '/trash'));
        self::assertFileDoesNotExist($this->root . '/trash/.storage.lock');
        if ($files === 'pair' || $files === 'collision') {
            self::assertSame($files === 'pair', $result['disk']['same_inode']);
            self::assertSame($files === 'collision', $result['disk']['collision']);
        }
        if ($files === 'symbolic') {
            self::assertSame('symlink', $result['disk']['public']['type']);
            self::assertNull($result['disk']['public']['sha256']);
        }
        if ($files === 'altered') {
            self::assertSame(hash('sha256', 'altered'), $result['disk']['public']['sha256']);
            self::assertFalse($result['disk']['public']['matches_journal']);
        }
        if ($files === 'unreadable file') {
            self::assertSame('regular', $result['disk']['public']['type']);
            self::assertSame('content_unreadable_or_changed', $result['disk']['public']['error']);
            self::assertNull($result['disk']['public']['sha256']);
        }
    }

    public static function observations(): iterable
    {
        yield 'prepared deletion' => [Phase::SUPPRESSION_PREPAREE, 'original', 'public', 'intermediate_coherent'];
        yield 'prepared deletion with private link' => [Phase::SUPPRESSION_PREPAREE, 'original', 'pair', 'potentially_resumable'];
        yield 'public already removed' => [Phase::RETRAIT_PUBLIC_EN_ATTENTE, 'empty', 'private', 'potentially_resumable'];
        yield 'in trash' => [Phase::EN_CORBEILLE, 'other', 'private', 'completed'];
        yield 'prepared restoration' => [Phase::RESTAURATION_PREPAREE, 'empty', 'private', 'intermediate_coherent'];
        yield 'private already removed' => [Phase::RETRAIT_PRIVE_EN_ATTENTE, 'original', 'public', 'potentially_resumable'];
        yield 'restored' => [Phase::RESTAUREE, 'original', 'public', 'completed'];
        yield 'cancelled' => [Phase::ANNULEE, 'original', 'none', 'completed'];
        yield 'blocked' => [Phase::RESTAURATION_PREPAREE, 'empty', 'pair', 'conflict',
            ['blocked' => 1, 'last_diagnostic_code' => 'file_missing', 'last_diagnostic_at' => '2026-11-19 00:00:00.123456']];
        yield 'missing emission' => [Phase::RESTAURATION_PREPAREE, 'empty', 'private', 'conflict', ['current_id' => null]];
        yield 'deleted emission' => [Phase::RESTAURATION_PREPAREE, 'empty', 'private', 'conflict', ['deleted_at' => '2026-11-19 00:00:00']];
        yield 'new audio association' => [Phase::RESTAURATION_PREPAREE, 'other', 'private', 'conflict'];
        yield 'referenced by another emission' => [Phase::SUPPRESSION_PREPAREE, 'original', 'public', 'conflict', ['reference_count' => 1]];
        yield 'reserved by another operation' => [Phase::RESTAURATION_PREPAREE, 'empty', 'private', 'conflict', ['reservation_count' => 1]];
        yield 'distinct inode with equal bytes' => [Phase::RESTAURATION_PREPAREE, 'empty', 'collision', 'conflict'];
        yield 'no safe name found' => [Phase::RETRAIT_PUBLIC_EN_ATTENTE, 'empty', 'none', 'indeterminate'];
        yield 'changed content' => [Phase::SUPPRESSION_PREPAREE, 'original', 'altered', 'conflict'];
        yield 'symbolic name' => [Phase::SUPPRESSION_PREPAREE, 'original', 'symbolic', 'conflict'];
        yield 'unconfirmed absence' => [Phase::SUPPRESSION_PREPAREE, 'original', 'unreadable parent', 'indeterminate'];
        yield 'unreadable file' => [Phase::SUPPRESSION_PREPAREE, 'original', 'unreadable file', 'indeterminate'];
        yield 'unknown attempt' => [Phase::RESTAURATION_PREPAREE, 'empty', 'private', 'indeterminate', ['last_attempt_id' => null]];
    }
}
