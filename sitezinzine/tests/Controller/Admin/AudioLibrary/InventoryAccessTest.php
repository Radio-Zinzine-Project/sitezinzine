<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Service\AudioLibrary\Inventory\AudioInventoryReportDiscovery;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Real router, firewall, role hierarchy and IsGranted; no database user fixture. */
final class InventoryAccessTest extends WebTestCase
{
    /** @dataProvider accessCases */
    public function testRealAuthorizationPrecedesReportReads(?string $role, string $uri, bool $allowed): void
    {
        $client = static::createClient(['environment' => 'test', 'debug' => true]);
        $container = self::getContainer();
        $detail = $allowed && $uri !== '/admin/audio-library/inventory';
        $reader = $this->createMock(AudioInventoryReportDiscovery::class);
        $reader->expects($allowed ? self::once() : self::never())->method('getProfiles')->willReturn($detail ? ['fixture'] : []);
        foreach (['discover', 'readPage'] as $method) { $reader->expects(self::never())->method($method); }
        if ($detail) {
            $reader->expects(self::once())->method('readManifest')->willReturn(['status' => 'published', 'inventory_date' => null, 'manifest' => [
                'inventory_id' => str_repeat('a', 32), 'page_count' => 0, 'result_count' => 0,
                'classification_counts' => [], 'report' => ['scope' => 'test_subset', 'preparation' => [],
                    'restrictions' => ['references_complete' => true, 'physical_collection_complete' => true]]]]);
        } else { $reader->expects(self::never())->method('readManifest'); }
        $container->set(AudioInventoryReportDiscovery::class, $reader);
        if ($role !== null) {
            // Replace only identity refresh, not authorization or the firewall.
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn ($user) => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $user = (new User())->setUsername('inventory-test')->setPassword('synthetic')->setRoles([$role])->setVerified(true)->setApproved(true);
            $client->loginUser($user);
        }
        $db = $container->get('doctrine')->getConnection(); self::assertFalse($db->isConnected());
        $client->request('GET', $uri);
        $response = $client->getResponse();
        if ($allowed) {
            self::assertSame(200, $response->getStatusCode()); self::assertStringContainsString($detail ? 'Aucun résultat' : 'Aucun profil', $response->getContent());
            self::assertStringContainsString('Inventaire MP3', $response->getContent());
        } else {
            self::assertContains($response->getStatusCode(), [302, 403]);
        }
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($db->isConnected());
    }

    public static function accessCases(): array
    {
        $index = '/admin/audio-library/inventory';
        $detail = $index.'/fixture/'.str_repeat('a', 32);
        return [[null, $index, false], ['ROLE_USER', $index, false], ['ROLE_ADMIN', $index, false],
            ['ROLE_USER', $detail, false], ['ROLE_ADMIN', $detail, false], ['ROLE_SUPER_ADMIN', $index, true], ['ROLE_SUPER_ADMIN', $detail, true]];
    }
}
