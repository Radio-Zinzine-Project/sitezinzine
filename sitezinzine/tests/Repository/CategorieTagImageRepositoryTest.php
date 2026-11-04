<?php

namespace App\Tests\Repository;

use App\Entity\CategorieTagImage;
use App\Entity\Categories;
use App\Entity\Editeur;
use App\Repository\CategorieTagImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CategorieTagImageRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CategorieTagImageRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(CategorieTagImageRepository::class);

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

    public function testFindOneByCategorieAndYearReturnsMatchingTagImage(): void
    {
        $categorie = $this->createCategorie('Catégorie A ' . $this->unique());

        $tag2024 = $this->createTagImage($categorie, 2024);
        $tag2025 = $this->createTagImage($categorie, 2025);

        $this->em->flush();

        $result = $this->repository->findOneByCategorieAndYear($categorie, 2025);

        self::assertInstanceOf(CategorieTagImage::class, $result);
        self::assertSame($tag2025->getId(), $result->getId());
        self::assertNotSame($tag2024->getId(), $result->getId());
    }

    public function testFindOneByCategorieAndYearReturnsNullWhenNotFound(): void
    {
        $categorie = $this->createCategorie('Catégorie B ' . $this->unique());

        $this->createTagImage($categorie, 2024);

        $this->em->flush();

        $result = $this->repository->findOneByCategorieAndYear($categorie, 2030);

        self::assertNull($result);
    }

    public function testFindOneByCategorieAndYearDoesNotReturnAnotherCategoriesTagImage(): void
    {
        $categorieA = $this->createCategorie('Catégorie C ' . $this->unique());
        $categorieB = $this->createCategorie('Catégorie D ' . $this->unique());

        $this->createTagImage($categorieB, 2025);

        $this->em->flush();

        $result = $this->repository->findOneByCategorieAndYear($categorieA, 2025);

        self::assertNull($result);
    }

    public function testFindByCategorieOrderedByYearDescFiltersAndOrders(): void
    {
        $categorie = $this->createCategorie('Catégorie E ' . $this->unique());
        $otherCategorie = $this->createCategorie('Catégorie F ' . $this->unique());

        $tag2023 = $this->createTagImage($categorie, 2023);
        $tag2025 = $this->createTagImage($categorie, 2025);
        $tag2024 = $this->createTagImage($categorie, 2024);

        $other = $this->createTagImage($otherCategorie, 2030);

        $this->em->flush();

        $results = $this->repository->findByCategorieOrderedByYearDesc($categorie);

        $ids = array_map(
            static fn(CategorieTagImage $tag): ?int => $tag->getId(),
            $results
        );

        self::assertSame(
            [$tag2025->getId(), $tag2024->getId(), $tag2023->getId()],
            $ids
        );

        self::assertNotContains($other->getId(), $ids);
    }

    public function testFindAllOrderedOrdersByCategoryTitleThenYearDesc(): void
    {
        $unique = $this->unique();

        $categorieA = $this->createCategorie('AAA Tag ' . $unique);
        $categorieZ = $this->createCategorie('ZZZ Tag ' . $unique);

        $a2024 = $this->createTagImage($categorieA, 2024);
        $a2025 = $this->createTagImage($categorieA, 2025);

        $z2024 = $this->createTagImage($categorieZ, 2024);
        $z2025 = $this->createTagImage($categorieZ, 2025);

        $this->em->flush();

        $results = $this->repository->findAllOrdered();

        $ids = array_map(
            static fn(CategorieTagImage $tag): ?int => $tag->getId(),
            $results
        );

        $positionA2025 = array_search($a2025->getId(), $ids, true);
        $positionA2024 = array_search($a2024->getId(), $ids, true);
        $positionZ2025 = array_search($z2025->getId(), $ids, true);
        $positionZ2024 = array_search($z2024->getId(), $ids, true);

        self::assertIsInt($positionA2025);
        self::assertIsInt($positionA2024);
        self::assertIsInt($positionZ2025);
        self::assertIsInt($positionZ2024);

        // AAA avant ZZZ
        self::assertLessThan($positionZ2025, $positionA2025);
        self::assertLessThan($positionZ2024, $positionA2024);

        // Pour une même catégorie : année DESC
        self::assertLessThan($positionA2024, $positionA2025);
        self::assertLessThan($positionZ2024, $positionZ2025);

        // Le LEFT JOIN + addSelect doit hydrater la catégorie.
        self::assertSame($categorieA->getId(), $a2025->getCategorie()?->getId());
        self::assertSame($categorieZ->getId(), $z2025->getCategorie()?->getId());
    }

    private function createCategorie(string $titre): Categories
    {
        $categorie = (new Categories())
            ->setTitre($titre)
            ->setDuree(60)
            ->setDescriptif('Description test')
            ->setThumbnail('thumbnail-test.jpg')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($this->createEditeur());

        $this->em->persist($categorie);

        return $categorie;
    }

    private function createEditeur(): Editeur
    {
        $editeur = (new Editeur())
            ->setName('Éditeur test ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    private function createTagImage(
        Categories $categorie,
        int $annee
    ): CategorieTagImage {
        $tagImage = (new CategorieTagImage())
            ->setCategorie($categorie)
            ->setAnnee($annee);

        $this->em->persist($tagImage);

        return $tagImage;
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}
