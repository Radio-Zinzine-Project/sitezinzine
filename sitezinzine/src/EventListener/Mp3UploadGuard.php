<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/** Reject a raw Vich upload that bypasses private preparation and SQL coordination. */
#[AsEventListener(event: Events::PRE_UPLOAD, priority: 100)]
final class Mp3UploadGuard
{
    public function __invoke(Event $event): void
    {
        if ($event->getMapping()->getMappingName() === 'emissionsMp3') {
            throw new \LogicException('Les MP3 doivent être préparés par le traitement sécurisé avant enregistrement.');
        }
    }
}
