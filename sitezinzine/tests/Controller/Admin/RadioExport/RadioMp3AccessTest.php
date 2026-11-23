<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\RadioExport;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RadioMp3AccessTest extends WebTestCase
{
    /** @dataProvider roles */
    public function testReadOnlyPageAccess(string $role): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = self::getContainer();
        $container->set('clock', new \Symfony\Component\Clock\MockClock('2026-11-23T23:59:59+01:00'));
        $provider = $this->createMock(EntityUserProvider::class);
        $provider->method('supportsClass')->willReturn(true);
        $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
        $container->set('security.user.provider.concrete.app_user_provider', $provider);
        $user = (new User())->setUsername('radio-test')->setPassword('synthetic')
            ->setRoles([$role])->setVerified(true)->setApproved(true);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 12);
        $client->loginUser($user);

        $db = $this->createMock(Connection::class);
        $db->method('isAutoCommit')->willReturn(true);
        $db->expects($role === 'ROLE_USER' ? self::never() : self::once())->method('fetchAllAssociative')
            ->with(self::anything(), ['2026-11-17', '2026-11-17 00:00:00', '2026-11-24 00:00:00', 'published'])
            ->willReturn([['validated' => 0, 'manual_revision' => null, 'diffusion_id' => null]]);
        $db->expects(self::never())->method('executeStatement');
        $container->set('doctrine.dbal.default_connection', $db);

        $crawler = $client->request('GET', '/admin/radio-mp3?week=2026-11-17');
        if ($role === 'ROLE_USER') {
            $client->request('POST', '/admin/radio-mp3/2026-11-17/prepare');
            self::assertSame(302, $client->getResponse()->getStatusCode());
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $client->request('POST', '/admin/radio-mp3/2026-11-17/A/transfer/1');
            self::assertSame(302, $client->getResponse()->getStatusCode());
            self::assertSame($container->get('router')->generate('access_denied'), $client->getResponse()->headers->get('Location'));
            return;
        }
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('h1', 'Gestion des MP3 radio');
        self::assertSelectorTextContains('h2', '23/11/2026');
        self::assertSelectorTextContains('body', 'Semaine non validée');
        self::assertCount(1, $crawler->filter('nav a.active[href="/admin/radio-mp3"]'));
        self::assertCount(0, $crawler->filter('form[method="post"]'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $client->request('GET', '/admin/radio-mp3?week=2026-11-18');
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $client->request('POST', '/admin/radio-mp3/2026-11-17/prepare', [
            'request_id' => str_repeat('b', 32), '_token' => 'invalid',
        ]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', '/admin/radio-mp3/2026-11-17/A/transfer/1', [
            'request_id' => str_repeat('b', 32), 'version' => str_repeat('a', 64), '_token' => 'invalid',
        ]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testValidFormRedirectsAndReplayDoesNotDuplicateTheAttempt(): void
    {
        $root = \App\Tests\Support\Mp3TestStorage::root() . '/launch-http-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $environment = ['RADIO_EXPORT_ROOT' => $root, 'RADIO_EXPORT_DESTINATION' => 'synthetic'];
        $previous = array_intersect_key($_ENV, $environment);
        $_ENV = $environment + $_ENV;
        try {
            $client = self::createClient();
            $client->disableReboot();
            $container = self::getContainer();
            $clock = new \Symfony\Component\Clock\MockClock('2026-11-23T23:59:59+01:00');
            $container->set('clock', $clock);
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $client->loginUser((new User())->setUsername('radio-test')->setPassword('synthetic')
                ->setRoles(['ROLE_ADMIN'])->setVerified(true)->setApproved(true));
            $db = $this->createMock(Connection::class);
            $db->method('isAutoCommit')->willReturn(true);
            $db->method('fetchAllAssociative')->willReturn([['validated' => 1, 'manual_revision' => '1', 'diffusion_id' => null]]);
            $db->expects(self::never())->method('executeStatement');
            $container->set('doctrine.dbal.default_connection', $db);

            $url = '/admin/radio-mp3?week=2026-11-17';
            $crawler = $client->request('GET', $url);
            $form = $crawler->filter('form[method="post"]')->form();
            $client->submit($form);
            self::assertSame(303, $client->getResponse()->getStatusCode());
            self::assertSame($url, $client->getResponse()->headers->get('Location'));
            $marker = $root . '/2026-11-17/' . $form->getValues()['request_id'] . '/result.json';
            self::assertFileExists($marker);
            $client->submit($form);
            self::assertSame(303, $client->getResponse()->getStatusCode());
            self::assertSame([$marker], glob($root . '/2026-11-17/*/result.json'));
            $clock->sleep(1);
            $crawler = $client->request('GET', $url);
            self::assertCount(0, $crawler->filter('form[method="post"]'));
            $client->request('GET', '/admin/radio-mp3/2026-11-17/' . $form->getValues()['request_id'] . '/download/report');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame(file_get_contents($marker), $client->getInternalResponse()->getContent());
            $client->submit($form);
            self::assertSame(303, $client->getResponse()->getStatusCode());
            self::assertSame([$marker], glob($root . '/2026-11-17/*/result.json'));
        } finally {
            foreach (array_keys($environment) as $key) {
                unset($_ENV[$key]);
            }
            $_ENV = $previous + $_ENV;
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    public static function roles(): iterable
    {
        yield ['ROLE_ADMIN'];
        yield ['ROLE_SUPER_ADMIN'];
        yield ['ROLE_USER'];
    }
}
