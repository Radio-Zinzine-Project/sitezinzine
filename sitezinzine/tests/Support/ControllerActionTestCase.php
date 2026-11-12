<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Twig\Environment;

/** Isolation des actions : formulaires, rendu et persistance sont des frontières simulées. */
abstract class ControllerActionTestCase extends TestCase
{
    protected Container $container;
    protected Session $session;
    protected CsrfTokenManager $csrf;
    protected string $template = '';
    protected array $parameters = [];
    protected array $redirect = [];
    protected ?object $formData = null;
    protected array $formErrors = [];

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);
        $this->csrf = new CsrfTokenManager(null, new SessionTokenStorage($stack));
        $this->container->set('request_stack', $stack);
        $this->container->set('security.csrf.token_manager', $this->csrf);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $parameters): string {
            $this->template = $template;
            $this->parameters = $parameters;
            return 'Rendered';
        });
        $this->container->set('twig', $twig);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(function (string $route, array $parameters): string {
            $this->redirect = [$route, $parameters];
            return '/redirect';
        });
        $this->container->set('router', $router);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            unset($this->container, $this->session, $this->csrf);
            $this->formData = null;
            $this->parameters = [];
            $this->redirect = [];
            $this->formErrors = [];
        }
    }

    protected function wire(AbstractController $controller): void
    {
        $controller->setContainer($this->container);
    }

    /** Simule le résultat du formulaire ; les contraintes des FormType ne sont pas testées ici. */
    protected function form(
        string $type,
        bool $submitted,
        bool $valid,
        ?object $expectedData = null,
        ?callable $prepare = null,
        array $fields = []
    ): void {
        $children = [];

        foreach ($fields as $name => $value) {
            $child = $this->createMock(FormInterface::class);

            $child
                ->method('getData')
                ->willReturn($value);

            $child
                ->method('addError')
                ->willReturnCallback(
                    function ($error) use ($name, $child) {
                        $this->formErrors[$name][] = $error->getMessage();

                        return $child;
                    }
                );

            $children[$name] = $child;
        }

        $form = $this->createMock(FormInterface::class);

        $form
            ->expects(self::once())
            ->method('handleRequest')
            ->with(self::isInstanceOf(Request::class))
            ->willReturnSelf();

        $form
            ->method('isSubmitted')
            ->willReturn($submitted);

        $form
            ->method('isValid')
            ->willReturnCallback(
                fn(): bool =>
                $valid && [] === $this->formErrors
            );

        $form
            ->method('createView')
            ->willReturn(new FormView());

        $form
            ->method('has')
            ->willReturnCallback(
                fn(string $name): bool =>
                isset($children[$name])
            );

        $form
            ->method('get')
            ->willReturnCallback(
                static fn(string $name): FormInterface =>
                $children[$name]
            );

        $factory = $this->createMock(
            FormFactoryInterface::class
        );

        $factory
            ->expects(self::once())
            ->method('create')
            ->willReturnCallback(
                function (
                    string $actualType,
                    mixed $data,
                    array $options
                ) use (
                    $type,
                    $expectedData,
                    $prepare,
                    $form
                ): FormInterface {
                    self::assertSame(
                        $type,
                        $actualType
                    );

                    if (null !== $expectedData) {
                        self::assertSame(
                            $expectedData,
                            $data
                        );
                    }

                    $this->formData = $data;

                    if (null !== $prepare) {
                        $prepare(
                            $data,
                            $options
                        );
                    }

                    return $form;
                }
            );

        $this->container->set(
            'form.factory',
            $factory
        );
    }

    protected function identify(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }

    protected function tokenRequest(string $id, bool $valid): Request
    {
        return Request::create('/', 'POST', ['_token' => $valid ? $this->csrf->getToken($id)->getValue() : 'invalid']);
    }

    public static function formStates(): iterable
    {
        yield 'affichage' => [false, false];
        yield 'soumission invalide' => [true, false];
        yield 'soumission valide' => [true, true];
    }
}
