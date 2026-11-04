<?php

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

class ErrorPagesTest extends KernelTestCase
{
    private Environment $twig;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->twig = $container->get(Environment::class);
        $this->requestStack = $container->get(RequestStack::class);

        $request = Request::create('/');
        $request->attributes->set('_route', 'home');

        $this->requestStack->push($request);
    }

    protected function tearDown(): void
    {
        if ($this->requestStack->getCurrentRequest() !== null) {
            $this->requestStack->pop();
        }

        parent::tearDown();
    }

    public function testGenericErrorPageRendersSuccessfully(): void
    {
        $html = $this->twig->render(
            'bundles/TwigBundle/Exception/error.html.twig',
            [
                'status_code' => 418,
                'status_text' => 'I\'m a teapot',
            ]
        );

        $this->assertStringContainsString('Erreur 418', $html);
        $this->assertStringContainsString(
            'Une erreur est survenue',
            $html
        );

        $this->assertStringContainsString(
            'La page demandée ne peut pas être affichée pour le moment.',
            $html
        );

        $this->assertStringContainsString(
            'href="/"',
            $html
        );
    }

    public function test404ErrorPageRendersSuccessfully(): void
    {
        $html = $this->twig->render(
            'bundles/TwigBundle/Exception/error404.html.twig',
            [
                'status_code' => 404,
                'status_text' => 'Not Found',
            ]
        );

        $this->assertStringContainsString(
            'Page introuvable | Radio Zinzine',
            $html
        );

        $this->assertStringContainsString(
            'Cette page est introuvable',
            $html
        );

        $this->assertStringContainsString(
            'Impossible de trouver la page',
            $html
        );

        $this->assertStringContainsString(
            "La page que vous cherchez n'existe pas,",
            $html
        );

        $this->assertStringContainsString(
            "a été déplacée ou n'est plus disponible.",
            $html
        );

        $this->assertStringContainsString(
            "Vous pouvez retourner à l'accueil et reprendre",
            $html
        );

        $this->assertStringContainsString(
            'votre navigation sur Radio Zinzine.',
            $html
        );

        $this->assertStringContainsString(
            'href="/"',
            $html
        );
    }

    public function test403ErrorPageRendersSuccessfully(): void
    {
        $html = $this->twig->render(
            'bundles/TwigBundle/Exception/error403.html.twig',
            [
                'status_code' => 403,
                'status_text' => 'Forbidden',
            ]
        );

        $this->assertStringContainsString(
            'Accès interdit | Radio Zinzine',
            $html
        );

        $this->assertStringContainsString(
            'Cette partie du site ne vous est pas accessible',
            $html
        );

        $this->assertStringContainsString(
            "Vous n'avez pas les droits nécessaires",
            $html
        );

        $this->assertStringContainsString(
            'href="/"',
            $html
        );
    }

    public function test500ErrorPageRendersSuccessfully(): void
    {
        $html = $this->twig->render(
            'bundles/TwigBundle/Exception/error500.html.twig',
            [
                'status_code' => 500,
                'status_text' => 'Internal Server Error',
            ]
        );

        $this->assertStringContainsString(
            'Problème technique | Radio Zinzine',
            $html
        );

        $this->assertStringContainsString(
            'Petit problème technique',
            $html
        );

        $this->assertStringContainsString(
            'Il y a un peu de friture sur la ligne',
            $html
        );

        $this->assertStringContainsString(
            'href="/"',
            $html
        );
    }

    public function testErrorPagesIncludeMainSiteStructure(): void
    {
        $html = $this->twig->render(
            'bundles/TwigBundle/Exception/error500.html.twig',
            [
                'status_code' => 500,
                'status_text' => 'Internal Server Error',
            ]
        );

        $this->assertStringContainsString(
            '<body class="site-layout">',
            $html
        );

        $this->assertStringContainsString(
            '<header>',
            $html
        );

        $this->assertStringContainsString(
            '<main class="body">',
            $html
        );

        $this->assertStringContainsString(
            '<footer>',
            $html
        );

        $this->assertStringContainsString(
            'top-content-dechire-D.gif',
            $html
        );
    }

    public function test500PageDoesNotExposeExceptionDetails(): void
    {
        $secretTechnicalMessage = 'SQLSTATE SECRET DATABASE FAILURE';

        $html = $this->twig->render(
            'bundles/TwigBundle/Exception/error500.html.twig',
            [
                'status_code' => 500,
                'status_text' => 'Internal Server Error',
                'exception' => new \RuntimeException(
                    $secretTechnicalMessage
                ),
            ]
        );

        $this->assertStringNotContainsString(
            $secretTechnicalMessage,
            $html
        );

        $this->assertStringNotContainsString(
            'RuntimeException',
            $html
        );
    }
}
