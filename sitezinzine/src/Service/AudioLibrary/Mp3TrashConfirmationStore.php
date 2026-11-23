<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary;

use App\Enum\Mp3TrashConfirmationAction as Action;
use App\Enum\Mp3TrashPhase as Phase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class Mp3TrashConfirmationStore
{
    public const SESSION_KEY = 'mp3_trash_confirmations';
    private const TTL_SECONDS = 900;
    private const MAX_CONFIRMATIONS = 20;

    public function __construct(private ClockInterface $clock, private CsrfTokenManagerInterface $csrf) {}

    public function issue(SessionInterface $session, string $operationId, Action $action, int $actorId, Phase $phase,
        ?string $attemptId, ?string $blockVersion, ?string $lastAttemptId = null): array
    {
        $now = $this->clock->now()->getTimestamp();
        $records = $this->activeRecords($session, $now);
        if (count($records) >= self::MAX_CONFIRMATIONS) {
            $oldest = array_key_first($records);
            $this->csrf->removeToken($this->tokenId($records[$oldest]));
            unset($records[$oldest]);
        }
        do {
            $requestId = bin2hex(random_bytes(16));
        } while ($requestId === $operationId || $requestId === $attemptId || $requestId === $lastAttemptId || isset($records[$requestId]));
        $record = [
            'operation_id' => $operationId,
            'action' => $action->value,
            'actor_id' => $actorId,
            'expected_phase' => $phase->value,
            'expected_attempt_id' => $attemptId,
            'expected_block_version' => $blockVersion,
            'request_id' => $requestId,
            'expires_at' => $now + self::TTL_SECONDS,
        ];
        if ($action === Action::RESTORE) {
            $record['expected_last_attempt_id'] = $lastAttemptId;
        }
        $token = $this->csrf->getToken($this->tokenId($record))->getValue();
        $records[$requestId] = $record;
        $session->set(self::SESSION_KEY, $records);

        return ['request_id' => $requestId, 'csrf_token' => $token];
    }

    /** Return only the server-side context. A valid read does not consume it. */
    public function validate(SessionInterface $session, string $operationId, Action $action, int $actorId,
        string $requestId, string $csrfToken): ?array
    {
        $record = $this->activeRecords($session, $this->clock->now()->getTimestamp())[$requestId] ?? null;
        if ($record === null || $record['operation_id'] !== $operationId || $record['action'] !== $action->value
            || $record['actor_id'] !== $actorId) {
            return null;
        }
        if (!$this->csrf->isTokenValid(new CsrfToken($this->tokenId($record), $csrfToken))) {
            throw new InvalidCsrfTokenException('Invalid MP3 confirmation CSRF token.');
        }

        return $record;
    }

    private function activeRecords(SessionInterface $session, int $now): array
    {
        $records = $session->get(self::SESSION_KEY, []);
        $active = $records;
        foreach ($records as $id => $record) {
            if ($record['expires_at'] <= $now) {
                $this->csrf->removeToken($this->tokenId($record));
                unset($active[$id]);
            }
        }
        if ($records !== $active) {
            $session->set(self::SESSION_KEY, $active);
        }

        return $active;
    }

    private function tokenId(array $record): string
    {
        return 'mp3-trash-confirm.' . $record['action'] . '.' . $record['operation_id'] . '.' . $record['request_id'];
    }
}
