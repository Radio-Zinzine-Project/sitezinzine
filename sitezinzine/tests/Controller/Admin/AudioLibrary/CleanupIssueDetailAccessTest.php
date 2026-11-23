<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupAction;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupResult;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueReader;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CleanupIssueDetailAccessTest extends WebTestCase
{
    private const URI = '/admin/audio-library/cleanup-issues/17';

    /** @dataProvider roles */
    public function testCleanupIssueDetail(?string $role, bool $impersonated = false): void
    {
        $client = self::createClient(['debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        $allowed = $role === 'ROLE_SUPER_ADMIN';
        $db = $this->createMock(Connection::class);
        $db->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'dbname' => 'symfony_test']);
        $detailDb = $this->createMock(Connection::class);
        $container->set('doctrine.dbal.default_connection', $db);
        $cleanup = $this->createMock(Mp3DeferredCleanupAction::class);
        $cleanup->expects(self::never())->method('remove');
        $cleanup->expects(self::never())->method('closeIncident');
        $cleanup->method('read')->willReturn(['attempts' => [], 'active' => null, 'diagnostic' => null, 'configured' => false]);
        $container->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER_ARGUMENTS,
            static function (ControllerArgumentsEvent $event) use ($detailDb, $cleanup): void {
                $arguments = $event->getArguments();
                foreach ($arguments as &$argument) {
                    if ($argument instanceof Mp3CleanupIssueReader) {
                        $argument = new Mp3CleanupIssueReader($detailDb);
                    } elseif ($argument instanceof Mp3DeferredCleanupAction) {
                        $argument = $cleanup;
                    }
                }
                $event->setArguments($arguments);
            });
        $db->expects($allowed ? self::once() : self::never())->method('fetchAllAssociative')->willReturn([self::issue()]);
        $detailDb->expects($allowed ? self::exactly(4) : self::never())->method('executeStatement')
            ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $detailDb->expects($allowed ? self::exactly(4) : self::never())->method('beginTransaction');
        $detailDb->expects($allowed ? self::exactly(4) : self::never())->method('commit');
        $detailDb->expects($allowed ? self::exactly(3) : self::never())->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql, array $params): array|false {
                self::assertStringContainsString('FROM mp3_cleanup_issue WHERE id = ?', $sql);
                return $params === [17] ? self::issue() : false;
            });
        $queries = [];
        $detailDb->expects($allowed ? self::exactly(3) : self::never())->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params) use (&$queries): array {
                self::assertStringContainsString('ORDER BY id DESC LIMIT 21', $sql);
                $queries[] = $params;
                $ids = $params === [17] ? range(25, 5) : [5, 4];

                return array_map(static fn (int $id): array => [
                    'id' => $id,
                    'occurred_at' => '2026-11-19 15:00:00.000000',
                    'reason' => 'obsolete_cleanup_failed',
                    'emission_id' => 42,
                    'obsolete_relative_path' => self::issue()['obsolete_relative_path'],
                    'context' => json_encode([
                        'replacement_relative_path' => $id === 24 ? '/private/root/hidden.mp3' : 'TST/2026/new.mp3',
                        'commit_confirmed' => true,
                        'reference_count' => $id === 25 ? 0 : null,
                        'private' => 'secret-context',
                    ], JSON_THROW_ON_ERROR),
                    'diagnostic' => '{"class":"RuntimeException","code":0,"sql_state":null,"message":"secret-diagnostic"}',
                ], $ids);
            });
        foreach (['insert', 'update', 'delete'] as $method) {
            $detailDb->expects(self::never())->method($method);
            $db->expects(self::never())->method($method);
        }
        $db->expects(self::never())->method('executeStatement');

        if ($role !== null) {
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $user = (new User())->setUsername('cleanup-detail-test')->setPassword('synthetic')
                ->setRoles([$role])->setVerified(true)->setApproved(true);
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, 123);
            $client->loginUser($user);
            if ($impersonated) {
                $session = $container->get('session.factory')->createSession();
                $session->setId($client->getCookieJar()->get($session->getName())->getValue());
                $original = new UsernamePasswordToken($user, 'main', $user->getRoles());
                $session->set('_security_main', serialize(new SwitchUserToken($user, 'main', $user->getRoles(), $original)));
                $session->save();
            }
        }

        if (!$allowed) {
            $client->request('GET', self::URI);
            if ($role === null) {
                self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
            } else {
                self::assertResponseRedirects('/access-denied');
            }

            $client->request('POST', self::URI . '/cleanup');
            self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);

            $client->request('POST', self::URI . '/close');
            self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);

            return;
        }

        if ($impersonated) {
            $client->request('POST', self::URI . '/close');
            self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
        }

        $directories = [Mp3TestStorage::library(), Mp3TestStorage::work(), Mp3TestStorage::root() . '/trash'];
        $entries = array_map('scandir', $directories);
        $list = $client->request('GET', '/admin/audio-library/cleanup-issues');
        $link = $list->filter('tbody')->selectLink('17');
        self::assertCount(1, $link);
        self::assertSame(self::URI, parse_url($link->link()->getUri(), PHP_URL_PATH));
        $page = $client->click($link->link());
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        foreach (['Incident de nettoyage MP3', '17', 'En cours', 'emissionsMp3', 'TST/2026/old.mp3',
            '19/11/2026 15:00', 'TST/2026/new.mp3', '42', 'Inconnu'] as $label) {
            self::assertSelectorTextContains('.main-content', $label);
        }
        self::assertSame(array_map('strval', range(25, 6)),
            $page->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame('Chemin indisponible', $page->filter('tbody tr')->eq(1)->filter('code')->text());
        foreach (['secret-context', 'secret-diagnostic', 'hidden.mp3'] as $hidden) {
            self::assertStringNotContainsString($hidden, $client->getResponse()->getContent());
        }
        self::assertCount(0, $page->filter('form'));
        self::assertCount(1, $page->filter('a[href="/admin/audio-library/cleanup-issues"]'));

        $older = $client->click($page->selectLink('Événements plus anciens')->link());
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['5', '4'],
            $older->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertCount(0, $older->selectLink('Événements plus anciens'));
        $client->request('GET', self::URI . '?before=2');
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/admin/audio-library/cleanup-issues/99');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', self::URI);
        self::assertResponseStatusCodeSame(405);
        self::assertSame([[17], [17], [17, 25, 6]], $queries);
        self::assertSame($entries, array_map('scandir', $directories));
    }

    public function testClosureRequiresPost(): void
    {
        $client = self::createClient();
        $client->request('GET', self::URI . '/close');
        self::assertSame(405, $client->getResponse()->getStatusCode());
    }

    public function testCleanupActionRequiresPost(): void
    {
        $client = self::createClient();
        $client->request('GET', self::URI . '/cleanup');
        self::assertSame(405, $client->getResponse()->getStatusCode());
    }

    public function testConfirmedCleanupAndReadOnlyDiagnostic(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = self::getContainer();
        $db = $this->createMock(Connection::class);
        $db->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'dbname' => 'symfony_test']);
        $container->set('doctrine.dbal.default_connection', $db);
        $detailDb = $this->createMock(Connection::class);
        $detailDb->method('fetchAssociative')->willReturn(self::issue());
        $detailDb->method('fetchAllAssociative')->willReturn([[
            'id' => 25, 'occurred_at' => '2026-11-19 15:00:00', 'reason' => 'obsolete_cleanup_failed',
            'emission_id' => 42, 'obsolete_relative_path' => self::issue()['obsolete_relative_path'],
            'context' => '{"replacement_relative_path":null,"commit_confirmed":true,"reference_count":0}',
            'diagnostic' => '{"class":"RuntimeException","code":0,"sql_state":null}',
        ]]);
        foreach (['insert', 'update', 'delete'] as $method) {
            $detailDb->expects(self::never())->method($method);
            $db->expects(self::never())->method($method);
        }
        $db->expects(self::never())->method('executeStatement');

        $provider = $this->createMock(EntityUserProvider::class);
        $provider->method('supportsClass')->willReturn(true);
        $provider->method('refreshUser')->willReturnArgument(0);
        $container->set('security.user.provider.concrete.app_user_provider', $provider);
        $actor = (new User())->setUsername('cleanup-action-test')->setPassword('synthetic')
            ->setRoles(['ROLE_SUPER_ADMIN'])->setVerified(true)->setApproved(true);
        (new \ReflectionProperty(User::class, 'id'))->setValue($actor, 123);
        $client->loginUser($actor);

        $cleanup = $this->createMock(Mp3DeferredCleanupAction::class);
        $active = null;
        $cleanup->method('read')->willReturnCallback(static function (int $issueId, ?string $attempt) use (&$active): array {
            self::assertSame(17, $issueId);
            return ['attempts' => $active === null ? [] : [[
                'request_id' => $active, 'requested_at' => '2026-11-19 15:00:00',
                'state' => 'pending', 'result_code' => null,
            ]], 'active' => $active, 'diagnostic' => $attempt === null ? null : ['observation' => 'absent_now'], 'configured' => true];
        });
        $cleanup->expects(self::exactly(2))->method('closeIncident')
            ->with(17, 25, 123, 'Verified absence')->willReturnOnConsecutiveCalls('allowed', 'already_resolved');
        $requestId = null;
        $results = [
            Mp3DeferredCleanupResult::Removed, Mp3DeferredCleanupResult::DuplicateOrActiveRequest,
            Mp3DeferredCleanupResult::StaleView, Mp3DeferredCleanupResult::AlreadyAbsent,
            Mp3DeferredCleanupResult::ReferenceActive, Mp3DeferredCleanupResult::OutcomeUncertain,
        ];
        $cleanup->expects(self::exactly(count($results)))->method('remove')
            ->willReturnCallback(static function (int $issueId, int $eventId, string $id, int $actorId) use (&$requestId, &$results, &$active): Mp3DeferredCleanupResult {
                self::assertSame([17, 25, $requestId, 123], [$issueId, $eventId, $id, $actorId]);
                $result = array_shift($results);
                if ($result === Mp3DeferredCleanupResult::OutcomeUncertain) {
                    $active = $id;
                }

                return $result;
            });
        $container->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER_ARGUMENTS,
            static function (ControllerArgumentsEvent $event) use ($detailDb, $cleanup): void {
                $arguments = $event->getArguments();
                foreach ($arguments as &$argument) {
                    if ($argument instanceof Mp3CleanupIssueReader) {
                        $argument = new Mp3CleanupIssueReader($detailDb);
                    } elseif ($argument instanceof Mp3DeferredCleanupAction) {
                        $argument = $cleanup;
                    }
                }
                $event->setArguments($arguments);
            });

        $page = $client->request('GET', self::URI);
        self::assertResponseIsSuccessful();
        $close = $page->filter('form[action$="/close"]')->form()->getValues();
        $close['confirmed'] = '1';
        $client->request('POST', self::URI . '/close', $close);
        self::assertResponseStatusCodeSame(422);
        $close['reason'] = 'Verified absence';
        $client->request('POST', self::URI . '/close', ['_token' => 'invalid'] + $close);
        self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
        foreach (['est confirm', 'est d'] as $expected) {
            $client->request('POST', self::URI . '/close', ['actor_id' => '999'] + $close);
            self::assertResponseRedirects(self::URI, 303);
            $messages = $client->getRequest()->getSession()->getFlashBag()->peekAll();
            self::assertStringContainsString($expected, implode(' ', array_merge(...array_values($messages))));
        }
        $data = $page->selectButton('Demander le nettoyage')->form()->getValues();
        $requestId = $data['request_id'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $requestId);
        self::assertCount(1, $page->filter('form[action$="/cleanup"] input[name="confirmed"][required]'));
        self::assertCount(1, $page->filter('form[action$="/close"] input[name="confirmed"][required]'));
        $client->request('POST', self::URI . '/cleanup', $data);
        self::assertResponseStatusCodeSame(422);
        $data['confirmed'] = '1';
        $client->request('POST', self::URI . '/cleanup', ['event_id' => '26'] + $data);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', self::URI . '/cleanup', ['_token' => 'invalid'] + $data);
        self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);

        foreach (['Retrait du fichier', 'Cette demande existe', 'incident a chang', 'Fichier d',
            'encore r', 'sultat du nettoyage est incertain'] as $index => $message) {
            if ($index >= 2) {
                $page = $client->request('GET', self::URI);
                $data = $page->selectButton('Demander le nettoyage')->form()->getValues();
                $data['confirmed'] = '1';
                self::assertNotSame($requestId, $data['request_id']);
                $requestId = $data['request_id'];
            }
            $client->request('POST', self::URI . '/cleanup', ['actor_id' => '999'] + $data);
            self::assertResponseRedirects(self::URI, 303);
            $messages = $client->getRequest()->getSession()->getFlashBag()->peekAll();
            self::assertStringContainsString($message, implode(' ', array_merge(...array_values($messages))));
        }
        $page = $client->request('GET', self::URI);
        self::assertSelectorTextContains('section[aria-labelledby="cleanup-attempts-title"]', 'bloque toute nouvelle demande');
        self::assertCount(0, $page->selectButton('Demander le nettoyage'));
        $page = $client->clickLink('Consulter son diagnostic');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Le fichier est absent au moment de la consultation.', $page->text());
        self::assertCount(0, $page->selectButton('Demander le nettoyage'));
    }

    private static function issue(): array
    {
        return [
            'id' => 17, 'status' => 'investigating', 'storage' => 'emissionsMp3',
            'obsolete_relative_path' => 'TST/2026/old.mp3',
            'created_at' => '2026-11-19 14:00:00.000000', 'resolved_at' => null,
        ];
    }

    public static function roles(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => ['ROLE_USER'];
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'super admin' => ['ROLE_SUPER_ADMIN'];
        yield 'impersonated super admin' => ['ROLE_SUPER_ADMIN', true];
    }
}
