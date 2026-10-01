<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingRebroadcast;
use App\Entity\Emission;
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

    public function existsForEmission(Emission $emission): bool
    {
        return null !== $this->createQueryBuilder('pending')
            ->select('pending.id')
            ->andWhere('pending.emission = :emission')
            ->setParameter('emission', $emission)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne les identifiants des émissions qui possèdent actuellement
     * au moins une rediffusion dans le parc.
     *
     * Les identifiants fournis sont normalisés afin d'éviter les doublons
     * et d'ignorer les valeurs invalides.
     *
     * @param int[] $emissionIds
     *
     * @return int[]
     */
    public function findEmissionIdsPresentInPool(array $emissionIds): array
    {
        $emissionIds = array_values(array_unique(array_filter(
            array_map('intval', $emissionIds),
            static fn(int $id): bool => $id > 0
        )));

        if ([] === $emissionIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('pending')
            ->select('DISTINCT IDENTITY(pending.emission) AS emissionId')
            ->andWhere('pending.emission IN (:emissionIds)')
            ->setParameter('emissionIds', $emissionIds)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(
            static fn(array $row): int => (int) $row['emissionId'],
            $rows
        ));
    }
}
