<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3AdministrativeEventType as Type;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3AdministrativeRequestUncertain;
use App\Exception\Mp3RestoreFailure;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReferences;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupDecision;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class Mp3RestoreService
{
    public function __construct(private Mp3StorageLock $lock, private Mp3TrashDisk $disk,
        private Mp3AdministrativeJournal $administrativeJournal, private AudioLocalUrlResolver $localUrls) {}

    /**
     * The authorized caller supplies a dedicated, clean entity manager.
     * Discard that manager after failure and inspect the durable journal.
     */
    public function restore(EntityManagerInterface $em, string $operationId, int $actorId, Phase $expectedPhase,
        ?string $expectedLastAttemptId, string $requestId): Mp3TrashOperation
    {
        $attemptId = null;
        $step = 'precondition';
        $requestConfirmed = false;
        $commitUncertain = false;
        $observed = null;
        $db = null;
        try {
            $db = $em->getConnection();
            $uow = $em->getUnitOfWork();
            if (!$em->isOpen() || $db->getTransactionNestingLevel() !== 0 || $uow->size() !== 0
                || $uow->getScheduledEntityInsertions() || $uow->getScheduledEntityUpdates()
                || $uow->getScheduledEntityDeletions() || $uow->getScheduledCollectionUpdates()
                || $uow->getScheduledCollectionDeletions()) {
                throw new \LogicException('La restauration MP3 exige un gestionnaire dédié sans transaction en cours.');
            }
            Mp3TrashOperation::trashRelativePathFor($operationId);
            if ($actorId <= 0 || $expectedPhase !== Phase::EN_CORBEILLE
                || preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1
                || ($expectedLastAttemptId !== null && preg_match('/\A[a-f0-9]{32}\z/', $expectedLastAttemptId) !== 1)
                || $requestId === $operationId || $requestId === $expectedLastAttemptId) {
                throw new \InvalidArgumentException('Identifiants ou phase de restauration MP3 invalides.');
            }

            $step = 'storage_lock';
            return $this->lock->synchronized(function () use (
                $em, $db, $operationId, $actorId, $expectedPhase, $expectedLastAttemptId, $requestId,
                &$attemptId, &$step, &$requestConfirmed, &$commitUncertain, &$observed,
            ): Mp3TrashOperation {
                // The initial administrative record supplies the immutable attempt context for every event.
                $step = 'administrative_request';
                do {
                    $attemptId = bin2hex(random_bytes(16));
                } while (in_array($attemptId, [$operationId, $requestId, $expectedLastAttemptId], true));
                if ($db instanceof PrimaryReadReplicaConnection) {
                    $db->ensureConnectedToPrimary();
                }
                $this->administrativeJournal->recordRequest($db, $requestId, $operationId, $actorId, 'restore',
                    $expectedPhase, $attemptId, new \DateTimeImmutable());
                $requestConfirmed = true;

                $step = 'reservation';
                $operation = $this->transaction($db, $commitUncertain, function () use (
                    $em, $db, $operationId, $actorId, $expectedPhase, $expectedLastAttemptId, $requestId, $attemptId, &$step, &$observed,
                ): Mp3TrashOperation {
                    // Discover the ID, then acquire row locks in the common
                    // emission → journal order under the storage lock.
                    $id = $db->fetchOne('SELECT emission_id FROM mp3_trash_operation WHERE id = ?', [$operationId]);
                    if ($id === false) {
                        throw new \DomainException('Opération de corbeille MP3 introuvable.');
                    }
                    $this->requireEmission($db, (int) $id);
                    $operation = $em->find(Mp3TrashOperation::class, $operationId, LockMode::PESSIMISTIC_WRITE);
                    if (!$operation instanceof Mp3TrashOperation || $operation->id() !== $operationId || $operation->emissionId() !== (int) $id) {
                        throw new \DomainException('Le journal MP3 est introuvable ou incohérent.');
                    }
                    $observed = $operation->phase();
                    if ($observed !== $expectedPhase || $operation->lastAttemptId() !== $expectedLastAttemptId) {
                        $step = 'confirmation';
                        throw new \DomainException('La phase ou la dernière tentative de restauration a changé.');
                    }
                    if ($operation->isBlocked()) {
                        throw new \DomainException('Le journal MP3 est bloqué.');
                    }
                    $this->requireAvailableAssociation($db, $operation);
                    try {
                        $this->disk->assertRestoreAvailable($operation);
                    } catch (\RuntimeException $error) {
                        throw new \DomainException('La copie privée ou le nom public ne permet pas la restauration.', 0, $error);
                    }
                    $operation->prepareRestoration($attemptId, $actorId, new \DateTimeImmutable());
                    $this->flushAndTrace($em, $db, $requestId, $operation, 'restoration_prepared', $step);

                    return $operation;
                });

                $observed = Phase::RESTAURATION_PREPAREE;
                $step = 'public_link';
                $this->disk->prepareRestore($operation);

                $step = 'association';
                $this->transaction($db, $commitUncertain, function () use ($db, $em, $operation, $attemptId, $requestId, &$step, &$observed): void {
                    $id = $operation->emissionId();
                    $this->requireEmission($db, $id);
                    $this->requireJournal($db, $operation->id(), Phase::RESTAURATION_PREPAREE, $attemptId, $observed);
                    $this->requireAvailableAssociation($db, $operation);
                    $this->disk->assertRestorePrepared($operation);
                    if ($db->update('emission', [
                        'thumbnail_mp3' => $operation->originalThumbnailMp3(),
                        'url' => $operation->originalUrl(),
                    ], ['id' => $id]) !== 1) {
                        throw new \RuntimeException('La réassociation audio n’a pas été confirmée.');
                    }
                    $operation->confirmAssociation($attemptId, new \DateTimeImmutable());
                    $this->flushAndTrace($em, $db, $requestId, $operation, 'associated', $step);
                });

                $observed = Phase::RETRAIT_PRIVE_EN_ATTENTE;
                $step = 'finalization';
                $this->transaction($db, $commitUncertain, function () use ($db, $em, $operation, $attemptId, $requestId, &$step, &$observed): void {
                    $this->requireEmission($db, $operation->emissionId(), $operation);
                    $this->requireJournal($db, $operation->id(), Phase::RETRAIT_PRIVE_EN_ATTENTE, $attemptId, $observed);
                    $this->disk->finalizeRestore($operation);
                    $operation->confirmPrivateRemoval($attemptId, new \DateTimeImmutable());
                    $this->flushAndTrace($em, $db, $requestId, $operation, 'private_removed', $step);
                });
                $observed = Phase::RESTAUREE;
                $step = 'lock_integrity';

                return $operation;
            });
        } catch (\Throwable $error) {
            $uncertain = $commitUncertain || $error instanceof Mp3AdministrativeRequestUncertain;
            $reason = match (true) {
                $uncertain => 'commit_uncertain',
                $step === 'administrative_request' && $error instanceof UniqueConstraintViolationException => 'duplicate_request',
                in_array($step, ['administrative_request', 'administrative_confirmation'], true) => 'trace_unavailable',
                $step === 'storage_lock' => 'lock_unavailable',
                $step === 'precondition' => 'refused',
                $step === 'confirmation' => 'confirmation_obsolete',
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
                        'confirmation_obsolete', 'conflict' => Type::REFUSAL,
                        default => Type::FAILURE,
                    };
                    $this->administrativeJournal->recordOutcome($db, $requestId, $type, $observed, $reason, new \DateTimeImmutable());
                } catch (\Throwable $traceFailure) {
                    // The restoration error remains the primary error.
                }
            }
            $reportedAttemptId = $requestConfirmed || $error instanceof Mp3AdministrativeRequestUncertain ? $attemptId : null;
            throw new Mp3RestoreFailure($step, $operationId, $reportedAttemptId, $uncertain, $error, $reason, $requestId, $traceFailure);
        }
    }

    private function flushAndTrace(EntityManagerInterface $em, Connection $db, string $requestId,
        Mp3TrashOperation $operation, string $code, string &$step): void
    {
        $em->flush();
        $businessStep = $step;
        $step = 'administrative_confirmation';
        $this->administrativeJournal->recordInTransaction($db, $requestId, Type::TRANSITION_CONFIRMED,
            $operation->phase(), $code, new \DateTimeImmutable());
        $step = $businessStep;
    }

    private function requireEmission(Connection $db, int $id, ?Mp3TrashOperation $restored = null): void
    {
        $row = $db->fetchAssociative('SELECT thumbnail_mp3, url, deleted_at FROM emission WHERE id = ? FOR UPDATE', [$id]);
        if ($row === false || $row['deleted_at'] !== null) {
            throw new \DomainException('L’émission d’origine est indisponible.');
        }
        $expected = $restored === null
            ? in_array($row['thumbnail_mp3'], [null, ''], true) && in_array($row['url'], [null, ''], true)
            : $row['thumbnail_mp3'] === $restored->originalThumbnailMp3() && $row['url'] === $restored->originalUrl();
        if (!$expected) {
            throw new \DomainException('Les références audio de l’émission empêchent la restauration.');
        }
    }

    private function requireJournal(Connection $db, string $id, Phase $phase, string $attemptId, ?Phase &$observed): void
    {
        $row = $db->fetchAssociative('SELECT phase, blocked, last_attempt_id FROM mp3_trash_operation WHERE id = ? FOR UPDATE', [$id]);
        $observed = $row === false ? null : Phase::tryFrom($row['phase']);
        if ($row === false || $observed !== $phase || (int) $row['blocked'] !== 0 || $row['last_attempt_id'] !== $attemptId) {
            throw new \DomainException('Le journal MP3 est bloqué ou sa phase ou tentative a changé.');
        }
    }

    private function requireAvailableAssociation(Connection $db, Mp3TrashOperation $operation): void
    {
        if ((int) $db->fetchOne(
            'SELECT COUNT(*) FROM mp3_trash_operation WHERE id <> ? AND active_marker = 1 AND (emission_id = ? OR original_thumbnail_mp3 = ?)',
            [$operation->id(), $operation->emissionId(), $operation->originalThumbnailMp3()],
        ) !== 0 || (int) $db->fetchOne(
            'SELECT COUNT(*) FROM emission WHERE id <> ? AND (thumbnail_mp3 = ? OR url = ?)',
            [$operation->emissionId(), $operation->originalThumbnailMp3(), $operation->originalUrl()],
        ) !== 0 || Mp3CleanupReferences::inspectUrls($db, $operation->originalThumbnailMp3(), $this->localUrls, $operation->emissionId())
            !== Mp3DeferredCleanupDecision::PreconditionsVerified) {
            throw new \DomainException('Le chemin MP3 ou l’URL est déjà réservé ou associé à une autre émission.');
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
                // Inspect the outcome with a fresh manager. Never compensate on disk here.
            }
            throw $error;
        }
    }
}
