<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Enum\Mp3TrashConfirmationAction as Action;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3TrashRecoveryFailure;
use App\Service\AudioLibrary\Mp3TrashConfirmationStore;
use App\Service\AudioLibrary\Mp3TrashDetail;
use App\Service\AudioLibrary\Mp3TrashRecoveryAction;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class TrashConfirmationAccessTest extends WebTestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const ATTEMPT = 'abcdef0123456789abcdef0123456789';

    /** @dataProvider roles */
    public function testConfirmationIsReadOnlyAndUsesTheAuthenticatedContext(?string $role, bool $impersonated = false): void
    {
        $client = self::createClient(['environment' => 'test', 'debug' => true]);
        $client->disableReboot();
        $container = self::getContainer();
        $allowed = $role === 'ROLE_SUPER_ADMIN' && !$impersonated;
        $db = $this->createMock(Connection::class);
        foreach (['fetchAssociative', 'fetchAllAssociative', 'fetchOne', 'executeStatement', 'insert', 'update', 'delete', 'beginTransaction'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $container->set('doctrine.dbal.default_connection', $db);
        $detail = $this->createMock(Mp3TrashDetail::class);
        $detail->expects($allowed ? self::exactly(4) : self::never())->method('read')->with(self::ID)
            ->willReturnOnConsecutiveCalls(null, [
                'inspection' => ['journal' => [
                    'phase' => 'RESTAURATION_PREPAREE', 'last_attempt_id' => self::ATTEMPT,
                    'last_attempt' => ['outcome' => 'pending'], 'blocked' => true,
                    'block_version' => '2026-11-19 14:00:00.123456', 'last_diagnostic_code' => 'private_file_missing',
                ], 'diagnostic' => ['status' => 'indeterminate']],
            ], ['inspection' => ['journal' => [
                'phase' => 'EN_CORBEILLE', 'last_attempt_id' => null, 'last_attempt' => null,
                'blocked' => false, 'block_version' => null, 'last_diagnostic_code' => null,
            ], 'diagnostic' => ['status' => 'completed']]], ['inspection' => ['journal' => [
                'phase' => 'SUPPRESSION_PREPAREE', 'last_attempt_id' => null, 'last_attempt' => null,
                'blocked' => false, 'block_version' => null, 'last_diagnostic_code' => null,
            ], 'diagnostic' => ['status' => 'intermediate_coherent']]]);
        $container->set(Mp3TrashDetail::class, $detail);
        $outcomes = [
            ['conflict', 'journal_lookup', 404, 'introuvable'],
            ['conflict', 'association', 409, 'ne permet plus'],
            ['duplicate_request', 'administrative_request', 409, 'déjà enregistrée'],
            ['refused', 'storage_lock', 503, 'verrou'],
            ['trace_unavailable', 'administrative_request', 503, 'journal administratif'],
            ['commit_uncertain', 'association', 409, 'résultat du commit est inconnu'],
            ['interrupted', 'private_removal', 503, 'interrompue'],
        ];
        $call = 0;
        $expectedRecord = null;
        $recovery = $this->createMock(Mp3TrashRecoveryAction::class);
        $recovery->expects($allowed ? self::exactly(count($outcomes) + 2) : self::never())->method('execute')
            ->willReturnCallback(static function (Action $action, array $confirmation) use (&$call, &$expectedRecord, $outcomes): void {
                $index = $call++;
                if ($index === count($outcomes) + 1) {
                    self::assertSame(Action::RESUME_DELETION, $action);
                    self::assertSame('SUPPRESSION_PREPAREE', $confirmation['expected_phase']);
                    self::assertNull($confirmation['expected_attempt_id']);
                    self::assertNotSame($expectedRecord['request_id'], $confirmation['request_id']);
                    return;
                }
                self::assertSame(Action::RESUME_RESTORATION, $action);
                self::assertSame($expectedRecord, $confirmation);
                if ($index > 0) {
                    [$reason, $step] = $outcomes[$index - 1];
                    throw new Mp3TrashRecoveryFailure($reason, $step, self::ID, 42, Phase::RESTAURATION_PREPAREE,
                        new \RuntimeException('private-error-marker'), self::ATTEMPT, $confirmation['request_id']);
                }
            });
        $container->set(Mp3TrashRecoveryAction::class, $recovery);
        if ($role !== null) {
            $provider = $this->createMock(EntityUserProvider::class);
            $provider->method('supportsClass')->willReturn(true);
            $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
            $container->set('security.user.provider.concrete.app_user_provider', $provider);
            $user = (new User())->setUsername('trash-confirmation-test')->setPassword('synthetic')
                ->setRoles([$role])->setVerified(true)->setApproved(true);
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);
            $client->loginUser($user);
            if ($impersonated) {
                $session = $client->getSession();
                $original = new PostAuthenticationToken($user, 'main', $user->getRoles());
                $session->set('_security_main', serialize(new SwitchUserToken($user, 'main', $user->getRoles(), $original)));
                $session->save();
            }
        }

        $base = '/admin/audio-library/trash/' . self::ID . '/confirm/';
        $client->request('GET', $base . 'resume-restoration');
        if (!$allowed) {
            self::assertContains($client->getResponse()->getStatusCode(), $impersonated ? [403] : [302, 403]);
            $client->request('POST', '/admin/audio-library/trash/' . self::ID . '/resume-restoration');
            self::assertContains($client->getResponse()->getStatusCode(), $impersonated ? [403] : [302, 403]);
            return;
        }
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', '/admin/audio-library/trash/invalid/confirm/resume-restoration');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        foreach (['unblock', 'unknown'] as $action) {
            $client->request('GET', $base . $action);
            self::assertSame(404, $client->getResponse()->getStatusCode());
        }

        $crawler = $client->request('GET', $base . 'resume-restoration?phase=RESTAURATION_PREPAREE&blocked=1&page=2&actor_id=99&attempt=wrong');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        foreach (['Reprendre la restauration', 'Restauration préparée', 'Opération bloquée', self::ATTEMPT, 'Inspection indéterminée'] as $label) {
            self::assertSelectorTextContains('.main-content', $label);
        }
        self::assertCount(1, $crawler->filter('.main-content form[method="post"]'));
        $back = $crawler->selectLink('Retour au détail de l’opération')->link()->getUri();
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        self::assertSame(['phase' => 'RESTAURATION_PREPAREE', 'blocked' => '1', 'page' => '2'], $query);
        $session = $client->getSession();
        $records = $session->get(Mp3TrashConfirmationStore::SESSION_KEY);
        self::assertCount(1, $records);
        $record = reset($records);
        $expectedRecord = $record;
        self::assertSame(42, $record['actor_id']);
        self::assertSame('resume-restoration', $record['action']);
        self::assertSame('RESTAURATION_PREPAREE', $record['expected_phase']);
        self::assertSame(self::ATTEMPT, $record['expected_attempt_id']);
        self::assertSame('2026-11-19 14:00:00.123456', $record['expected_block_version']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $record['request_id']);
        self::assertNotContains($record['request_id'], [self::ID, self::ATTEMPT]);
        self::assertTrue($session->has('_csrf/mp3-trash-confirm.resume-restoration.' . self::ID . '.' . $record['request_id']));
        $session->save();
        $form = $crawler->selectButton('Reprendre la restauration')->form();
        $postUri = $form->getUri();
        $payload = $form->getPhpValues();
        self::assertSame(['request_id', '_token'], array_keys($payload));
        $client->request('POST', $postUri, ['request_id' => str_repeat('d', 32)]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $client->request('POST', $postUri, array_replace($payload, ['_token' => 'wrong']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', str_replace('/resume-restoration', '/resume-deletion', $postUri), $payload);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $client->request('POST', $postUri, $payload + ['actor_id' => '99', 'expected_phase' => 'ANNULEE', 'expected_block_version' => 'wrong']);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('phase=RESTAURATION_PREPAREE&blocked=1&page=2', $client->getResponse()->headers->get('Location'));
        foreach ($outcomes as [$reason, $step, $status, $message]) {
            $errorPage = $client->request('POST', $postUri, $payload);
            self::assertSame($status, $client->getResponse()->getStatusCode());
            self::assertSelectorTextContains('.main-content', $message);
            self::assertSelectorTextContains('.main-content', $record['request_id']);
            self::assertStringNotContainsString('private-error-marker', $client->getResponse()->getContent());
            self::assertCount(1, $errorPage->selectLink('Historique administratif'));
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        }
        $session = $client->getSession();
        $records[$record['request_id']]['expires_at'] = 0;
        $session->set(Mp3TrashConfirmationStore::SESSION_KEY, $records);
        $session->save();
        $client->request('POST', $postUri, $payload);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        $client->request('GET', $base . 'cancel-deletion');
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $client->request('POST', $base . 'resume-restoration');
        self::assertSame(405, $client->getResponse()->getStatusCode());
        $deletion = $client->request('GET', $base . 'resume-deletion');
        $client->submit($deletion->selectButton('Reprendre la suppression')->form());
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist(Mp3TestStorage::root() . '/trash/.storage.lock');
    }

    public static function roles(): iterable
    {
        yield 'anonymous' => [null];
        yield 'user' => ['ROLE_USER'];
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'impersonated super admin' => ['ROLE_SUPER_ADMIN', true];
        yield 'super admin' => ['ROLE_SUPER_ADMIN'];
    }
}
