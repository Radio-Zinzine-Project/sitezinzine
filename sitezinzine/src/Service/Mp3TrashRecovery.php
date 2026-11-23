<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3AdministrativeEventType as Type;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3AdministrativeRequestUncertain;
use App\Exception\Mp3TrashDiskMutationFailure;
use App\Exception\Mp3TrashRecoveryFailure;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReferences;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupDecision;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class Mp3TrashRecovery
{

    public function __construct(
        private Mp3StorageLock $lock,
        private Mp3TrashDisk $disk,
        private LoggerInterface $logger,
        private string $publicBaseUrl,
        private Mp3AdministrativeJournal $administrativeJournal,
        private AudioLocalUrlResolver $localUrls,
    ) {
    }

    /** The authorized caller supplies a dedicated manager and a stable request ID from the confirmation. */
    public function resumeDeletion(
        EntityManagerInterface $em,
        string $operationId,
        int $actorId,
        Phase $expectedPhase,
        string $requestId,
        ?string $expectedBlockVersion = null,
    ): Phase {
        return $this->execute($em, $operationId, $actorId, $expectedPhase, $requestId, null, $expectedBlockVersion,
            function (Connection $db, int $id, string &$step, bool &$commitUncertain, ?Phase &$observed) use (
                $em, $operationId, $expectedPhase, $requestId, $expectedBlockVersion,
            ): Phase {
                if ($expectedPhase === Phase::SUPPRESSION_PREPAREE) {
                    $step = 'disassociation';
                    $this->transaction($db, $commitUncertain, function () use (
                        $em, $db, $id, $operationId, $requestId, $expectedBlockVersion, &$step, &$observed,
                    ): void {
                        $operation = $this->requireReady($em, $db, $id, $operationId, Phase::SUPPRESSION_PREPAREE, $observed, expectedBlockVersion: $expectedBlockVersion);
                        $step = 'private_link';
                        $this->checkDisk(fn () => $this->disk->prepareTrash($operation));
                        $step = 'disassociation';
                        if ($db->update('emission', ['thumbnail_mp3' => null, 'url' => null], ['id' => $id]) !== 1) {
                            throw new \RuntimeException('La d?sassociation audio n?a pas ?t? confirm?e.');
                        }
                        $this->transition($operation, 'disassociated', $operation->confirmDisassociation(...), $em, $db, $requestId, $step);
                    });
                    $em->clear();
                }

                $step = 'finalization';
                $this->transaction($db, $commitUncertain, function () use (
                    $em, $db, $id, $operationId, $requestId, $expectedBlockVersion, $expectedPhase, &$step, &$observed,
                ): void {
                    $operation = $this->requireReady($em, $db, $id, $operationId, Phase::RETRAIT_PUBLIC_EN_ATTENTE, $observed,
                        expectedBlockVersion: $expectedPhase === Phase::RETRAIT_PUBLIC_EN_ATTENTE ? $expectedBlockVersion : null);
                    $step = 'public_removal';
                    $this->checkDisk(fn () => $this->disk->finalizeTrash($operation));
                    $step = 'finalization';
                    $this->transition($operation, 'public_removed', $operation->confirmPublicRemoval(...), $em, $db, $requestId, $step);
                });

                return Phase::EN_CORBEILLE;
            },
        );
    }

    /** Discard the dedicated manager after failure. An uncertain commit requires fresh inspection. */
    public function resumeRestoration(
        EntityManagerInterface $em,
        string $operationId,
        int $actorId,
        Phase $expectedPhase,
        string $expectedAttemptId,
        string $requestId,
        ?string $expectedBlockVersion = null,
    ): Mp3TrashOperation {
        return $this->execute($em, $operationId, $actorId, $expectedPhase, $requestId, $expectedAttemptId, $expectedBlockVersion,
            function (Connection $db, int $id, string &$step, bool &$commitUncertain, ?Phase &$observed) use (
                $em, $operationId, $expectedPhase, $expectedAttemptId, $requestId, $expectedBlockVersion,
            ): Mp3TrashOperation {
                if ($expectedPhase === Phase::RESTAURATION_PREPAREE) {
                    $step = 'association';
                    $this->transaction($db, $commitUncertain, function () use (
                        $em, $db, $id, $operationId, $expectedAttemptId, $requestId, $expectedBlockVersion, &$step, &$observed,
                    ): void {
                        $operation = $this->requireReady($em, $db, $id, $operationId, Phase::RESTAURATION_PREPAREE, $observed, $expectedAttemptId, $expectedBlockVersion);
                        $step = 'public_link';
                        $this->checkDisk(fn () => $this->disk->prepareRestore($operation));
                        $step = 'association';
                        if ($db->update('emission', [
                            'thumbnail_mp3' => $operation->originalThumbnailMp3(),
                            'url' => $operation->originalUrl(),
                        ], ['id' => $id]) !== 1) {
                            throw new \RuntimeException('La r?association audio n?a pas ?t? confirm?e.');
                        }
                        $this->transition($operation, 'associated', fn ($at) => $operation->confirmAssociation($expectedAttemptId, $at), $em, $db, $requestId, $step);
                    });
                    $em->clear();
                }

                $step = 'finalization';
                return $this->transaction($db, $commitUncertain, function () use (
                    $em, $db, $id, $operationId, $expectedAttemptId, $requestId, $expectedBlockVersion, $expectedPhase, &$step, &$observed,
                ): Mp3TrashOperation {
                    $operation = $this->requireReady($em, $db, $id, $operationId, Phase::RETRAIT_PRIVE_EN_ATTENTE, $observed, $expectedAttemptId,
                        $expectedPhase === Phase::RETRAIT_PRIVE_EN_ATTENTE ? $expectedBlockVersion : null);
                    $step = 'private_removal';
                    $this->checkDisk(fn () => $this->disk->finalizeRestore($operation));
                    $step = 'finalization';
                    $this->transition($operation, 'private_removed', fn ($at) => $operation->confirmPrivateRemoval($expectedAttemptId, $at), $em, $db, $requestId, $step);

                    return $operation;
                });
            },
        );
    }

    private function execute(EntityManagerInterface $em, string $operationId, int $actorId, Phase $expectedPhase,
        string $requestId, ?string $attemptId, ?string $expectedBlockVersion, callable $recover): Phase|Mp3TrashOperation
    {
        $step = 'precondition';
        $commitUncertain = false;
        $requestConfirmed = false;
        $observed = null;
        $db = null;
        $context = ['request_id' => $requestId, 'operation_id' => $operationId, 'actor_id' => $actorId,
            'expected_phase' => $expectedPhase->value, 'attempt_id' => $attemptId, 'block_version' => $expectedBlockVersion];
        $phases = $attemptId === null
            ? [Phase::SUPPRESSION_PREPAREE, Phase::RETRAIT_PUBLIC_EN_ATTENTE]
            : [Phase::RESTAURATION_PREPAREE, Phase::RETRAIT_PRIVE_EN_ATTENTE];
        try {
            Mp3TrashOperation::trashRelativePathFor($operationId);
            if ($actorId <= 0 || !in_array($expectedPhase, $phases, true)
                || preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1
                || ($attemptId !== null && preg_match('/\A[a-f0-9]{32}\z/', $attemptId) !== 1)
                || $requestId === $operationId || $requestId === $attemptId) {
                throw new \InvalidArgumentException('Identifiants ou phase de reprise MP3 invalides.');
            }
            $db = $this->dedicatedConnection($em);
            $step = 'storage_lock';
            $result = $this->lock->synchronized(function () use (
                $db, $operationId, $actorId, $expectedPhase, $requestId, $attemptId, $recover, $context,
                &$step, &$requestConfirmed, &$commitUncertain, &$observed,
            ): Phase|Mp3TrashOperation {
                $step = 'administrative_request';
                $this->administrativeJournal->recordRequest($db, $requestId, $operationId, $actorId,
                    $attemptId === null ? 'resume_deletion' : 'resume_restoration', $expectedPhase, $attemptId, new \DateTimeImmutable());
                $requestConfirmed = true;
                $this->log('resume_requested', $context);
                $step = 'journal_lookup';
                $id = $this->findEmissionId($db, $operationId);
                $result = $recover($db, $id, $step, $commitUncertain, $observed);
                $step = 'lock_integrity';

                return $result;
            });
        } catch (\Throwable $error) {
            $uncertain = $commitUncertain || $error instanceof Mp3AdministrativeRequestUncertain;
            $reason = $this->failureReason($step, $uncertain, $error);
            $traceFailure = null;
            if ($requestConfirmed || $error instanceof Mp3AdministrativeRequestUncertain) {
                try {
                    if ($uncertain) {
                        // Discard the ambiguous session before recording the observation.
                        $db->close();
                    }
                    $type = match ($reason) {
                        'commit_uncertain' => Type::UNCERTAIN,
                        'conflict', 'refused' => Type::REFUSAL,
                        default => Type::FAILURE,
                    };
                    $this->administrativeJournal->recordOutcome($db, $requestId, $type, $observed, $reason, new \DateTimeImmutable());
                } catch (\Throwable $traceFailure) {
                    // Preserve the recovery failure and expose the secondary trace failure.
                }
            }
            $this->log('resume_failed', $context + ['step' => $step, 'reason' => $reason,
                'cause' => $error::class, 'trace_failure' => $traceFailure === null ? null : $traceFailure::class]);
            throw new Mp3TrashRecoveryFailure($reason, $step, $operationId, $actorId, $expectedPhase, $error,
                $attemptId, $requestId, $traceFailure);
        }
        $this->log('resume_confirmed', $context + ['phase' => ($result instanceof Phase ? $result : $result->phase())->value]);

        return $result;
    }

    private function transition(Mp3TrashOperation $operation, string $code, callable $change,
        EntityManagerInterface $em, Connection $db, string $requestId, string &$step): void
    {
        $at = new \DateTimeImmutable();
        if ($operation->isBlocked()) {
            $operation->resume($operation->phase(), $at);
            $code = 'unblocked_' . $code;
        }
        $change($at);
        $em->flush();
        $businessStep = $step;
        $step = 'administrative_confirmation';
        $this->administrativeJournal->recordInTransaction($db, $requestId, Type::TRANSITION_CONFIRMED,
            $operation->phase(), $code, new \DateTimeImmutable());
        $step = $businessStep;
    }

    private function dedicatedConnection(EntityManagerInterface $em): Connection
    {
        $db = $em->getConnection();
        $uow = $em->getUnitOfWork();
        if (!$em->isOpen() || $db->getTransactionNestingLevel() !== 0 || $uow->size() !== 0
            || $uow->getScheduledEntityInsertions() || $uow->getScheduledEntityUpdates()
            || $uow->getScheduledEntityDeletions() || $uow->getScheduledCollectionUpdates()
            || $uow->getScheduledCollectionDeletions()) {
            throw new \LogicException('La reprise MP3 exige un gestionnaire dédié sans transaction en cours.');
        }

        return $db;
    }

    private function findEmissionId(Connection $db, string $operationId): int
    {
        if ($db instanceof PrimaryReadReplicaConnection) {
            $db->ensureConnectedToPrimary();
        }
        // Discover the ID outside the transaction and revalidate it under the emission → journal locks.
        $id = $db->fetchOne('SELECT emission_id FROM mp3_trash_operation WHERE id = ?', [$operationId]);
        if ($id === false) {
            throw new \DomainException('Opération MP3 introuvable.');
        }

        return (int) $id;
    }

    private function requireReady(
        EntityManagerInterface $em,
        Connection $db,
        int $id,
        string $operationId,
        Phase $phase,
        ?Phase &$observed,
        ?string $attemptId = null,
        ?string $expectedBlockVersion = null,
    ): Mp3TrashOperation
    {
        $observed = null;
        $row = $db->fetchAssociative('SELECT thumbnail_mp3, url, deleted_at FROM emission WHERE id = ? FOR UPDATE', [$id]);
        if ($row === false || $row['deleted_at'] !== null) {
            throw new \DomainException('L’émission d’origine est indisponible.');
        }
        $operation = $em->find(Mp3TrashOperation::class, $operationId, LockMode::PESSIMISTIC_WRITE);
        $observed = $operation instanceof Mp3TrashOperation && $operation->id() === $operationId ? $operation->phase() : null;
        if (!$operation instanceof Mp3TrashOperation || $operation->id() !== $operationId || $operation->emissionId() !== $id
            || $operation->phase() !== $phase) {
            throw new \DomainException('Le journal MP3 est incohérent, bloqué ou sa phase a changé.');
        }
        if ($attemptId !== null && ($operation->lastAttemptId() !== $attemptId
            || ($operation->restorationAttempts()[$attemptId]['outcome'] ?? null) !== 'pending')) {
            throw new \DomainException('La tentative de restauration MP3 a changé ou n’est plus en attente.');
        }

        $path = $operation->originalThumbnailMp3();
        $url = $operation->originalUrl();
        $local = $this->localUrls->resolve($url);
        $audioMatches = match ($phase) {
            Phase::SUPPRESSION_PREPAREE, Phase::RETRAIT_PRIVE_EN_ATTENTE => $row['thumbnail_mp3'] === $path && $row['url'] === $url,
            Phase::RETRAIT_PUBLIC_EN_ATTENTE => $row['thumbnail_mp3'] === null && $row['url'] === null,
            Phase::RESTAURATION_PREPAREE => in_array($row['thumbnail_mp3'], [null, ''], true) && in_array($row['url'], [null, ''], true),
            default => false,
        };
        if ($path === '' || ($local['status'] !== 'absent' && $local['relative_path'] !== $path)
            || !$audioMatches) {
            throw new \DomainException('Les références audio ne correspondent pas au journal MP3.');
        }
        if ((int) $db->fetchOne(
            'SELECT COUNT(*) FROM emission WHERE id <> ? AND (thumbnail_mp3 = ? OR url IN (?, ?))',
            [$id, $path, rtrim($this->publicBaseUrl, '/') . '/' . $path, $url === '' ? null : $url],
        ) !== 0 || (int) $db->fetchOne(
            'SELECT COUNT(*) FROM mp3_trash_operation WHERE id <> ? AND active_marker = 1 AND (emission_id = ? OR original_thumbnail_mp3 = ?)',
            [$operationId, $id, $path],
        ) !== 0 || Mp3CleanupReferences::inspectUrls($db, $path, $this->localUrls, $id) !== Mp3DeferredCleanupDecision::PreconditionsVerified) {
            throw new \DomainException('Une référence ou une réservation concurrente protège ce MP3.');
        }

        Mp3TrashBlockResolution::requireResolved($operation, $expectedBlockVersion, fn () => $this->disk->inspect(
            $path, $operation->trashRelativePath(), $operation->size(), $operation->sha256(),
        ));

        return $operation;
    }

    private function checkDisk(callable $action): void
    {
        try {
            $action();
        } catch (Mp3TrashDiskMutationFailure $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            throw new \DomainException('L’identité ou le chemin du MP3 ne peut pas être confirmé.', 0, $error);
        }
    }

    private function transaction(Connection $db, bool &$commitUncertain, callable $change): mixed
    {
        try {
            $db->beginTransaction();
            $result = $change();
            $commitUncertain = true;
            $db->commit();
            $commitUncertain = false;

            return $result;
        } catch (\Throwable $error) {
            try {
                if ($db->getTransactionNestingLevel() !== 0) {
                    $db->rollBack();
                }
            } catch (\Throwable) {
                // Keep the original error. The caller must discard this manager.
            }
            throw $error;
        }
    }

    private function failureReason(string $step, bool $commitUncertain, \Throwable $error): string
    {
        return match (true) {
            $commitUncertain => 'commit_uncertain',
            $step === 'administrative_request' && $error instanceof UniqueConstraintViolationException => 'duplicate_request',
            in_array($step, ['administrative_request', 'administrative_confirmation'], true) => 'trace_unavailable',
            in_array($step, ['precondition', 'storage_lock'], true) => 'refused',
            $error instanceof \DomainException => 'conflict',
            default => 'interrupted',
        };
    }

    private function log(string $event, array $context): void
    {
        try {
            $this->logger->log($event === 'resume_failed' ? 'error' : 'notice',
                isset($context['attempt_id']) ? 'MP3 manual restoration recovery' : 'MP3 manual deletion recovery',
                ['event' => $event] + $context);
        } catch (\Throwable) {
            // Diagnostic availability cannot change a committed result.
        }
    }
}
