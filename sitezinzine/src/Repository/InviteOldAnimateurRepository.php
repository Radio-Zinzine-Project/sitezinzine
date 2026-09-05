<?php

namespace App\Repository;

use App\Entity\Emission;
use App\Entity\InviteOldAnimateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<InviteOldAnimateur>
 */
class InviteOldAnimateurRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly PaginatorInterface $paginator
    ) {
        parent::__construct($registry, InviteOldAnimateur::class);
    }

    public function findFiltered(
        string $initiale = '',
        string $type = ''
    ): array {
        $qb = $this->createQueryBuilder('i')
            ->orderBy('i.lastName', 'ASC')
            ->addOrderBy('i.firstName', 'ASC');

        if ($initiale !== '') {
            if ($initiale === '0-9') {
                $qb
                    ->andWhere('SUBSTRING(i.lastName, 1, 1) BETWEEN :zero AND :nine')
                    ->setParameter('zero', '0')
                    ->setParameter('nine', '9');
            } else {
                $qb
                    ->andWhere('UPPER(i.lastName) LIKE :initiale')
                    ->setParameter('initiale', $initiale . '%');
            }
        }

        if ($type === 'invite') {
            $qb
                ->andWhere('i.ancienanimateur = :ancien')
                ->setParameter('ancien', false);
        }

        if ($type === 'ancien') {
            $qb
                ->andWhere('i.ancienanimateur = :ancien')
                ->setParameter('ancien', true);
        }

        return $qb
            ->getQuery()
            ->getResult();
    }

    public function paginateEmissions(
        InviteOldAnimateur $inviteOldAnimateur,
        int $page = 1,
        string $initiale = ''
    ): PaginationInterface {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('emission')
            ->from(Emission::class, 'emission')
            ->innerJoin('emission.inviteOldAnimateurs', 'inviteOldAnimateur')
            ->andWhere('inviteOldAnimateur = :inviteOldAnimateur')
            ->setParameter('inviteOldAnimateur', $inviteOldAnimateur);

        if ($initiale !== '') {
            if ($initiale === '0-9') {
                $qb
                    ->andWhere('SUBSTRING(emission.titre, 1, 1) BETWEEN :zero AND :nine')
                    ->setParameter('zero', '0')
                    ->setParameter('nine', '9');
            } else {
                $qb
                    ->andWhere('UPPER(emission.titre) LIKE :initiale')
                    ->setParameter('initiale', $initiale . '%');
            }
        }

        $qb
            ->orderBy('emission.datepub', 'DESC')
            ->addOrderBy('emission.titre', 'ASC');

        return $this->paginator->paginate(
            $qb,
            $page,
            20
        );
    }
}