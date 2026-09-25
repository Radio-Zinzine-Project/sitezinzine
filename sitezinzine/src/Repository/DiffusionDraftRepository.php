<?php

namespace App\Repository;

use SortDirection;

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
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending)
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
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending);

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
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending)
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
            ->andWhere('d.draftType = :draftType')
            ->andWhere($orX)
            ->setParameter('draftType', DiffusionDraft::TYPE_REGULAR)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne uniquement les drafts actifs et publiables de la semaine.
     *
     * Un draft dévalidé reste publiable même s’il conserve un lien
     * vers une ancienne Diffusion via publishedDiffusion.
     *
     * @return DiffusionDraft[]
     */
    public function findPublishableByWeek(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): array {
        return $this->createQueryBuilder('d')
            ->addSelect('e', 'publishedDiffusion')
            ->join('d.emission', 'e')
            ->leftJoin('d.publishedDiffusion', 'publishedDiffusion')
            ->andWhere('d.horaireDiffusion >= :start')
            ->andWhere('d.horaireDiffusion < :end')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.publicationStatus = :status')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('status', DiffusionDraft::STATUS_DRAFT)
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending)
            ->addOrderBy('d.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche les rediffusions futures encore en brouillon pour les groupes
     * présents dans la semaine que l’on envisage de valider.
     *
     * @param string[] $assignmentGroupKeys
     *
     * @return DiffusionDraft[]
     */
    public function findFutureDraftsByAssignmentGroupKeys(
        array $assignmentGroupKeys,
        \DateTimeImmutable $from
    ): array {
        $assignmentGroupKeys = array_values(array_unique(array_filter(
            $assignmentGroupKeys,
            static fn(mixed $key): bool => \is_string($key) && '' !== trim($key)
        )));

        if ([] === $assignmentGroupKeys) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->addSelect('e')
            ->join('d.emission', 'e')
            ->andWhere('d.assignmentGroupKey IN (:groupKeys)')
            ->andWhere('d.horaireDiffusion >= :from')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.publicationStatus = :status')
            ->setParameter('groupKeys', $assignmentGroupKeys)
            ->setParameter('from', $from)
            ->setParameter('status', DiffusionDraft::STATUS_DRAFT)
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending)
            ->addOrderBy('d.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveDraftBySlotAndHoraire(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $horaireDiffusion
    ): ?DiffusionDraft {
        return $this->createQueryBuilder('d')
            ->andWhere('d.slot = :slot')
            ->andWhere('d.horaireDiffusion = :horaire')
            ->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.publicationStatus = :status')
            ->setParameter('slot', $slot)
            ->setParameter('horaire', $horaireDiffusion)
            ->setParameter('status', DiffusionDraft::STATUS_DRAFT)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne tous les DiffusionDraft liés à une liste de Diffusion publiées.
     *
     * @param int[] $diffusionIds
     *
     * @return DiffusionDraft[]
     */
    public function findByPublishedDiffusionIds(array $diffusionIds): array
    {
        $diffusionIds = array_values(array_unique(array_filter(
            array_map('intval', $diffusionIds),
            static fn(int $id): bool => $id > 0
        )));

        if ([] === $diffusionIds) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->addSelect('pd')
            ->leftJoin('d.publishedDiffusion', 'pd')
            ->andWhere('IDENTITY(d.publishedDiffusion) IN (:diffusionIds)')
            ->setParameter('diffusionIds', $diffusionIds)
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne tous les Drafts appartenant à des groupes réguliers
     * d'une règle donnée.
     *
     * On part volontairement des Drafts réguliers liés aux slots de la règle,
     * puis on récupère tous les Drafts portant les mêmes assignmentGroupKey.
     *
     * Cela permet notamment d'inclure :
     * - les diffusions régulières ;
     * - les rediffusions ponctuelles ajoutées au groupe.
     *
     * @return DiffusionDraft[]
     */
    public function findRegularGroupsByRule(int $ruleId): array
    {
        $groupKeyRows = $this->createQueryBuilder('regularDraft')
            ->select('DISTINCT regularDraft.assignmentGroupKey AS assignmentGroupKey')
            ->join('regularDraft.slot', 'slot')
            ->andWhere('IDENTITY(slot.rule) = :ruleId')
            ->andWhere('regularDraft.draftType = :regularType')
            ->andWhere('regularDraft.assignmentGroupKey IS NOT NULL')
            ->setParameter('ruleId', $ruleId)
            ->setParameter('regularType', DiffusionDraft::TYPE_REGULAR)
            ->getQuery()
            ->getArrayResult();

        $groupKeys = array_values(array_filter(
            array_map(
                static fn(array $row): ?string =>
                isset($row['assignmentGroupKey'])
                    && \is_string($row['assignmentGroupKey'])
                    ? $row['assignmentGroupKey']
                    : null,
                $groupKeyRows
            ),
            static fn(?string $key): bool =>
            null !== $key && '' !== trim($key)
        ));

        if ([] === $groupKeys) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->addSelect('slot', 'emission', 'publishedDiffusion')
            ->leftJoin('d.slot', 'slot')
            ->join('d.emission', 'emission')
            ->leftJoin('d.publishedDiffusion', 'publishedDiffusion')
            ->andWhere('d.assignmentGroupKey IN (:groupKeys)')
            ->setParameter('groupKeys', $groupKeys)
            ->orderBy('d.assignmentGroupKey', SortDirection::Ascending)
            ->addOrderBy('d.horaireDiffusion', SortDirection::Ascending)
            ->addOrderBy('d.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
