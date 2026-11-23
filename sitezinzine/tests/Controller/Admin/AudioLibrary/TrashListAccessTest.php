<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TrashListAccessTest extends WebTestCase
{
    /** @dataProvider roles */
    public function testTrashListAccess(?string $role): void
    {
        $client = self::createClient(['environment' => 'test', 'debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        $db = $this->createMock(Connection::class);
        $allowed = $role === 'ROLE_SUPER_ADMIN';
        $calls = 0;
        $db->expects($allowed ? self::exactly(4) : self::never())->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params, array $types) use (&$calls): array {
                self::assertStringStartsWith('SELECT', ltrim($sql));
                self::assertStringContainsString('LEFT JOIN emission e ON e.id = j.emission_id', $sql);
                self::assertStringContainsString('ORDER BY j.created_at DESC, j.id DESC LIMIT :limit OFFSET :offset', $sql);
                self::assertStringNotContainsString('sha256', $sql);
                self::assertSame(26, $params['limit']);
                self::assertSame(ParameterType::INTEGER, $types['limit']);
                ++$calls;
                if ($calls === 1) {
                    return [];
                }
                $row = [
                    'id' => str_repeat('a', 32), 'emission_id' => 42, 'created_at' => '2026-11-19 14:00:00.000000',
                    'phase' => 'EN_CORBEILLE', 'blocked' => 0, 'last_diagnostic_code' => null,
                    'current_emission_id' => 42, 'emission_title' => 'Émission témoin', 'deleted_at' => null,
                ];
                if ($calls === 2) {
                    self::assertSame(['limit' => 26, 'offset' => 0], $params);
                    return [$row, array_replace($row, ['phase' => 'ANNULEE', 'deleted_at' => '2026-11-19']),
                        array_replace($row, ['phase' => 'SUPPRESSION_PREPAREE', 'current_emission_id' => null,
                            'blocked' => 1, 'last_diagnostic_code' => 'public_file_missing'])];
                }
                self::assertStringContainsString('WHERE j.phase = :phase AND j.blocked = :blocked', $sql);
                self::assertSame(['phase' => 'EN_CORBEILLE', 'blocked' => 0, 'limit' => 26,
                    'offset' => $calls === 3 ? 0 : 25], $params);
                self::assertSame(ParameterType::INTEGER, $types['blocked']);
                return array_fill(0, $calls === 3 ? 26 : 1, $row);
            },
        );
        $db->expects(self::never())->method('executeStatement');
        $db->expects(self::never())->method('beginTransaction');
        foreach (['insert', 'update', 'delete'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $container->set('doctrine.dbal.default_connection', $db);
        if ($role !== null) {
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $client->loginUser((new User())->setUsername('trash-list-test')->setPassword('synthetic')
                ->setRoles([$role])->setVerified(true)->setApproved(true));
        }

        if (!$allowed) {
            $client->request('GET', '/admin/audio-library/trash');
            self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
            return;
        }
        $library = $client->request('GET', '/admin/audio-library');
        $link = $library->selectLink('Corbeille MP3');
        self::assertCount(1, $link);
        $client->click($link->link());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('h1', 'Corbeille MP3');
        self::assertSelectorTextContains('.main-content', 'Aucune opération');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $crawler = $client->request('GET', '/admin/audio-library/trash');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        foreach (['Émission témoin', 'Émission supprimée', 'Émission introuvable', 'En corbeille', 'Annulée',
            'Suppression préparée', 'public_file_missing', '19/11/2026 15:00'] as $label) {
            self::assertSelectorTextContains('tbody', $label);
        }
        self::assertCount(0, $crawler->filter('form[method="post"]'));
        $crawler = $client->request('GET', '/admin/audio-library/trash?phase=EN_CORBEILLE&blocked=0');
        self::assertCount(25, $crawler->filter('tbody tr'));
        $next = $crawler->selectLink('Suivant')->link();
        $crawler = $client->click($next);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('tbody tr'));
        $detail = $crawler->filter('tbody a')->link()->getUri();
        self::assertSame('/admin/audio-library/trash/' . str_repeat('a', 32), parse_url($detail, PHP_URL_PATH));
        parse_str((string) parse_url($detail, PHP_URL_QUERY), $detailQuery);
        self::assertSame(['phase' => 'EN_CORBEILLE', 'blocked' => '0', 'page' => '2'], $detailQuery);
        self::assertCount(0, $crawler->selectLink('Suivant'));
        $previous = $crawler->selectLink('Précédent')->link()->getUri();
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);
        self::assertSame(['phase' => 'EN_CORBEILLE', 'blocked' => '0', 'page' => '1'], $query);

        foreach (['phase[]=invalid', 'phase=invalid', 'blocked=2', 'page=0'] as $invalid) {
            $client->request('GET', '/admin/audio-library/trash?' . $invalid);
            self::assertSame(400, $client->getResponse()->getStatusCode());
        }
    }

    public static function roles(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => ['ROLE_USER'];
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'super admin' => ['ROLE_SUPER_ADMIN'];
    }
}
