<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\CategoriesShowsController;
use App\Entity\Categories;
use App\Repository\CategoriesRepository;
use App\Repository\EmissionRepository;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\QueryBuilder;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CategoriesShowsControllerTest extends ControllerActionTestCase
{
    /** @dataProvider pages */
    public function testShowPaginatesOnlyRequestedCategory(string $query, int $page): void
    {
        $controller = new CategoriesShowsController();
        $this->wire($controller);
        $category = new Categories();
        $categories = $this->createMock(CategoriesRepository::class);
        $categories->expects(self::once())->method('find')->with(42)->willReturn($category);
        $qb = $this->createMock(QueryBuilder::class);
        $emissions = $this->createMock(EmissionRepository::class);
        $emissions->expects(self::once())->method('createLatestByCategoryQueryBuilder')->with(42)->willReturn($qb);
        $pagination = $this->createMock(PaginationInterface::class);
        $paginator = $this->createMock(PaginatorInterface::class);
        $paginator->expects(self::once())->method('paginate')->with($qb, $page, 20)->willReturn($pagination);
        self::assertSame(200, $controller->show(42, $categories, $emissions, Request::create('/'.$query), $paginator)->getStatusCode());
        self::assertSame('/home/showCat.html.twig', $this->template);
        self::assertSame(['categorie' => $category, 'emissions' => $pagination], $this->parameters);
    }

    public static function pages(): iterable
    {
        yield ['', 1];
        yield ['?page=4', 4];
    }

    public function testMissingCategoryStopsBeforeQueryingEmissions(): void
    {
        $controller = new CategoriesShowsController();
        $this->wire($controller);
        $categories = $this->createMock(CategoriesRepository::class);
        $categories->method('find')->with(42)->willReturn(null);
        $emissions = $this->createMock(EmissionRepository::class);
        $emissions->expects(self::never())->method('createLatestByCategoryQueryBuilder');
        $paginator = $this->createMock(PaginatorInterface::class);
        $paginator->expects(self::never())->method('paginate');
        $this->expectException(NotFoundHttpException::class);
        $controller->show(42, $categories, $emissions, Request::create('/'), $paginator);
    }
}
