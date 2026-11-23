<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationPurge;
use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferJournal;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;

final class RadioPreparationPurgeTest extends TestCase
{
    public function testCalendarBoundariesUseParisLocalDays(): void
    {
        foreach (['2026-03-31', '2026-10-27'] as $tuesday) {
            $local = new \DateTimeImmutable($tuesday, new \DateTimeZone('Europe/Paris'));
            self::assertSame($local->format('Y-m-d'), RadioPreparationPurge::cutoff($local));
            self::assertSame($local->modify('-7 days')->format('Y-m-d'), RadioPreparationPurge::cutoff($local->modify('-1 second')));
        }
    }

    public function testPurgeIsReadOnlyInDryRunAndRecoversAfterSqlFailure(): void
    {
        $base = sys_get_temp_dir() . '/radio-purge-' . bin2hex(random_bytes(8));
        mkdir($base, 0700);
        foreach (['root', 'library', 'work', 'trash', 'public'] as $dir) {
            mkdir($base . '/' . $dir, 0700);
        }
        $roots = ['library' => $base . '/library', 'work' => $base . '/work', 'trash' => $base . '/trash', 'public' => $base . '/public'];
        $root = $base . '/root';
        $original = $roots['library'] . '/source.mp3';
        file_put_contents($original, 'source');
        foreach (['2026-10-27', '2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24', '2026-12-01'] as $week) {
            mkdir("$root/$week/A", 0700, true);
            file_put_contents("$root/$week/A/audio.zip", 'zip');
            file_put_contents("$root/$week/A/result.json", '{}');
            file_put_contents("$root/$week/A/Radio.mp3", 'copy');
        }
        mkdir("$root/unknown", 0700);
        symlink($roots['library'], "$root/2026-11-03/A/RadioLink.mp3");
        $held = fopen("$root/2026-11-10/.storage.lock", 'xb');
        chmod("$root/2026-11-10/.storage.lock", 0600);
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));

        $db = $this->createMock(Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $remaining = ['2026-11-10', '2026-11-17'];
        $db->method('fetchFirstColumn')->willReturnCallback(static function () use (&$remaining): array { return $remaining; });
        $fail = true;
        $db->expects(self::exactly(3))->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$remaining, &$fail): int {
                self::assertSame('DELETE FROM radio_transfer_confirmation WHERE week_start = ?', $sql);
                if ($params === ['2026-10-27']) {
                    return 0;
                }
                self::assertSame(['2026-11-17'], $params);
                if ($fail) {
                    throw new \RuntimeException('Synthetic SQL failure');
                }
                $remaining = ['2026-11-10'];
                return 1;
            }
        );
        $purge = new RadioPreparationPurge(new RadioTransferJournal($db, $this->createMock(ClockInterface::class)));
        $now = new \DateTimeImmutable('2026-11-24T00:00:00+01:00');
        try {
            $dry = $purge->run($root, $roots, $now, true);
            self::assertSame('planned', $dry['2026-11-17']['status']);
            self::assertFileExists("$root/2026-11-17/A/audio.zip");
            self::assertFileDoesNotExist("$root/2026-11-17/.storage.lock");
            $first = $purge->run($root, $roots, $now);
            self::assertSame('purged', $first['2026-10-27']['status']);
            self::assertSame(3, $first['2026-10-27']['files']);
            self::assertDirectoryDoesNotExist("$root/2026-10-27/A");
            self::assertSame('busy', $first['2026-11-10']['status']);
            self::assertSame('failed', $first['2026-11-03']['status']);
            self::assertSame('failed', $first['2026-11-17']['status']);
            self::assertDirectoryDoesNotExist("$root/2026-11-17/A");
            self::assertSame('source', file_get_contents($original));
            self::assertDirectoryExists("$root/unknown");
            self::assertDirectoryExists("$root/2026-11-24/A");
            self::assertDirectoryExists("$root/2026-12-01/A");
            $fail = false;
            self::assertSame('purged', $purge->run($root, $roots, $now)['2026-11-17']['status']);
            self::assertArrayNotHasKey('2026-11-17', $purge->run($root, $roots, $now));
            $this->expectException(\RuntimeException::class);
            $purge->run($roots['library'], $roots, $now);
        } finally {
            fclose($held);
            (new Filesystem())->remove($base);
        }
    }
}
