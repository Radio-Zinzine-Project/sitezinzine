<?php

namespace App\Repository;

use App\Entity\PendingRebroadcast;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use SortDirection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PendingRebroadcast>
 */
class PendingRebroadcastRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingRebroadcast::class);
    }

    /**
     * @return PendingRebroadcast[]
     */
    public function findByAssignmentGroupKey(string $assignmentGroupKey): array
    {
        return $this->createQueryBuilder('pending')
            ->andWhere('pending.assignmentGroupKey = :assignmentGroupKey')
            ->setParameter('assignmentGroupKey', $assignmentGroupKey)
            ->orderBy('pending.createdAt', SortDirection::Ascending)
            ->addOrderBy('pending.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function existsForAssignmentGroupKey(string $assignmentGroupKey): bool
    {
        return (bool) $this->createQueryBuilder('pending')
            ->select('1')
            ->andWhere('pending.assignmentGroupKey = :assignmentGroupKey')
            ->setParameter('assignmentGroupKey', $assignmentGroupKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}