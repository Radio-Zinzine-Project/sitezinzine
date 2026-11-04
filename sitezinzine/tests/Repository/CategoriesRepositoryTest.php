<?php

namespace App\Tests\Repository;

use App\DTO\CategoriesWithCountDTO;
use App\Entity\Categories;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\User;
use App\Repository\CategoriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CategoriesRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CategoriesRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(CategoriesRepository::class);

        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }

        $this->em->close();

        parent::tearDown();
    }

    public function testPaginateCategoriesWithCountReturnsPaginationAndExcludesSoftDeleted(): void
    {
        $visible = $this->createCategorie(
            'Visible ' . $this->unique()
        );

        $deleted = $this->createCategorie(
            'Supprimée ' . $this->unique(),
            true
        );

        $this->em->flush();

        $pagination = $this->repository->paginateCategoriesWithCount(
            1,
            10000
        );

        self::assertInstanceOf(PaginationInterface::class, $pagination);

        $ids = $this->extractCategoryIdsFromPagination($pagination);

        self::assertContains($visible->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testPaginateCategoriesWithCountFiltersByLetter(): void
    {
        $matching = $this->createCategorie(
            'Qtest ' . $this->unique()
        );

        $notMatching = $this->createCategorie(
            'Ztest ' . $this->unique()
        );

        $this->em->flush();

        $pagination = $this->repository->paginateCategoriesWithCount(
            1,
            1000,
            null,
            'Q'
        );

        $ids = $this->extractCategoryIdsFromPagination($pagination);

        self::assertContains($matching->getId(), $ids);
        self::assertNotContains($notMatching->getId(), $ids);
    }

    public function testPaginateCategoriesWithCountFiltersNumericTitles(): void
    {
        $numeric = $this->createCategorie(
            '9 test ' . $this->unique()
        );

        $alphabetic = $this->createCategorie(
            'A test ' . $this->unique()
        );

        $this->em->flush();

        $pagination = $this->repository->paginateCategoriesWithCount(
            1,
            1000,
            null,
            '0-9'
        );

        $ids = $this->extractCategoryIdsFromPagination($pagination);

        self::assertContains($numeric->getId(), $ids);
        self::assertNotContains($alphabetic->getId(), $ids);
    }

    public function testPaginateCategoriesWithCountIgnoresEmptyInitial(): void
    {
        $category = $this->createCategorie(
            'Catégorie ' . $this->unique()
        );

        $this->em->flush();

        $pagination = $this->repository->paginateCategoriesWithCount(
            1,
            1000,
            null,
            ''
        );

        $ids = $this->extractCategoryIdsFromPagination($pagination);

        self::assertContains($category->getId(), $ids);
    }

    public function testPaginateCategoriesWithCountStandardUserOnlySeesOwnCategories(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $owned = $this->createCategorie(
            'Possédée ' . $this->unique()
        );

        $notOwned = $this->createCategorie(
            'Non possédée ' . $this->unique()
        );

        $user->addCategory($owned);

        $this->em->flush();

        $pagination = $this->repository->paginateCategoriesWithCount(
            1,
            1000,
            $user
        );

        $ids = $this->extractCategoryIdsFromPagination($pagination);

        self::assertContains($owned->getId(), $ids);
        self::assertNotContains($notOwned->getId(), $ids);
    }

    public function testPaginateCategoriesWithCountAdminIsNotRestrictedToOwnCategories(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $category = $this->createCategorie(
            'Admin visible ' . $this->unique()
        );

        $this->em->flush();

        $pagination = $this->repository->paginateCategoriesWithCount(
            1,
            1000,
            $admin
        );

        $ids = $this->extractCategoryIdsFromPagination($pagination);

        self::assertContains($category->getId(), $ids);
    }

    public function testPaginateCategoriesWithCountSuperAdminIsNotRestrictedToOwnCategories(): void
    {
        $superAdmin = $this->createUser(['ROLE_SUPER_ADMIN']);

        $category = $this->createCategorie(
            'Super admin visible ' . $this->unique()
        );

        $this->em->flush();

        $pagination = $this->repository->paginateCategoriesWithCount(
            1,
            10000,
            $superAdmin
        );

        $ids = $this->extractCategoryIdsFromPagination($pagination);

        self::assertContains($category->getId(), $ids);
    }

    public function testFindAllAscExcludesSoftDeletedAndOrdersByTitle(): void
    {
        $unique = $this->unique();

        $alpha = $this->createCategorie(
            'AAA Test ' . $unique
        );

        $zulu = $this->createCategorie(
            'ZZZ Test ' . $unique
        );

        $deleted = $this->createCategorie(
            'BBB Supprimée ' . $unique,
            true
        );

        $this->em->flush();

        $results = $this->repository->findAllAsc();

        $ids = array_map(
            static fn(Categories $category): ?int => $category->getId(),
            $results
        );

        // Les catégories non supprimées sont présentes.
        self::assertContains($alpha->getId(), $ids);
        self::assertContains($zulu->getId(), $ids);

        // La catégorie soft-deleted est exclue.
        self::assertNotContains($deleted->getId(), $ids);

        // Vérifie réellement l'ORDER BY titre ASC
        // sans tenter de reproduire la collation MySQL en PHP.
        $positionAlpha = array_search($alpha->getId(), $ids, true);
        $positionZulu = array_search($zulu->getId(), $ids, true);

        self::assertIsInt($positionAlpha);
        self::assertIsInt($positionZulu);
        self::assertLessThan($positionZulu, $positionAlpha);
    }

    public function testFindAllWithCountReturnsDtos(): void
    {
        $category = $this->createCategorie(
            'DTO ' . $this->unique()
        );

        $this->createEmission(
            $category,
            'Émission 1 ' . $this->unique(),
            new \DateTime('2026-09-01 10:00:00')
        );

        $this->createEmission(
            $category,
            'Émission 2 ' . $this->unique(),
            new \DateTime('2026-09-02 10:00:00')
        );

        $deleted = $this->createCategorie(
            'DTO supprimée ' . $this->unique(),
            true
        );

        $this->em->flush();

        $results = $this->repository->findAllWithCount();

        self::assertNotEmpty($results);

        foreach ($results as $result) {
            self::assertInstanceOf(CategoriesWithCountDTO::class, $result);
        }

        /*
         * On ne teste pas ici les getters du DTO :
         * le rôle de ce test est de vérifier que la requête DQL
         * construit correctement les DTO sans erreur.
         *
         * Le contenu précis du DTO pourra être testé dans son propre test
         * si nécessaire.
         */
    }

    public function testFindLatestEmissionsFiltersCategoryOrdersByDateAndAppliesLimit(): void
    {
        $category = $this->createCategorie(
            'Latest ' . $this->unique()
        );

        $otherCategory = $this->createCategorie(
            'Autre ' . $this->unique()
        );

        $old = $this->createEmission(
            $category,
            'Ancienne émission',
            new \DateTime('2026-08-01 10:00:00')
        );

        $middle = $this->createEmission(
            $category,
            'Émission intermédiaire',
            new \DateTime('2026-08-10 10:00:00')
        );

        $latest = $this->createEmission(
            $category,
            'Dernière émission',
            new \DateTime('2026-08-20 10:00:00')
        );

        $other = $this->createEmission(
            $otherCategory,
            'Émission autre catégorie',
            new \DateTime('2026-09-01 10:00:00')
        );

        $this->em->flush();

        $results = $this->repository->findLatestEmissions(
            $category->getId(),
            2
        );

        self::assertCount(2, $results);

        self::assertSame(
            [
                $latest->getId(),
                $middle->getId(),
            ],
            array_map(
                static fn(Emission $emission): ?int => $emission->getId(),
                $results
            )
        );

        $ids = array_map(
            static fn(Emission $emission): ?int => $emission->getId(),
            $results
        );

        self::assertNotContains($old->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testFindDistinctEditeursWithNamesReturnsDistinctEditorsOrderedByName(): void
    {
        $unique = $this->unique();

        $editorA = $this->createEditeur('AAA Test ' . $unique);
        $editorZ = $this->createEditeur('ZZZ Test ' . $unique);

        $this->createCategorie(
            'Catégorie A ' . $this->unique(),
            false,
            true,
            $editorA
        );

        // Deux catégories pour le même éditeur :
        // le DISTINCT doit empêcher un doublon.
        $this->createCategorie(
            'Catégorie Z1 ' . $this->unique(),
            false,
            true,
            $editorZ
        );

        $this->createCategorie(
            'Catégorie Z2 ' . $this->unique(),
            false,
            true,
            $editorZ
        );

        $this->em->flush();

        $results = $this->repository->findDistinctEditeursWithNames();

        $editorIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $results
        );

        self::assertContains($editorA->getId(), $editorIds);
        self::assertContains($editorZ->getId(), $editorIds);

        // Vérifie le DISTINCT : editorZ ne doit apparaître qu'une seule fois.
        self::assertSame(
            1,
            count(array_filter(
                $results,
                static fn(array $row): bool => (int) $row['id'] === $editorZ->getId()
            ))
        );

        // Vérifie l'ordre produit par la requête :
        // AAA doit apparaître avant ZZZ.
        $positionA = array_search($editorA->getId(), $editorIds, true);
        $positionZ = array_search($editorZ->getId(), $editorIds, true);

        self::assertIsInt($positionA);
        self::assertIsInt($positionZ);
        self::assertLessThan($positionZ, $positionA);
    }

    public function testCreateActiveOrCurrentQueryBuilderWithoutCurrentCategoryReturnsOnlyActiveCategories(): void
    {
        $active = $this->createCategorie(
            'Active ' . $this->unique(),
            false,
            true
        );

        $inactive = $this->createCategorie(
            'Inactive ' . $this->unique(),
            false,
            false
        );

        $this->em->flush();

        $qb = $this->repository->createActiveOrCurrentQueryBuilder(null);

        self::assertInstanceOf(QueryBuilder::class, $qb);

        $results = $qb->getQuery()->getResult();

        $ids = array_map(
            static fn(Categories $category): ?int => $category->getId(),
            $results
        );

        self::assertContains($active->getId(), $ids);
        self::assertNotContains($inactive->getId(), $ids);
    }

    public function testCreateActiveOrCurrentQueryBuilderIncludesInactiveCurrentCategory(): void
    {
        $unique = $this->unique();

        $activeAlpha = $this->createCategorie(
            'AAA Active ' . $unique,
            false,
            true
        );

        $currentInactive = $this->createCategorie(
            'MMM Inactive courante ' . $unique,
            false,
            false
        );

        $activeZulu = $this->createCategorie(
            'ZZZ Active ' . $unique,
            false,
            true
        );

        $otherInactive = $this->createCategorie(
            'NNN Autre inactive ' . $unique,
            false,
            false
        );

        $this->em->flush();

        $qb = $this->repository->createActiveOrCurrentQueryBuilder($currentInactive);
        $results = $qb->getQuery()->getResult();

        $ids = array_map(
            static fn(Categories $category): ?int => $category->getId(),
            $results
        );

        // Les catégories actives sont présentes.
        self::assertContains($activeAlpha->getId(), $ids);
        self::assertContains($activeZulu->getId(), $ids);

        // La catégorie courante est conservée même si elle est inactive.
        self::assertContains($currentInactive->getId(), $ids);

        // Une autre catégorie inactive ne doit pas apparaître.
        self::assertNotContains($otherInactive->getId(), $ids);

        // Vérifie l'ORDER BY c.titre ASC sans reproduire
        // la collation MySQL avec sort() côté PHP.
        $positionAlpha = array_search($activeAlpha->getId(), $ids, true);
        $positionCurrent = array_search($currentInactive->getId(), $ids, true);
        $positionZulu = array_search($activeZulu->getId(), $ids, true);

        self::assertIsInt($positionAlpha);
        self::assertIsInt($positionCurrent);
        self::assertIsInt($positionZulu);

        self::assertLessThan($positionCurrent, $positionAlpha);
        self::assertLessThan($positionZulu, $positionCurrent);
    }

    private function createCategorie(
        ?string $titre = null,
        bool $softDelete = false,
        bool $active = true,
        ?Editeur $editeur = null
    ): Categories {
        $categorie = (new Categories())
            ->setTitre($titre ?? 'Catégorie test ' . $this->unique())
            ->setDuree(60)
            ->setActive($active)
            ->setSoftDelete($softDelete)
            ->setDescriptif('Description catégorie test')
            ->setThumbnail('thumbnail-test.jpg')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($editeur ?? $this->createEditeur());

        $this->em->persist($categorie);

        return $categorie;
    }

    private function createEditeur(?string $name = null): Editeur
    {
        $editeur = (new Editeur())
            ->setName($name ?? 'Éditeur test ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    private function createEmission(
        Categories $categorie,
        string $title,
        \DateTime $datepub,
        int $duration = 60
    ): Emission {
        $emission = (new Emission())
            ->setTitre($title)
            ->setKeyword('test')
            ->setDatepub($datepub)
            ->setRef('REF-' . $this->unique())
            ->setDuree($duration)
            ->setUrl('https://emission-' . $this->unique() . '.test')
            ->setDescriptif('Description de test')
            ->setCategorie($categorie)
            ->setUpdatedAt(new \DateTime())
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->em->persist($emission);

        return $emission;
    }

    private function createUser(array $roles = ['ROLE_USER']): User
    {
        $unique = $this->unique();

        $user = (new User())
            ->setUsername('user_' . $unique)
            ->setEmail('user_' . $unique . '@test.fr')
            ->setPassword('password-test')
            ->setRoles($roles)
            ->setVerified(true);

        $this->em->persist($user);

        return $user;
    }

    private function extractCategoryIdsFromPagination(
        PaginationInterface $pagination
    ): array {
        $ids = [];

        foreach ($pagination->getItems() as $item) {
            /*
             * La requête sélectionne :
             *
             * c, COUNT(...) AS total
             *
             * Doctrine/KNP peut donc renvoyer soit directement
             * Categories, soit un tableau [0 => Categories, 'total' => ...].
             */
            if ($item instanceof Categories) {
                $ids[] = $item->getId();
                continue;
            }

            if (
                is_array($item)
                && isset($item[0])
                && $item[0] instanceof Categories
            ) {
                $ids[] = $item[0]->getId();
            }
        }

        return $ids;
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}
