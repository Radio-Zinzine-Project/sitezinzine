<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Includes firewall/IsGranted refusals, which never reach the controller. */
final class AudioInventoryResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => ['makePrivate', -128]]; }
    public function makePrivate(ResponseEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with((string)$event->getRequest()->attributes->get('_route'), 'admin.audio_library.inventory.')
            || $path === '/admin/audio-library/inventory' || str_starts_with($path, '/admin/audio-library/inventory/')) {
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
        }
    }
}
