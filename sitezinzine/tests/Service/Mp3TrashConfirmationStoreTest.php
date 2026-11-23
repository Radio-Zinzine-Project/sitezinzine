<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Mp3TrashConfirmationAction as Action;
use App\Enum\Mp3TrashPhase as Phase;
use App\Service\AudioLibrary\Mp3TrashConfirmationStore;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class Mp3TrashConfirmationStoreTest extends TestCase
{
    private const OPERATION = '0123456789abcdef0123456789abcdef';
    private const ATTEMPT = 'abcdef0123456789abcdef0123456789';

    public function testActionsAcceptOnlyThePhasesSupportedByTheirServices(): void
    {
        $expected = [
            'resume-deletion' => [Phase::SUPPRESSION_PREPAREE, Phase::RETRAIT_PUBLIC_EN_ATTENTE],
            'resume-restoration' => [Phase::RESTAURATION_PREPAREE, Phase::RETRAIT_PRIVE_EN_ATTENTE],
            'cancel-deletion' => [Phase::SUPPRESSION_PREPAREE],
            'cancel-restoration' => [Phase::RESTAURATION_PREPAREE],
            'restore' => [Phase::EN_CORBEILLE],
        ];
        foreach (Action::cases() as $action) {
            self::assertSame($expected[$action->value], array_values(array_filter(Phase::cases(), $action->acceptsPhase(...))));
        }
    }

    public function testConfirmationIsBoundToItsContextAndReusableUntilExpiry(): void
    {
        $now = new \DateTimeImmutable('2026-11-19T15:00:00Z');
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturnCallback(static function () use (&$now): \DateTimeImmutable { return $now; });
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $storage = new SessionTokenStorage($stack);
        $csrf = new CsrfTokenManager(null, $storage);
        $store = new Mp3TrashConfirmationStore($clock, $csrf);
        $action = Action::RESUME_RESTORATION;
        $issued = $store->issue($session, self::OPERATION, $action, 42, Phase::RESTAURATION_PREPAREE, self::ATTEMPT, '2026-11-19 14:00:00.123456');
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $issued['request_id']);
        self::assertNotContains($issued['request_id'], [self::OPERATION, self::ATTEMPT]);
        $validate = fn () => $store->validate($session, self::OPERATION, $action, 42, $issued['request_id'], $issued['csrf_token']);
        $first = $validate();
        self::assertSame($first, $validate());
        self::assertSame($issued['request_id'], $first['request_id']);
        self::assertSame(Phase::RESTAURATION_PREPAREE->value, $first['expected_phase']);
        self::assertSame(self::ATTEMPT, $first['expected_attempt_id']);
        self::assertSame('2026-11-19 14:00:00.123456', $first['expected_block_version']);
        self::assertNull($store->validate($session, self::OPERATION, $action, 43, $issued['request_id'], $issued['csrf_token']));
        self::assertNull($store->validate($session, self::OPERATION, Action::CANCEL_RESTORATION, 42, $issued['request_id'], $issued['csrf_token']));
        try {
            $store->validate($session, self::OPERATION, $action, 42, $issued['request_id'], 'invalid');
            self::fail('An invalid CSRF token was accepted.');
        } catch (InvalidCsrfTokenException) {
            self::assertSame($first, $validate());
        }
        $now = $now->modify('+15 minutes');
        self::assertNull($validate());
        self::assertFalse($storage->hasToken('mp3-trash-confirm.' . $action->value . '.' . self::OPERATION . '.' . $issued['request_id']));
        $requests = [];
        for ($i = 0; $i < 21; ++$i) {
            $requests[] = $store->issue($session, self::OPERATION, $action, 42, Phase::RESTAURATION_PREPAREE, self::ATTEMPT, null);
        }
        self::assertCount(20, $session->get(Mp3TrashConfirmationStore::SESSION_KEY));
        self::assertNull($store->validate($session, self::OPERATION, $action, 42, $requests[0]['request_id'], $requests[0]['csrf_token']));
        self::assertFalse($storage->hasToken('mp3-trash-confirm.' . $action->value . '.' . self::OPERATION . '.' . $requests[0]['request_id']));
        self::assertNotNull($store->validate($session, self::OPERATION, $action, 42, $requests[20]['request_id'], $requests[20]['csrf_token']));
    }
}
