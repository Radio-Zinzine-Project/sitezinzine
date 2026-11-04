<?php

namespace App\Tests\Repository;

use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\InviteOldAnimateur;
use App\Repository\InviteOldAnimateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class InviteOldAnimateurRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InviteOldAnimateurRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(InviteOldAnimateurRepository::class);

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

    public function testFindFilteredReturnsInviteOldAnimateursOrderedByLastNameThenFirstName(): void
    {
        $alpha = $this->createInvite(
            'AAA-' . $this->unique(),
            'Zoé',
            false
        );

        $alphaSecond = $this->createInvite(
            $alpha->getLastName(),
            'Alice',
            false
        );

        $zulu = $this->createInvite(
            'ZZZ-' . $this->unique(),
            'Bob',
            false
        );

        $this->em->flush();

        $results = $this->repository->findFiltered();
        $ids = $this->extractInviteIds($results);

        $positionAlphaSecond = array_search($alphaSecond->getId(), $ids, true);
        $positionAlpha = array_search($alpha->getId(), $ids, true);
        $positionZulu = array_search($zulu->getId(), $ids, true);

        self::assertIsInt($positionAlphaSecond);
        self::assertIsInt($positionAlpha);
        self::assertIsInt($positionZulu);

        self::assertLessThan($positionAlpha, $positionAlphaSecond);
        self::assertLessThan($positionZulu, $positionAlpha);
    }

    public function testFindFilteredFiltersNumericInitial(): void
    {
        $numeric = $this->createInvite(
            '9Nom-' . $this->unique(),
            'Jean',
            false
        );

        $alphabetic = $this->createInvite(
            'ANom-' . $this->unique(),
            'Paul',
            false
        );

        $this->em->flush();

        $results = $this->repository->findFiltered('0-9');
        $ids = $this->extractInviteIds($results);

        self::assertContains($numeric->getId(), $ids);
        self::assertNotContains($alphabetic->getId(), $ids);
    }

    public function testFindFilteredFiltersAlphabeticInitial(): void
    {
        $matching = $this->createInvite(
            'QNom-' . $this->unique(),
            'Jean',
            false
        );

        $notMatching = $this->createInvite(
            'ZNom-' . $this->unique(),
            'Paul',
            false
        );

        $this->em->flush();

        $results = $this->repository->findFiltered('Q');
        $ids = $this->extractInviteIds($results);

        self::assertContains($matching->getId(), $ids);
        self::assertNotContains($notMatching->getId(), $ids);
    }

    public function testFindFilteredFiltersInvites(): void
    {
        $invite = $this->createInvite(
            'Invite-' . $this->unique(),
            'Alice',
            false
        );

        $ancien = $this->createInvite(
            'Ancien-' . $this->unique(),
            'Bob',
            true
        );

        $this->em->flush();

        $results = $this->repository->findFiltered('', 'invite');
        $ids = $this->extractInviteIds($results);

        self::assertContains($invite->getId(), $ids);
        self::assertNotContains($ancien->getId(), $ids);
    }

    public function testFindFilteredFiltersAnciensAnimateurs(): void
    {
        $invite = $this->createInvite(
            'Invite-' . $this->unique(),
            'Alice',
            false
        );

        $ancien = $this->createInvite(
            'Ancien-' . $this->unique(),
            'Bob',
            true
        );

        $this->em->flush();

        $results = $this->repository->findFiltered('', 'ancien');
        $ids = $this->extractInviteIds($results);

        self::assertContains($ancien->getId(), $ids);
        self::assertNotContains($invite->getId(), $ids);
    }

    public function testPaginateEmissionsReturnsOnlyLinkedEmissions(): void
    {
        $invite = $this->createInvite();
        $otherInvite = $this->createInvite();

        $expected = $this->createEmission(
            'Emission liée ' . $this->unique(),
            new \DateTime('2199-01-02 10:00:00')
        );

        $other = $this->createEmission(
            'Emission autre ' . $this->unique(),
            new \DateTime('2199-01-03 10:00:00')
        );

        $expected->addInviteOldAnimateur($invite);
        $other->addInviteOldAnimateur($otherInvite);

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions($invite);

        self::assertInstanceOf(PaginationInterface::class, $pagination);

        $ids = $this->extractEmissionIds($pagination);

        self::assertContains($expected->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testPaginateEmissionsFiltersNumericInitial(): void
    {
        $invite = $this->createInvite();

        $numeric = $this->createEmission(
            '9 émission ' . $this->unique(),
            new \DateTime('2199-02-02 10:00:00')
        );

        $alphabetic = $this->createEmission(
            'A émission ' . $this->unique(),
            new \DateTime('2199-02-03 10:00:00')
        );

        $numeric->addInviteOldAnimateur($invite);
        $alphabetic->addInviteOldAnimateur($invite);

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions(
            $invite,
            1,
            '0-9'
        );

        $ids = $this->extractEmissionIds($pagination);

        self::assertContains($numeric->getId(), $ids);
        self::assertNotContains($alphabetic->getId(), $ids);
    }

    public function testPaginateEmissionsFiltersAlphabeticInitial(): void
    {
        $invite = $this->createInvite();

        $matching = $this->createEmission(
            'Q émission ' . $this->unique(),
            new \DateTime('2199-03-02 10:00:00')
        );

        $notMatching = $this->createEmission(
            'Z émission ' . $this->unique(),
            new \DateTime('2199-03-03 10:00:00')
        );

        $matching->addInviteOldAnimateur($invite);
        $notMatching->addInviteOldAnimateur($invite);

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions(
            $invite,
            1,
            'Q'
        );

        $ids = $this->extractEmissionIds($pagination);

        self::assertContains($matching->getId(), $ids);
        self::assertNotContains($notMatching->getId(), $ids);
    }

    public function testPaginateEmissionsOrdersByDateDescending(): void
    {
        $invite = $this->createInvite();

        $older = $this->createEmission(
            'Emission ancienne ' . $this->unique(),
            new \DateTime('2199-04-01 10:00:00')
        );

        $latest = $this->createEmission(
            'Emission récente ' . $this->unique(),
            new \DateTime('2199-04-10 10:00:00')
        );

        $older->addInviteOldAnimateur($invite);
        $latest->addInviteOldAnimateur($invite);

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions($invite);
        $ids = $this->extractEmissionIds($pagination);

        $positionOlder = array_search($older->getId(), $ids, true);
        $positionLatest = array_search($latest->getId(), $ids, true);

        self::assertIsInt($positionOlder);
        self::assertIsInt($positionLatest);

        self::assertLessThan($positionOlder, $positionLatest);
    }

    public function testPaginateEmissionsOrdersByTitleAscendingWhenDatesAreEqual(): void
    {
        $invite = $this->createInvite();

        $date = new \DateTime('2199-05-01 10:00:00');

        $alpha = $this->createEmission(
            'AAA Emission ' . $this->unique(),
            clone $date
        );

        $zulu = $this->createEmission(
            'ZZZ Emission ' . $this->unique(),
            clone $date
        );

        $alpha->addInviteOldAnimateur($invite);
        $zulu->addInviteOldAnimateur($invite);

        $this->em->flush();

        $pagination = $this->repository->paginateEmissions($invite);
        $ids = $this->extractEmissionIds($pagination);

        $positionAlpha = array_search($alpha->getId(), $ids, true);
        $positionZulu = array_search($zulu->getId(), $ids, true);

        self::assertIsInt($positionAlpha);
        self::assertIsInt($positionZulu);

        self::assertLessThan($positionZulu, $positionAlpha);
    }

    private function createInvite(
        ?string $lastName = null,
        ?string $firstName = null,
        bool $ancienAnimateur = false
    ): InviteOldAnimateur {
        $invite = (new InviteOldAnimateur())
            ->setLastName($lastName ?? 'Nom-' . $this->unique())
            ->setFirstName($firstName ?? 'Prénom-' . $this->unique())
            ->setAncienanimateur($ancienAnimateur);

        $this->em->persist($invite);

        return $invite;
    }

    private function createEmission(
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
            ->setName('Éditeur test ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    /**
     * @param InviteOldAnimateur[] $invites
     *
     * @return int[]
     */
    private function extractInviteIds(array $invites): array
    {
        return array_map(
            static fn(InviteOldAnimateur $invite): ?int => $invite->getId(),
            $invites
        );
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