<?php

namespace App\Tests\Repository;

use App\Entity\Editeur;
use App\Entity\Emission;
use App\Repository\EditeurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EditeurRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EditeurRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(EditeurRepository::class);

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

    public function testPaginateEmissionsReturnsOnlyEmissionsFromRequestedEditeur(): void
    {
        $editeur = $this->createEditeur('Éditeur A ' . $this->unique());
        $otherEditeur = $this->createEditeur('Éditeur B ' . $this->unique());

        $expected = $this->createEmission(
            $editeur,
            'Emission éditeur demandé ' . $this->unique(),
            new \DateTime('2099-01-02 10:00:00')
        );

        $other = $this->createEmission(
            $otherEditeur,
            'Emission autre éditeur ' . $this->unique(),
            new \DateTime('2099-01-03 10:00:00')
        );

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions($editeur);

        self::assertInstanceOf(PaginationInterface::class, $pagination);

        $ids = $this->extractEmissionIds($pagination);

        self::assertContains($expected->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testPaginateEmissionsFiltersNumericInitial(): void
    {
        $editeur = $this->createEditeur();

        $numeric = $this->createEmission(
            $editeur,
            '9 émission ' . $this->unique(),
            new \DateTime('2099-02-02 10:00:00')
        );

        $alphabetic = $this->createEmission(
            $editeur,
            'A émission ' . $this->unique(),
            new \DateTime('2099-02-03 10:00:00')
        );

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions(
            $editeur,
            1,
            '0-9'
        );

        $ids = $this->extractEmissionIds($pagination);

        self::assertContains($numeric->getId(), $ids);
        self::assertNotContains($alphabetic->getId(), $ids);
    }

    public function testPaginateEmissionsFiltersByInitial(): void
    {
        $editeur = $this->createEditeur();

        $matching = $this->createEmission(
            $editeur,
            'Q émission ' . $this->unique(),
            new \DateTime('2099-03-02 10:00:00')
        );

        $notMatching = $this->createEmission(
            $editeur,
            'Z émission ' . $this->unique(),
            new \DateTime('2099-03-03 10:00:00')
        );

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions(
            $editeur,
            1,
            'Q'
        );

        $ids = $this->extractEmissionIds($pagination);

        self::assertContains($matching->getId(), $ids);
        self::assertNotContains($notMatching->getId(), $ids);
    }

    public function testPaginateEmissionsOrdersByDateDescending(): void
    {
        $editeur = $this->createEditeur();

        $old = $this->createEmission(
            $editeur,
            'Emission ancienne ' . $this->unique(),
            new \DateTime('2099-04-01 10:00:00')
        );

        $latest = $this->createEmission(
            $editeur,
            'Emission récente ' . $this->unique(),
            new \DateTime('2099-04-10 10:00:00')
        );

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions($editeur);

        $ids = $this->extractEmissionIds($pagination);

        $positionOld = array_search($old->getId(), $ids, true);
        $positionLatest = array_search($latest->getId(), $ids, true);

        self::assertIsInt($positionOld);
        self::assertIsInt($positionLatest);

        self::assertLessThan($positionOld, $positionLatest);
    }

    public function testPaginateEmissionsOrdersByTitleAscendingWhenDatesAreEqual(): void
    {
        $editeur = $this->createEditeur();

        $date = new \DateTime('2099-05-01 10:00:00');

        $alpha = $this->createEmission(
            $editeur,
            'AAA Emission ' . $this->unique(),
            clone $date
        );

        $zulu = $this->createEmission(
            $editeur,
            'ZZZ Emission ' . $this->unique(),
            clone $date
        );

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions($editeur);

        $ids = $this->extractEmissionIds($pagination);

        $positionAlpha = array_search($alpha->getId(), $ids, true);
        $positionZulu = array_search($zulu->getId(), $ids, true);

        self::assertIsInt($positionAlpha);
        self::assertIsInt($positionZulu);

        self::assertLessThan($positionZulu, $positionAlpha);
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
        Editeur $editeur,
        string $titre,
        \DateTime $datepub
    ): Emission {
        $emission = (new Emission())
            ->setTitre($titre)
            ->setKeyword('test')
            ->setDatepub($datepub)
            ->setRef('REF-' . $this->unique())
            ->setDuree(60)
            ->setUrl('https://emission-' . $this->unique() . '.test')
            ->setDescriptif('Description test')
            ->setEditeur($editeur)
            ->setUpdatedAt(new \DateTime())
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->em->persist($emission);

        return $emission;
    }

    private function extractEmissionIds(PaginationInterface $pagination): array
    {
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
