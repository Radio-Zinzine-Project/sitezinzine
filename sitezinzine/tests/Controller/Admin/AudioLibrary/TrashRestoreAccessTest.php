<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Enum\Mp3TrashConfirmationAction;
use App\Exception\Mp3RestoreFailure;
use App\Service\AudioLibrary\Mp3RestoreAction;
use App\Service\AudioLibrary\Mp3TrashConfirmationStore;
use App\Service\AudioLibrary\Mp3TrashDetail;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class TrashRestoreAccessTest extends WebTestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    /** @dataProvider accessCases */
    public function testRestoreConfirmationUsesTheObservedLastAttempt(string $role, bool $impersonated): void
    {
        $client = self::createClient(['environment' => 'test', 'debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        $provider = $this->createMock(EntityUserProvider::class);
        $provider->method('supportsClass')->willReturn(true);
        $provider->method('refreshUser')->willReturnCallback(static fn ($user) => $user);
        $container->set('security.user.provider.concrete.app_user_provider', $provider);
        $actor = (new User())->setUsername('restore-http-test')->setPassword('synthetic')
            ->setRoles([$role])->setVerified(true)->setApproved(true);
        (new \ReflectionProperty(User::class, 'id'))->setValue($actor, 42);
        $client->loginUser($actor);
        if ($impersonated) {
            $session = $client->getSession();
            $token = new PostAuthenticationToken($actor, 'main', $actor->getRoles());
            $session->set('_security_main', serialize(new SwitchUserToken($actor, 'main', $actor->getRoles(), $token)));
            $session->save();
        }
        $allowed = $role === 'ROLE_SUPER_ADMIN' && !$impersonated;
        $db = $this->createMock(Connection::class);
        foreach (['fetchAssociative', 'fetchOne', 'beginTransaction', 'executeStatement', 'insert', 'update', 'delete'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $container->set('doctrine.dbal.default_connection', $db);
        $detail = $this->createMock(Mp3TrashDetail::class);
        $observation = ['inspection' => [
            'journal' => ['phase' => 'EN_CORBEILLE', 'emission_id' => 19, 'last_attempt_id' => null, 'blocked' => false,
                'block_version' => null, 'last_diagnostic_code' => null],
            'emission' => ['exists' => true, 'deleted_at' => null, 'thumbnail_mp3' => null, 'url' => null],
            'disk' => ['public' => ['present' => false], 'private' => ['matches_journal' => true]],
            'diagnostic' => ['status' => 'completed'],
        ]];
        $detail->expects($allowed ? self::exactly(6) : self::never())->method('read')->with(self::ID)
            ->willReturnCallback(static function () use (&$observation): ?array { return $observation; });
        $container->set(Mp3TrashDetail::class, $detail);

        $failures = [
            ['confirmation_obsolete', 'confirmation', 409, 'obsolète'],
            ['duplicate_request', 'administrative_request', 409, 'déjà enregistrée'],
            ['conflict', 'reservation', 409, 'ne permet plus'],
            ['trace_unavailable', 'administrative_request', 503, 'journal administratif'],
            ['lock_unavailable', 'storage_lock', 503, 'verrou'],
            ['commit_uncertain', 'association', 409, 'résultat du commit est inconnu'],
            ['interrupted', 'public_link', 503, 'interrompue'],
            ['missing', 'reservation', 404, 'introuvable'],
        ];
        $received = [];
        $last = str_repeat('a', 32);
        $service = $this->createMock(Mp3RestoreAction::class);
        $service->expects($allowed ? self::exactly(count($failures) + 2) : self::never())->method('execute')
            ->willReturnCallback(static function (array $record) use (&$received, $failures, $last): bool {
                $index = count($received);
                $received[] = $record;
                self::assertSame(42, $record['actor_id']);
                self::assertSame(self::ID, $record['operation_id']);
                self::assertSame(Mp3TrashConfirmationAction::RESTORE->value, $record['action']);
                self::assertSame('EN_CORBEILLE', $record['expected_phase']);
                self::assertArrayHasKey('expected_last_attempt_id', $record);
                self::assertNull($record['expected_attempt_id']);
                if ($index === 0) {
                    self::assertNull($record['expected_last_attempt_id']);
                    return true;
                }
                self::assertSame($last, $record['expected_last_attempt_id']);
                if ($index === 1) {
                    return true;
                }
                self::assertSame($received[1]['request_id'], $record['request_id']);
                [$reason, $step] = $failures[$index - 2];
                if ($reason === 'missing') {
                    return false;
                }
                throw new Mp3RestoreFailure($step, self::ID, null, $reason === 'commit_uncertain',
                    new \RuntimeException('hidden-error-marker'), $reason, $record['request_id']);
            });
        $container->set(Mp3RestoreAction::class, $service);

        $uri = '/admin/audio-library/trash/' . self::ID;
        $crawler = $client->request('GET', $uri . '/confirm/restore');
        if (!$allowed) {
            self::assertContains($client->getResponse()->getStatusCode(), $impersonated ? [403] : [302, 403]);
            $client->request('POST', $uri . '/restore');
            self::assertContains($client->getResponse()->getStatusCode(), $impersonated ? [403] : [302, 403]);
            return;
        }
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('.main-content', 'Aucune tentative enregistrée');
        self::assertSelectorTextContains('.main-content', '#19');
        $form = $crawler->selectButton('Restaurer le MP3')->form();
        $payload = $form->getPhpValues();
        self::assertSame(['request_id', '_token'], array_keys($payload));
        $client->request('POST', $form->getUri(), array_replace($payload, ['_token' => 'bad']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', $form->getUri(), $payload + ['actor_id' => 7, 'last_attempt_id' => $last]);
        self::assertSame(303, $client->getResponse()->getStatusCode());

        $observation['inspection']['journal']['last_attempt_id'] = $last;
        $crawler = $client->request('GET', $uri . '/confirm/restore?page=2&blocked=0&phase=EN_CORBEILLE');
        self::assertSelectorTextContains('.main-content', $last);
        $form = $crawler->selectButton('Restaurer le MP3')->form();
        $payload = $form->getPhpValues();
        $client->submit($form);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        foreach ($failures as [, , $status, $message]) {
            $crawler = $client->request('POST', $form->getUri(), $payload);
            self::assertSame($status, $client->getResponse()->getStatusCode());
            self::assertSelectorTextContains('.main-content', $message);
            self::assertSelectorTextContains('.main-content', $payload['request_id']);
            self::assertStringNotContainsString('hidden-error-marker', $client->getResponse()->getContent());
            self::assertCount(1, $crawler->selectLink('Historique administratif'));
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        }
        $session = $client->getSession();
        $records = $session->get(Mp3TrashConfirmationStore::SESSION_KEY);
        $records[$payload['request_id']]['expires_at'] = 0;
        $session->set(Mp3TrashConfirmationStore::SESSION_KEY, $records);
        $session->save();
        $client->request('POST', $form->getUri(), $payload);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        $observation['inspection']['journal']['blocked'] = true;
        $crawler = $client->request('GET', $uri . '/confirm/restore');
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->selectButton('Restaurer le MP3'));
        $observation['inspection']['journal']['blocked'] = false;
        $observation['inspection']['diagnostic']['status'] = 'indeterminate';
        $crawler = $client->request('GET', $uri . '/confirm/restore');
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('.main-content', 'Inspection indéterminée');
        $observation['inspection']['diagnostic']['status'] = 'completed';
        $observation['inspection']['journal']['phase'] = 'RESTAUREE';
        $client->request('GET', $uri . '/confirm/restore');
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $observation = null;
        $client->request('GET', $uri . '/confirm/restore');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public static function accessCases(): iterable
    {
        yield 'administrator' => ['ROLE_ADMIN', false];
        yield 'impersonation' => ['ROLE_SUPER_ADMIN', true];
        yield 'super administrator' => ['ROLE_SUPER_ADMIN', false];
    }
}
