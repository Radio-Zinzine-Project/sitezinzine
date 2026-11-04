<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AnnonceController;
use App\Entity\Annonce;
use App\Form\AnnonceType;
use App\Repository\AnnonceRepository;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Component\HttpFoundation\Request;

class AnnonceControllerTest extends ControllerActionTestCase
{
    /** @dataProvider pages */
    public function testIndexPaginatesUpcomingAnnouncements(string $queryString, int $page): void
    {
        $controller = new AnnonceController();
        $this->wire($controller);
        $query = $this->createMock(Query::class);
        $repo = $this->createMock(AnnonceRepository::class);
        $repo->expects(self::once())->method('findUpcomingAnnoncesQuery')->willReturn($query);
        $pagination = $this->createMock(PaginationInterface::class);
        $paginator = $this->createMock(PaginatorInterface::class);
        $paginator->expects(self::once())->method('paginate')->with($query, $page, 12)->willReturn($pagination);
        self::assertSame(200, $controller->index($repo, $paginator, Request::create('/'.$queryString))->getStatusCode());
        self::assertSame('/home/annonces.html.twig', $this->template);
        self::assertSame($pagination, $this->parameters['annonces']);
    }

    public static function pages(): iterable
    {
        yield 'défaut' => ['', 1];
        yield 'page suivante' => ['?page=3', 3];
    }

    /** @dataProvider submissions */
    public function testCreateRequiresValidFormAndAlwaysAwaitsModeration(bool $submitted, bool $valid, string $type, string $other, string $expected): void
    {
        $controller = new AnnonceController();
        $this->wire($controller);
        $this->form(AnnonceType::class, $submitted, $valid, prepare: static function (Annonce $annonce, array $options) use ($type): void {
            self::assertFalse($options['show_valid']);
            $annonce->setType($type)->setValid(true)->setSoftDelete(true);
        }, fields: ['autreType' => $other]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($submitted && $valid ? self::once() : self::never())->method('persist')->with(self::callback(function (Annonce $annonce) use ($expected): bool {
            self::assertSame($this->formData, $annonce);
            self::assertSame($expected, $annonce->getType());
            self::assertFalse($annonce->isValid());
            self::assertFalse($annonce->isSoftDelete());
            self::assertNotNull($annonce->getUpdateAt());
            return true;
        }));
        $em->expects($submitted && $valid ? self::once() : self::never())->method('flush');
        $response = $controller->create(Request::create('/annonce/create'), $em);
        if ($submitted && $valid) {
            self::assertTrue($response->isRedirect());
            self::assertSame(['annonce.index', []], $this->redirect);
            self::assertCount(1, $this->session->getFlashBag()->get('success'));
        } else {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('/home/annoncesCreate.html.twig', $this->template);
            self::assertSame([], $this->session->getFlashBag()->all());
        }
    }

    public static function submissions(): iterable
    {
        yield 'affichage' => [false, false, 'Concert', '', 'Concert'];
        yield 'invalide' => [true, false, 'Concert', '', 'Concert'];
        yield 'type standard' => [true, true, 'Concert', 'ignoré', 'Concert'];
        yield 'type personnalisé' => [true, true, '__autre__', '  Rencontre  ', 'Rencontre'];
        yield 'autre vide' => [true, true, '__autre__', '  ', 'Autre'];
    }

    public function testShowPassesAnnouncementToTemplate(): void
    {
        $controller = new AnnonceController();
        $this->wire($controller);
        $annonce = new Annonce();
        $repo = $this->createMock(AnnonceRepository::class);
        $repo->expects(self::once())->method('find')->with(12)->willReturn($annonce);
        self::assertSame(200, $controller->annonceShow($annonce, 12, $repo)->getStatusCode());
        self::assertSame('home/annonceShow.html.twig', $this->template);
        self::assertSame($annonce, $this->parameters['annonce']);
    }
}
