<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Emission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Enum\Mp3FailureStage;
use App\Enum\Mp3StoredFileState;
use App\Exception\Mp3OperationFailure;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReferences;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupDecision;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;

/** SQL controls references; disk changes have their own lifetime and compensation. */
final class Mp3Replacement
{
    private ?Mp3CleanupReporter $cleanupReporter = null;

    public function __construct(
        private Mp3Processor $processor,
        private ?Mp3StorageLock $storageLock = null,
        private string $publicBaseUrl = '',
        private string $localUrlPrefixes = '[]',
    ) {}

    /** Transitional explicit injection; Symfony wiring is deliberately deferred. */
    public function setCleanupReporter(Mp3CleanupReporter $cleanupReporter): void
    {
        $this->cleanupReporter = $cleanupReporter;
    }

    public function replace(
        Emission $emission,
        EntityManagerInterface $em,
        ?string $original,
        ?string $originalUrl,
    ): void {
        $upload = $emission->getThumbnailFileMp3();
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            throw new Mp3OperationFailure(Mp3FailureStage::VALIDATION, new \RuntimeException('Upload MP3 invalide.'), true);
        }
        $this->execute($emission, $em, $original, $originalUrl, $upload);
    }

    public function reprocess(
        Emission $emission,
        EntityManagerInterface $em,
        string $original,
        ?string $originalUrl,
    ): void {
        if ($original === '' || $emission->getThumbnailFileMp3() instanceof UploadedFile) {
            throw new \LogicException('Le retraitement éditorial nécessite un MP3 existant sans nouvel upload.');
        }
        $this->execute($emission, $em, $original, $originalUrl, null);
    }

    private function execute(
        Emission $emission,
        EntityManagerInterface $em,
        ?string $original,
        ?string $originalUrl,
        ?UploadedFile $upload,
    ): void {
        $run = fn () => $this->replaceUnderLock($emission, $em, $original, $originalUrl, $upload);
        if ($this->storageLock === null) {
            $run();
            return;
        }

        $entered = false;
        $completed = false;
        try {
            if ($em->getConnection()->getTransactionNestingLevel() !== 0) {
                if (!$this->storageLock->isHeldByCurrentProcess()) {
                    throw new \RuntimeException('Le verrou MP3 doit précéder la transaction SQL externe.');
                }
                // The outer owner retains the lock through its commit. The
                // existing savepoint path deliberately skips obsolete cleanup.
                $entered = true;
                $run();
                return;
            }
            $this->storageLock->synchronized(static function () use ($run, &$entered, &$completed): void {
                $entered = true;
                $run();
                $completed = true;
            });
        } catch (\Throwable $error) {
            if ($entered && !$completed) {
                throw $error;
            }
            // A failed post-callback lock check must not offer an unsafe retry.
            throw new Mp3OperationFailure($entered ? Mp3FailureStage::COMMIT : Mp3FailureStage::CONFLICT, $error, !$entered);
        }
    }

    private function historicalIdentity(string $relative, ?string $url, ?array $identity): Mp3CleanupHistoricalIdentity
    {
        return Mp3CleanupHistoricalIdentity::capture(
            'emissionsMp3', $relative, $url === $this->processor->publicUrl($relative) ? $url : null, $identity,
        );
    }

    private function replaceUnderLock(
        Emission $emission,
        EntityManagerInterface $em,
        ?string $original,
        ?string $originalUrl,
        ?UploadedFile $upload,
    ): void {
        $connection = $em->getConnection();
        $initialLevel = $connection->getTransactionNestingLevel();
        $prepared = null;
        $commitAttempted = false;
        $transactionStarted = false;
        $stage = Mp3FailureStage::DOCTRINE;
        $originalIdentity = $this->storageLock === null ? null : $this->processor->storedFileIdentity($original);
        $originalHash = $this->storageLock === null
            ? $this->processor->storedFileHash($original)
            : ($originalIdentity['sha256'] ?? null);

        try {
            $connection->beginTransaction();
            $transactionStarted = true;
            // Serialize replacements and reject requests based on an obsolete MP3 reference.
            $current = $connection->fetchAssociative(
                'SELECT thumbnail_mp3, url FROM emission WHERE id = ? FOR UPDATE',
                [$emission->getId()]
            );
            if ($current === false || $current['thumbnail_mp3'] !== $original || $current['url'] !== $originalUrl) {
                throw new Mp3OperationFailure(Mp3FailureStage::CONFLICT, new \RuntimeException('Le MP3 a été modifié par une autre requête.'));
            }
            if ($this->storageLock !== null && Mp3PublicationReservations::hasActiveForEmission($connection, $emission->getId())) {
                throw new Mp3OperationFailure(Mp3FailureStage::CONFLICT,
                    new \RuntimeException('Une opération de corbeille est en cours pour cette émission.'));
            }

            // Validate the editorial draft in SQL, but do not commit it. onFlush observers
            // can inspect the incoming upload; Vich's later preUpdate must never receive it.
            $detach = new class($emission) {
                public function __construct(private Emission $emission) {}
                public function onFlush(OnFlushEventArgs $event): void
                {
                    $this->emission->setThumbnailFileMp3(null);
                }
            };
            $events = $em->getEventManager();
            $events->addEventListener([Events::onFlush], $detach);
            try {
                $emission->setThumbnailMp3($original)->setUrl($originalUrl)->setUpdatedat(new \DateTime());
                $em->flush();
            } finally {
                $events->removeEventListener([Events::onFlush], $detach);
                $emission->setThumbnailFileMp3(null);
            }

            $stage = Mp3FailureStage::VALIDATION;
            $source = $upload ?? $this->processor->existingFile($original);
            $stage = Mp3FailureStage::PREPARATION;
            $prepared = $this->processor->prepare($emission, $source);
            $stage = Mp3FailureStage::DOCTRINE;
            $reserved = $this->storageLock === null ? [] : Mp3PublicationReservations::read($connection);
            $relative = $this->processor->publish($prepared, $reserved);
            $emission->setThumbnailMp3($relative)->setUrl($this->processor->publicUrl($relative));
            $emission->setUpdatedat(new \DateTime());
            $stage = Mp3FailureStage::DOCTRINE;
            $em->flush();
            $stage = Mp3FailureStage::COMMIT;
            $commitAttempted = true;
            $connection->commit();
        } catch (\Throwable $error) {
            $rollbackConfirmed = !$transactionStarted;
            if ($transactionStarted && $connection->getTransactionNestingLevel() > $initialLevel) {
                try {
                    $connection->rollBack();
                    $rollbackConfirmed = $connection->getTransactionNestingLevel() === $initialLevel;
                } catch (\Throwable) {
                    // Neither a rollback failure nor an uncertain commit authorizes disk deletion.
                }
            }
            if (!$commitAttempted) {
                $emission->setThumbnailMp3($original)->setUrl($originalUrl);
            }
            // Retain any published candidate, even after rollback, for reconciliation.
            // In particular, never mistake an uncertain commit for permission to remove it.
            $originalPreserved = $originalHash !== null && $originalHash === $this->processor->storedFileHash($original);
            throw new Mp3OperationFailure(
                $error instanceof Mp3OperationFailure ? $error->stage : $stage,
                $error instanceof Mp3OperationFailure ? ($error->getPrevious() ?? $error) : $error,
                !$commitAttempted && $rollbackConfirmed,
                $originalPreserved
            );
        } finally {
            $emission->setThumbnailFileMp3(null);
            if ($prepared !== null) {
                $this->processor->release($prepared);
            }
            if ($upload !== null) {
                $this->processor->consumeUpload($upload, $original);
            }
        }
        // A savepoint is not a durable commit. The enclosing owner must reconcile later.
        if ($initialLevel === 0 && $connection->getTransactionNestingLevel() === 0
            && $original !== null && $original !== $emission->getThumbnailMp3()) {
            try {
                try {
                    $localUrls = AudioLocalUrlResolver::fromConfiguredLibrary($this->publicBaseUrl, $this->localUrlPrefixes);
                    if ($localUrls === null || $this->storageLock?->isHeldByCurrentProcess() !== true) {
                        throw new \RuntimeException('MP3 cleanup requires configured local URLs and the audio storage lock.');
                    }
                    $decision = Mp3CleanupReferences::inspect($connection, $original, $localUrls);
                    if ($decision !== Mp3DeferredCleanupDecision::PreconditionsVerified) {
                        throw new \RuntimeException('MP3 cleanup refused: ' . $decision->value);
                    }
                } catch (\Throwable $error) {
                    $this->cleanupReporter?->reportReferenceCheckFailure(
                        $emission->getId(), $original, $emission->getThumbnailMp3(), $error,
                        $this->historicalIdentity($original, $originalUrl, $originalIdentity),
                    );
                    return;
                }
                try {
                    $current = $this->processor->inspectStoredFile($original);
                    if ($current['state'] === Mp3StoredFileState::Absent) {
                        return;
                    }
                    if ($current['state'] !== Mp3StoredFileState::Verified) {
                        throw new \RuntimeException('The original MP3 cannot be checked: ' . $current['state']->value);
                    }
                    if ($originalIdentity === null || $current['identity'] !== $originalIdentity) {
                        throw new \RuntimeException('The original MP3 identity is missing or changed before cleanup.');
                    }
                    $this->processor->removeObsolete($original);
                    if ($this->processor->inspectStoredFile($original)['state'] !== Mp3StoredFileState::Absent) {
                        throw new \RuntimeException('The original MP3 removal could not be confirmed.');
                    }
                } catch (\Throwable $error) {
                    $this->cleanupReporter?->reportObsoleteCleanupFailure(
                        $emission->getId(), $original, $emission->getThumbnailMp3(), $error,
                        $this->historicalIdentity($original, $originalUrl, $originalIdentity),
                    );
                }
            } catch (\Throwable) {
                // Even an unexpected reporting failure cannot invalidate a confirmed replacement.
            }
        }
    }
}
