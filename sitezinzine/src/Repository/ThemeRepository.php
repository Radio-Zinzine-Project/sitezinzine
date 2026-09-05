<?php

namespace App\Repository;

use App\Entity\Emission;
use App\Entity\Theme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<Theme>
 */
class ThemeRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly PaginatorInterface $paginator
    ) {
        parent::__construct($registry, Theme::class);
    }

    public function paginateEmissions(
        Theme $theme,
        int $page = 1,
        string $initiale = ''
    ): PaginationInterface {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('emission')
            ->from(Emission::class, 'emission')
            ->andWhere('emission.theme = :theme')
            ->setParameter('theme', $theme);

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