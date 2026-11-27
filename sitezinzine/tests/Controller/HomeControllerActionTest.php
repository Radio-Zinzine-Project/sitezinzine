<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HomeController;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Service\GridOccurrenceProjectionService;
use App\Service\InfosSoirRssService;
use App\Service\ProgrammationGridBuilder;
use App\Service\PublicScheduleBuilder;
use App\Tests\Support\ControllerActionTestCase;
use Psr\Clock\ClockInterface;

/**
 * Complète les tests HTTP existants avec des données déterministes.
 *
 * La programmation publique de l'accueil et de la page /programme
 * repose désormais sur des services finaux :
 *
 * - PublicDailyScheduleBuilder
 * - PublicScheduleBuilder
 *
 * Leur logique métier est couverte par leurs tests dédiés.
 * Les parcours HTTP publics sont couverts par les tests fonctionnels.
 */
class HomeControllerActionTest extends ControllerActionTestCase
{
    /** @dataProvider programmeWeekBoundaries */
    public function testProgrammeUsesTheCurrentParisRadioWeek(string $instant, string $expectedTuesday): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable($instant));
        $diffusions = $this->createMock(DiffusionRepository::class);
        $diffusions->expects(self::once())->method('findPublishedByWeek')
            ->with(new \DateTimeImmutable("$expectedTuesday 00:00:00 Europe/Paris"))
            ->willReturn([]);
        $builder = new PublicScheduleBuilder(
            $diffusions,
            $this->createMock(ProgrammationGridBuilder::class),
            $this->createMock(GridOccurrenceProjectionService::class),
            $this->createMock(DiffusionDraftRepository::class),
        );
        $controller = new HomeController();
        $this->wire($controller);

        self::assertSame(200, $controller->programme($builder, $clock)->getStatusCode());
        self::assertSame('home/programme.html.twig', $this->template);
        self::assertSame($expectedTuesday, $this->parameters['programme']['startOfWeek']->format('Y-m-d'));
    }

    public static function programmeWeekBoundaries(): iterable
    {
        yield 'summer Monday 23:59 Paris' => ['2026-09-07 21:59:00 UTC', '2026-09-01'];
        yield 'summer Tuesday midnight Paris' => ['2026-09-07 22:00:00 UTC', '2026-09-08'];
        yield 'summer Tuesday 00:30 Paris' => ['2026-09-07 22:30:00 UTC', '2026-09-08'];
        yield 'winter Monday 23:59 Paris' => ['2026-01-12 22:59:00 UTC', '2026-01-06'];
        yield 'winter Tuesday midnight Paris' => ['2026-01-12 23:00:00 UTC', '2026-01-13'];
        yield 'winter Tuesday 00:30 Paris' => ['2026-01-12 23:30:00 UTC', '2026-01-13'];
    }

    public function testInfosRendersEmptyFeedWithoutNetworkAccess(): void
    {
        $controller = new HomeController();
        $this->wire($controller);

        $rss = $this->createMock(InfosSoirRssService::class);

        $rss
            ->expects(self::once())
            ->method('getLatest')
            ->with(7)
            ->willReturn([]);

        self::assertSame(
            200,
            $controller->infos($rss)->getStatusCode()
        );

        self::assertSame(
            'home/infos.html.twig',
            $this->template
        );

        self::assertSame(
            [],
            $this->parameters['infos']
        );
    }
}
