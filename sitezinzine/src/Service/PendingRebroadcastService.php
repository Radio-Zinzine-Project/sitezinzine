<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use Doctrine\ORM\EntityManagerInterface;

final class PendingRebroadcastService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Crée une rediffusion en attente pour un groupe régulier.
     *
     * Le flush est volontairement laissé à l'appelant afin que la création
     * du PendingRebroadcast puisse faire partie d'une opération Doctrine
     * plus large et rester atomique.
     */
    public function createForGroup(
        Emission $emission,
        string $assignmentGroupKey
    ): PendingRebroadcast {
        if ('' === trim($assignmentGroupKey)) {
            throw new \InvalidArgumentException(
                'La clé du groupe d’affectation ne peut pas être vide.'
            );
        }

        $pendingRebroadcast = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($assignmentGroupKey);

        $this->entityManager->persist($pendingRebroadcast);

        return $pendingRebroadcast;
    }
}