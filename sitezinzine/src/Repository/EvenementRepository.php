<?php

namespace App\Repository;

use App\Entity\Evenement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evenement>
 */
class EvenementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evenement::class);
    }

    /**
     * Retourne les 3 prochains événements ou événements en cours.
     *
     * @return Evenement[]
     */
    public function findUpcomingEvenements(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.dateDebut >= :today')
            ->orWhere('(:today BETWEEN a.dateDebut AND a.dateFin)')
            ->andWhere('a.valid = 1')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('a.dateDebut', 'ASC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les derniers événements publics,
     * qu'ils soient à venir ou déjà terminés.
     *
     * Seuls les événements validés et non supprimés sont affichés.
     *
     * @return Evenement[]
     */
    public function findLatestPublicEvenements(int $limit = 3): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.valid = 1')
            ->andWhere('a.softDelete = 0')
            ->orderBy('a.dateDebut', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findAllDesc(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.softDelete = 0')
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOldEvenements(\DateTimeImmutable $dateLimit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.dateFin < :dateLimit')
            ->setParameter('dateLimit', $dateLimit)
            ->getQuery()
            ->getResult();
    }
}