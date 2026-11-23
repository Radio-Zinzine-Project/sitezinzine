<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Selection;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Repository\DiffusionRepository;
use App\Service\AudioLibrary\RadioExport\Selection\WeeklyAudioSelection;
use PHPUnit\Framework\TestCase;

final class WeeklyAudioSelectionTest extends TestCase
{
    /** @dataProvider weeks */
    public function testReadsOnlyTheExplicitRadioWeek(string $date, string $start, string $end): void
    {
        $repository = $this->createMock(DiffusionRepository::class);
        $repository->expects(self::once())->method('findPublishedByWeek')->willReturnCallback(
            static function (\DateTimeImmutable $from, \DateTimeImmutable $until) use ($start, $end): array {
                self::assertSame($start, $from->format(DATE_ATOM));
                self::assertSame($end, $until->format(DATE_ATOM));

                return [];
            },
        );

        $selection = $this->selection($repository)->select(new \DateTimeImmutable($date));
        self::assertSame($start, $selection['weekStart']->format(DATE_ATOM));
        self::assertSame($end, $selection['weekEnd']->format(DATE_ATOM));
        self::assertSame([], $selection['occurrences']);
    }

    public static function weeks(): iterable
    {
        yield 'last second of Monday' => ['2026-11-23T23:59:59+01:00', '2026-11-17T00:00:00+01:00', '2026-11-24T00:00:00+01:00'];
        yield 'next Tuesday at midnight' => ['2026-11-23T23:00:00Z', '2026-11-24T00:00:00+01:00', '2026-12-01T00:00:00+01:00'];
        yield 'spring clock change' => ['2026-03-29T12:00:00+02:00', '2026-03-24T00:00:00+01:00', '2026-03-31T00:00:00+02:00'];
        yield 'autumn clock change' => ['2026-10-25T12:00:00+01:00', '2026-10-20T00:00:00+02:00', '2026-10-27T00:00:00+01:00'];
    }

    public function testPreservesMultipleOccurrencesAndTheirEffectiveTimes(): void
    {
        $emission = (new Emission())->setTitre('Synthetic')->setThumbnailMp3('TST/absent-on-disk.mp3')
            ->setCategorie((new Categories())->setSlug('MUS'));
        (new \ReflectionProperty(Emission::class, 'id'))->setValue($emission, 12);
        $first = $this->diffusion($emission, 21, '2026-11-24T00:00:00+01:00');
        $last = $this->diffusion($emission, 22, '2026-11-30T23:59:59+01:00');
        $repository = $this->createMock(DiffusionRepository::class);
        $repository->expects(self::once())->method('findPublishedByWeek')->willReturn([$first, $last]);

        $rows = $this->selection($repository)->select(new \DateTimeImmutable('2026-11-25T12:00:00+01:00'))['occurrences'];
        self::assertSame([21, 22], array_column($rows, 'diffusionId'));
        self::assertSame([12, 12], array_column($rows, 'emissionId'));
        self::assertSame(['Synthetic', 'Synthetic'], array_column($rows, 'emissionTitle'));
        self::assertSame(['MUS', 'MUS'], array_column($rows, 'categoryCode'));
        self::assertSame(['2026-11-24T00:00:00+01:00', '2026-11-30T23:59:59+01:00'],
            array_map(static fn (array $row): string => $row['startsAt']->format(DATE_ATOM), $rows));
        self::assertSame(['local_reference', 'local_reference'], array_column($rows, 'audioKind'));
        self::assertSame(['TST/absent-on-disk.mp3', 'TST/absent-on-disk.mp3'], array_column($rows, 'mp3Path'));
    }

    /** @dataProvider audio */
    public function testClassifiesAudioWithoutExcludingLiveOrDeletedEmissions(?string $path, ?string $url,
        bool $live, bool $deleted, string $kind, ?string $urlPath): void
    {
        $emission = (new Emission())->setTitre('Synthetic')->setThumbnailMp3($path)->setUrl($url)->setIsLive($live);
        if ($deleted) {
            $emission->softDelete();
        }
        $repository = $this->createMock(DiffusionRepository::class);
        $repository->method('findPublishedByWeek')->willReturn([$this->diffusion($emission, 21, '2026-11-25T12:00:00+01:00')]);

        $rows = $this->selection($repository)->select(new \DateTimeImmutable('2026-11-25T12:00:00+01:00'))['occurrences'];
        self::assertCount(1, $rows);
        self::assertSame([$path, $url === '' ? null : $url, $live, $deleted, $kind, $urlPath], [
            $rows[0]['mp3Path'], $rows[0]['url'], $rows[0]['live'], $rows[0]['deleted'], $rows[0]['audioKind'], $rows[0]['localUrlPath'],
        ]);
    }

    public static function audio(): iterable
    {
        yield 'local filename and URL' => ['show.mp3', 'https://radio.example.test/mp3/show.mp3', false, false, 'local_reference', 'show.mp3'];
        yield 'local historical URL only' => [null, 'https://legacy.example.test/audio/show.mp3', false, false, 'local_reference', 'show.mp3'];
        yield 'external URL only' => [null, 'https://outside.example.test/show.mp3', false, false, 'external_url', null];
        yield 'no audio' => [null, null, false, false, 'none', null];
        yield 'live without audio' => [null, null, true, false, 'none', null];
        yield 'deleted with audio' => ['show.mp3', null, false, true, 'local_reference', null];
        yield 'ambiguous URL' => [null, '/mp3/show.mp3', false, false, 'uncertain_url', null];
        yield 'empty references' => ['', '', false, false, 'none', null];
    }

    private function selection(DiffusionRepository $repository): WeeklyAudioSelection
    {
        return new WeeklyAudioSelection($repository, 'https://radio.example.test/mp3', '["https://legacy.example.test/audio"]');
    }

    private function diffusion(Emission $emission, int $id, string $date): Diffusion
    {
        $diffusion = (new Diffusion())->setEmission($emission)->setHoraireDiffusion(new \DateTime($date));
        (new \ReflectionProperty(Diffusion::class, 'id'))->setValue($diffusion, $id);

        return $diffusion;
    }
}
