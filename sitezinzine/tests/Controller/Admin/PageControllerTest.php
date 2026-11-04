<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\PageController;
use App\Entity\Page;
use App\Form\PageType;
use App\Repository\PageRepository;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;

class PageControllerTest extends ControllerActionTestCase
{
    private PageController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new PageController();
        $this->wire($this->controller);
    }

    /** @dataProvider pageLists */
    public function testIndexDisplaysRepositoryResults(array $pages): void
    {
        $repo = $this->createMock(PageRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn($pages);
        self::assertSame(200, $this->controller->index($repo)->getStatusCode());
        self::assertSame('admin/page/index.html.twig', $this->template);
        self::assertSame($pages, $this->parameters['pages']);
    }

    public static function pageLists(): iterable
    {
        yield 'vide' => [[]];
        yield 'avec page' => [[(new Page())->setTitle('Présentation')]];
    }

    /** @dataProvider formStates */
    public function testCreatePersistsOnlyAValidSubmission(bool $submitted, bool $valid): void
    {
        $this->form(PageType::class, $submitted, $valid);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($valid ? self::once() : self::never())->method('persist')->with(self::callback(function ($page): bool {
            return $page === $this->formData && $page instanceof Page;
        }));
        $em->expects($valid ? self::once() : self::never())->method('flush');
        $before = new \DateTime();
        $response = $this->controller->create(Request::create('/', $submitted ? 'POST' : 'GET'), $em);
        if ($valid) {
            self::assertTrue($response->isRedirect());
            self::assertSame(['admin.page.index', []], $this->redirect);
            self::assertGreaterThanOrEqual($before, $this->formData->getUpdatedAt());
            self::assertSame(['La page a bien été créée.'], $this->session->getFlashBag()->get('success'));
        } else {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('admin/page/create.html.twig', $this->template);
            self::assertSame($this->formData, $this->parameters['page']);
            self::assertSame([], $this->session->getFlashBag()->all());
        }
    }

    /** @dataProvider editCases */
    public function testEditControlsImageRemovalAndTimestamp(bool $submitted, bool $valid, ?bool $delete): void
    {
        $oldDate = new \DateTime('2000-01-01');
        $image = new File(__FILE__);
        $page = (new Page())->setMainImageName('header.png')->setMainImageFile($image)->setUpdatedAt($oldDate);
        $this->form(PageType::class, $submitted, $valid, $page, fields: null === $delete ? [] : ['deleteMainImage' => $delete]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects($valid ? self::once() : self::never())->method('flush');
        $response = $this->controller->edit($page, Request::create('/'), $em);
        self::assertSame($valid && $delete ? null : 'header.png', $page->getMainImageName());
        self::assertSame($valid && $delete ? null : $image, $page->getMainImageFile());
        if ($valid) {
            self::assertTrue($response->isRedirect());
            self::assertSame(['admin.page.index', []], $this->redirect);
            self::assertGreaterThan($oldDate, $page->getUpdatedAt());
            self::assertSame(['La page a bien été mise à jour.'], $this->session->getFlashBag()->get('success'));
        } else {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($oldDate, $page->getUpdatedAt());
            self::assertSame('admin/page/edit.html.twig', $this->template);
            self::assertSame($page, $this->parameters['page']);
        }
    }

    public static function editCases(): iterable
    {
        yield 'affichage' => [false, false, false];
        yield 'invalide ne supprime pas image' => [true, false, true];
        yield 'garder image' => [true, true, false];
        yield 'supprimer image' => [true, true, true];
        yield 'sans champ suppression' => [true, true, null];
    }
}
