<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRuleSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GridSlotArbitrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GridSlotArbitration::class);
    }

    /**
     * Retourne les arbitrages utiles pour une semaine affichée.
     *
     * @return GridSlotArbitration[]
     */
    public function findRelevantForWeek(
        \DateTimeImmutable $startOfWeek,
        \DateTimeImmutable $endOfWeek
    ): array {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status != :cancelled')
            ->andWhere(
                '(a.originalStartsAt >= :start AND a.originalStartsAt < :end)
                 OR
                 (a.rescheduledStartsAt IS NOT NULL AND a.rescheduledStartsAt >= :start AND a.rescheduledStartsAt < :end)'
            )
            ->setParameter('cancelled', GridSlotArbitration::STATUS_CANCELLED)
            ->setParameter('start', $startOfWeek)
            ->setParameter('end', $endOfWeek)
            ->orderBy('a.originalStartsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveForOccurrence(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $originalStartsAt
    ): ?GridSlotArbitration {
        return $this->createQueryBuilder('a')
            ->andWhere('a.slot = :slot')
            ->andWhere('a.originalStartsAt = :originalStartsAt')
            ->andWhere('a.status != :cancelled')
            ->setParameter('slot', $slot)
            ->setParameter('originalStartsAt', $originalStartsAt)
            ->setParameter('cancelled', GridSlotArbitration::STATUS_CANCELLED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    
}