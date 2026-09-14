<?php

namespace App\Repository;

use SortDirection;

use App\Entity\Categories;
use App\Entity\ProgrammationRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgrammationRule>
 */
class ProgrammationRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgrammationRule::class);
    }

    public function save(ProgrammationRule $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ProgrammationRule $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function createNotDeletedQueryBuilder(string $alias = 'r')
    {
        return $this->createQueryBuilder($alias)
            ->andWhere(sprintf('%s.deletedAt IS NULL', $alias));
    }

    public function createActiveQueryBuilder(string $alias = 'r')
    {
        return $this->createNotDeletedQueryBuilder($alias)
            ->andWhere(sprintf('%s.isActive = :active', $alias))
            ->setParameter('active', true);
    }

    public function findAllNotDeleted(): array
    {
        return $this->createNotDeletedQueryBuilder('r')
            ->leftJoin('r.category', 'c')
            ->addSelect('c')
            ->orderBy('c.titre', SortDirection::Ascending)
            ->addOrderBy('r.ruleNumber', SortDirection::Ascending)
            ->addOrderBy('r.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function findDeletedRules(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.category', 'c')
            ->addSelect('c')
            ->andWhere('r.deletedAt IS NOT NULL')
            ->orderBy('c.titre', SortDirection::Ascending)
            ->addOrderBy('r.ruleNumber', SortDirection::Ascending)
            ->addOrderBy('r.deletedAt', SortDirection::Descending)
            ->addOrderBy('r.id', SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    public function findActiveRules(): array
    {
        return $this->createActiveQueryBuilder('r')
            ->leftJoin('r.category', 'c')
            ->addSelect('c')
            ->orderBy('c.titre', SortDirection::Ascending)
            ->addOrderBy('r.ruleNumber', SortDirection::Ascending)
            ->addOrderBy('r.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function findActiveRulesForDate(\DateTimeImmutable $date): array
    {
        return $this->createActiveQueryBuilder('r')
            ->leftJoin('r.category', 'c')
            ->addSelect('c')
            ->andWhere('(r.validFrom IS NULL OR r.validFrom <= :date)')
            ->andWhere('(r.validUntil IS NULL OR r.validUntil >= :date)')
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('c.titre', SortDirection::Ascending)
            ->addOrderBy('r.ruleNumber', SortDirection::Ascending)
            ->addOrderBy('r.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function findMaxRuleNumberByCategory(Categories $category): int
    {
        $result = $this->createQueryBuilder('r')
            ->select('MAX(r.ruleNumber) AS maxNumber')
            ->andWhere('r.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int) $result : 0;
    }

    public function hasActiveRuleForCategory(Categories $category): bool
    {
        $result = $this->createQueryBuilder('r')
            ->select('r.id')
            ->andWhere('r.category = :category')
            ->andWhere('r.isActive = :active')
            ->andWhere('r.deletedAt IS NULL')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $result;
    }
}