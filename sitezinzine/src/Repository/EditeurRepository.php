<?php

namespace App\Repository;

use App\Entity\Editeur;
use App\Entity\Emission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<Editeur>
 */
class EditeurRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly PaginatorInterface $paginator
    ) {
        parent::__construct($registry, Editeur::class);
    }

    public function paginateEmissions(
        Editeur $editeur,
        int $page = 1,
        string $initiale = ''
    ): PaginationInterface {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('emission')
            ->from(Emission::class, 'emission')
            ->andWhere('emission.editeur = :editeur')
            ->setParameter('editeur', $editeur);

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