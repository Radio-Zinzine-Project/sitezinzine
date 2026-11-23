<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary;

use App\Service\{Mp3DiskIo, Mp3TrashDisk, Mp3TrashInspection, Mp3TrashPath};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Mp3TrashInspectionIntegrationTest extends KernelTestCase
{
    public function testReadsSqlReferencesAndReservationsWithTheActualColumnCollations(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('symfony_test', $db->fetchOne('SELECT DATABASE()'));
        self::assertFalse($db->isTransactionActive());
        $marker = 'trash-inspection-' . bin2hex(random_bytes(8));
        $path = $marker . '.mp3';
        $url = 'https://example.test/uploads/emissionsMp3/' . $path;
        $operationId = bin2hex(random_bytes(16));
        $otherOperation = bin2hex(random_bytes(16));
        $origin = random_int(1_600_000_000, 1_900_000_000);
        self::assertFalse($db->fetchOne('SELECT 1 FROM emission WHERE id = ?', [$origin]));
        $witness = null;
        $root = null;
        try {
            $db->insert('emission', [
                'titre' => $marker,
                'keyword' => $marker,
                'datepub' => '2026-11-19 00:00:00',
                'ref' => $marker,
                'duree' => 1,
                'descriptif' => 'synthetic fixture',
                'url' => strtoupper($url),
                'deleted_at' => '2026-11-19 00:00:00',
            ]);
            $witness = (int) $db->lastInsertId();
            $common = [
                'original_thumbnail_mp3' => $path,
                'original_url' => $url,
                'size_bytes' => 9,
                'sha256' => hash('sha256', 'synthetic'),
                'author_id' => 7,
                'blocked' => 0,
                'created_at' => '2026-11-19 00:00:00',
                'updated_at' => '2026-11-19 00:00:00',
                'restoration_attempts' => '[]',
            ];
            $db->insert('mp3_trash_operation', ['id' => $operationId, 'emission_id' => $origin, 'phase' => 'EN_CORBEILLE'] + $common);
            $db->insert('mp3_trash_operation', [
                'id' => $otherOperation,
                'emission_id' => $witness,
                'phase' => 'SUPPRESSION_PREPAREE'
            ] + $common);
            $root = Mp3TestStorage::root();
            $root .= '/inspection-' . $operationId;
            mkdir($root . '/trash', 0700, true);
            mkdir($root . '/library', 0700, true);
            mkdir($root . '/work', 0700, true);
            $private = $root . '/trash/' . $operationId . '.mp3';
            self::assertSame(9, file_put_contents($private, 'synthetic'));
            $library = $root . '/library';
            $paths = new Mp3TrashPath($root . '/trash', $library, $root . '/work', dirname(__DIR__, 3) . '/public');
            $service = new Mp3TrashInspection(
                new Mp3TrashDisk($library, $paths, new Mp3DiskIo()),
                'https://example.test/uploads/emissionsMp3'
            );

            self::assertFileDoesNotExist($root . '/trash/.storage.lock');
            $result = $service->inspect($db, $operationId);

            self::assertSame('completed', $result['diagnostic']['status']);
            self::assertFalse($result['emission']['exists']);
            self::assertSame(1, $result['emission']['reference_count']);
            self::assertSame(1, $result['emission']['reservation_count']);
            self::assertSame($url, $result['journal']['original_url']);
            self::assertSame('EN_CORBEILLE', $db->fetchOne('SELECT phase FROM mp3_trash_operation WHERE id = ?', [$operationId]));
            self::assertFalse($db->isTransactionActive());
            self::assertFileDoesNotExist($root . '/trash/.storage.lock');
            $lockPath = $root . '/trash/.storage.lock';
            self::assertSame(8, file_put_contents($lockPath, 'existing'));
            $lockContents = file_get_contents($lockPath);

            $service->inspect($db, $operationId);

            self::assertFileExists($lockPath);
            self::assertSame($lockContents, file_get_contents($lockPath));
        } finally {
            $db->delete('mp3_trash_operation', ['id' => $otherOperation, 'original_thumbnail_mp3' => $path]);
            $db->delete('mp3_trash_operation', ['id' => $operationId, 'original_thumbnail_mp3' => $path]);
            if ($root !== null && is_dir($root)) {
                foreach (['trash', 'library', 'work'] as $directory) {
                    $path = $root . '/' . $directory;

                    if (is_dir($path)) {
                        foreach (new \FilesystemIterator($path) as $file) {
                            if ($file->isFile() && !$file->isLink()) {
                                unlink($file->getPathname());
                            }
                        }

                        rmdir($path);
                    }
                }

                rmdir($root);
            }
            if ($witness !== null) {
                $db->delete('emission', ['id' => $witness, 'ref' => $marker]);
            }
        }
    }
}
