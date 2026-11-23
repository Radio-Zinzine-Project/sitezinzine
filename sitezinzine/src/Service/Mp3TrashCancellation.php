<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3AdministrativeEventType as Type;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3AdministrativeRequestUncertain;
use App\Exception\Mp3TrashCancellationFailure;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** The administrative entry point must authorize the actor before calling the service. */
final readonly class Mp3TrashCancellation
{
    private AudioLocalUrlResolver $localUrls;

    public function __construct(
        private Mp3StorageLock $lock,
        private Mp3TrashDisk $disk,
        private Mp3AdministrativeJournal $journal,
        private LoggerInterface $logger,
        private string $publicBaseUrl,
    ) {
        $this->localUrls = new AudioLocalUrlResolver([$publicBaseUrl]);
    }

    public function cancelDeletion(EntityManagerInterface $em, string $operationId, int $actorId,
        Phase $expectedPhase, string $requestId, ?string $expectedBlockVersion = null): Phase
    {
        return $this->cancel($em, $operationId, $actorId, $expectedPhase, $requestId, null, $expectedBlockVersion);
    }

    public function cancelRestoration(EntityManagerInterface $em, string $operationId, int $actorId,
        Phase $expectedPhase, string $requestId, string $expectedAttemptId, ?string $expectedBlockVersion = null): Phase
    {
        return $this->cancel($em, $operationId, $actorId, $expectedPhase, $requestId, $expectedAttemptId, $expectedBlockVersion);
    }

    /** Discard the dedicated manager after a failure and inspect afresh after an uncertain commit. */
    private function cancel(EntityManagerInterface $em, string $operationId, int $actorId, Phase $expectedPhase,
        string $requestId, ?string $attemptId, ?string $expectedBlockVersion): Phase
    {
        $restoring = $attemptId !== null;
        $step = 'precondition';
        $observed = null;
        $requestConfirmed = false;
        $commitUncertain = false;
        $db = null;
        try {
            Mp3TrashOperation::trashRelativePathFor($operationId);
            if ($actorId <= 0 || $expectedPhase !== ($restoring ? Phase::RESTAURATION_PREPAREE : Phase::SUPPRESSION_PREPAREE)
                || preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1
                || ($restoring && preg_match('/\A[a-f0-9]{32}\z/', $attemptId) !== 1)
                || $requestId === $operationId || $requestId === $attemptId) {
                throw new \InvalidArgumentException('Identifiants ou phase d’annulation MP3 invalides.');
            }
            $db = $this->dedicatedConnection($em);
            $step = 'storage_lock';

            return $this->lock->synchronized(function () use (
                $em, $db, $operationId, $actorId, $expectedPhase, $requestId, $attemptId, $restoring, $expectedBlockVersion,
                &$step, &$observed, &$requestConfirmed, &$commitUncertain,
            ): Phase {
                $step = 'administrative_request';
                if ($db instanceof PrimaryReadReplicaConnection) {
                    $db->ensureConnectedToPrimary();
                }
                $this->journal->recordRequest($db, $requestId, $operationId, $actorId,
                    $restoring ? 'cancel_restoration' : 'cancel_deletion', $expectedPhase, $attemptId, new \DateTimeImmutable());
                $requestConfirmed = true;
                $step = 'journal_lookup';
                $emissionId = $db->fetchOne('SELECT emission_id FROM mp3_trash_operation WHERE id = ?', [$operationId]);
                if ($emissionId === false) {
                    throw new \DomainException('Opération MP3 introuvable.');
                }

                $step = 'cancellation';
                $db->beginTransaction();
                try {
                    $operation = $this->requireReady($em, $db, (int) $emissionId, $operationId, $expectedPhase, $attemptId, $observed, $expectedBlockVersion);
                    $at = new \DateTimeImmutable();
                    $code = 'cancelled';
                    if ($operation->isBlocked()) {
                        $operation->resume($expectedPhase, $at);
                        $code = 'unblocked_cancelled';
                    }
                    if ($attemptId === null) {
                        $operation->cancelDeletion($at);
                    } else {
                        $operation->cancelRestoration($attemptId, $at);
                    }
                    $em->flush();
                    $step = 'administrative_confirmation';
                    $this->journal->recordInTransaction($db, $requestId, Type::TRANSITION_CONFIRMED,
                        $operation->phase(), $code, $at);
                    $step = 'cancellation_commit';
                    $commitUncertain = true;
                    $db->commit();
                    $commitUncertain = false;
                } catch (\Throwable $error) {
                    try {
                        if ($db->isTransactionActive()) {
                            $db->rollBack();
                        }
                    } catch (\Throwable) {
                        // Preserve the original error and discard this manager.
                    }
                    throw $error;
                }
                $step = 'lock_integrity';
                $observed = $operation->phase();

                return $operation->phase();
            });
        } catch (\Throwable $error) {
            $uncertain = $commitUncertain || $error instanceof Mp3AdministrativeRequestUncertain;
            $reason = match (true) {
                $uncertain => 'commit_uncertain',
                $step === 'administrative_request' && $error instanceof UniqueConstraintViolationException => 'duplicate_request',
                in_array($step, ['administrative_request', 'administrative_confirmation'], true) => 'trace_unavailable',
                in_array($step, ['precondition', 'storage_lock'], true) => 'refused',
                $error instanceof \DomainException => 'conflict',
                default => 'interrupted',
            };
            $traceFailure = null;
            if ($requestConfirmed || $error instanceof Mp3AdministrativeRequestUncertain) {
                try {
                    if ($uncertain) {
                        $db->close();
                    }
                    $type = match ($reason) {
                        'commit_uncertain' => Type::UNCERTAIN,
                        'conflict', 'refused' => Type::REFUSAL,
                        default => Type::FAILURE,
                    };
                    $this->journal->recordOutcome($db, $requestId, $type, $observed, $reason, new \DateTimeImmutable());
                } catch (\Throwable $traceFailure) {
                    // Keep the cancellation error and report the secondary trace failure.
                }
            }
            try {
                $this->logger->error('MP3 administrative cancellation failed', [
                    'request_id' => $requestId, 'operation_id' => $operationId, 'actor_id' => $actorId,
                    'attempt_id' => $attemptId, 'block_version' => $expectedBlockVersion, 'step' => $step, 'reason' => $reason, 'cause' => $error::class,
                    'trace_failure' => $traceFailure === null ? null : $traceFailure::class,
                ]);
            } catch (\Throwable) {
                // Logging availability cannot change the outcome.
            }
            throw new Mp3TrashCancellationFailure($reason, $step, $requestId, $error, $traceFailure);
        }
    }

    private function dedicatedConnection(EntityManagerInterface $em): Connection
    {
        $db = $em->getConnection();
        $uow = $em->getUnitOfWork();
        if (!$em->isOpen() || $db->isTransactionActive() || !$db->isAutoCommit() || $uow->size() !== 0
            || $uow->getScheduledEntityInsertions() || $uow->getScheduledEntityUpdates()
            || $uow->getScheduledEntityDeletions() || $uow->getScheduledCollectionUpdates()
            || $uow->getScheduledCollectionDeletions()) {
            throw new \LogicException('L’annulation MP3 exige un gestionnaire dédié sans transaction en cours.');
        }

        return $db;
    }

    private function requireReady(EntityManagerInterface $em, Connection $db, int $id, string $operationId,
        Phase $expectedPhase, ?string $attemptId, ?Phase &$observed, ?string $expectedBlockVersion): Mp3TrashOperation
    {
        $row = $db->fetchAssociative('SELECT thumbnail_mp3, url, deleted_at FROM emission WHERE id = ? FOR UPDATE', [$id]);
        if ($row === false || $row['deleted_at'] !== null) {
            throw new \DomainException('L’émission d’origine est indisponible.');
        }
        $operation = $em->find(Mp3TrashOperation::class, $operationId, LockMode::PESSIMISTIC_WRITE);
        if (!$operation instanceof Mp3TrashOperation || $operation->id() !== $operationId || $operation->emissionId() !== $id) {
            throw new \DomainException('Journal MP3 incohérent.');
        }
        $observed = $operation->phase();
        if ($observed !== $expectedPhase
            || ($attemptId !== null && ($operation->lastAttemptId() !== $attemptId
                || ($operation->restorationAttempts()[$attemptId]['outcome'] ?? null) !== 'pending'))) {
            throw new \DomainException('Phase ou tentative obsolète, ou journal MP3 bloqué.');
        }

        $path = $operation->originalThumbnailMp3();
        $url = $operation->originalUrl();
        $local = $this->localUrls->resolve($url);
        $matches = $attemptId === null
            ? $row['thumbnail_mp3'] === $path && $row['url'] === $url
            : in_array($row['thumbnail_mp3'], [null, ''], true) && in_array($row['url'], [null, ''], true);
        if (!$matches || $path === '' || ($local['status'] !== 'absent' && $local['relative_path'] !== $path)) {
            throw new \DomainException('Les références audio ne correspondent pas au journal MP3.');
        }
        if ((int) $db->fetchOne(
            'SELECT COUNT(*) FROM emission WHERE id <> ? AND (thumbnail_mp3 = ? OR url IN (?, ?))',
            [$id, $path, rtrim($this->publicBaseUrl, '/') . '/' . $path, $url === '' ? null : $url],
        ) !== 0 || (int) $db->fetchOne(
            'SELECT COUNT(*) FROM mp3_trash_operation WHERE id <> ? AND active_marker = 1 AND (emission_id = ? OR original_thumbnail_mp3 = ?)',
            [$operationId, $id, $path],
        ) !== 0) {
            throw new \DomainException('Une référence ou une réservation concurrente protège ce MP3.');
        }
        $disk = $this->disk->inspect($path, $operation->trashRelativePath(), $operation->size(), $operation->sha256());
        [$keep, $other] = $attemptId === null ? [$disk['public'], $disk['private']] : [$disk['private'], $disk['public']];
        if ($keep['matches_journal'] !== true || $other['present'] !== false) {
            throw new \DomainException('La copie de référence ou l’absence du second nom MP3 ne peut pas être confirmée.');
        }

        Mp3TrashBlockResolution::requireResolved($operation, $expectedBlockVersion, static fn () => $disk);

        return $operation;
    }
}
