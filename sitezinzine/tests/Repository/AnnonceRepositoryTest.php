<?php

namespace App\Tests\Repository;

use App\Entity\Annonce;
use App\Repository\AnnonceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AnnonceRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AnnonceRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $this->em->getRepository(Annonce::class);

        $this->em->beginTransaction();

        $this->em
            ->createQuery('DELETE FROM App\Entity\Annonce a')
            ->execute();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }

        $this->em->close();

        parent::tearDown();
    }

    public function testFindUpcomingAnnoncesReturnsFutureAndCurrentValidAnnoncesOrderedByStartDate(): void
    {
        $today = new \DateTimeImmutable('today');

        $past = $this->createAnnonce(
            'Annonce passée',
            $today->modify('-3 days'),
            $today->modify('-2 days'),
            true
        );

        $current = $this->createAnnonce(
            'Annonce en cours',
            $today->modify('-1 day'),
            $today->modify('+1 day'),
            true
        );

        $futureLater = $this->createAnnonce(
            'Annonce future plus tard',
            $today->modify('+4 days'),
            $today->modify('+5 days'),
            true
        );

        $futureSoon = $this->createAnnonce(
            'Annonce future bientôt',
            $today->modify('+2 days'),
            $today->modify('+3 days'),
            true
        );

        $invalid = $this->createAnnonce(
            'Annonce invalide',
            $today->modify('+1 day'),
            $today->modify('+2 days'),
            false
        );

        $this->em->flush();
        $this->em->clear();

        $results = $this->repository->findUpcomingAnnonces();

        self::assertCount(3, $results);

        self::assertSame(
            [
                'Annonce en cours',
                'Annonce future bientôt',
                'Annonce future plus tard',
            ],
            array_map(
                static fn(Annonce $annonce): ?string => $annonce->getTitre(),
                $results
            )
        );

        self::assertNotContains(
            $past->getId(),
            array_map(static fn(Annonce $annonce): ?int => $annonce->getId(), $results)
        );

        self::assertNotContains(
            $invalid->getId(),
            array_map(static fn(Annonce $annonce): ?int => $annonce->getId(), $results)
        );
    }

    public function testFindUpcomingAnnoncesIncludesAnnonceStartingToday(): void
    {
        $today = new \DateTimeImmutable('today');

        $annonce = $this->createAnnonce(
            'Commence aujourd’hui',
            $today,
            $today->modify('+1 day'),
            true
        );

        $this->em->flush();
        $this->em->clear();

        $results = $this->repository->findUpcomingAnnonces();

        self::assertCount(1, $results);
        self::assertSame($annonce->getId(), $results[0]->getId());
    }

    public function testFindUpcomingAnnoncesQueryReturnsQueryAndSameExpectedResults(): void
    {
        $today = new \DateTimeImmutable('today');

        $current = $this->createAnnonce(
            'En cours query',
            $today->modify('-1 day'),
            $today->modify('+1 day'),
            true
        );

        $future = $this->createAnnonce(
            'Future query',
            $today->modify('+2 days'),
            $today->modify('+3 days'),
            true
        );

        $this->createAnnonce(
            'Invalide query',
            $today->modify('+1 day'),
            $today->modify('+2 days'),
            false
        );

        $this->createAnnonce(
            'Passée query',
            $today->modify('-5 days'),
            $today->modify('-4 days'),
            true
        );

        $this->em->flush();
        $this->em->clear();

        $query = $this->repository->findUpcomingAnnoncesQuery();

        self::assertInstanceOf(Query::class, $query);

        $results = $query->getResult();

        self::assertCount(2, $results);

        self::assertSame(
            [
                $current->getId(),
                $future->getId(),
            ],
            array_map(
                static fn(Annonce $annonce): ?int => $annonce->getId(),
                $results
            )
        );
    }

    public function testFindAllDescExcludesSoftDeletedAndOrdersByIdDescending(): void
    {
        $first = $this->createAnnonce(
            'Première annonce',
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            true,
            false
        );

        $second = $this->createAnnonce(
            'Deuxième annonce',
            new \DateTimeImmutable('2026-09-12'),
            new \DateTimeImmutable('2026-09-13'),
            true,
            false
        );

        $deleted = $this->createAnnonce(
            'Annonce supprimée',
            new \DateTimeImmutable('2026-09-14'),
            new \DateTimeImmutable('2026-09-15'),
            true,
            true
        );

        $third = $this->createAnnonce(
            'Troisième annonce',
            new \DateTimeImmutable('2026-09-16'),
            new \DateTimeImmutable('2026-09-17'),
            true,
            false
        );

        $this->em->flush();
        $this->em->clear();

        $results = $this->repository->findAllDesc();

        self::assertCount(3, $results);

        self::assertSame(
            [
                $third->getId(),
                $second->getId(),
                $first->getId(),
            ],
            array_map(
                static fn(Annonce $annonce): ?int => $annonce->getId(),
                $results
            )
        );

        self::assertNotContains(
            $deleted->getId(),
            array_map(static fn(Annonce $annonce): ?int => $annonce->getId(), $results)
        );
    }

    public function testFindAllDescQueryReturnsQueryExcludesSoftDeletedAndOrdersByUpdateAtThenId(): void
    {
        $old = $this->createAnnonce(
            'Ancienne mise à jour',
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            true,
            false,
            new \DateTime('2026-09-01 10:00:00')
        );

        $sameUpdateFirst = $this->createAnnonce(
            'Même update première',
            new \DateTimeImmutable('2026-09-12'),
            new \DateTimeImmutable('2026-09-13'),
            true,
            false,
            new \DateTime('2026-09-05 10:00:00')
        );

        $sameUpdateSecond = $this->createAnnonce(
            'Même update deuxième',
            new \DateTimeImmutable('2026-09-14'),
            new \DateTimeImmutable('2026-09-15'),
            true,
            false,
            new \DateTime('2026-09-05 10:00:00')
        );

        $this->createAnnonce(
            'Soft deleted',
            new \DateTimeImmutable('2026-09-16'),
            new \DateTimeImmutable('2026-09-17'),
            true,
            true,
            new \DateTime('2026-09-10 10:00:00')
        );

        $this->em->flush();
        $this->em->clear();

        $query = $this->repository->findAllDescQuery();

        self::assertInstanceOf(Query::class, $query);

        $results = $query->getResult();

        self::assertCount(3, $results);

        self::assertSame(
            [
                $sameUpdateSecond->getId(),
                $sameUpdateFirst->getId(),
                $old->getId(),
            ],
            array_map(
                static fn(Annonce $annonce): ?int => $annonce->getId(),
                $results
            )
        );
    }

    public function testFindOldAnnoncesReturnsOnlyAnnoncesEndingBeforeDateLimit(): void
    {
        $dateLimit = new \DateTimeImmutable('2026-09-10 00:00:00');

        $older = $this->createAnnonce(
            'Très ancienne',
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-05'),
            true
        );

        $old = $this->createAnnonce(
            'Ancienne',
            new \DateTimeImmutable('2026-09-07'),
            new \DateTimeImmutable('2026-09-09'),
            true
        );

        $boundary = $this->createAnnonce(
            'Exactement à la limite',
            new \DateTimeImmutable('2026-09-09'),
            $dateLimit,
            true
        );

        $future = $this->createAnnonce(
            'Après la limite',
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            true
        );

        $this->em->flush();
        $this->em->clear();

        $results = $this->repository->findOldAnnonces($dateLimit);

        $ids = array_map(
            static fn(Annonce $annonce): ?int => $annonce->getId(),
            $results
        );

        self::assertCount(2, $results);
        self::assertContains($older->getId(), $ids);
        self::assertContains($old->getId(), $ids);
        self::assertNotContains($boundary->getId(), $ids);
        self::assertNotContains($future->getId(), $ids);
    }

    private function createAnnonce(
        string $titre,
        \DateTimeInterface $dateDebut,
        \DateTimeInterface $dateFin,
        bool $valid = true,
        bool $softDelete = false,
        ?\DateTime $updateAt = null
    ): Annonce {
        $annonce = (new Annonce())
            ->setTitre($titre)
            ->setOrganisateur('Organisateur test')
            ->setVille('Nantes')
            ->setDepartement('44')
            ->setAdresse('1 rue du Test')
            ->setDateDebut(
                $dateDebut instanceof \DateTime
                    ? clone $dateDebut
                    : \DateTime::createFromImmutable(
                        \DateTimeImmutable::createFromInterface($dateDebut)
                    )
            )
            ->setDateFin(
                $dateFin instanceof \DateTime
                    ? clone $dateFin
                    : \DateTime::createFromImmutable(
                        \DateTimeImmutable::createFromInterface($dateFin)
                    )
            )
            ->setHoraire('20h00')
            ->setPrix('Gratuit')
            ->setPresentation('Présentation de test')
            ->setContact('contact@test.fr')
            ->setType('concert')
            ->setValid($valid)
            ->setUpdateAt($updateAt ?? new \DateTime())
            ->setSoftDelete($softDelete);

        $this->em->persist($annonce);

        return $annonce;
    }
}
