<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TrashHistoryAccessTest extends WebTestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const UNKNOWN = 'fedcba9876543210fedcba9876543210';

    /** @dataProvider roles */
    public function testHistoryAccessAndNavigation(?string $role): void
    {
        $client = self::createClient(['environment' => 'test', 'debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        $allowed = $role === 'ROLE_SUPER_ADMIN';
        $db = $this->createMock(Connection::class);
        $db->expects($allowed ? self::exactly(5) : self::never())->method('fetchOne')
            ->with('SELECT id FROM mp3_trash_operation WHERE id = ?', self::callback(
                static fn (array $ids): bool => $ids === [self::ID] || $ids === [self::UNKNOWN],
            ))->willReturnCallback(static fn (string $sql, array $ids) => $ids === [self::ID] ? self::ID : false);
        $scenario = 'empty';
        $db->expects($allowed ? self::exactly(4) : self::never())->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params) use (&$scenario): array {
                self::assertStringStartsWith('SELECT', ltrim($sql));
                self::assertSame(self::ID, $params['operation']);
                self::assertSame(26, $params['limit']);
                if ($scenario === 'empty') {
                    return [];
                }
                if ($scenario === 'unavailable') {
                    throw new ConnectionException(new \Doctrine\DBAL\Driver\PDO\Exception('unavailable'), null);
                }
                $row = [
                    'id' => 30, 'request_id' => str_repeat('a', 32), 'actor_id' => 17, 'actor_name' => 'Admin témoin',
                    'attempt_id' => str_repeat('c', 32), 'action' => 'resume_restoration', 'expected_phase' => 'RESTAURATION_PREPAREE',
                    'observed_phase' => null, 'event_type' => 'request', 'result_code' => 'requested',
                    'recorded_at' => '2026-11-19 14:00:00.000000',
                ];
                if ($scenario === 'older') {
                    self::assertSame('6', $params['cursor']);
                    return [array_replace($row, ['id' => 5])];
                }
                return array_map(static fn (int $id): array => array_replace($row, [
                    'id' => $id,
                    'event_type' => match ($id) { 30 => 'uncertain', 29 => 'failure', 28 => 'refusal', 27 => 'transition_confirmed', default => 'request' },
                    'observed_phase' => $id === 27 ? 'RESTAUREE' : null,
                    'request_id' => str_repeat($id < 28 ? 'b' : 'a', 32),
                    'actor_name' => $id === 28 ? null : $row['actor_name'],
                ]), range(30, 5));
            });
        foreach (['fetchAssociative', 'executeStatement', 'insert', 'update', 'delete', 'beginTransaction'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $container->set('doctrine.dbal.default_connection', $db);
        if ($role !== null) {
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $client->loginUser((new User())->setUsername('trash-history-test')->setPassword('synthetic')
                ->setRoles([$role])->setVerified(true)->setApproved(true));
        }

        $uri = '/admin/audio-library/trash/' . self::ID . '/history';
        $client->request('GET', $uri);
        if (!$allowed) {
            self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
            return;
        }
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSelectorTextContains('.main-content', 'Aucun événement administratif enregistré');
        $client->request('GET', '/admin/audio-library/trash/' . self::UNKNOWN . '/history');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', '/admin/audio-library/trash/invalid/history');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        foreach (['before[]=2', 'before=18446744073709551616', 'before=2&after=3'] as $invalid) {
            $client->request('GET', $uri . '?' . $invalid);
            self::assertSame(400, $client->getResponse()->getStatusCode());
        }

        $scenario = 'populated';
        $crawler = $client->request('GET', $uri . '?phase=EN_CORBEILLE&blocked=0&page=2&ignored=x');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(25, $crawler->filter('tbody th[scope="row"]'));
        self::assertCount(2, $crawler->filter('tbody tr.table-light'));
        self::assertSame('#30', $crawler->filter('tbody th[scope="row"]')->first()->text());
        foreach (['Demande enregistrée', 'Transition confirmée', 'Refus', 'Échec', 'Issue incertaine',
            'Compte introuvable', '#17', 'Admin témoin', '19/11/2026 15:00:00', str_repeat('c', 32)] as $label) {
            self::assertSelectorTextContains('tbody', $label);
        }
        self::assertCount(0, $crawler->filter('form[method="post"]'));
        $scenario = 'older';
        $crawler = $client->click($crawler->selectLink('Plus anciens')->link());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('tbody th[scope="row"]'));
        self::assertCount(0, $crawler->selectLink('Plus anciens'));
        $back = $crawler->selectLink('Retour au détail de l’opération')->link()->getUri();
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        self::assertSame(['phase' => 'EN_CORBEILLE', 'blocked' => '0', 'page' => '2'], $query);

        $scenario = 'unavailable';
        $client->request('GET', $uri . '?phase=invalide&blocked=2&page=0');
        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('.main-content', 'Le journal administratif est indisponible');
        self::assertCount(1, $client->getCrawler()->filter('.main-content a[href="/admin/audio-library/trash/' . self::ID . '"]'));
        self::assertFileDoesNotExist(Mp3TestStorage::root() . '/trash/.storage.lock');
    }

    public static function roles(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => ['ROLE_USER'];
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'super admin' => ['ROLE_SUPER_ADMIN'];
    }
}
