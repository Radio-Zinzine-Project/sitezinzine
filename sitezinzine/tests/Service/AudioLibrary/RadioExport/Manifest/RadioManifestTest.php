<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Manifest;

use App\Service\AudioLibrary\RadioExport\Manifest\RadioFilename;
use App\Service\AudioLibrary\RadioExport\Manifest\RadioManifestBuilder;
use App\Service\AudioLibrary\RadioExport\Manifest\RadioManifestComparator;
use PHPUnit\Framework\TestCase;

final class RadioManifestTest extends TestCase
{
    /** @dataProvider titles */
    public function testRadioFilename(string $title, string $tail): void
    {
        $result = (new RadioFilename())->format('MUS', new \DateTimeImmutable('2026-11-17T13:30:00Z'), $title);
        self::assertSame(['filename' => "MUSd26-11-17t14'30'00-$tail.mp3", 'issues' => []], $result);
    }

    public static function titles(): iterable
    {
        yield 'both markers' => ["Les musiques du monde n\u{00B0}12 \u{00E9}pisode 3", '12-3-LesMusiquesDuMonde'];
        yield 'number only' => ['Les musiques du monde n 12', '12-LesMusiquesDuMonde'];
        yield 'part only' => ['Les musiques du monde part 4', '4-LesMusiquesDuMonde'];
        yield 'partie and punctuation' => ["\u{00C9}t\u{00E9}, d'hier partie 2", '2-EteDHier'];
        yield 'unaccented episode' => ['Concert episode 3', '3-Concert'];
        yield 'markers without numbers' => ['Les nouvelles musiques n partie episode', 'LesNouvellesMusiquesNPartieEpisode'];
        yield 'fallback' => ['n 9 !!!', '9-Emission'];
        foreach (range(1, 4) as $part) {
            yield "fraction $part/4" => ["Les musiques du monde $part/4", "$part-LesMusiquesDuMonde"];
        }
        yield 'explicit fractional marker' => ['Concert partie 2/4', '2-Concert'];
        yield 'agreeing markers' => ['Concert partie 2 2/4', '2-Concert'];
        yield 'fraction and number' => ['Concert n 12 2/4', '12-2-Concert'];
        yield 'calendar date is not a part' => ['Concert 1/4/2026', 'Concert142026'];
        yield 'title limit' => [str_repeat('a', 55), 'A' . str_repeat('a', 49)];
    }

    public function testAmbiguousMarkersDoNotPickAnArbitraryNumber(): void
    {
        $result = (new RadioFilename())->format('MUS', new \DateTimeImmutable('2026-11-17T13:30:00Z'), 'Concert n 1 n 2');
        self::assertSame(['filename' => null, 'issues' => ['ambiguous_title_markers']], $result);
    }

    public function testContradictoryFractionIsAmbiguous(): void
    {
        $result = (new RadioFilename())->format('MUS', new \DateTimeImmutable('2026-11-17T14:30:00+01:00'), 'Concert partie 2 3/4');

        self::assertSame(['filename' => null, 'issues' => ['ambiguous_title_markers']], $result);
    }

    public function testRadioTransliterationIsIndependentOfLcCtype(): void
    {
        $locale = setlocale(LC_CTYPE, '0');
        self::assertNotFalse($locale);
        try {
            foreach ([['C'], ['C.UTF-8', 'C.utf8', '.UTF-8']] as $locales) {
                $active = setlocale(LC_CTYPE, ...$locales);
                self::assertNotFalse($active);
                $result = (new RadioFilename())->format('MUS', new \DateTimeImmutable('2026-11-17T14:30:00+01:00'),
                    "\u{00C9}t\u{00E9}, \u{0153}uvre & \u{00E6}ther \u{2603}");
                self::assertSame("MUSd26-11-17t14'30'00-EteOeuvreAether.mp3", $result['filename']);
                self::assertSame($active, setlocale(LC_CTYPE, '0'));
            }
        } finally {
            setlocale(LC_CTYPE, $locale);
        }
    }

    public function testFullFilenameByteLimitIsPropagatedToManifest(): void
    {
        $row = $this->row(1, '2026-10-25T04:00:00+01:00');
        $builder = new RadioManifestBuilder(new RadioFilename());
        // Prefix (21) + two separators (2) + title (1) + extension (4).
        $row['emissionTitle'] = 'n ' . str_repeat('9', 227) . ' A';
        $entry = $builder->build($this->selection([$row]))['entries'][0];
        self::assertSame(255, strlen($entry['filename']));
        self::assertSame([], $entry['issues']);

        $row['emissionTitle'] = 'n ' . str_repeat('9', 228) . ' A';
        $entry = $builder->build($this->selection([$row]))['entries'][0];
        self::assertNull($entry['filename']);
        self::assertSame(['filename_too_long'], $entry['issues']);
        self::assertSame($row['emissionTitle'], $entry['emissionTitle']);
    }

    public function testNormalRepeatedBroadcastsHaveDistinctNames(): void
    {
        $builder = new RadioManifestBuilder(new RadioFilename());
        $entries = $builder->build($this->selection([
            $this->row(1, '2026-10-25T10:00:00+01:00'), $this->row(2, '2026-10-25T11:00:00+01:00'),
        ]))['entries'];

        self::assertSame(['source.mp3', 'source.mp3'], array_column($entries, 'mp3Path'));
        self::assertSame(["MUSd26-10-25t10'00'00-Concert.mp3", "MUSd26-10-25t11'00'00-Concert.mp3"], array_column($entries, 'filename'));
        self::assertSame([[], []], array_column($entries, 'issues'));
    }

    public function testComparatorRejectsDifferentWeeks(): void
    {
        $builder = new RadioManifestBuilder(new RadioFilename());
        $manifest = $builder->build($this->selection([]));
        $this->expectException(\InvalidArgumentException::class);
        (new RadioManifestComparator())->compare($manifest, array_replace($manifest, [
            'weekStart' => new \DateTimeImmutable('2026-10-27T00:00:00+01:00'),
            'weekEnd' => new \DateTimeImmutable('2026-11-03T00:00:00+01:00'),
        ]));
    }

    public function testBuilderKeepsOccurrencesAndReportsAutumnCollision(): void
    {
        $summer = $this->row(2, '2026-10-25T02:30:00+02:00');
        $winter = $this->row(1, '2026-10-25T02:30:00+01:00');
        $external = array_replace($this->row(3, '2026-10-25T04:00:00+01:00'), [
            'audioKind' => 'external_url', 'mp3Path' => null, 'url' => 'https://outside.example.test/radio.mp3', 'live' => true,
        ]);
        $ambiguous = array_replace($this->row(4, '2026-10-25T05:00:00+01:00'), [
            'audioKind' => 'uncertain_url', 'mp3Path' => null, 'url' => '/radio.mp3',
        ]);
        $none = array_replace($this->row(5, '2026-10-25T06:00:00+01:00'), [
            'audioKind' => 'none', 'mp3Path' => null, 'categoryCode' => null, 'deleted' => true,
        ]);
        $builder = new RadioManifestBuilder(new RadioFilename());
        $manifest = $builder->build($this->selection([$none, $winter, $external, $ambiguous, $summer]));
        self::assertEquals($manifest, $builder->build($this->selection([$summer, $external, $none, $winter, $ambiguous])));
        $entries = $manifest['entries'];
        self::assertSame([2, 1, 3, 4, 5], array_column($entries, 'diffusionId'));
        self::assertSame("MUSd26-10-25t02'30'00-Concert.mp3", $entries[0]['filename']);
        self::assertSame($entries[0]['filename'], $entries[1]['filename']);
        self::assertSame(['filename_collision'], $entries[0]['issues']);
        self::assertSame(['filename_collision'], $entries[1]['issues']);
        self::assertSame(['external_url', 'live'], $entries[2]['issues']);
        self::assertSame(['uncertain_url'], $entries[3]['issues']);
        self::assertSame(['invalid_category_code', 'no_audio_reference', 'deleted'], $entries[4]['issues']);
        self::assertNull($entries[4]['filename']);
    }

    public function testComparatorKeepsIdentityAndReportsIndependentChanges(): void
    {
        $unchanged = $this->row(1, '2026-10-25T01:00:00+02:00');
        $before = $this->row(2, '2026-10-25T02:30:00+02:00');
        $after = array_replace($this->row(2, '2026-10-25T02:30:00+01:00'), ['mp3Path' => 'new.mp3']);
        $rename = $this->row(3, '2026-10-25T03:00:00+01:00');
        $recreated = $this->row(4, '2026-10-25T04:00:00+01:00');
        $builder = new RadioManifestBuilder(new RadioFilename());
        $diff = (new RadioManifestComparator())->compare(
            $builder->build($this->selection([$unchanged, $before, $rename, $recreated])),
            $builder->build($this->selection([$after, $unchanged, array_replace($rename, ['emissionTitle' => 'Other']),
                array_replace($recreated, ['diffusionId' => 5])])),
        );
        self::assertSame([
            'added' => [5], 'removed' => [4], 'renamed' => [3], 'audioChanged' => [2],
            'scheduleChanged' => [2], 'emissionChanged' => [3], 'statusChanged' => [], 'unchanged' => [1],
        ], $diff);
    }

    private function selection(array $rows): array
    {
        return ['weekStart' => new \DateTimeImmutable('2026-10-20T00:00:00+02:00'),
            'weekEnd' => new \DateTimeImmutable('2026-10-27T00:00:00+01:00'), 'occurrences' => $rows];
    }

    private function row(int $id, string $date): array
    {
        return ['diffusionId' => $id, 'emissionId' => 7, 'emissionTitle' => 'Concert', 'categoryCode' => 'MUS',
            'startsAt' => new \DateTimeImmutable($date), 'audioKind' => 'local_reference',
            'mp3Path' => 'source.mp3', 'url' => null, 'localUrlPath' => null, 'live' => false, 'deleted' => false];
    }
}
