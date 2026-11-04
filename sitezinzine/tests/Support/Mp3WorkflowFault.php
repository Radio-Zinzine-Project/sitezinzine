<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Emission;
use Doctrine\ORM\Event\OnFlushEventArgs;

/** Observes the persistence boundary; never writes application data or files. */
final class Mp3WorkflowFault
{
    public bool $triggered = false;
    public ?Emission $observed = null;
    public ?string $preparedRelative = null;
    public array $originalPresentBeforeConfirmation = [];

    public function __construct(
        private Emission $emission,
        private string $original,
        private bool $failPreparedSave = false,
    ) {}

    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager();
        foreach ($em->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Emission || $entity->getId() !== $this->emission->getId()) {
                continue;
            }
            $this->observed = $entity;
            $this->originalPresentBeforeConfirmation[] = is_file(Mp3TestStorage::root() . '/' . $this->original);
            $relative = $entity->getThumbnailMp3();
            if ($this->failPreparedSave && $relative !== null
                && ($relative !== $this->original || !$entity->getThumbnailFileMp3() instanceof \Symfony\Component\HttpFoundation\File\UploadedFile)
                && str_contains($relative, '/')
                && is_file(Mp3TestStorage::root() . '/' . $relative)) {
                $this->triggered = true;
                $this->preparedRelative = $relative;
                throw new \RuntimeException('MP3_TEST_FAULT:doctrine');
            }
        }
    }
}
