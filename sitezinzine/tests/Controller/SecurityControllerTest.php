<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\SecurityController;
use App\Tests\Support\ControllerActionTestCase;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityControllerTest extends ControllerActionTestCase
{
    /** @dataProvider errors */
    public function testLoginPreservesAuthenticationFeedbackAndDisablesCaching(bool $failed): void
    {
        $controller = new SecurityController($this->container->get('request_stack'));
        $this->wire($controller);
        $error = $failed ? new BadCredentialsException() : null;
        $utils = $this->createMock(AuthenticationUtils::class);
        $utils->method('getLastUsername')->willReturn('listener@example.com');
        $utils->method('getLastAuthenticationError')->willReturn($error);
        $response = $controller->login($utils);
        self::assertSame('security/login.html.twig', $this->template);
        self::assertSame(['last_username' => 'listener@example.com', 'error' => $error], $this->parameters);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('0', $response->headers->get('Expires'));
    }

    public static function errors(): iterable
    {
        yield 'première visite' => [false];
        yield 'connexion refusée' => [true];
    }

    /** @dataProvider errors */
    public function testAccessDeniedDisplaysCurrentUserAndRequiredRole(bool $authenticated): void
    {
        $controller = new SecurityController($this->container->get('request_stack'));
        $this->wire($controller);
        $user = $authenticated ? new User() : null;
        $storage = new TokenStorage();
        if ($user) {
            $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
            $this->session->set('required_role', 'ROLE_ADMIN');
        }
        $this->container->set('security.token_storage', $storage);
        self::assertSame(200, $controller->accessDenied()->getStatusCode());
        self::assertSame('security/access_denied.html.twig', $this->template);
        self::assertSame(['user' => $user, 'required_role' => $authenticated ? 'ROLE_ADMIN' : null], $this->parameters);
    }
}
