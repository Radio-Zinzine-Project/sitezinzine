<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Emission;
use App\Exception\Mp3TrashFailure;
use App\Service\AudioLibrary\Orphan\Mp3CleanupServiceFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

/** Bridges the journal transaction to the managed audio snapshot of the edit form. */
class Mp3TrashEdit
{
    public function __construct(private readonly Mp3TrashService $trashService) {}

    public function trash(
        EntityManagerInterface $editor,
        Emission $emission,
        string $expectedPath,
        ?string $expectedUrl,
        ?int $actorId,
    ): void {
        $emissionId = $emission->getId();
        try {
            if ($actorId === null || $emissionId === null || !$editor->contains($emission)
                || $editor->getConnection()->getTransactionNestingLevel() !== 0) {
                throw new \LogicException('La suppression MP3 exige une émission gérée hors transaction éditoriale.');
            }
            $db = Mp3CleanupServiceFactory::createConnection($editor->getConnection());
        } catch (\Throwable $error) {
            throw new Mp3TrashFailure('precondition', null, false, $error);
        }

        try {
            // A fresh ORM event manager keeps form/Vich listeners outside this unit of work.
            $journal = new EntityManager($db, $editor->getConfiguration());
            $this->trashService->trash($journal, $emissionId, $expectedPath, $expectedUrl, $actorId);
        } finally {
            $db->close();
        }

        // The separate commit already updated these columns. Synchronize only
        // their baseline so the editorial flush cannot overwrite a later upload.
        $emission->setThumbnailMp3(null)->setUrl(null);
        $uow = $editor->getUnitOfWork();
        foreach (['thumbnailMp3', 'url'] as $field) {
            $uow->setOriginalEntityProperty(spl_object_id($emission), $field, null);
        }
    }
}
