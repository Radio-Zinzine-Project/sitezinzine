<?php

namespace App\Repository;

use App\Entity\Categories;
use App\Entity\Editeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\QueryBuilder;

class CategoriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private PaginatorInterface $paginator)
    {
        parent::__construct($registry, Categories::class);
    }

    public function paginateCategoriesWithCount(
        int $page,
        int $limit,
        ?UserInterface $user = null,
        ?string $initiale = null
    ): PaginationInterface {
        $qb = $this->createQueryBuilder('c')
            ->select('c', 'COUNT(DISTINCT r.id) AS total')
            ->leftJoin('c.emissions', 'r')
            ->andWhere('c.softDelete = false')
            ->groupBy('c.id')
            ->orderBy('c.titre', 'ASC');

        if ($initiale !== null && $initiale !== '') {
            if ($initiale === '0-9') {
                $qb->andWhere("REGEXP(c.titre, '^[0-9]') = 1");
            } elseif (preg_match('/^[A-Z]$/', $initiale)) {
                $qb->andWhere('UPPER(c.titre) LIKE :initiale')
                    ->setParameter('initiale', $initiale . '%');
            }
        }

        if (
            $user
            && !in_array('ROLE_ADMIN', $user->getRoles(), true)
            && !in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)
        ) {
            // inner join volontaire : on veut UNIQUEMENT les catégories liées à ce user
            $qb->join('c.users', 'u')
                ->andWhere('u = :user')
                ->setParameter('user', $user);
        }

        return $this->paginator->paginate(
            $qb->getQuery(),
            $page,
            $limit
        );
    }

    public function findAllAsc(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.softDelete = false')
            ->orderBy('c.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllWithCount(): array
    {
        return $this->createQueryBuilder('c')
            ->select('NEW App\\DTO\\CategoriesWithCountDTO(c.id, c.titre, c.thumbnail, c.descriptif, COUNT(DISTINCT r.id))')
            ->leftJoin('c.emissions', 'r')
            ->andWhere('c.softDelete = false')
            ->groupBy('c.id')
            ->getQuery()
            ->getResult();
    }

    public function findLatestEmissions(int $categoryId, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.emissions', 'e')
            ->andWhere('c.id = :id')
            ->setParameter('id', $categoryId)
            ->orderBy('e.datepub', 'DESC')
            ->setMaxResults($limit)
            ->select('e')
            ->getQuery()
            ->getResult();
    }

    public function findDistinctEditeursWithNames(): array
    {
        return $this->createQueryBuilder('c')
            ->select('DISTINCT e.id AS id, e.name AS name')
            ->join('c.editeur', 'e')
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }


    public function createActiveOrCurrentQueryBuilder(?Categories $currentCategorie): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c');

        if ($currentCategorie !== null) {
            $qb
                ->where('c.isActive = true OR c.id = :currentId')
                ->setParameter('currentId', $currentCategorie->getId());
        } else {
            $qb->where('c.isActive = true');
        }

        return $qb->orderBy('c.titre', 'ASC');
    }
}
