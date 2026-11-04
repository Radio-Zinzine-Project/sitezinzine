<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HomeController;
use App\Service\InfosSoirRssService;
use App\Tests\Support\ControllerActionTestCase;

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