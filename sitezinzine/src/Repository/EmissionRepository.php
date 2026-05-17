<?php

namespace App\Repository;

use App\Entity\Categories;
use App\Entity\Emission;
use App\Entity\ProgrammationRuleSlot;
use App\Entity\Theme;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<Emission>
 */
class EmissionRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly PaginatorInterface $paginator
    ) {
        parent::__construct($registry, Emission::class);
    }

    public function paginateEmissionsAdmin(int $page, string $excludeUrl, ?User $user = null, bool $isAdmin = false): PaginationInterface
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e', 'c', '(SELECT MAX(d2.horaireDiffusion) FROM App\Entity\Diffusion d2 WHERE d2.emission = e) AS HIDDEN lastDiffusion')
            ->leftJoin('e.categorie', 'c')
            ->andWhere('(e.url != :excludeUrl) OR (e.url = :excludeUrl AND SIZE(e.users) > 0)')
            ->andWhere('c.id != 0')
            ->setParameter('excludeUrl', $excludeUrl)
            ->orderBy('lastDiffusion', 'DESC');

        if ($user && !$isAdmin) {
            $qb->innerJoin('e.users', 'u_filter')
                ->andWhere('u_filter = :user')
                ->setParameter('user', $user)
                ->distinct();
        }

        /** @var PaginationInterface $pagination */
        $pagination = $this->paginator->paginate($qb, $page, 20, [
            'distinct' => true,
            'sortFieldAllowList' => ['e.titre'],
        ]);

        foreach ($pagination as $row) {
            $lastDiffusion = $this->createQueryBuilder('e2')
                ->select('MAX(d.horaireDiffusion)')
                ->leftJoin('e2.diffusions', 'd')
                ->andWhere('e2.id = :id')
                ->setParameter('id', $row->getId())
                ->getQuery()
                ->getSingleScalarResult();

            $row->setLastDiffusion($lastDiffusion ? new \DateTime($lastDiffusion) : null);
        }

        return $pagination;
    }

    public function paginateEmissions(int $page, string $excludeUrl): PaginationInterface
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e', 'c', 'MAX(d.horaireDiffusion) AS lastDiffusion')
            ->leftJoin('e.categorie', 'c')
            ->innerJoin('e.diffusions', 'd')
            ->andWhere('e.url != :excludeUrl')
            ->andWhere('c.id != 0')
            ->setParameter('excludeUrl', $excludeUrl)
            ->groupBy('e.id')
            ->orderBy('lastDiffusion', 'DESC');

        return $this->paginator->paginate($qb, $page, 20, [
            'distinct' => true,
            'sortFieldAllowList' => ['lastDiffusion'],
        ]);
    }

    public function findEmissionsByDate(\DateTime $date): array
    {
        $start = (clone $date)->setTime(0, 0, 0);
        $end = (clone $date)->setTime(23, 59, 59);

        return $this->createQueryBuilder('e')
            ->addSelect('d', 'c')
            ->join('e.diffusions', 'd')
            ->leftJoin('e.categorie', 'c')
            ->where('d.horaireDiffusion BETWEEN :start AND :end')
            ->orderBy('d.horaireDiffusion', 'ASC')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();
    }

    public function getThemeGroups(): array
    {
        return [
            'musique/littérature/ciné...' => [1, 7, 8],
            'histoire/politique' => [2, 9],
            'agriculture/forêt/écologie' => [3, 10, 5],
            'alimentation/santé' => [4, 11],
            'féminisme/société/éducation' => [14, 6, 16],
            'international/migrations' => [13, 12],
        ];
    }

    public function findEmissionsByThemeGroup(array $themeIds): array
    {
        $now = new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('e')
            ->select('e', 'c', 't')
            ->addSelect('
                (SELECT MAX(d1.horaireDiffusion)
                 FROM App\Entity\Diffusion d1
                 WHERE d1.emission = e.id
                   AND d1.horaireDiffusion <= :now
                ) AS lastDiffusion
            ')
            ->addSelect('
                (SELECT MIN(d2.horaireDiffusion)
                 FROM App\Entity\Diffusion d2
                 WHERE d2.emission = e.id
                   AND d2.horaireDiffusion > :now
                ) AS nextDiffusion
            ')
            ->leftJoin('e.categorie', 'c')
            ->leftJoin('e.theme', 't')
            ->where('e.theme IN (:themeIds)')
            ->setParameter('themeIds', $themeIds)
            ->setParameter('now', $now)
            ->orderBy('lastDiffusion', 'DESC');

        $results = $qb->getQuery()->getResult();

        foreach ($results as $key => $row) {
            $emission = \is_array($row) ? $row[0] : $row;

            if (\is_array($row)) {
                $last = $row['lastDiffusion'] ?? null;
                $next = $row['nextDiffusion'] ?? null;
            } else {
                $last = null;
                $next = null;
            }

            $emission->setLastDiffusion($last ? new \DateTime($last) : null);
            $emission->setNextDiffusion($next ? new \DateTime($next) : null);

            $results[$key] = $emission;
        }

        return $results;
    }

    public function paginateEmissionsByThemeGroup(array $themeIds, int $page): PaginationInterface
    {
        $now = new \DateTimeImmutable();

        $themeIds = array_values(array_map('intval', $themeIds));
        if ([] === $themeIds) {
            $qb = $this->createQueryBuilder('e')->andWhere('1 = 0');
            return $this->paginator->paginate($qb, max(1, $page), 12);
        }

        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.categorie', 'c')
            ->leftJoin('e.diffusions', 'd')
            ->andWhere('e.theme IN (:themeIds)')
            ->andWhere('e.url IS NOT NULL AND e.url <> :empty')
            ->setParameter('empty', '')
            ->setParameter('themeIds', $themeIds)
            ->groupBy('e.id')
            ->orderBy('e.datepub', 'DESC');

        $pagination = $this->paginator->paginate($qb, max(1, $page), 12);

        foreach ($pagination as $emission) {
            $lastDiffusion = $this->getLastDiffusion($emission, $now);
            $emission->setLastDiffusion($lastDiffusion);
        }

        return $pagination;
    }

    public function getLastDiffusion(Emission $emission, \DateTimeInterface $now): ?\DateTimeInterface
    {
        $result = $this->getEntityManager()
            ->createQuery('
                SELECT MAX(d.horaireDiffusion)
                FROM App\Entity\Diffusion d
                WHERE d.emission = :emission AND d.horaireDiffusion <= :now
            ')
            ->setParameter('emission', $emission)
            ->setParameter('now', $now)
            ->getSingleScalarResult();

        if (null === $result) {
            return null;
        }

        return new \DateTime($result);
    }

    public function getNextDiffusion(Emission $emission, \DateTimeInterface $now): ?\DateTimeInterface
    {
        $result = $this->getEntityManager()
            ->createQuery('
                SELECT MIN(d.horaireDiffusion)
                FROM App\Entity\Diffusion d
                WHERE d.emission = :emission AND d.horaireDiffusion > :now
            ')
            ->setParameter('emission', $emission)
            ->setParameter('now', $now)
            ->getSingleScalarResult();

        if (null === $result) {
            return null;
        }

        return new \DateTime($result);
    }

    public function lastEmissionsByGroupTheme(string $excludeUrl): array
    {
        $themeGroups = $this->getThemeGroups();

        $cases = [];
        foreach ($themeGroups as $label => $ids) {
            $idList = implode(', ', $ids);
            $cases[] = "WHEN e.theme_id IN ($idList) THEN '$label'";
        }
        $caseSql = implode("\n", $cases);

        $sql = "
        WITH GroupedEmissions AS (
            SELECT
                e.id AS emission_id,
                e.titre AS emission_titre,
                MAX(d.horaire_diffusion) AS last_diffusion,
                e.duree AS emission_duree,
                e.url AS emission_url,
                e.descriptif AS emission_descriptif,
                e.thumbnail AS emission_thumbnail,
                e.categorie_id AS emission_categorie_id,
                e.theme_id AS emission_theme_id,

                c.id AS categorie_id,
                c.titre AS categorie_titre,
                c.editeur_id AS categorie_editeur_id,
                c.duree AS categorie_duree,
                c.descriptif AS categorie_descriptif,
                c.thumbnail AS categorie_thumbnail,
                c.active AS categorie_active,

                t.id AS theme_id,
                t.name AS theme_name,
                t.thumbnail AS theme_thumbnail,

                CASE
                    $caseSql
                    ELSE 'autre'
                END AS theme_group,

                ROW_NUMBER() OVER (
                    PARTITION BY
                        CASE
                            $caseSql
                            ELSE 'autre'
                        END
                    ORDER BY MAX(d.horaire_diffusion) DESC
                ) AS rn

            FROM emission e
            LEFT JOIN categories c ON e.categorie_id = c.id
            LEFT JOIN theme t ON e.theme_id = t.id
            LEFT JOIN diffusion d ON d.emission_id = e.id
            WHERE e.url != :val
              AND e.theme_id != 0
              AND d.horaire_diffusion IS NOT NULL
              AND d.horaire_diffusion <= NOW()
            GROUP BY
                e.id, e.titre, e.duree, e.url, e.descriptif, e.thumbnail, e.categorie_id, e.theme_id,
                c.id, c.titre, c.editeur_id, c.duree, c.descriptif, c.thumbnail, c.active,
                t.id, t.name, t.thumbnail
        )

        SELECT * FROM GroupedEmissions
        WHERE rn = 1 AND theme_group != 'autre'
        ORDER BY last_diffusion DESC
        LIMIT 6
    ";

        $conn = $this->getEntityManager()->getConnection();
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('val', $excludeUrl);

        return $stmt->executeQuery()->fetchAllAssociative();
    }

    public function findBySearchAdmin(array $criteria, int $page = 1): PaginationInterface
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.categorie', 'c')
            ->leftJoin('e.theme', 't')
            ->andWhere('c.id IS NOT NULL');

        if (!empty($criteria['dateDebut']) || !empty($criteria['dateFin'])) {
            $subQb = $this->getEntityManager()->createQueryBuilder()
                ->select('IDENTITY(d2.emission)')
                ->from(\App\Entity\Diffusion::class, 'd2')
                ->groupBy('d2.emission');

            $havingConditions = [];

            if (!empty($criteria['dateDebut'])) {
                $havingConditions[] = 'MAX(d2.horaireDiffusion) >= :dateDebut';
                $qb->setParameter('dateDebut', $criteria['dateDebut']);
            }

            if (!empty($criteria['dateFin'])) {
                $havingConditions[] = 'MAX(d2.horaireDiffusion) <= :dateFin';
                $qb->setParameter('dateFin', $criteria['dateFin']);
            }

            if ([] !== $havingConditions) {
                $subQb->having(implode(' AND ', $havingConditions));
            }

            $ids = array_map(
                'current',
                $subQb->getQuery()->getScalarResult()
            );

            if ([] === $ids) {
                return $this->paginator->paginate([], $page, 12);
            }

            $qb->andWhere('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }

        if (!empty($criteria['titre'])) {
            $search = trim((string) $criteria['titre']);
            $words = preg_split('/\s+/', mb_strtolower($search));

            $validWords = [];
            foreach ($words as $word) {
                $word = trim($word);

                if ('' !== $word && mb_strlen($word) >= 3) {
                    $validWords[] = $word;
                }
            }

            foreach ($validWords as $index => $word) {
                $paramName = 'searchRegex' . $index;
                $regex = '(^|[[:space:][:punct:]])' . preg_quote($word, '/') . '($|[[:space:][:punct:]])';

                $qb->andWhere(
                    $qb->expr()->orX(
                        "REGEXP(LOWER(e.titre), :$paramName) = 1",
                        "REGEXP(LOWER(e.descriptif), :$paramName) = 1",
                        "REGEXP(LOWER(e.ref), :$paramName) = 1"
                    )
                )->setParameter($paramName, $regex);
            }
        }

        if (($criteria['categorie'] ?? null) instanceof Categories) {
            $qb->andWhere('c.id = :categorieId')
                ->setParameter('categorieId', $criteria['categorie']->getId());
        }

        if (($criteria['theme'] ?? null) instanceof Theme) {
            $qb->andWhere('t.id = :themeId')
                ->setParameter('themeId', $criteria['theme']->getId());
        }

        if (!empty($criteria['personne'])) {
            $parts = explode(':', (string) $criteria['personne'], 2);

            if (2 === \count($parts)) {
                [$type, $id] = $parts;

                if ('user' === $type && ctype_digit($id)) {
                    $qb->innerJoin('e.users', 'u_filter')
                        ->andWhere('u_filter.id = :personId')
                        ->setParameter('personId', (int) $id);
                } elseif ('old' === $type && ctype_digit($id)) {
                    $qb->innerJoin('e.inviteOldAnimateurs', 'ioa_filter')
                        ->andWhere('ioa_filter.id = :personId')
                        ->setParameter('personId', (int) $id);
                }
            }
        }

        $lastDiffSubQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(d3.horaireDiffusion)')
            ->from(\App\Entity\Diffusion::class, 'd3')
            ->where('d3.emission = e')
            ->getDQL();

        $qb->addSelect('(' . $lastDiffSubQuery . ') AS HIDDEN lastDiff')
            ->orderBy('lastDiff', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        $pagination = $this->paginator->paginate(
            $qb,
            $page,
            12,
            [
                'distinct' => true,
            ]
        );

        $emissions = [];
        foreach ($pagination as $item) {
            if ($item instanceof Emission) {
                $emissions[] = $item;
            }
        }

        if ([] !== $emissions) {
            $emissionIds = array_map(
                fn(Emission $emission) => $emission->getId(),
                $emissions
            );

            $rows = $this->getEntityManager()->createQueryBuilder()
                ->select('IDENTITY(d.emission) AS emissionId, MAX(d.horaireDiffusion) AS lastDiff')
                ->from(\App\Entity\Diffusion::class, 'd')
                ->where('d.emission IN (:ids)')
                ->groupBy('d.emission')
                ->setParameter('ids', $emissionIds)
                ->getQuery()
                ->getArrayResult();

            $lastDiffByEmissionId = [];
            foreach ($rows as $row) {
                $lastDiffByEmissionId[(int) $row['emissionId']] = $row['lastDiff'];
            }

            foreach ($emissions as $emission) {
                $lastDiff = $lastDiffByEmissionId[$emission->getId()] ?? null;

                if (null !== $lastDiff) {
                    $emission->setLastDiffusion(new \DateTime($lastDiff));
                }
            }
        }

        return $pagination;
    }

    public function findBySearch(array $criteria, int $page = 1): PaginationInterface
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.categorie', 'c')
            ->leftJoin('e.theme', 't')
            ->andWhere('e.url IS NOT NULL')
            ->andWhere('e.url != :emptyUrl')
            ->andWhere('c.id IS NOT NULL')
            ->setParameter('emptyUrl', '');

        if (!empty($criteria['dateDebut']) || !empty($criteria['dateFin'])) {
            $subQb = $this->getEntityManager()->createQueryBuilder()
                ->select('IDENTITY(d2.emission)')
                ->from(\App\Entity\Diffusion::class, 'd2')
                ->groupBy('d2.emission');

            $havingConditions = [];

            if (!empty($criteria['dateDebut'])) {
                $havingConditions[] = 'MAX(d2.horaireDiffusion) >= :dateDebut';
                $qb->setParameter('dateDebut', $criteria['dateDebut']);
            }

            if (!empty($criteria['dateFin'])) {
                $havingConditions[] = 'MAX(d2.horaireDiffusion) <= :dateFin';
                $qb->setParameter('dateFin', $criteria['dateFin']);
            }

            if ([] !== $havingConditions) {
                $subQb->having(implode(' AND ', $havingConditions));
            }

            $ids = array_map(
                'current',
                $subQb->getQuery()->getScalarResult()
            );

            if ([] === $ids) {
                return $this->paginator->paginate([], $page, 12);
            }

            $qb->andWhere('e.id IN (:ids)')
                ->setParameter('ids', $ids);
        }

        if (!empty($criteria['titre'])) {
            $search = trim((string) $criteria['titre']);
            $words = preg_split('/\s+/', mb_strtolower($search));

            $validWords = [];
            foreach ($words as $word) {
                $word = trim($word);
                if ('' !== $word && mb_strlen($word) >= 3) {
                    $validWords[] = $word;
                }
            }

            foreach ($validWords as $index => $word) {
                $paramName = 'searchRegex' . $index;
                $regex = '(^|[[:space:][:punct:]])' . preg_quote($word, '/') . '($|[[:space:][:punct:]])';

                $qb->andWhere(
                    $qb->expr()->orX(
                        "REGEXP(LOWER(e.titre), :$paramName) = 1",
                        "REGEXP(LOWER(e.descriptif), :$paramName) = 1",
                        "REGEXP(LOWER(e.ref), :$paramName) = 1"
                    )
                )->setParameter($paramName, $regex);
            }
        }

        if (($criteria['categorie'] ?? null) instanceof Categories) {
            $qb->andWhere('c.id = :categorieId')
                ->setParameter('categorieId', $criteria['categorie']->getId());
        }

        if (($criteria['theme'] ?? null) instanceof Theme) {
            $qb->andWhere('t.id = :themeId')
                ->setParameter('themeId', $criteria['theme']->getId());
        }

        if (!empty($criteria['personne'])) {
            $parts = explode(':', (string) $criteria['personne'], 2);

            if (2 === \count($parts)) {
                [$type, $id] = $parts;

                if ('user' === $type && ctype_digit($id)) {
                    $qb->innerJoin('e.users', 'u_filter')
                        ->andWhere('u_filter.id = :personId')
                        ->setParameter('personId', (int) $id);
                }

                if ('old' === $type && ctype_digit($id)) {
                    $qb->innerJoin('e.inviteOldAnimateurs', 'ioa_filter')
                        ->andWhere('ioa_filter.id = :personId')
                        ->setParameter('personId', (int) $id);
                }
            }
        }

        $lastDiffSubQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(d3.horaireDiffusion)')
            ->from(\App\Entity\Diffusion::class, 'd3')
            ->where('d3.emission = e')
            ->getDQL();

        $qb->addSelect('(' . $lastDiffSubQuery . ') AS HIDDEN lastDiff')
            ->orderBy('lastDiff', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        $pagination = $this->paginator->paginate(
            $qb,
            $page,
            12,
            [
                'distinct' => true,
            ]
        );

        foreach ($pagination as $emission) {
            if (!$emission instanceof Emission) {
                continue;
            }

            $lastDiff = $this->getEntityManager()->createQueryBuilder()
                ->select('MAX(d4.horaireDiffusion)')
                ->from(\App\Entity\Diffusion::class, 'd4')
                ->where('d4.emission = :emission')
                ->setParameter('emission', $emission)
                ->getQuery()
                ->getSingleScalarResult();

            if (null !== $lastDiff) {
                $emission->setLastDiffusion(new \DateTime($lastDiff));
            }
        }

        return $pagination;
    }

    public function findLastDiffusionDate(int $emissionId): ?\DateTimeInterface
    {
        $lastDateString = $this->createQueryBuilder('e')
            ->select('MAX(d.horaireDiffusion) AS lastDate')
            ->leftJoin('e.diffusions', 'd')
            ->andWhere('e.id = :id')
            ->setParameter('id', $emissionId)
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $lastDateString) {
            return null;
        }

        return new \DateTime($lastDateString);
    }

    public function findLatestByCategory(int $categoryId, int $limit = 20): array
    {
        $now = new \DateTimeImmutable();

        $rows = $this->createQueryBuilder('e')
            ->select('e.id AS emissionId, MAX(d.horaireDiffusion) AS lastDiffusion')
            ->leftJoin('e.diffusions', 'd', 'WITH', 'd.horaireDiffusion <= :now')
            ->andWhere('e.categorie = :cat')
            ->setParameter('cat', $categoryId)
            ->setParameter('now', $now)
            ->groupBy('e.id')
            ->orderBy('lastDiffusion', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult(Query::HYDRATE_ARRAY);

        $emissions = [];
        foreach ($rows as $row) {
            $emission = $this->find($row['emissionId']);
            $last = $row['lastDiffusion'];

            if ($emission instanceof Emission) {
                $emission->setLastDiffusion($last ? new \DateTime($last) : null);
                $emissions[] = $emission;
            }
        }

        return $emissions;
    }

    public function findWithDureeLowerThan(int $duree): array
    {
        return $this->createQueryBuilder('e')
            ->select('e', 'c')
            ->leftJoin('e.categorie', 'c')
            ->where('e.duree < :duree')
            ->setParameter('duree', $duree)
            ->orderBy('e.duree', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function createLatestByCategoryQueryBuilder(int $categoryId): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.categorie', 'c')
            ->addSelect('c')
            ->andWhere('c.id = :catId')
            ->setParameter('catId', $categoryId)
            ->orderBy('e.id', 'DESC');
    }

    public function findAssignableForCategory(Categories $category): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.categorie', 'c')
            ->andWhere('e.categorie = :category')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->orderBy('e.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestFirstPassCandidatesByCategory(Categories $category, int $limit = 20): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.categorie', 'c')
            ->andWhere('e.categorie = :category')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->andWhere('e.datepub IS NOT NULL')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->orderBy('e.datepub', 'DESC')
            ->addOrderBy('e.titre', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne un tableau de lignes structurées :
     * [
     *   'emission' => Emission,
     *   'playCount' => int,
     * ]
     */
    public function findSpecialCandidatesForRegularCategory(Categories $category, ?int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e', 'COUNT(d.id) AS playCount')
            ->leftJoin('e.diffusions', 'd')
            ->innerJoin('e.categorie', 'c')
            ->andWhere('e.categorie = :category')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->andWhere('e.isAutoGenerated = :isAutoGenerated')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->setParameter('isAutoGenerated', false)
            ->groupBy('e.id')
            ->having('COUNT(d.id) < 3')
            ->orderBy('e.datepub', 'DESC')
            ->addOrderBy('e.titre', 'ASC')
            ->setMaxResults(null !== $limit ? $limit : null)
            ->getQuery()
            ->getResult();

        return $this->normalizeSpecialCandidateRows($rows);
    }

    public function countSpecialCandidatesForRegularCategory(Categories $category): int
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.id')
            ->leftJoin('e.diffusions', 'd')
            ->innerJoin('e.categorie', 'c')
            ->andWhere('e.categorie = :category')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->andWhere('e.isAutoGenerated = :isAutoGenerated')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->setParameter('isAutoGenerated', false)
            ->groupBy('e.id')
            ->having('COUNT(d.id) < 3')
            ->getQuery()
            ->getScalarResult();

        return \count($rows);
    }

    /**
     * Retourne un tableau de lignes structurées :
     * [
     *   'emission' => Emission,
     *   'playCount' => int,
     * ]
     */
    public function findSpecialCandidatesForNonRegularCategory(Categories $category, ?int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e', 'COUNT(d.id) AS playCount')
            ->leftJoin('e.diffusions', 'd')
            ->innerJoin('e.categorie', 'c')
            ->andWhere('e.categorie = :category')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->andWhere('e.isAutoGenerated = :isAutoGenerated')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->setParameter('isAutoGenerated', false)
            ->groupBy('e.id')
            ->orderBy('e.datepub', 'DESC')
            ->addOrderBy('e.titre', 'ASC')
            ->setMaxResults(null !== $limit ? $limit : null)
            ->getQuery()
            ->getResult();

        return $this->normalizeSpecialCandidateRows($rows);
    }

    public function countSpecialCandidatesForNonRegularCategory(Categories $category): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->innerJoin('e.categorie', 'c')
            ->andWhere('e.categorie = :category')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->andWhere('e.isAutoGenerated = :isAutoGenerated')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->setParameter('isAutoGenerated', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array{emission: Emission, playCount: int}>
     */
    private function normalizeSpecialCandidateRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if ($row instanceof Emission) {
                $normalized[] = [
                    'emission' => $row,
                    'playCount' => 0,
                ];
                continue;
            }

            if (!\is_array($row)) {
                continue;
            }

            $emission = null;
            $playCount = 0;

            if (isset($row[0]) && $row[0] instanceof Emission) {
                $emission = $row[0];
            } elseif (isset($row['emission']) && $row['emission'] instanceof Emission) {
                $emission = $row['emission'];
            }

            if (isset($row['playCount'])) {
                $playCount = (int) $row['playCount'];
            } elseif (isset($row[1]) && is_numeric($row[1])) {
                $playCount = (int) $row[1];
            }

            if ($emission instanceof Emission) {
                $normalized[] = [
                    'emission' => $emission,
                    'playCount' => $playCount,
                ];
            }
        }

        return $normalized;
    }

    public function findAutoGeneratedForSlotAndStartsAt(
        ProgrammationRuleSlot $slot,
        \DateTimeInterface $startsAt
    ): ?Emission {
        return $this->createQueryBuilder('e')
            ->andWhere('e.autoGeneratedForSlot = :slot')
            ->andWhere('e.autoGeneratedForStartsAt = :startsAt')
            ->andWhere('e.isAutoGenerated = :isAutoGenerated')
            ->setParameter('slot', $slot)
            ->setParameter('startsAt', \DateTime::createFromInterface($startsAt))
            ->setParameter('isAutoGenerated', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPendingCompletionForUser(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.users', 'u')
            ->leftJoin('e.categorie', 'c')
            ->addSelect('c')
            ->andWhere('u = :user')
            ->andWhere('e.isPendingCompletion = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', true)
            ->orderBy('e.datepub', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPendingCompletionForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.id)')
            ->innerJoin('e.users', 'u')
            ->andWhere('u = :user')
            ->andWhere('e.isPendingCompletion = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findAllPendingCompletion(int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.categorie', 'c')
            ->addSelect('c')
            ->leftJoin('e.users', 'u')
            ->addSelect('u')
            ->andWhere('e.isPendingCompletion = :pending')
            ->setParameter('pending', true)
            ->orderBy('e.datepub', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAllPendingCompletion(): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.id)')
            ->andWhere('e.isPendingCompletion = :pending')
            ->setParameter('pending', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recherche les émissions candidates pour la barre de droite de la grille.
     *
     * Mode normal :
     * - catégorie active
     * - non soft delete
     * - émissions non auto-générées
     * - datepub renseignée
     * - limité aux plus récentes
     *
     * Mode étendu :
     * - même catégorie
     * - mêmes sécurités
     * - mais limite plus large
     * - permet de retrouver l'historique
     *
     * Recherche titre :
     * - active à partir de 3 caractères côté controller/JS
     * - cherche dans le titre uniquement
     */
    public function findGridCandidatesByCategory(
        Categories $category,
        ?string $titleSearch = null,
        bool $extended = false,
        int $limit = 20
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->innerJoin('e.categorie', 'c')
            ->addSelect('c')
            ->andWhere('e.categorie = :category')
            ->andWhere('c.active = :active')
            ->andWhere('c.softDelete = :softDelete')
            ->andWhere('e.isAutoGenerated = :isAutoGenerated')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setParameter('softDelete', false)
            ->setParameter('isAutoGenerated', false);

        if (!$extended) {
            $qb->andWhere('e.datepub IS NOT NULL');
        }

        $titleSearch = trim((string) $titleSearch);

        if (mb_strlen($titleSearch) >= 3) {
            $words = preg_split('/\s+/', mb_strtolower($titleSearch));

            foreach ($words as $index => $word) {
                $word = trim($word);

                if (mb_strlen($word) < 3) {
                    continue;
                }

                $paramName = 'titleRegex' . $index;
                $regex = '(^|[[:space:][:punct:]])' . preg_quote($word, '/') . '($|[[:space:][:punct:]])';

                $qb->andWhere("REGEXP(LOWER(e.titre), :$paramName) = 1")
                    ->setParameter($paramName, $regex);
            }
        }

        $qb
            ->orderBy('e.datepub', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->addOrderBy('e.titre', 'ASC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }
}
