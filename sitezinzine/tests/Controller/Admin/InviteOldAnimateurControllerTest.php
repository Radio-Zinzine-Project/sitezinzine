<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\InviteOldAnimateurController;
use App\Entity\InviteOldAnimateur;
use App\Repository\InviteOldAnimateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

/** Tests des actions et des vrais formulaires, sans base de données ni rendu Twig. */
class InviteOldAnimateurControllerTest extends TestCase
{
    private InviteOldAnimateurController $controller;
    private Session $session;
    private CsrfTokenManager $csrf;
    private string $template;
    private array $parameters;

    protected function setUp(): void
    {
        $this->template = '';
        $this->parameters = [];
        $this->session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);
        $this->csrf = new CsrfTokenManager(null, new SessionTokenStorage($stack));

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $forms = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension($validator))
            ->addExtension(new CsrfExtension($this->csrf))
            ->getFormFactory();

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $parameters): string {
            $this->template = $template;
            $this->parameters = $parameters;

            return 'Rendered';
        });
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->with('admin.InviteOldAnimateur.index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/admin/InviteOldAnimateur/');

        $container = new Container();
        $container->set('twig', $twig);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('form.factory', $forms);
        $this->controller = new InviteOldAnimateurController();
        $this->controller->setContainer($container);
    }

    /** @dataProvider indexFilters */
    public function testIndexPassesNormalizedFiltersAndResultsToTemplate(array $query, string $initiale, string $type): void
    {
        $items = [$this->guest()];
        $repository = $this->createMock(InviteOldAnimateurRepository::class);
        $repository->expects(self::once())->method('findFiltered')->with($initiale, $type)->willReturn($items);

        $response = $this->controller->index(Request::create('/', 'GET', $query), $repository);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin/InviteOldAnimateur/index.html.twig', $this->template);
        self::assertSame([
            'InviteOldAnimateurs' => $items,
            'initiale' => $initiale,
            'type' => $type,
            'alphabet' => range('A', 'Z'),
        ], $this->parameters);
    }

    public static function indexFilters(): iterable
    {
        yield 'sans filtre' => [[], '', ''];
        yield 'invités et normalisation' => [['initiale' => ' a ', 'type' => ' invite '], 'A', 'invite'];
        yield 'anciens animateurs et chiffres' => [['initiale' => '0-9', 'type' => 'ancien'], '0-9', 'ancien'];
    }

    public function testIndexAcceptsEmptyResults(): void
    {
        $repository = $this->createMock(InviteOldAnimateurRepository::class);
        $repository->method('findFiltered')->willReturn([]);
        $response = $this->controller->index(Request::create('/'), $repository);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->parameters['InviteOldAnimateurs']);
    }

    /** @dataProvider emissionPages */
    public function testShowPassesPaginationAndNormalizedInitialToRepository(array $query, int $page, string $initiale): void
    {
        $guest = $this->guest();
        $pagination = $this->createMock(PaginationInterface::class);
        $repository = $this->createMock(InviteOldAnimateurRepository::class);
        $repository->expects(self::once())->method('paginateEmissions')
            ->with($guest, $page, $initiale)->willReturn($pagination);

        $response = $this->controller->show($guest, Request::create('/', 'GET', $query), $repository);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin/InviteOldAnimateur/show.html.twig', $this->template);
        self::assertSame([
            'InviteOldAnimateur' => $guest,
            'emissions' => $pagination,
            'initiale' => $initiale,
            'alphabet' => range('A', 'Z'),
        ], $this->parameters);
    }

    public static function emissionPages(): iterable
    {
        yield 'première page' => [[], 1, ''];
        yield 'page filtrée' => [['page' => '3', 'initiale' => ' b '], 3, 'B'];
    }

    /** @dataProvider formActions */
    public function testGetDisplaysFormWithoutWriting(string $action): void
    {
        $guest = $this->guest();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $response = 'create' === $action
            ? $this->controller->create(Request::create('/'), $em)
            : $this->controller->edit($guest, Request::create('/'), $em);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin/InviteOldAnimateur/' . $action . '.html.twig', $this->template);
        self::assertInstanceOf(FormView::class, $this->parameters['form']);
        self::assertSame('edit' === $action ? 'Alice' : '', $this->parameters['form']['firstName']->vars['value']);
        self::assertSame([], $this->session->getFlashBag()->peekAll());
        if ('edit' === $action) {
            self::assertSame($guest, $this->parameters['InviteOldAnimateur']);
        }
    }

    public static function formActions(): iterable
    {
        yield 'création' => ['create'];
        yield 'modification' => ['edit'];
    }

    /** @dataProvider validForms */
    public function testValidFormSavesValuesAndRedirects(string $action, bool $ancien): void
    {
        $guest = $this->guest()->setAncienanimateur(!$ancien);
        $saved = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects('create' === $action ? self::once() : self::never())->method('persist')
            ->willReturnCallback(function (object $entity) use (&$saved): void { $saved = $entity; });
        $em->expects(self::once())->method('flush');
        $data = ['firstName' => 'Camille', 'lastName' => '', 'phoneNumber' => '', 'mail' => ''];
        if ($ancien) {
            $data['ancienanimateur'] = '1';
        }
        $request = $this->submit($data);
        $response = 'create' === $action
            ? $this->controller->create($request, $em)
            : $this->controller->edit($guest, $request, $em);

        $saved = 'edit' === $action ? $guest : $saved;
        self::assertInstanceOf(InviteOldAnimateur::class, $saved);
        self::assertSame('Camille', $saved->getFirstName());
        self::assertNull($saved->getLastName());
        self::assertNull($saved->getPhoneNumber());
        self::assertNull($saved->getMail());
        self::assertSame($ancien, $saved->isAncienanimateur());
        self::assertTrue($response->isRedirect('/admin/InviteOldAnimateur/'));
        self::assertSame(['success' => ['create' === $action
            ? "L'invité·e a été créé·e !"
            : "L'invité·e a bien été modifié·e."]], $this->session->getFlashBag()->all());
        self::assertSame('', $this->template);
    }

    public static function validForms(): iterable
    {
        foreach (['create', 'edit'] as $action) {
            yield $action . ' invité' => [$action, false];
            yield $action . ' ancien animateur' => [$action, true];
        }
    }

    /** @dataProvider invalidForms */
    public function testInvalidFormDoesNotWriteAndDisplaysErrors(string $action, string $firstName, ?string $token): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $request = $this->submit(['firstName' => $firstName], $token);
        $response = 'create' === $action
            ? $this->controller->create($request, $em)
            : $this->controller->edit($this->guest(), $request, $em);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin/InviteOldAnimateur/' . $action . '.html.twig', $this->template);
        $view = $this->parameters['form'];
        self::assertFalse($view->vars['valid']);
        self::assertGreaterThan(0, count(null === $token ? $view['firstName']->vars['errors'] : $view->vars['errors']));
        self::assertSame([], $this->session->getFlashBag()->peekAll());
    }

    public static function invalidForms(): iterable
    {
        foreach (['create', 'edit'] as $action) {
            yield $action . ' prénom vide' => [$action, '', null];
            yield $action . ' prénom espaces' => [$action, '   ', null];
            yield $action . ' CSRF invalide' => [$action, 'Camille', 'invalid-token'];
        }
    }

    public function testRemoveDeletesRequestedGuestAndRedirectsWithFlash(): void
    {
        $guest = $this->guest();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with(self::identicalTo($guest));
        $em->expects(self::once())->method('flush');
        $em->expects(self::never())->method('persist');

        $response = $this->controller->remove($guest, $em);

        self::assertTrue($response->isRedirect('/admin/InviteOldAnimateur/'));
        self::assertSame(["L'invité·e a bien été supprimé·e."], $this->session->getFlashBag()->get('success'));
    }

    private function guest(): InviteOldAnimateur
    {
        return (new InviteOldAnimateur())->setFirstName('Alice')->setLastName('Martin')
            ->setPhoneNumber('0102030405')->setMail('alice@example.com')->setAncienanimateur(false);
    }

    private function submit(array $data, ?string $token = null): Request
    {
        $data['_token'] = $token ?? $this->csrf->getToken('invite_old_animateur')->getValue();

        return Request::create('/', 'POST', ['invite_old_animateur' => $data]);
    }
}
