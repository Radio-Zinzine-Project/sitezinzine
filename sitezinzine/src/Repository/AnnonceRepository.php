<?php

namespace App\Repository;

use App\Entity\Annonce;
use Doctrine\ORM\Query;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Annonce>
 */
class AnnonceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Annonce::class);
    }

    /**
     * @return Annonce[] Returns an array of Annonce objects
     */
    public function findUpcomingAnnonces(): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.dateDebut >= :today')  // Événements futurs
            ->orWhere('(:today BETWEEN a.dateDebut AND a.dateFin)') // Événements en cours
            ->andWhere('a.valid = 1')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('a.dateDebut', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function findUpcomingAnnoncesQuery(): Query
    {
        return $this->createQueryBuilder('a')
            ->where(
                '(a.dateDebut >= :today OR :today BETWEEN a.dateDebut AND a.dateFin)'
            )
            ->andWhere('a.valid = 1')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('a.dateDebut', 'ASC')
            ->getQuery();
    }


    public function findAllDesc(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.softDelete = 0')
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    

public function findAllDescQuery(): Query
{
    return $this->createQueryBuilder('a')
        ->andWhere('a.softDelete = :softDelete')
        ->setParameter('softDelete', false)
        ->orderBy('a.updateAt', 'DESC')
        ->addOrderBy('a.id', 'DESC')
        ->getQuery();
}

    public function findOldAnnonces(\DateTimeImmutable $dateLimit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.dateFin < :dateLimit')
            ->setParameter('dateLimit', $dateLimit)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Annonce[] Returns an array of Annonce objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('a.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Annonce
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
