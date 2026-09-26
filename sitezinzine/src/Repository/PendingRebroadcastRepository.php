<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingRebroadcast;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

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
     * Retourne toutes les rediffusions actuellement présentes dans le parc.
     *
     * L'émission et sa catégorie sont chargées dans la même requête afin
     * d'éviter des requêtes supplémentaires lors de la construction du JSON
     * destiné à la sidebar.
     *
     * @return PendingRebroadcast[]
     */
    public function findAllForPool(): array
    {
        return $this->createQueryBuilder('pending')
            ->addSelect('emission')
            ->addSelect('category')
            ->innerJoin('pending.emission', 'emission')
            ->leftJoin('emission.categorie', 'category')
            ->orderBy('pending.createdAt', SortDirection::Ascending)
            ->addOrderBy('pending.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PendingRebroadcast[]
     */
    public function findByAssignmentGroupKey(
        string $assignmentGroupKey
    ): array {
        return $this->createQueryBuilder('pending')
            ->andWhere(
                'pending.assignmentGroupKey = :assignmentGroupKey'
            )
            ->setParameter(
                'assignmentGroupKey',
                $assignmentGroupKey
            )
            ->orderBy(
                'pending.createdAt',
                SortDirection::Ascending
            )
            ->addOrderBy(
                'pending.id',
                SortDirection::Ascending
            )
            ->getQuery()
            ->getResult();
    }

    public function existsForAssignmentGroupKey(
        string $assignmentGroupKey
    ): bool {
        return (bool) $this->createQueryBuilder('pending')
            ->select('1')
            ->andWhere(
                'pending.assignmentGroupKey = :assignmentGroupKey'
            )
            ->setParameter(
                'assignmentGroupKey',
                $assignmentGroupKey
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}