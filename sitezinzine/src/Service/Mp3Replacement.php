<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Emission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Enum\Mp3FailureStage;
use App\Exception\Mp3OperationFailure;

/** SQL controls references; disk changes have their own lifetime and compensation. */
final class Mp3Replacement
{
    public function __construct(private Mp3Processor $processor) {}

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
        $connection = $em->getConnection();
        $initialLevel = $connection->getTransactionNestingLevel();
        $prepared = null;
        $commitAttempted = false;
        $transactionStarted = false;
        $stage = Mp3FailureStage::DOCTRINE;
        $originalHash = $this->processor->storedFileHash($original);

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
            $relative = $this->processor->publish($prepared);
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
                $references = (int) $connection->fetchOne('SELECT COUNT(*) FROM emission WHERE thumbnail_mp3 = ?', [$original]);
                if ($references === 0) {
                    $this->processor->removeObsolete($original);
                }
            } catch (\Throwable) {
                // A retained old file is preferable to turning a confirmed replacement into failure.
            }
        }
    }
}
