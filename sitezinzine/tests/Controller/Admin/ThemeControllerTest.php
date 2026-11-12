<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\ThemeController;
use App\Entity\Theme;
use App\Form\ThemeType;
use App\Repository\ThemeRepository;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Component\HttpFoundation\Request;

class ThemeControllerTest extends ControllerActionTestCase
{
    private ThemeController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ThemeController();
        $this->wire($this->controller);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            unset($this->controller);
        }
    }

    public function testIndexDisplaysThemes(): void
    {
        $themes = [(new Theme())->setName('Musique')];
        $repo = $this->createMock(ThemeRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn($themes);
        self::assertSame(200, $this->controller->index(Request::create('/'), $repo)->getStatusCode());
        self::assertSame('admin/theme/index.html.twig', $this->template);
        self::assertSame($themes, $this->parameters['themes']);
    }

    /** @dataProvider pages */
    public function testShowPaginatesAndNormalizesInitial(array $query, int $page, string $initiale): void
    {
        $theme = new Theme();
        $pagination = $this->createMock(PaginationInterface::class);
        $repo = $this->createMock(ThemeRepository::class);
        $repo->expects(self::once())->method('paginateEmissions')->with($theme, $page, $initiale)->willReturn($pagination);
        self::assertSame(200, $this->controller->show($theme, Request::create('/', 'GET', $query), $repo)->getStatusCode());
        self::assertSame('admin/theme/show.html.twig', $this->template);
        self::assertSame(['theme' => $theme, 'emissions' => $pagination, 'initiale' => $initiale, 'alphabet' => range('A', 'Z')], $this->parameters);
    }

    public static function pages(): iterable
    {
        yield 'défaut' => [[], 1, ''];
        yield 'filtrée' => [['page' => '2', 'initiale' => ' m '], 2, 'M'];
    }

    /** @dataProvider actions */
    public function testFormWritesAndUpdatesDateOnlyWhenValid(string $action, bool $submitted, bool $valid): void
    {
        $oldDate = new \DateTime('2000-01-01');
        $theme = (new Theme())->setName('Musique')->setUpdatedAt($oldDate);
        $this->form(ThemeType::class, $submitted, $valid, 'edit' === $action ? $theme : null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($valid && 'create' === $action ? self::once() : self::never())->method('persist')
            ->with(self::callback(fn ($entity): bool => $entity === $this->formData));
        $em->expects($valid ? self::once() : self::never())->method('flush');
        $response = 'create' === $action
            ? $this->controller->create(Request::create('/'), $em)
            : $this->controller->edit($theme, Request::create('/'), $em);
        if ($valid) {
            self::assertTrue($response->isRedirect());
            self::assertSame(['admin.theme.index', []], $this->redirect);
            self::assertGreaterThan($oldDate, $this->formData->getUpdatedAt());
            self::assertCount(1, $this->session->getFlashBag()->get('success'));
        } else {
            self::assertSame($submitted ? 422 : 200, $response->getStatusCode());
            self::assertSame('admin/theme/' . $action . '.html.twig', $this->template);
            self::assertSame([], $this->session->getFlashBag()->all());
            if ('edit' === $action) {
                self::assertSame($oldDate, $theme->getUpdatedAt());
            }
        }
    }

    public static function actions(): iterable
    {
        foreach (['create', 'edit'] as $action) {
            foreach (self::formStates() as $name => $state) {
                yield $action . ' ' . $name => [$action, ...$state];
            }
        }
    }

    public function testRemoveDeletesTheRequestedTheme(): void
    {
        $theme = new Theme();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with(self::identicalTo($theme));
        $em->expects(self::once())->method('flush');
        self::assertTrue($this->controller->remove($theme, $em)->isRedirect());
        self::assertSame(['admin.theme.index', []], $this->redirect);
        self::assertCount(1, $this->session->getFlashBag()->get('success'));
    }
}
