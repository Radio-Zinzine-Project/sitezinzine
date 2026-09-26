<?php

namespace App\Repository;

use SortDirection;

use App\Entity\Diffusion;
use App\Entity\Emission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Diffusion>
 */
class DiffusionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Diffusion::class);
    }


    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.id', SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByWeek(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('e')
            ->join('d.emission', 'e')
            ->andWhere('d.horaireDiffusion >= :start')
            ->andWhere('d.horaireDiffusion < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function hasDiffusionsBetween(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): bool {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.horaireDiffusion >= :start')
            ->andWhere('d.horaireDiffusion < :end')
            ->andWhere('d.publicationStatus = :status')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('status', Diffusion::STATUS_PUBLISHED)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Retourne toutes les diffusions présentes exactement à cet horaire.
     *
     * On retourne un tableau plutôt qu’une seule Diffusion afin que la preview
     * puisse signaler proprement une éventuelle anomalie contenant plusieurs lignes.
     *
     * @return Diffusion[]
     */
    public function findAllAtHoraire(\DateTimeInterface $horaire): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('e')
            ->join('d.emission', 'e')
            ->andWhere('d.horaireDiffusion = :horaire')
            ->setParameter('horaire', $horaire)
            ->orderBy('d.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne, pour chaque émission demandée, le dernier nombreDiffusion
     * enregistré avant le début de la semaine publiée.
     *
     * @param int[] $emissionIds
     *
     * @return array<int, int> Tableau sous la forme [emissionId => maxNombreDiffusion]
     */
    public function findMaxNumbersBeforeForEmissionIds(
        array $emissionIds,
        \DateTimeInterface $before
    ): array {
        $emissionIds = array_values(array_unique(array_filter(
            array_map('intval', $emissionIds),
            static fn(int $id): bool => $id > 0
        )));

        if ([] === $emissionIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.emission) AS emissionId')
            ->addSelect('MAX(d.nombreDiffusion) AS maxNombreDiffusion')
            ->andWhere('d.emission IN (:emissionIds)')
            ->andWhere('d.horaireDiffusion < :before')
            ->setParameter('emissionIds', $emissionIds)
            ->setParameter('before', $before)
            ->groupBy('d.emission')
            ->getQuery()
            ->getArrayResult();

        $result = [];

        foreach ($rows as $row) {
            $emissionId = (int) ($row['emissionId'] ?? 0);

            if ($emissionId <= 0) {
                continue;
            }

            $result[$emissionId] = (int) ($row['maxNombreDiffusion'] ?? 0);
        }

        return $result;
    }

    /**
     * @return Diffusion[]
     */
    public function findPublishedByWeek(
        \DateTimeInterface $start,
        \DateTimeInterface $end
    ): array {
        return $this->createQueryBuilder('d')
            ->addSelect('e', 'c')
            ->join('d.emission', 'e')
            ->leftJoin('e.categorie', 'c')
            ->andWhere('d.horaireDiffusion >= :start')
            ->andWhere('d.horaireDiffusion < :end')
            ->andWhere('d.publicationStatus = :status')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('status', Diffusion::STATUS_PUBLISHED)
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Diffusion[]
     */
    public function findLatestByEmission(
        \App\Entity\Emission $emission,
        int $limit = 5
    ): array {
        return $this->createQueryBuilder('d')
            ->andWhere('d.emission = :emission')
            ->andWhere('d.publicationStatus = :status')
            ->setParameter('emission', $emission)
            ->setParameter('status', Diffusion::STATUS_PUBLISHED)
            ->orderBy('d.horaireDiffusion', SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Diffusion[]
     */
    public function findAllByEmission(Emission $emission): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.emission = :emission')
            ->setParameter('emission', $emission)
            ->orderBy('d.horaireDiffusion', SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les Diffusion d'un groupe, quel que soit leur statut.
     *
     * Les Diffusion non publiées sont volontairement incluses :
     * elles font partie du cycle publication → dévalidation → republication.
     *
     * @return Diffusion[]
     */
    public function findByAssignmentGroupKey(
        string $assignmentGroupKey
    ): array {
        return $this->createQueryBuilder('d')
            ->addSelect('e')
            ->join('d.emission', 'e')
            ->andWhere('d.assignmentGroupKey = :assignmentGroupKey')
            ->setParameter('assignmentGroupKey', $assignmentGroupKey)
            ->orderBy('d.horaireDiffusion', SortDirection::Ascending)
            ->addOrderBy('d.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
