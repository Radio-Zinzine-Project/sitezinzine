<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Service\AudioLibrary\Inventory\AudioInventoryReportDiscovery;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LibraryAccessTest extends WebTestCase
{
    /** @dataProvider roles */
    public function testLibraryNavigationAndAccess(?string $role): void
    {
        $client = static::createClient(['environment' => 'test', 'debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        $allowed = $role === 'ROLE_SUPER_ADMIN';
        $reader = $this->createMock(AudioInventoryReportDiscovery::class);
        $reader->expects($allowed ? self::once() : self::never())->method('getProfiles')->willReturn([]);
        foreach (['discover', 'readManifest', 'readPage'] as $method) {
            $reader->expects(self::never())->method($method);
        }
        $container->set(AudioInventoryReportDiscovery::class, $reader);
        if ($role !== null) {
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $client->loginUser((new User())->setUsername('library-test')->setPassword('synthetic')
                ->setRoles([$role])->setVerified(true)->setApproved(true));
        }

        $db = $container->get('doctrine')->getConnection();
        $crawler = $client->request('GET', '/admin/audio-library');
        if (!$allowed) {
            self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
            self::assertFalse($db->isConnected());
            return;
        }

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bibliothèque MP3');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertCount(1, $crawler->filter('nav a.active[href="/admin/audio-library"]'));
        self::assertCount(0, $crawler->filter('nav a[href="/admin/audio-library/inventory"]'));
        $inventoryLink = $crawler->selectLink('Inventaire MP3');
        self::assertCount(1, $inventoryLink);
        $crawler = $client->click($inventoryLink->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inventaire MP3');
        self::assertCount(1, $crawler->filter('nav a.active[href="/admin/audio-library"]'));
        self::assertFalse($db->isConnected());
    }

    public static function roles(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => ['ROLE_USER'];
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'super admin' => ['ROLE_SUPER_ADMIN'];
    }
}
