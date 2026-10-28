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
     * Retourne les rediffusions actives non régulières du parc.
     *
     * Les groupes issus des règles de programmation (rule_)
     * sont exclus de cette rubrique, mais restent enregistrés
     * en base et accessibles par les autres méthodes du repository.
     *
     * Les entrées dismissed restent également exclues.
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
            ->andWhere('pending.status = :status')
            ->setParameter(
                'status',
                PendingRebroadcast::STATUS_PENDING
            )
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
     * Retourne les identifiants des émissions possédant
     * au moins une rediffusion active dans le parc.
     *
     * Les Pending dismissed ne sont pas considérés
     * comme présents dans le parc.
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
            ->andWhere('pending.status = :status')
            ->setParameter('emissionIds', $emissionIds)
            ->setParameter(
                'status',
                PendingRebroadcast::STATUS_PENDING
            )
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(
            static fn(array $row): int => (int) $row['emissionId'],
            $rows
        ));
    }
}
