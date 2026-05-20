<?php

namespace App\Repository;

use App\Entity\DiffusionDraft;
use App\Entity\ProgrammationRuleSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DiffusionDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiffusionDraft::class);
    }

    public function findOneBySlotAndHoraire(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $horaireDiffusion
    ): ?DiffusionDraft {
        return $this->createQueryBuilder('d')
            ->andWhere('d.slot = :slot')
            ->andWhere('d.horaireDiffusion = :horaire')
            ->setParameter('slot', $slot)
            ->setParameter('horaire', $horaireDiffusion)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByWeek(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.horaireDiffusion >= :start')
            ->andWhere('d.horaireDiffusion < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('d.horaireDiffusion', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOverlappingDrafts(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?int $excludeDraftId = null
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.horaireDiffusion < :endsAt')
            ->andWhere('d.endsAt > :startsAt')
            ->setParameter('startsAt', $startsAt)
            ->setParameter('endsAt', $endsAt)
            ->orderBy('d.horaireDiffusion', 'ASC');

        if (null !== $excludeDraftId) {
            $qb
                ->andWhere('d.id != :excludeDraftId')
                ->setParameter('excludeDraftId', $excludeDraftId);
        }

        return $qb->getQuery()->getResult();
    }

    public function findByAssignmentGroupKey(string $assignmentGroupKey): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.assignmentGroupKey = :assignmentGroupKey')
            ->setParameter('assignmentGroupKey', $assignmentGroupKey)
            ->orderBy('d.horaireDiffusion', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneRegularBySlotAndHoraire(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $horaireDiffusion
    ): ?DiffusionDraft {
        return $this->createQueryBuilder('d')
            ->andWhere('d.slot = :slot')
            ->andWhere('d.horaireDiffusion = :horaire')
            ->andWhere('d.draftType = :type')
            ->setParameter('slot', $slot)
            ->setParameter('horaire', $horaireDiffusion)
            ->setParameter('type', DiffusionDraft::TYPE_REGULAR)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param array<int, array{slotId:int, startsAt:\DateTimeImmutable}> $pairs
     *
     * @return DiffusionDraft[]
     */
    public function findRegularDraftsBySlotAndStartsAtPairs(array $pairs): array
    {
        if ([] === $pairs) {
            return [];
        }

        $qb = $this->createQueryBuilder('d');
        $orX = $qb->expr()->orX();

        foreach ($pairs as $index => $pair) {
            $orX->add(sprintf(
                '(IDENTITY(d.slot) = :slotId_%d AND d.horaireDiffusion = :startsAt_%d)',
                $index,
                $index
            ));

            $qb
                ->setParameter(sprintf('slotId_%d', $index), $pair['slotId'])
                ->setParameter(sprintf('startsAt_%d', $index), $pair['startsAt']);
        }

        return $qb
            ->andWhere('d.slot IS NOT NULL')
            ->andWhere($orX)
            ->getQuery()
            ->getResult();
    }
}
