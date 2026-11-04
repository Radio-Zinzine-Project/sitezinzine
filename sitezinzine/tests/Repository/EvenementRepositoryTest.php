<?php

namespace App\Tests\Repository;

use App\Entity\Evenement;
use App\Repository\EvenementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EvenementRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EvenementRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(EvenementRepository::class);

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

    public function testFindUpcomingEvenementsReturnsFutureAndCurrentValidatedEvents(): void
    {
        $today = new \DateTimeImmutable('today');

        $current = $this->createEvenement(
            'En cours ' . $this->unique(),
            $today->modify('-1 day'),
            $today->modify('+1 day'),
            true
        );

        $future = $this->createEvenement(
            'À venir ' . $this->unique(),
            $today->modify('+2 days'),
            $today->modify('+3 days'),
            true
        );

        $past = $this->createEvenement(
            'Passé ' . $this->unique(),
            $today->modify('-5 days'),
            $today->modify('-3 days'),
            true
        );

        $invalid = $this->createEvenement(
            'Non validé ' . $this->unique(),
            $today->modify('+1 day'),
            $today->modify('+2 days'),
            false
        );

        $this->em->flush();

        $results = $this->repository->findUpcomingEvenements();

        $ids = $this->extractIds($results);

        self::assertContains($current->getId(), $ids);
        self::assertContains($future->getId(), $ids);

        self::assertNotContains($past->getId(), $ids);
        self::assertNotContains($invalid->getId(), $ids);
    }

    public function testFindUpcomingEvenementsOrdersByStartDateAscending(): void
    {
        $today = new \DateTimeImmutable('today');

        $first = $this->createEvenement(
            'Premier ' . $this->unique(),
            $today->modify('+1 hour'),
            $today->modify('+2 hours'),
            true
        );

        $second = $this->createEvenement(
            'Deuxième ' . $this->unique(),
            $today->modify('+3 hours'),
            $today->modify('+4 hours'),
            true
        );

        $this->em->flush();

        $results = $this->repository->findUpcomingEvenements();
        $ids = $this->extractIds($results);

        $positionFirst = array_search($first->getId(), $ids, true);
        $positionSecond = array_search($second->getId(), $ids, true);

        self::assertIsInt($positionFirst);
        self::assertIsInt($positionSecond);

        self::assertLessThan($positionSecond, $positionFirst);
    }

    public function testFindUpcomingEvenementsLimitsResultsToThree(): void
    {
        $today = new \DateTimeImmutable('today');

        for ($i = 1; $i <= 4; ++$i) {
            $this->createEvenement(
                'Événement limite ' . $i . ' ' . $this->unique(),
                $today->modify('+' . (200 + $i) . ' days'),
                $today->modify('+' . (201 + $i) . ' days'),
                true
            );
        }

        $this->em->flush();

        $results = $this->repository->findUpcomingEvenements();

        self::assertCount(3, $results);
    }

    public function testFindLatestPublicEvenementsFiltersAndOrdersResults(): void
    {
        $base = new \DateTimeImmutable('2100-01-01');

        $older = $this->createEvenement(
            'Public ancien ' . $this->unique(),
            $base,
            $base->modify('+1 day'),
            true,
            false
        );

        $latest = $this->createEvenement(
            'Public récent ' . $this->unique(),
            $base->modify('+10 days'),
            $base->modify('+11 days'),
            true,
            false
        );

        $invalid = $this->createEvenement(
            'Non validé ' . $this->unique(),
            $base->modify('+20 days'),
            $base->modify('+21 days'),
            false,
            false
        );

        $deleted = $this->createEvenement(
            'Supprimé ' . $this->unique(),
            $base->modify('+30 days'),
            $base->modify('+31 days'),
            true,
            true
        );

        $this->em->flush();

        $results = $this->repository->findLatestPublicEvenements(1000);
        $ids = $this->extractIds($results);

        self::assertContains($older->getId(), $ids);
        self::assertContains($latest->getId(), $ids);
        self::assertNotContains($invalid->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);

        $positionOlder = array_search($older->getId(), $ids, true);
        $positionLatest = array_search($latest->getId(), $ids, true);

        self::assertIsInt($positionOlder);
        self::assertIsInt($positionLatest);
        self::assertLessThan($positionOlder, $positionLatest);
    }

    public function testFindLatestPublicEvenementsAppliesLimit(): void
    {
        $base = new \DateTimeImmutable('2101-01-01');

        for ($i = 1; $i <= 4; ++$i) {
            $this->createEvenement(
                'Public limite ' . $i . ' ' . $this->unique(),
                $base->modify('+' . $i . ' days'),
                $base->modify('+' . ($i + 1) . ' days'),
                true,
                false
            );
        }

        $this->em->flush();

        $results = $this->repository->findLatestPublicEvenements(2);

        self::assertCount(2, $results);
    }

    public function testFindAllDescExcludesSoftDeletedAndOrdersByIdDescending(): void
    {
        $base = new \DateTimeImmutable('2102-01-01');

        $first = $this->createEvenement(
            'Premier ID ' . $this->unique(),
            $base,
            $base->modify('+1 day'),
            true,
            false
        );

        $second = $this->createEvenement(
            'Deuxième ID ' . $this->unique(),
            $base,
            $base->modify('+1 day'),
            true,
            false
        );

        $deleted = $this->createEvenement(
            'Supprimé ID ' . $this->unique(),
            $base,
            $base->modify('+1 day'),
            true,
            true
        );

        $this->em->flush();

        $results = $this->repository->findAllDesc();
        $ids = $this->extractIds($results);

        self::assertContains($first->getId(), $ids);
        self::assertContains($second->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);

        $positionFirst = array_search($first->getId(), $ids, true);
        $positionSecond = array_search($second->getId(), $ids, true);

        self::assertIsInt($positionFirst);
        self::assertIsInt($positionSecond);

        self::assertLessThan($positionFirst, $positionSecond);
    }

    public function testFindOldEvenementsReturnsOnlyEventsEndingBeforeLimit(): void
    {
        $limit = new \DateTimeImmutable('2103-06-15 00:00:00');

        $old = $this->createEvenement(
            'Ancien ' . $this->unique(),
            $limit->modify('-10 days'),
            $limit->modify('-5 days'),
            true,
            false
        );

        $after = $this->createEvenement(
            'Récent ' . $this->unique(),
            $limit->modify('+1 day'),
            $limit->modify('+2 days'),
            true,
            false
        );

        // La requête utilise strictement "<", donc une dateFin
        // exactement égale à dateLimit ne doit pas être retournée.
        $exactLimit = $this->createEvenement(
            'Limite exacte ' . $this->unique(),
            $limit->modify('-1 day'),
            $limit,
            true,
            false
        );

        $this->em->flush();

        $results = $this->repository->findOldEvenements($limit);
        $ids = $this->extractIds($results);

        self::assertContains($old->getId(), $ids);
        self::assertNotContains($after->getId(), $ids);
        self::assertNotContains($exactLimit->getId(), $ids);
    }

    private function createEvenement(
        string $titre,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin,
        bool $valid = true,
        bool $softDelete = false
    ): Evenement {
        $evenement = (new Evenement())
            ->setTitre($titre)
            ->setDateDebut(\DateTime::createFromImmutable($dateDebut))
            ->setDateFin(\DateTime::createFromImmutable($dateFin))
            ->setValid($valid)
            ->setSoftDelete($softDelete)
            ->setUpdateAt(new \DateTime());

        $this->em->persist($evenement);

        return $evenement;
    }

    /**
     * @param Evenement[] $evenements
     *
     * @return int[]
     */
    private function extractIds(array $evenements): array
    {
        return array_map(
            static fn(Evenement $evenement): ?int => $evenement->getId(),
            $evenements
        );
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}
