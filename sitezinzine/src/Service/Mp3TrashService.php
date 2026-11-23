<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3TrashFailure;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReferences;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupDecision;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class Mp3TrashService
{
    public function __construct(
        private Mp3StorageLock $lock,
        private Mp3TrashDisk $disk,
        private Mp3Processor $processor,
        private AudioLocalUrlResolver $localUrls,
    ) {}

    /**
     * The authorized caller supplies the actor ID, expected audio pair and a
     * dedicated, clean entity manager. Discard that manager after a failure.
     */
    public function trash(
        EntityManagerInterface $em,
        int $emissionId,
        ?string $expectedThumbnailMp3,
        ?string $expectedUrl,
        int $actorId,
    ): Mp3TrashOperation {
        $db = $em->getConnection();
        $operation = null;
        $entered = false;
        try {
            $uow = $em->getUnitOfWork();
            if (!$em->isOpen() || $db->getTransactionNestingLevel() !== 0 || $uow->size() !== 0
                || $uow->getScheduledEntityInsertions() || $uow->getScheduledEntityUpdates()
                || $uow->getScheduledEntityDeletions() || $uow->getScheduledCollectionUpdates()
                || $uow->getScheduledCollectionDeletions()) {
                throw new \LogicException('La corbeille MP3 exige un gestionnaire dédié sans transaction en cours.');
            }

            return $this->lock->synchronized(function () use (
                $em, $db, $emissionId, $expectedThumbnailMp3, $expectedUrl, $actorId, &$operation, &$entered,
            ): Mp3TrashOperation {
                $entered = true;
                $this->transaction($db, 'reservation', $operation, function () use (
                    $em, $db, $emissionId, $expectedThumbnailMp3, $expectedUrl, $actorId, &$operation,
                ): void {
                    $this->requireEmission($db, $emissionId, $expectedThumbnailMp3, $expectedUrl);
                    $url = $this->localUrls->resolve($expectedUrl);
                    if ($expectedThumbnailMp3 === null || $expectedThumbnailMp3 === ''
                        || ($url['status'] !== 'absent' && $url['relative_path'] !== $expectedThumbnailMp3)) {
                        throw new \DomainException('Aucun MP3 local non ambigu à mettre en corbeille.');
                    }
                    if ((int) $db->fetchOne(
                        'SELECT COUNT(*) FROM mp3_trash_operation WHERE active_marker = 1 AND (emission_id = ? OR original_thumbnail_mp3 = ?)',
                        [$emissionId, $expectedThumbnailMp3],
                    ) !== 0) {
                        throw new \DomainException('Une opération MP3 est déjà réservée pour cette émission ou ce chemin.');
                    }
                    $this->requireUnshared($db, $emissionId, $expectedThumbnailMp3, $expectedUrl);
                    $identity = $this->processor->storedFileIdentity($expectedThumbnailMp3);
                    if ($identity === null || $identity['size'] <= 0) {
                        throw new \DomainException('Le fichier MP3 local est absent ou son identité est incertaine.');
                    }
                    $operation = new Mp3TrashOperation(
                        bin2hex(random_bytes(16)), $emissionId, $expectedThumbnailMp3, $expectedUrl,
                        $identity['size'], $identity['sha256'], $actorId, new \DateTimeImmutable(),
                    );
                    $em->persist($operation);
                    $em->flush();
                });

                $this->transaction($db, 'disassociation', $operation, function () use ($em, $db, $operation): void {
                    $id = $operation->emissionId();
                    $path = $operation->originalThumbnailMp3();
                    $url = $operation->originalUrl();
                    $this->requireEmission($db, $id, $path, $url);
                    $this->requireJournal($db, $operation, Phase::SUPPRESSION_PREPAREE);
                    $this->requireUnshared($db, $id, $path, $url);
                    $this->disk->prepareTrash($operation);
                    if ($db->update('emission', ['thumbnail_mp3' => null, 'url' => null], ['id' => $id]) !== 1) {
                        throw new \RuntimeException('La désassociation audio n’a pas été confirmée.');
                    }
                    $operation->confirmDisassociation(new \DateTimeImmutable());
                    $em->flush();
                });

                $this->transaction($db, 'finalization', $operation, function () use ($em, $db, $operation): void {
                    $id = $operation->emissionId();
                    $this->requireEmission($db, $id, null, null);
                    $this->requireJournal($db, $operation, Phase::RETRAIT_PUBLIC_EN_ATTENTE);
                    $this->requireUnshared($db, $id, $operation->originalThumbnailMp3(), $operation->originalUrl());
                    $this->disk->finalizeTrash($operation);
                    $operation->confirmPublicRemoval(new \DateTimeImmutable());
                    $em->flush();
                });

                return $operation;
            });
        } catch (Mp3TrashFailure $failure) {
            throw $failure;
        } catch (\Throwable $error) {
            throw new Mp3TrashFailure($entered ? 'lock_integrity' : 'precondition', $operation?->id(), false, $error);
        }
    }

    private function requireEmission(Connection $db, int $id, ?string $path, ?string $url): void
    {
        $row = $db->fetchAssociative('SELECT thumbnail_mp3, url, deleted_at FROM emission WHERE id = ? FOR UPDATE', [$id]);
        if ($row === false || $row['deleted_at'] !== null || $row['thumbnail_mp3'] !== $path || $row['url'] !== $url) {
            throw new \DomainException('L’émission est indisponible ou ses références audio ont changé.');
        }
    }

    private function requireJournal(Connection $db, Mp3TrashOperation $operation, Phase $phase): void
    {
        $row = $db->fetchAssociative('SELECT phase, blocked FROM mp3_trash_operation WHERE id = ? FOR UPDATE', [$operation->id()]);
        if ($row === false || $row['phase'] !== $phase->value || (int) $row['blocked'] !== 0) {
            throw new \DomainException('Le journal MP3 est bloqué ou n’est plus dans la phase attendue.');
        }
    }

    private function requireUnshared(Connection $db, int $id, string $path, ?string $url): void
    {
        if ((int) $db->fetchOne(
            'SELECT COUNT(*) FROM emission WHERE id <> ? AND (thumbnail_mp3 = ? OR url IN (?, ?))',
            [$id, $path, $this->processor->publicUrl($path), $url === '' ? null : $url],
        ) !== 0 || Mp3CleanupReferences::inspectUrls($db, $path, $this->localUrls, $id) !== Mp3DeferredCleanupDecision::PreconditionsVerified) {
            throw new \DomainException('Le fichier MP3 est encore référencé par une autre émission.');
        }
    }

    private function transaction(Connection $db, string $step, ?Mp3TrashOperation &$operation, callable $change): void
    {
        $commitAttempted = false;
        try {
            $db->beginTransaction();
            $change();
            $commitAttempted = true;
            $db->commit();
        } catch (\Throwable $error) {
            try {
                if ($db->getTransactionNestingLevel() !== 0) {
                    $db->rollBack();
                }
            } catch (\Throwable) {
                // The caller discards the manager. No disk compensation or
                // retry follows an unconfirmed commit or a failed rollback.
            }
            throw new Mp3TrashFailure($step, $operation?->id(), $commitAttempted, $error);
        }
    }
}
