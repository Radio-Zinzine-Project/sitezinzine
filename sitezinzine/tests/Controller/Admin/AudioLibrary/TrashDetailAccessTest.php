<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Service\AudioLibrary\Mp3TrashDetail;
use App\Service\{Mp3DiskIo, Mp3TrashDisk, Mp3TrashInspection, Mp3TrashPath};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TrashDetailAccessTest extends WebTestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    /** @dataProvider roles */
    public function testDetailAccess(?string $role): void
    {
        $client = self::createClient(['environment' => 'test', 'debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');
        $container->set('doctrine.dbal.default_connection', $db);
        if ($role !== null) {
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $client->loginUser((new User())->setUsername('trash-detail-test')->setPassword('synthetic')
                ->setRoles([$role])->setVerified(true)->setApproved(true));
        }

        $root = Mp3TestStorage::root() . '/detail-' . bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir([$root . '/library', $root . '/trash', $root . '/work', $root . '/public'], 0700);
        $public = $root . '/library/source.mp3';
        $private = $root . '/trash/' . self::ID . '.mp3';
        $base = 'https://example.test/uploads/emissionsMp3';
        $paths = new Mp3TrashPath($root . '/trash', $root . '/library', $root . '/work', $root . '/public');
        $io = $this->createMock(Mp3DiskIo::class);
        $io->expects(self::never())->method('link');
        $io->expects(self::never())->method('unlink');
        $row = [
            'id' => self::ID, 'emission_id' => 42, 'phase' => 'SUPPRESSION_PREPAREE', 'blocked' => 0,
            'last_diagnostic_code' => null, 'last_diagnostic_at' => null, 'original_thumbnail_mp3' => 'source.mp3',
            'original_url' => $base . '/source.mp3', 'size_bytes' => 9, 'sha256' => hash('sha256', 'synthetic'),
            'last_attempt_id' => null, 'restoration_attempts' => '[]', 'current_id' => 42, 'deleted_at' => null,
            'current_path' => 'source.mp3', 'current_url' => $base . '/source.mp3', 'reference_count' => 0, 'reservation_count' => 0,
        ];
        $responseRow = false;
        $connections = 0;
        $detail = new Mp3TrashDetail(new Mp3TrashInspection(new Mp3TrashDisk($root . '/library', $paths, $io), $base),
            function () use (&$connections, &$responseRow, $base): Connection {
                ++$connections;
                $fresh = $this->createMock(Connection::class);
                $fresh->expects(self::once())->method('fetchAssociative')->with(
                    self::callback(static fn (string $sql): bool => str_starts_with(ltrim($sql), 'SELECT')),
                    [$base . '/', self::ID],
                )->willReturnCallback(static function () use ($responseRow): array|false {
                    if ($responseRow instanceof ConnectionException) {
                        throw $responseRow;
                    }
                    return $responseRow;
                });
                $fresh->expects(is_array($responseRow) ? self::once() : self::never())->method('fetchOne')
                    ->with('SELECT created_at FROM mp3_trash_operation WHERE id = ?', [self::ID])->willReturn('2026-11-19 14:00:00.000000');
                foreach (['executeStatement', 'insert', 'update', 'delete', 'beginTransaction'] as $method) {
                    $fresh->expects(self::never())->method($method);
                }
                $fresh->expects(self::once())->method('close');
                return $fresh;
            },
        );
        $container->set(Mp3TrashDetail::class, $detail);
        $uri = '/admin/audio-library/trash/' . self::ID;
        try {
            $client->request('GET', $uri);
            if ($role !== 'ROLE_SUPER_ADMIN') {
                self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
                self::assertSame(0, $connections);
                return;
            }
            self::assertSame(404, $client->getResponse()->getStatusCode());
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
            $client->request('GET', '/admin/audio-library/trash/invalid');
            self::assertSame(404, $client->getResponse()->getStatusCode());
            self::assertSame(1, $connections);

            $responseRow = $row;
            file_put_contents($public, 'synthetic');
            $crawler = $client->request('GET', $uri . '?phase=SUPPRESSION_PREPAREE&blocked=0&page=2');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            foreach (['État intermédiaire cohérent', 'Absence confirmée', '19/11/2026 15:00', 'Aucune tentative enregistrée'] as $text) {
                self::assertSelectorTextContains('.main-content', $text);
            }
            $back = $crawler->selectLink('Retour à la corbeille MP3')->link()->getUri();
            parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
            self::assertSame(['phase' => 'SUPPRESSION_PREPAREE', 'blocked' => '0', 'page' => '2'], $query);
            $history = $crawler->selectLink('Historique administratif')->link()->getUri();
            self::assertSame($uri . '/history', parse_url($history, PHP_URL_PATH));
            parse_str((string) parse_url($history, PHP_URL_QUERY), $historyQuery);
            self::assertSame($query, $historyQuery);
            self::assertCount(0, $crawler->filter('form[method="post"]'));
            self::assertSame('synthetic', file_get_contents($public));

            file_put_contents($public, 'incorrect');
            $client->request('GET', $uri);
            self::assertSelectorTextContains('.main-content', 'Conflit détecté');
            self::assertSelectorTextContains('.main-content', 'Contenu différent du journal');
            self::assertSame('incorrect', file_get_contents($public));

            self::assertTrue(symlink($public, $private));
            $attempt = str_repeat('a', 32);
            $responseRow = array_replace($row, ['blocked' => 1, 'last_attempt_id' => $attempt,
                'last_diagnostic_at' => '2026-11-19 14:00:00.123456', 'deleted_at' => '2026-11-19']);
            $client->request('GET', $uri);
            foreach (['Lien symbolique', 'Bloquée', 'Émission supprimée', $attempt, '2026-11-19 14:00:00.123456'] as $text) {
                self::assertSelectorTextContains('.main-content', $text);
            }
            self::assertSame($public, readlink($private));
            self::assertStringNotContainsString($root, $client->getResponse()->getContent());

            $responseRow = array_replace($row, ['current_id' => null, 'original_thumbnail_mp3' => 'missing/source.mp3']);
            $client->request('GET', $uri);
            self::assertSelectorTextContains('.main-content', 'Émission introuvable');
            self::assertSelectorTextContains('.main-content', 'Présence indéterminée');

            $responseRow = new ConnectionException(new \Doctrine\DBAL\Driver\PDO\Exception('unavailable'), null);
            $client->request('GET', $uri);
            self::assertSame(503, $client->getResponse()->getStatusCode());
            self::assertSelectorTextContains('.main-content', 'Inspection indéterminée');
            self::assertSelectorTextNotContains('.main-content', 'Opération de corbeille introuvable');
            self::assertSame(6, $connections);
            self::assertFileDoesNotExist($root . '/trash/.storage.lock');
        } finally {
            $fs->remove($root);
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
