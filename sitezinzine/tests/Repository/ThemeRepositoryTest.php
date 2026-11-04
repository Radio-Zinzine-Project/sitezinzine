<?php

namespace App\Tests\Repository;

use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\Theme;
use App\Repository\ThemeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ThemeRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ThemeRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(ThemeRepository::class);

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

    public function testPaginateEmissionsReturnsOnlyEmissionsFromRequestedTheme(): void
    {
        $theme = $this->createTheme();
        $otherTheme = $this->createTheme();

        $expected = $this->createEmission(
            'Emission thème ' . $this->unique(),
            new \DateTime('2199-01-03'),
            $theme
        );

        $other = $this->createEmission(
            'Emission autre thème ' . $this->unique(),
            new \DateTime('2199-01-04'),
            $otherTheme
        );

        $this->em->flush();

        $ids = $this->extractEmissionIds(
            $this->repository->paginateEmissions($theme)
        );

        self::assertContains($expected->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testPaginateEmissionsFiltersNumericInitial(): void
    {
        $theme = $this->createTheme();

        $numeric = $this->createEmission(
            '7Emission ' . $this->unique(),
            new \DateTime('2199-02-03'),
            $theme
        );

        $letter = $this->createEmission(
            'AEmission ' . $this->unique(),
            new \DateTime('2199-02-02'),
            $theme
        );

        $this->em->flush();

        $ids = $this->extractEmissionIds(
            $this->repository->paginateEmissions(
                $theme,
                1,
                '0-9'
            )
        );

        self::assertContains($numeric->getId(), $ids);
        self::assertNotContains($letter->getId(), $ids);
    }

    public function testPaginateEmissionsFiltersAlphabeticInitial(): void
    {
        $theme = $this->createTheme();

        $matching = $this->createEmission(
            'Alpha ' . $this->unique(),
            new \DateTime('2199-03-03'),
            $theme
        );

        $other = $this->createEmission(
            'Beta ' . $this->unique(),
            new \DateTime('2199-03-02'),
            $theme
        );

        $this->em->flush();

        $ids = $this->extractEmissionIds(
            $this->repository->paginateEmissions(
                $theme,
                1,
                'A'
            )
        );

        self::assertContains($matching->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testPaginateEmissionsOrdersByDateDescending(): void
    {
        $theme = $this->createTheme();

        $older = $this->createEmission(
            'Emission ancienne ' . $this->unique(),
            new \DateTime('2199-04-01'),
            $theme
        );

        $newer = $this->createEmission(
            'Emission récente ' . $this->unique(),
            new \DateTime('2199-04-03'),
            $theme
        );

        $this->em->flush();

        $ids = $this->extractEmissionIds(
            $this->repository->paginateEmissions($theme)
        );

        $positionOlder = array_search($older->getId(), $ids, true);
        $positionNewer = array_search($newer->getId(), $ids, true);

        self::assertIsInt($positionOlder);
        self::assertIsInt($positionNewer);

        self::assertLessThan($positionOlder, $positionNewer);
    }

    public function testPaginateEmissionsOrdersByTitleAscendingWhenDatesAreEqual(): void
    {
        $theme = $this->createTheme();

        $date = new \DateTime('2199-05-01');

        $first = $this->createEmission(
            'AAA-' . $this->unique(),
            $date,
            $theme
        );

        $second = $this->createEmission(
            'ZZZ-' . $this->unique(),
            clone $date,
            $theme
        );

        $this->em->flush();

        $ids = $this->extractEmissionIds(
            $this->repository->paginateEmissions($theme)
        );

        $positionFirst = array_search($first->getId(), $ids, true);
        $positionSecond = array_search($second->getId(), $ids, true);

        self::assertIsInt($positionFirst);
        self::assertIsInt($positionSecond);

        self::assertLessThan($positionSecond, $positionFirst);
    }

    private function createTheme(): Theme
    {
        $theme = new Theme();

        if (method_exists($theme, 'setTitre')) {
            $theme->setTitre('Thème test ' . $this->unique());
        } elseif (method_exists($theme, 'setTitle')) {
            $theme->setTitle('Thème test ' . $this->unique());
        } elseif (method_exists($theme, 'setName')) {
            $theme->setName('Thème test ' . $this->unique());
        }

        if (method_exists($theme, 'setUpdatedAt')) {
            $theme->setUpdatedAt(new \DateTime());
        } elseif (method_exists($theme, 'setUpdateAt')) {
            $theme->setUpdateAt(new \DateTime());
        }

        $this->em->persist($theme);

        return $theme;
    }

    private function createEmission(
        string $titre,
        \DateTime $datepub,
        Theme $theme
    ): Emission {
        $emission = (new Emission())
            ->setTitre($titre)
            ->setKeyword('test')
            ->setDatepub($datepub)
            ->setRef('REF-' . $this->unique())
            ->setDuree(60)
            ->setUrl('https://emission-' . $this->unique() . '.test')
            ->setDescriptif('Description test')
            ->setTheme($theme)
            ->setEditeur($this->createEditeur())
            ->setUpdatedAt(new \DateTime())
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->em->persist($emission);

        return $emission;
    }

    private function createEditeur(): Editeur
    {
        $editeur = (new Editeur())
            ->setName('Éditeur thème ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    private function extractEmissionIds(
        PaginationInterface $pagination
    ): array {
        $ids = [];

        foreach ($pagination->getItems() as $item) {
            if ($item instanceof Emission) {
                $ids[] = $item->getId();
            }
        }

        return $ids;
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}
