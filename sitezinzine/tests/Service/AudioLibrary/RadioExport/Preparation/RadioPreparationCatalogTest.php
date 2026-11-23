<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationCatalog;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RadioPreparationCatalogTest extends TestCase
{
    public function testOnlyVerifiedCausalOrderCanIdentifyTheAutomaticReference(): void
    {
        $root = sys_get_temp_dir() . '/radio-catalog-' . bin2hex(random_bytes(8));
        $week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
        mkdir($root . '/2026-11-17', 0700, true);
        try {
            foreach (['A', 'B', 'C', 'broken', 'failed', 'interrupted', 'branch'] as $id) {
                $path = "$root/2026-11-17/$id";
                mkdir($path, 0700);
                if ($id === 'failed') {
                    file_put_contents("$path/failure.json", '{}');
                } elseif ($id === 'interrupted') {
                    file_put_contents("$path/audio.zip", 'isolated ZIP');
                } else {
                    file_put_contents("$path/result.json", '{}');
                }
            }
            $file = ['diffusionId' => 1, 'filename' => 'File.mp3', 'sha256' => str_repeat('a', 64), 'size' => 12];
            $reports = [
                'A' => ['version' => 2, 'attemptId' => 'A', 'status' => 'ready', 'artifacts' => [$file], 'entries' => [], 'sourceIssues' => []],
                'B' => ['version' => 3, 'attemptId' => 'B', 'status' => 'ready', 'artifacts' => [$file], 'entries' => [], 'sourceIssues' => [],
                    'differential' => ['referenceAttemptId' => 'A', 'expected' => []]],
                'C' => ['version' => 3, 'attemptId' => 'C', 'status' => 'report_only', 'artifacts' => [], 'entries' => [], 'sourceIssues' => [],
                    'differential' => ['referenceAttemptId' => 'B', 'expected' => [['origin' => ['attemptId' => 'A', 'artifact' => $file]]]]],
            ];
            $publisher = $this->createMock(RadioPreparationPublisher::class);
            $publisher->method('read')->willReturnCallback(static function ($root, $week, $id) use (&$reports): ?array {
                return $reports[$id] ?? null;
            });
            $catalog = new RadioPreparationCatalog($publisher);
            $listing = $catalog->read($root, $week);
            self::assertSame('C', $listing['reference']['attemptId']);
            $rows = array_column($listing['rows'], null, 'attemptId');
            self::assertSame(['C', 'B', 'A'], array_values(array_intersect(array_keys($rows), ['A', 'B', 'C'])));
            self::assertSame('report_only', $rows['C']['type']);
            self::assertSame('complete', $rows['A']['type']);
            self::assertSame('differential', $rows['B']['type']);
            self::assertSame('failed', $rows['failed']['status']);
            self::assertSame('incomplete', $rows['interrupted']['status']);
            self::assertSame('unusable', $rows['broken']['status']);
            self::assertStringNotContainsString($root, json_encode($listing['rows'], JSON_THROW_ON_ERROR));

            $saved = $reports['B'];
            unset($reports['B']);
            self::assertSame('C', $catalog->read($root, $week)['reference']['attemptId'] ?? null);
            $reports['B'] = $saved;
            chmod("$root/2026-11-17/broken", 0750);
            self::assertSame('unusable', array_column($catalog->read($root, $week)['rows'], 'status', 'attemptId')['broken']);
            chmod("$root/2026-11-17/broken", 0700);

            $reports['branch'] = ['version' => 2, 'attemptId' => 'branch', 'status' => 'ready', 'artifacts' => [$file], 'entries' => [], 'sourceIssues' => []];
            $listing = $catalog->read($root, $week);
            self::assertNull($listing['reference']);
            self::assertTrue($listing['orderUncertain']);
            unset($reports['branch']);
            $reports['A']['artifacts'] = [];
            $listing = $catalog->read($root, $week);
            self::assertSame('B', $listing['reference']['attemptId']);
            self::assertSame('origin_unavailable', array_column($listing['rows'], 'status', 'attemptId')['C']);
            symlink("$root/2026-11-17/A", "$root/2026-11-17/alias");
            self::assertSame('unusable', array_column($catalog->read($root, $week)['rows'], 'status', 'attemptId')['alias']);
            foreach ([$root, "$root/2026-11-17"] as $directory) {
                chmod($directory, 0750);
                try {
                    $catalog->read($root, $week);
                    self::fail('The catalog must reject an unsafe root or week directory.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('private storage', $error->getMessage());
                } finally {
                    chmod($directory, 0700);
                }
            }

        } finally {
            (new Filesystem())->remove($root);
        }
    }
}
