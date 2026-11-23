<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Mp3AdministrativeEventType as Type;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3AdministrativeRequestUncertain;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;

/** Append-only DBAL access to the administrative journal. */
final class Mp3AdministrativeJournal
{
    public function recordRequest(Connection $db, string $requestId, string $operationId, int $actorId,
        string $action, Phase $expectedPhase, ?string $attemptId, \DateTimeImmutable $at): void
    {
        $this->requireOutsideTransaction($db);
        $this->validateId($requestId);
        $this->validateId($operationId);
        if ($attemptId !== null) {
            $this->validateId($attemptId);
        }
        $this->validateCode($action);
        if ($actorId < 1 || $actorId > 2_147_483_647) {
            throw new \InvalidArgumentException('Invalid MP3 administrative actor.');
        }

        $row = [
            'request_id' => $requestId,
            'operation_id' => $operationId,
            'actor_id' => $actorId,
            'attempt_id' => $attemptId,
            'action' => $action,
            'expected_phase' => $expectedPhase->value,
            'observed_phase' => null,
            'event_type' => Type::REQUEST->value,
            'result_code' => 'requested',
            'recorded_at' => $this->utc($at),
        ];
        $inserted = false;
        try {
            $db->transactional(static function (Connection $connection) use ($row, &$inserted): void {
                $connection->insert('mp3_administrative_event', $row);
                $inserted = true;
            });
        } catch (\Throwable $error) {
            if ($inserted) {
                throw new Mp3AdministrativeRequestUncertain('The administrative request commit was not confirmed.', 0, $error);
            }
            throw $error;
        }
    }

    /** The caller must roll back its business transaction if the append fails. */
    public function recordInTransaction(Connection $db, string $requestId, Type $type, ?Phase $observedPhase,
        string $resultCode, \DateTimeImmutable $at): void
    {
        if (!$db->isTransactionActive()) {
            throw new \LogicException('A business transaction is required for a confirmed transition.');
        }
        if ($type !== Type::TRANSITION_CONFIRMED || $observedPhase === null) {
            throw new \InvalidArgumentException('A confirmed transition requires an observed phase.');
        }

        $this->append($db, $requestId, $type, $observedPhase, $resultCode, $at);
    }

    /** Use an idle connection after the business transaction has ended. */
    public function recordOutcome(Connection $db, string $requestId, Type $type, ?Phase $observedPhase,
        string $resultCode, \DateTimeImmutable $at): void
    {
        $this->requireOutsideTransaction($db);
        if (!in_array($type, [Type::REFUSAL, Type::FAILURE, Type::UNCERTAIN], true)) {
            throw new \InvalidArgumentException('An independent outcome must be a refusal, failure or uncertain result.');
        }

        $db->transactional(fn (Connection $connection) => $this->append($connection, $requestId, $type, $observedPhase, $resultCode, $at));
    }

    /** @return list<array<string, mixed>> */
    public function eventsForRequest(Connection $db, string $requestId): array
    {
        $this->requireOutsideTransaction($db);
        $this->validateId($requestId);
        if ($db instanceof PrimaryReadReplicaConnection) {
            $db->ensureConnectedToPrimary();
        }

        return $db->fetchAllAssociative(
            'SELECT id, request_id, operation_id, actor_id, attempt_id, action, expected_phase, observed_phase, event_type, result_code, recorded_at
             FROM mp3_administrative_event WHERE request_id = ? ORDER BY id',
            [$requestId],
        );
    }

    private function append(Connection $db, string $requestId, Type $type, ?Phase $observedPhase,
        string $resultCode, \DateTimeImmutable $at): void
    {
        $this->validateId($requestId);
        $this->validateCode($resultCode);
        // Serialize appends per request until commit. Copy the immutable
        // context from the confirmed request, including the original actor.
        $request = $db->fetchAssociative(
            "SELECT request_id, operation_id, actor_id, attempt_id, action, expected_phase
             FROM mp3_administrative_event
             WHERE request_id = ? AND event_type = 'request' AND confirmed_phase_key = '' FOR UPDATE",
            [$requestId],
        );
        if ($request === false) {
            throw new \DomainException('The initial MP3 administrative request is missing.');
        }

        $db->insert('mp3_administrative_event', $request + [
            'observed_phase' => $observedPhase?->value,
            'event_type' => $type->value,
            'result_code' => $resultCode,
            'recorded_at' => $this->utc($at),
        ]);
    }

    private function requireOutsideTransaction(Connection $db): void
    {
        if ($db->isTransactionActive() || !$db->isAutoCommit()) {
            throw new \LogicException('The MP3 administrative journal requires an idle autocommit connection.');
        }
    }

    private function validateId(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid MP3 administrative identifier.');
        }
    }

    private function validateCode(string $code): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $code) !== 1) {
            throw new \InvalidArgumentException('Invalid MP3 administrative action or result code.');
        }
    }

    private function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
