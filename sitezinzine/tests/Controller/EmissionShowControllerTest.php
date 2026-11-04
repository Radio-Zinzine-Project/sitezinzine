<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\EmissionShowController;
use App\Entity\Emission;
use App\Entity\Theme;
use App\Form\EmissionSearchType;
use App\Repository\DiffusionRepository;
use App\Repository\EmissionRepository;
use App\Repository\ThemeRepository;
use App\Tests\Support\PublicControllerActionTestCase;
use Knp\Bundle\PaginatorBundle\Pagination\SlidingPagination;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EmissionShowControllerTest extends PublicControllerActionTestCase
{
    /** @dataProvider themes */
    public function testShowSelectsRelatedThemesAndLatestDiffusions(?int $themeId, array $expectedIds): void
    {
        $controller = new EmissionShowController();
        $this->wire($controller);

        $emission = new Emission();
        $theme = null;

        if ($themeId !== null) {
            $theme = new Theme();
            $this->identify($theme, $themeId);
            $emission->setTheme($theme);
        }

        $repo = $this->createMock(EmissionRepository::class);
        $repo->method('getThemeGroups')->willReturn([
            'first' => [1, 7],
            'second' => [2, 9],
        ]);

        $themes = $this->createMock(ThemeRepository::class);
        $diffusions = $this->createMock(DiffusionRepository::class);
        $pagination = $this->createMock(PaginationInterface::class);

        $themes
            ->expects($theme ? self::once() : self::never())
            ->method('findBy')
            ->with(['id' => $expectedIds])
            ->willReturn($theme ? [$theme] : []);

        $repo
            ->expects($theme ? self::once() : self::never())
            ->method('paginateEmissionsByThemeGroup')
            ->with($expectedIds, 2)
            ->willReturn($pagination);

        $latest = [new \App\Entity\Diffusion()];

        $diffusions
            ->expects($theme ? self::once() : self::never())
            ->method('findLatestByEmission')
            ->with($emission, 6)
            ->willReturn($latest);

        if (!$theme) {
            $this->expectException(NotFoundHttpException::class);
        }

        $response = $controller->show(
            $emission,
            $repo,
            $themes,
            $diffusions,
            $this->createMock(PaginatorInterface::class),
            Request::create('/?page=2')
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/home/show.html.twig', $this->template);
        self::assertSame([
            'emission' => $emission,
            'theme' => $theme,
            'themesInGroup' => [$theme],
            'relatedEmissions' => $pagination,
            'latestDiffusions' => $latest,
        ], $this->parameters);
    }

    public static function themes(): iterable
    {
        yield 'premier groupe' => [7, [1, 7]];
        yield 'second groupe' => [9, [2, 9]];
        yield 'hors groupe' => [99, []];
        yield 'sans thème' => [null, []];
    }

    /** @dataProvider formStates */
    public function testSearchIgnoresInvalidCriteriaAndEnrichesResults(
        bool $submitted,
        bool $valid
    ): void {
        $controller = new EmissionShowController();
        $this->wire($controller);

        $criteria = ['titre' => 'radio'];

        $this->unmappedForm(
            EmissionSearchType::class,
            $submitted,
            $valid,
            $criteria,
            $criteria,
            ['public_only' => true]
        );

        $first = new Emission();
        $second = new Emission();

        $this->identify($first, 10);
        $this->identify($second, 11);

        $pagination = new SlidingPagination([]);
        $pagination->setItems([$first, $second]);

        $repo = $this->createMock(EmissionRepository::class);

        $repo
            ->expects(self::once())
            ->method('findBySearch')
            ->with(
                $submitted && $valid ? $criteria : [],
                3,
                'R'
            )
            ->willReturn($pagination);

        $date = new \DateTimeImmutable('2026-09-01');

        $repo
            ->expects(self::exactly(2))
            ->method('findLastDiffusionDate')
            ->willReturnMap([
                [10, $date],
                [11, null],
            ]);

        self::assertSame(
            200,
            $controller
                ->search(
                    Request::create('/?page=3&initiale=%20r%20'),
                    $repo
                )
                ->getStatusCode()
        );

        self::assertSame($date, $first->getLastDiffusion());
        self::assertNull($second->getLastDiffusion());

        self::assertSame(
            'home/recherche.html.twig',
            $this->template
        );

        self::assertSame(
            $pagination,
            $this->parameters['emissions']
        );

        self::assertSame(
            'radio',
            $this->parameters['searchTerm']
        );

        self::assertSame(
            'R',
            $this->parameters['initiale']
        );

        self::assertSame(
            range('A', 'Z'),
            $this->parameters['alphabet']
        );
    }

    public function testEmptySearchResultsRenderNormally(): void
    {
        $controller = new EmissionShowController();
        $this->wire($controller);

        $this->unmappedForm(
            EmissionSearchType::class,
            false,
            false,
            ['titre' => null],
            [],
            ['public_only' => true]
        );

        $pagination = new SlidingPagination([]);
        $pagination->setItems([]);

        $repo = $this->createMock(EmissionRepository::class);

        $repo
            ->expects(self::once())
            ->method('findBySearch')
            ->with([], 1, '')
            ->willReturn($pagination);

        $repo
            ->expects(self::never())
            ->method('findLastDiffusionDate');

        self::assertSame(
            200,
            $controller
                ->search(
                    Request::create('/'),
                    $repo
                )
                ->getStatusCode()
        );

        self::assertSame(
            '',
            $this->parameters['initiale']
        );

        self::assertNull(
            $this->parameters['searchTerm']
        );
    }
}