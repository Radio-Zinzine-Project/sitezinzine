<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CleanupIssueListAccessTest extends WebTestCase
{
    private const URI = '/admin/audio-library/cleanup-issues';

    private const UNSAFE_PATHS = [
        24 => '/private/root/hidden.mp3',
        23 => 'C:\private\windows-secret.mp3',
        22 => '\\\\server\share\unc-secret.mp3',
        21 => 'TST/../traversal-secret.mp3',
        20 => '',
        19 => 'TST//invalid-secret.mp3',
    ];

    /** @dataProvider roles */
    public function testCleanupIssueList(?string $role): void
    {
        $client = self::createClient(['debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        $db = $this->createMock(Connection::class);
        $allowed = $role === 'ROLE_SUPER_ADMIN';
        $offsets = [];
        $db->expects($allowed ? self::exactly(3) : self::never())->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $parameters, array $types) use (&$offsets): array {
                self::assertSame(
                    'SELECT id, storage, obsolete_relative_path, status, created_at FROM mp3_cleanup_issue'
                    . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
                    trim((string) preg_replace('/\s+/', ' ', $sql)),
                );
                self::assertSame(26, $parameters['limit']);
                self::assertSame(['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER], $types);
                $offsets[] = $parameters['offset'];
                if (count($offsets) === 1) {
                    return [];
                }
                $ids = $parameters['offset'] === 0 ? range(26, 1) : [1];

                return array_map(static fn (int $id): array => [
                    'id' => $id,
                    'storage' => 'emissionsMp3',
                    'obsolete_relative_path' => self::UNSAFE_PATHS[$id] ?? 'TST/2026/incident-' . $id . '.mp3',
                    'status' => match ($id) { 26 => 'open', 25 => 'investigating', default => 'resolved' },
                    'created_at' => '2026-11-19 14:00:00.000000',
                ], $ids);
            },
        );
        foreach (['executeStatement', 'beginTransaction', 'insert', 'update', 'delete'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $container->set('doctrine.dbal.default_connection', $db);
        if ($role !== null) {
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $client->loginUser((new User())->setUsername('cleanup-list-test')->setPassword('synthetic')
                ->setRoles([$role])->setVerified(true)->setApproved(true));
        }

        if (!$allowed) {
            $client->request('GET', self::URI);
            if ($role === null) {
                self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
            } else {
                self::assertResponseRedirects('/access-denied');
            }

            return;
        }

        $audioDirectories = [Mp3TestStorage::library(), Mp3TestStorage::work(), Mp3TestStorage::root() . '/trash'];
        $initialEntries = array_map('scandir', $audioDirectories);
        $library = $client->request('GET', '/admin/audio-library');
        $link = $library->selectLink('Incidents de nettoyage MP3');
        self::assertCount(1, $link);
        self::assertSame(self::URI, parse_url($link->link()->getUri(), PHP_URL_PATH));
        $client->click($link->link());
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Incidents de nettoyage MP3');
        self::assertSelectorTextContains('.main-content', 'Aucun incident de nettoyage MP3');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $page = $client->request('GET', self::URI);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(25, $page->filter('tbody tr'));
        self::assertSame(array_map('strval', range(26, 2)),
            $page->filter('tbody tr td:first-child')->each(static fn ($cell): string => $cell->text()));
        foreach (['Ouvert', 'En cours', "R\u{00E9}solu", 'TST/2026/incident-26.mp3', '19/11/2026 15:00'] as $label) {
            self::assertSelectorTextContains('tbody', $label);
        }
        foreach (self::UNSAFE_PATHS as $id => $unsafe) {
            self::assertSame('Chemin indisponible', $page->filter('tbody tr')->eq(26 - $id)->filter('code')->text());
            if ($unsafe !== '') {
                $filename = basename(str_replace('\\', '/', $unsafe));
                self::assertStringNotContainsString($filename, $client->getResponse()->getContent());
            }
        }
        $page = $client->click($page->selectLink('Suivant')->link());
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('tbody tr'));
        self::assertSame('1', $page->filter('tbody tr td:first-child')->text());
        self::assertCount(1, $page->selectLink("Pr\u{00E9}c\u{00E9}dent"));
        self::assertCount(0, $page->selectLink('Suivant'));
        self::assertCount(1, $page->filter('tbody a[href="/admin/audio-library/cleanup-issues/1"]'));
        self::assertCount(0, $page->filter('form[method="post"]'));

        foreach (['page=0', 'page[]=2'] as $invalid) {
            $client->request('GET', self::URI . '?' . $invalid);
            self::assertResponseStatusCodeSame(400);
        }
        $client->request('POST', self::URI);
        self::assertResponseStatusCodeSame(405);
        self::assertSame([0, 0, 25], $offsets);
        self::assertSame($initialEntries, array_map('scandir', $audioDirectories));
    }

    public static function roles(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => ['ROLE_USER'];
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'super admin' => ['ROLE_SUPER_ADMIN'];
    }
}
