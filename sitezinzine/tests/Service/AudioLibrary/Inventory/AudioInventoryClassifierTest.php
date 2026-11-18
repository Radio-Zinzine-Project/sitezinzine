<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryClassifier;
use PHPUnit\Framework\TestCase;

final class AudioInventoryClassifierTest extends TestCase
{
    /** @dataProvider observations */
    public function testClassificationUsesCurrentExactAssociations(
        ?bool $present,
        bool $complete,
        array $associations,
        array $history,
        string $classification,
        array $emissionIds,
        array $missingThumbnails,
    ): void {
        $before = $associations;
        $historyBefore = $history;
        $result = (new AudioInventoryClassifier())->classify(
            'emissionsMp3', 'ABC/2026/Émission.mp3', $present, $complete, $associations, $history,
        );

        self::assertSame([
            'storage' => 'emissionsMp3',
            'relative_path' => 'ABC/2026/Émission.mp3',
            'classification' => $classification,
            'emission_ids' => $emissionIds,
            'missing_thumbnail_emission_ids' => $missingThumbnails,
        ], $result);
        self::assertSame($before, $associations);
        self::assertSame($historyBefore, $history);
    }

    public static function observations(): array
    {
        $physical = self::association(17, 'thumbnailMp3');
        $local = self::association(17, 'local_audio_url', true);
        $both = self::association(17, 'local_audio_url');

        return [
            'physical association' => [true, true, [$physical], [], 'associated', [17], []],
            'recognized local URL with missing physical field' => [true, true, [$local], [], 'associated', [17], [17]],
            'both fields of one emission' => [true, true, [$physical, $both], [], 'associated', [17], []],
            'duplicate collected reference is not another emission' => [true, true, [$physical, $physical], [], 'associated', [17], []],
            'present without references' => [true, true, [], [], 'orphan_candidate', [], []],
            'absent but referenced' => [false, true, [$physical], [], 'broken_reference', [17], []],
            'multiple distinct emissions' => [true, true, [$physical, self::association(18, 'thumbnailMp3')], [], 'multiple_associations', [17, 18], []],
            'missing file can also have multiple associations' => [false, true, [$physical, self::association(18, 'thumbnailMp3')], [], 'multiple_associations', [17, 18], []],
            'cleanup failure cannot override current association' => [true, true, [$physical], ['obsolete_cleanup_failed'], 'associated', [17], []],
            'historical check failure cannot override complete current check' => [true, true, [], ['reference_check_failed'], 'orphan_candidate', [], []],
            'historical zero references cannot replace current check' => [true, false, [], ['obsolete_cleanup_failed'], 'indeterminate', [], []],
            'unknown references' => [true, false, [], ['reference_check_failed'], 'indeterminate', [], []],
            'known association despite incomplete search' => [true, false, [$physical], [], 'associated', [17], []],
            'unknown physical presence' => [null, true, [$physical], [], 'indeterminate', [17], []],
            'absent and unreferenced' => [false, true, [], [], 'indeterminate', [], []],
            'case remains exact' => [true, true, [self::association(17, 'thumbnailMp3', false, 'ABC/2026/émission.mp3')], [], 'orphan_candidate', [], []],
            'similar names are distinct' => [true, true, [self::association(17, 'thumbnailMp3', false, 'ABC/2026/Émission-1.mp3'), self::association(18, 'thumbnailMp3', false, 'DEF/2026/Émission.mp3')], [], 'orphan_candidate', [], []],
            'storage is part of identity' => [true, true, [array_replace($physical, ['storage' => 'other'])], [], 'orphan_candidate', [], []],
            'separators remain exact' => [true, true, [self::association(17, 'thumbnailMp3', false, 'ABC\\2026\\Émission.mp3')], [], 'orphan_candidate', [], []],
            'accents remain exact' => [true, true, [self::association(17, 'thumbnailMp3', false, 'ABC/2026/Emission.mp3')], [], 'orphan_candidate', [], []],
        ];
    }

    private static function association(int $id, string $origin, bool $thumbnailMissing = false, string $path = 'ABC/2026/Émission.mp3'): array
    {
        return [
            'storage' => 'emissionsMp3',
            'relative_path' => $path,
            'emission_id' => $id,
            'origin' => $origin,
            'thumbnail_missing' => $thumbnailMissing,
        ];
    }
}
