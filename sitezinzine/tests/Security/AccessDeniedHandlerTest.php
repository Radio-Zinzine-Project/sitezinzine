<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AccessDeniedHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AccessDeniedHandlerTest extends TestCase
{
    /** @dataProvider deniedAttributes */
    public function testRedirectStoresRequiredAttributeAndReplacesPreviousValue(array $attributes, ?string $expected): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('required_role', 'ROLE_PREVIOUS');
        $session->set('unrelated', 'preserved');
        $request = Request::create('/admin/evenement');
        $request->setSession($session);
        $exception = new AccessDeniedException();
        $exception->setAttributes($attributes);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())->method('generate')->with('access_denied')->willReturn('/access-denied');

        $response = (new AccessDeniedHandler($router))->handle($request, $exception);

        self::assertTrue($response->isRedirect('/access-denied'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($expected, $session->get('required_role'));
        self::assertSame('preserved', $session->get('unrelated'));
    }

    public static function deniedAttributes(): iterable
    {
        yield 'rôle requis' => [['ROLE_EDITOR'], 'ROLE_EDITOR'];
        yield 'premier attribut affiché' => [['ROLE_ADMIN', 'ROLE_EDITOR'], 'ROLE_ADMIN'];
        yield 'absence de rôle efface le précédent' => [[], null];
    }
}
