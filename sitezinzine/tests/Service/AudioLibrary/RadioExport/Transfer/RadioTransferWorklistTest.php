<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Transfer;

use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferWorklist;
use PHPUnit\Framework\TestCase;

final class RadioTransferWorklistTest extends TestCase
{
    public function testPartialTransferAcrossThreePreparations(): void
    {
        $rows = $confirmations = [];
        for ($id = 1; $id <= 20; ++$id) {
            $file = self::file($id);
            if ($id <= 12) {
                $confirmations[$file['filename']] = self::confirmation($file);
            }
            if ($id === 12) {
                $removed = self::row($file, 'A');
                continue;
            }
            $rows[] = $id <= 3 ? self::row(self::file($id, 'b'), 'B') : self::row($file, 'A');
        }
        $rows[] = self::row(self::file(21), null);
        $snapshot = self::snapshot(array_reverse($rows), [$removed]);
        $result = RadioTransferWorklist::evaluate($snapshot, 'alpha', $confirmations);
        self::assertSame('current', $result['status']);
        $lines = array_column($result['lines'], null, 'diffusionId');
        self::assertSame([...range(1, 11), ...range(13, 21)], array_keys($lines));
        self::assertSame(['new_version', 'transferred', 'to_transfer', 'to_transfer'], array_map(
            static fn (int $id): string => $lines[$id]['transferState'], [1, 4, 13, 21]
        ));
        self::assertSame(['B', 'A', 'C'], array_map(static fn (int $id): string => $lines[$id]['zip']['attemptId'], [1, 13, 21]));
        self::assertSame('audio.zip', $lines[13]['zip']['path']);
        self::assertSame(12, $result['removed'][0]['diffusionId']);
        self::assertSame($confirmations['File12.mp3'], $result['removed'][0]['confirmation']);
        self::assertSame($result, RadioTransferWorklist::evaluate(self::snapshot($rows, [$removed]), 'alpha', array_reverse($confirmations)));
    }

    public function testOnlyTheLatestExactNameAndDestinationCanConfirmTheFile(): void
    {
        $file = self::file(1);
        $snapshot = self::snapshot([self::row($file, null), self::row(self::file(2), null)]);
        $state = static fn (array $events, string $destination = 'alpha'): array =>
            array_column(RadioTransferWorklist::evaluate($snapshot, $destination, $events)['lines'], 'transferState');
        self::assertSame(['to_transfer', 'to_transfer'], $state([]));
        self::assertSame(['transferred', 'transferred'], $state([self::confirmation($file), self::confirmation(self::file(2))]));
        self::assertSame(['to_transfer', 'to_transfer'], $state([self::confirmation($file)], 'beta'));
        self::assertSame(['transferred', 'to_transfer'], $state([self::confirmation($file)]));
        self::assertSame(['new_version', 'to_transfer'], $state([self::confirmation($file), self::confirmation(self::file(1, 'b'), 2)]));
        self::assertSame(['transferred', 'to_transfer'], $state([self::confirmation($file, 3), self::confirmation(self::file(1, 'b'), 2)]));
        $renamed = $file;
        $renamed['filename'] = 'NewName.mp3';
        self::assertSame('to_transfer', RadioTransferWorklist::evaluate(self::snapshot([self::row($renamed, null)]), 'alpha',
            [self::confirmation($file)])['lines'][0]['transferState']);
    }

    public function testHistoryAndUnavailableProvenanceAreExplicit(): void
    {
        $file = self::file(1);
        $missing = ['diffusionId' => 2, 'filename' => 'Missing.mp3', 'admission' => 'candidate',
            'artifact' => null, 'issue' => 'source_absent', 'origin' => null, 'changes' => ['unavailable']];
        $snapshot = self::snapshot([self::row($file, 'A'), $missing]);
        $snapshot['unavailableOrigins'] = [1];
        $snapshot['current'] = false;
        $stale = RadioTransferWorklist::evaluate($snapshot, 'alpha', []);
        self::assertSame('preparation_required', $stale['status']);
        self::assertSame([], $stale['lines']);
        $history = RadioTransferWorklist::evaluate($snapshot, 'alpha', [self::confirmation($file)], true);
        self::assertSame('historical', $history['status']);
        self::assertSame(['unavailable', 'unavailable'], array_column($history['lines'], 'transferState'));
        self::assertSame([null, null], array_column($history['lines'], 'zip'));
        self::assertSame($file['sha256'], $history['lines'][0]['confirmation']['sha256']);
        self::assertSame(['origin_unavailable'], $history['lines'][0]['issues']);
        self::assertSame(['source_absent'], $history['lines'][1]['issues']);
    }

    private static function file(int $id, string $hash = 'a'): array
    {
        return ['diffusionId' => $id, 'filename' => "File$id.mp3", 'size' => 15, 'sha256' => str_repeat($hash, 64)];
    }

    private static function row(array $file, ?string $origin): array
    {
        return ['diffusionId' => $file['diffusionId'], 'filename' => $file['filename'], 'admission' => 'candidate',
            'artifact' => $file, 'issue' => null, 'changes' => [$origin === null ? 'added' : 'unchanged'],
            'origin' => $origin === null ? null : ['attemptId' => $origin, 'artifact' => $file]];
    }

    private static function confirmation(array $file, int $id = 1): array
    {
        return ['id' => $id, 'destination_key' => 'alpha', 'filename' => $file['filename'], 'sha256' => $file['sha256']];
    }

    private static function snapshot(array $rows, array $removed = []): array
    {
        return ['current' => true, 'unavailableOrigins' => [], 'report' => [
            'version' => 3, 'attemptId' => 'C', 'week' => '2026-11-17', 'revision' => str_repeat('c', 64),
            'entries' => array_map(static fn (array $row): array => ['diffusionId' => $row['diffusionId'],
                'filename' => $row['filename'], 'admission' => 'candidate', 'issues' => []], $rows),
            'sourceIssues' => [], 'archive' => ['path' => 'audio.zip'],
            'artifacts' => array_values(array_column(array_filter($rows, static fn (array $r): bool => $r['origin'] === null && $r['artifact'] !== null), 'artifact')),
            'differential' => ['expected' => $rows, 'removed' => $removed],
        ]];
    }
}
