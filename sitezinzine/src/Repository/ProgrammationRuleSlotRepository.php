<?php

namespace App\Repository;

use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgrammationRuleSlot>
 */
class ProgrammationRuleSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgrammationRuleSlot::class);
    }

    public function save(ProgrammationRuleSlot $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ProgrammationRuleSlot $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function createNotDeletedQueryBuilder(string $alias = 's')
    {
        return $this->createQueryBuilder($alias)
            ->andWhere(sprintf('%s.deletedAt IS NULL', $alias));
    }

    public function createActiveQueryBuilder(string $alias = 's')
    {
        return $this->createNotDeletedQueryBuilder($alias)
            ->andWhere(sprintf('%s.isActive = :active', $alias))
            ->setParameter('active', true);
    }

    public function findNotDeletedByRule(ProgrammationRule $rule): array
    {
        return $this->createNotDeletedQueryBuilder('s')
            ->andWhere('s.rule = :rule')
            ->setParameter('rule', $rule)
            ->orderBy('s.broadcastRank', 'ASC')
            ->addOrderBy('s.dayOfWeek', 'ASC')
            ->addOrderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

public function findActiveByRule(ProgrammationRule $rule): array
{
    return $this->createActiveQueryBuilder('s')
        ->andWhere('s.rule = :rule')
        ->setParameter('rule', $rule)
        ->orderBy('s.broadcastRank', 'ASC')
        ->addOrderBy('s.weekOffset', 'ASC')
        ->addOrderBy('s.dayOfWeek', 'ASC')
        ->addOrderBy('s.startTime', 'ASC')
        ->getQuery()
        ->getResult();
}

    public function findActiveByDay(int $dayOfWeek): array
    {
        return $this->createActiveQueryBuilder('s')
            ->innerJoin('s.rule', 'r')
            ->addSelect('r')
            ->andWhere('s.dayOfWeek = :dayOfWeek')
            ->andWhere('r.deletedAt IS NULL')
            ->andWhere('r.isActive = :ruleActive')
            ->setParameter('dayOfWeek', $dayOfWeek)
            ->setParameter('ruleActive', true)
            ->orderBy('s.startTime', 'ASC')
            ->addOrderBy('s.broadcastRank', 'ASC')
            ->getQuery()
            ->getResult();
    }

    
}