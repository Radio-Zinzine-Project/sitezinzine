<?php

namespace App\Tests\Repository;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Repository\DiffusionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DiffusionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DiffusionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(DiffusionRepository::class);

        $this->em
            ->createQuery('DELETE FROM App\Entity\Diffusion d')
            ->execute();

        $this->em
            ->createQuery('DELETE FROM App\Entity\Emission e')
            ->execute();
    }

    public function testFindLatestReturnsNewestIdsAndRespectsLimit(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission test findLatest',
            new \DateTime('2026-09-01')
        );

        $first = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-01 08:00:00'),
            1
        );

        $second = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-01 09:00:00'),
            2
        );

        $third = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-01 10:00:00'),
            3
        );

        $this->flush();

        /** @var Diffusion[] $results */
        $results = $this->repository->findLatest(2);

        $this->assertCount(2, $results);
        $this->assertSame($third->getId(), $results[0]->getId());
        $this->assertSame($second->getId(), $results[1]->getId());
        $this->assertNotSame($first->getId(), $results[0]->getId());
    }

    public function testFindByWeekUsesStartInclusiveAndEndExclusiveAndOrdersAscending(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission semaine',
            new \DateTime('2026-09-01')
        );

        $startBoundary = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-08 00:00:00'),
            1
        );

        $middle = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-10 12:00:00'),
            2
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-15 00:00:00'),
            3
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-07 23:59:59'),
            4
        );

        $this->flush();

        /** @var Diffusion[] $results */
        $results = $this->repository->findByWeek(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertCount(2, $results);
        $this->assertSame($startBoundary->getId(), $results[0]->getId());
        $this->assertSame($middle->getId(), $results[1]->getId());
    }

    public function testHasDiffusionsBetweenReturnsTrueForPublishedDiffusionInRange(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission publiée',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-10 10:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->flush();

        $result = $this->repository->hasDiffusionsBetween(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertTrue($result);
    }

    public function testHasDiffusionsBetweenReturnsFalseForUnpublishedDiffusion(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission non publiée',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-10 10:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        $this->flush();

        $result = $this->repository->hasDiffusionsBetween(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertFalse($result);
    }

    public function testHasDiffusionsBetweenReturnsFalseWhenNoPublishedDiffusionIsInRange(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission hors semaine',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-16 10:00:00'),
            1
        );

        $this->flush();

        $result = $this->repository->hasDiffusionsBetween(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertFalse($result);
    }

    public function testFindAllAtHoraireReturnsAllMatchingDiffusionsOrderedById(): void
    {
        $categorie = $this->createCategorie();

        $emissionA = $this->createEmission(
            $categorie,
            'Emission A même horaire',
            new \DateTime('2026-09-01')
        );

        $emissionB = $this->createEmission(
            $categorie,
            'Emission B même horaire',
            new \DateTime('2026-09-01')
        );

        $horaire = new \DateTime('2026-09-10 14:00:00');

        $first = $this->createDiffusion($emissionA, clone $horaire, 1);
        $second = $this->createDiffusion($emissionB, clone $horaire, 1);

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-10 15:00:00'),
            2
        );

        $this->flush();

        /** @var Diffusion[] $results */
        $results = $this->repository->findAllAtHoraire($horaire);

        $this->assertCount(2, $results);
        $this->assertSame($first->getId(), $results[0]->getId());
        $this->assertSame($second->getId(), $results[1]->getId());
    }

    public function testFindMaxNumbersBeforeForEmissionIdsReturnsMaximumPerEmissionBeforeDate(): void
    {
        $categorie = $this->createCategorie();

        $emissionA = $this->createEmission(
            $categorie,
            'Emission A compteur',
            new \DateTime('2026-09-01')
        );

        $emissionB = $this->createEmission(
            $categorie,
            'Emission B compteur',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-01 08:00:00'),
            2
        );

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-05 08:00:00'),
            5
        );

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-10 08:00:00'),
            9
        );

        $this->createDiffusion(
            $emissionB,
            new \DateTime('2026-09-03 08:00:00'),
            4
        );

        $this->createDiffusion(
            $emissionB,
            new \DateTime('2026-09-07 08:00:00'),
            7
        );

        $this->flush();

        $results = $this->repository->findMaxNumbersBeforeForEmissionIds(
            [$emissionA->getId(), $emissionB->getId()],
            new \DateTimeImmutable('2026-09-08 00:00:00')
        );

        $this->assertSame(5, $results[$emissionA->getId()]);
        $this->assertSame(7, $results[$emissionB->getId()]);
    }

    public function testFindMaxNumbersBeforeForEmissionIdsIgnoresDuplicateAndInvalidIds(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission compteur filtrage ids',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-05 08:00:00'),
            6
        );

        $this->flush();

        $results = $this->repository->findMaxNumbersBeforeForEmissionIds(
            [
                $emission->getId(),
                $emission->getId(),
                0,
                -1,
                '0',
                (string) $emission->getId(),
            ],
            new \DateTimeImmutable('2026-09-08 00:00:00')
        );

        $this->assertCount(1, $results);
        $this->assertSame(6, $results[$emission->getId()]);
    }

    public function testFindMaxNumbersBeforeForEmissionIdsReturnsEmptyArrayForEmptyOrInvalidInput(): void
    {
        $before = new \DateTimeImmutable('2026-09-08 00:00:00');

        $this->assertSame(
            [],
            $this->repository->findMaxNumbersBeforeForEmissionIds([], $before)
        );

        $this->assertSame(
            [],
            $this->repository->findMaxNumbersBeforeForEmissionIds(
                [0, -1, '0'],
                $before
            )
        );
    }

    public function testFindPublishedByWeekReturnsOnlyPublishedDiffusionsInChronologicalOrder(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission semaine publiée',
            new \DateTime('2026-09-01')
        );

        $publishedFirst = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-09 09:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-10 10:00:00'),
            2
        )->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        $publishedSecond = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-11 11:00:00'),
            3
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-15 00:00:00'),
            4
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->flush();

        /** @var Diffusion[] $results */
        $results = $this->repository->findPublishedByWeek(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertCount(2, $results);
        $this->assertSame($publishedFirst->getId(), $results[0]->getId());
        $this->assertSame($publishedSecond->getId(), $results[1]->getId());
    }

    public function testFindLatestByEmissionReturnsOnlyPublishedRowsOrderedDescendingAndLimited(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission historique',
            new \DateTime('2026-09-01')
        );

        $otherEmission = $this->createEmission(
            $categorie,
            'Autre émission',
            new \DateTime('2026-09-01')
        );

        $oldest = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-01 08:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $middle = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-02 08:00:00'),
            2
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $latest = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-03 08:00:00'),
            3
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-04 08:00:00'),
            4
        )->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        $this->createDiffusion(
            $otherEmission,
            new \DateTime('2026-09-05 08:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->flush();

        /** @var Diffusion[] $results */
        $results = $this->repository->findLatestByEmission($emission, 2);

        $this->assertCount(2, $results);
        $this->assertSame($latest->getId(), $results[0]->getId());
        $this->assertSame($middle->getId(), $results[1]->getId());
        $this->assertNotSame($oldest->getId(), $results[0]->getId());
    }

    public function testFindLatestByEmissionSelectsSixLatestAcrossAllGroups(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission historique multi-groupes',
            new \DateTime('2026-10-01')
        );

        $groups = [
            null,
            'manual_100',
            'manual_200',
            'manual_100',
            null,
            'manual_200',
            'manual_100',
            'manual_200',
        ];

        $diffusions = [];

        foreach ($groups as $index => $groupKey) {
            $diffusion = $this->createDiffusion(
                $emission,
                new \DateTime(sprintf(
                    '2026-10-%02d 14:00:00',
                    $index + 1
                )),
                $index + 1
            );

            $diffusion->setAssignmentGroupKey($groupKey);
            $diffusion->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

            $diffusions[] = $diffusion;
        }

        // Plus récente que les autres, mais non publiée.
        $this->createDiffusion(
            $emission,
            new \DateTime('2026-10-09 14:00:00'),
            9
        )->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        $this->flush();

        $results = $this->repository->findLatestByEmission($emission, 6);

        self::assertCount(6, $results);

        // Les deux plus anciennes diffusions sont exclues.
        $expected = array_reverse(array_slice($diffusions, 2));

        foreach ($expected as $index => $diffusion) {
            self::assertSame(
                $diffusion->getId(),
                $results[$index]->getId()
            );

            self::assertSame(
                $diffusion->getAssignmentGroupKey(),
                $results[$index]->getAssignmentGroupKey()
            );

            self::assertSame(
                $diffusion->getNombreDiffusion(),
                $results[$index]->getNombreDiffusion()
            );
        }

        // Une diffusion historique sans groupe reste présente.
        self::assertNull($results[3]->getAssignmentGroupKey());
    }

    public function testFindPublishedHistoryForEmissionIdsSeparatesEmissionsAndLimitsHistory(): void
    {
        $categorie = $this->createCategorie();

        $emissionA = $this->createEmission(
            $categorie,
            'Emission historique A',
            new \DateTime('2026-10-01')
        );

        $emissionB = $this->createEmission(
            $categorie,
            'Emission historique B',
            new \DateTime('2026-10-01')
        );

        $emissionC = $this->createEmission(
            $categorie,
            'Emission sans diffusion',
            new \DateTime('2026-10-01')
        );

        $diffusionsA = [];

        for ($day = 1; $day <= 8; $day++) {
            $diffusion = $this->createDiffusion(
                $emissionA,
                new \DateTime(sprintf('2026-10-%02d 14:00:00', $day)),
                $day
            );

            $diffusion
                ->setAssignmentGroupKey(
                    $day % 2 === 0 ? 'manual_100' : 'manual_200'
                )
                ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

            $diffusionsA[] = $diffusion;
        }

        $diffusionB = $this->createDiffusion(
            $emissionB,
            new \DateTime('2026-10-05 10:00:00'),
            1
        );

        $diffusionB->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        // Une diffusion non publiée ne doit pas être comptée.
        $this->createDiffusion(
            $emissionB,
            new \DateTime('2026-10-06 10:00:00'),
            2
        )->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        $this->flush();

        $result = $this->repository->findPublishedHistoryForEmissionIds(
            [
                $emissionA->getId(),
                $emissionB->getId(),
                $emissionC->getId(),
            ],
            6
        );

        self::assertSame(8, $result[$emissionA->getId()]['total']);
        self::assertCount(6, $result[$emissionA->getId()]['items']);

        $expectedA = array_reverse(array_slice($diffusionsA, 2));

        foreach ($expectedA as $index => $diffusion) {
            self::assertSame(
                $diffusion->getId(),
                $result[$emissionA->getId()]['items'][$index]->getId()
            );
        }

        self::assertSame(1, $result[$emissionB->getId()]['total']);
        self::assertCount(1, $result[$emissionB->getId()]['items']);
        self::assertSame(
            $diffusionB->getId(),
            $result[$emissionB->getId()]['items'][0]->getId()
        );

        self::assertSame(
            ['total' => 0, 'items' => []],
            $result[$emissionC->getId()]
        );
    }

    public function testFindAllByEmissionReturnsOnlyPublishedRowsOrderedDescending(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission diffusions publiées',
            new \DateTime('2026-09-01')
        );

        $otherEmission = $this->createEmission(
            $categorie,
            'Autre émission diffusions publiées',
            new \DateTime('2026-09-01')
        );

        $oldest = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-01 08:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $unpublished = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-02 08:00:00'),
            2
        )->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        $latest = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-03 08:00:00'),
            3
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->createDiffusion(
            $otherEmission,
            new \DateTime('2026-09-04 08:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->flush();

        /** @var Diffusion[] $results */
        $results = $this->repository->findAllByEmission($emission);

        $this->assertCount(2, $results);

        $this->assertSame($latest->getId(), $results[0]->getId());
        $this->assertSame($oldest->getId(), $results[1]->getId());

        $this->assertNotSame($unpublished->getId(), $results[0]->getId());
        $this->assertNotSame($unpublished->getId(), $results[1]->getId());
    }

    public function testCountPublishedBeforeForEmissionIdsCountsOnlyPublishedDiffusionsBeforeBoundary(): void
    {
        $categorie = $this->createCategorie();

        $emissionA = $this->createEmission(
            $categorie,
            'Emission A parc',
            new \DateTime('2026-09-01')
        );

        $emissionB = $this->createEmission(
            $categorie,
            'Emission B parc',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-01 08:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-08 08:00:00'),
            2
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-10 08:00:00'),
            3
        )->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        // Exactement sur la borne : doit être exclue.
        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-15 00:00:00'),
            4
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->createDiffusion(
            $emissionB,
            new \DateTime('2026-09-12 12:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->flush();

        $results = $this->repository->countPublishedBeforeForEmissionIds(
            [$emissionA->getId(), $emissionB->getId()],
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertSame(2, $results[$emissionA->getId()]);
        $this->assertSame(1, $results[$emissionB->getId()]);
    }

    public function testCountPublishedBeforeForEmissionIdsOmitsEmissionWithoutPublishedDiffusion(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission sans diffusion publiée',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-10 08:00:00'),
            1
        )->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        $this->flush();

        $results = $this->repository->countPublishedBeforeForEmissionIds(
            [$emission->getId()],
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertSame([], $results);
    }

    public function testCountPublishedBeforeForEmissionIdsReturnsEmptyArrayForEmptyOrInvalidInput(): void
    {
        $before = new \DateTimeImmutable('2026-09-15 00:00:00');

        $this->assertSame(
            [],
            $this->repository->countPublishedBeforeForEmissionIds([], $before)
        );

        $this->assertSame(
            [],
            $this->repository->countPublishedBeforeForEmissionIds(
                [0, -1, '0'],
                $before
            )
        );
    }

    public function testCountPublishedBeforeByEmissionAndGroupSeparatesEmissionsAndGroups(): void
    {
        $categorie = $this->createCategorie();

        $emissionA = $this->createEmission(
            $categorie,
            'Emission A comptage groupes',
            new \DateTime('2026-09-01')
        );

        $emissionB = $this->createEmission(
            $categorie,
            'Emission B comptage groupes',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-08 08:00:00')
        )->setAssignmentGroupKey('manual_shared');

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-09 08:00:00')
        )->setAssignmentGroupKey('manual_shared');

        $this->createDiffusion(
            $emissionA,
            new \DateTime('2026-09-10 08:00:00')
        )->setAssignmentGroupKey('manual_other');

        $this->createDiffusion(
            $emissionB,
            new \DateTime('2026-09-11 08:00:00')
        )->setAssignmentGroupKey('manual_shared');

        $this->flush();

        $result = $this->repository->countPublishedBeforeByEmissionAndGroup(
            ['manual_shared', 'manual_other'],
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $expected = [
            $emissionA->getId() => [
                'manual_shared' => 2,
                'manual_other' => 1,
            ],
            $emissionB->getId() => [
                'manual_shared' => 1,
            ],
        ];

        // Normaliser l'ordre des émissions et des groupes.
        ksort($expected);
        ksort($result);

        foreach ($expected as &$groups) {
            ksort($groups);
        }
        unset($groups);

        foreach ($result as &$groups) {
            ksort($groups);
        }
        unset($groups);

        $this->assertSame($expected, $result);
    }

    public function testCountPublishedBeforeByEmissionAndGroupFiltersStatusAndDate(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission filtrage parc',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-10 08:00:00')
        )
            ->setAssignmentGroupKey('manual_eligible')
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-11 08:00:00')
        )
            ->setAssignmentGroupKey('manual_eligible')
            ->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        // Exactement sur la borne : exclue.
        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-15 00:00:00')
        )
            ->setAssignmentGroupKey('manual_eligible')
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        // Après la borne : exclue.
        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-16 08:00:00')
        )
            ->setAssignmentGroupKey('manual_eligible')
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        // Autre groupe non demandé : exclu.
        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-12 08:00:00')
        )
            ->setAssignmentGroupKey('manual_other')
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->flush();

        $result = $this->repository->countPublishedBeforeByEmissionAndGroup(
            ['manual_eligible'],
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertSame([
            $emission->getId() => [
                'manual_eligible' => 1,
            ],
        ], $result);
    }

    public function testCountPublishedBeforeByEmissionAndGroupHandlesEmptyAndDuplicateKeys(): void
    {
        $before = new \DateTimeImmutable('2026-09-15 00:00:00');

        $this->assertSame(
            [],
            $this->repository->countPublishedBeforeByEmissionAndGroup(
                [],
                $before
            )
        );

        $this->assertSame(
            [],
            $this->repository->countPublishedBeforeByEmissionAndGroup(
                ['', ' ', '   '],
                $before
            )
        );

        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission normalisation groupes',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-10 08:00:00')
        )->setAssignmentGroupKey('manual_normalized');

        $this->flush();

        $result = $this->repository->countPublishedBeforeByEmissionAndGroup(
            [
                'manual_normalized',
                ' manual_normalized ',
                'manual_normalized',
                '',
                ' ',
            ],
            $before
        );

        $this->assertSame([
            $emission->getId() => [
                'manual_normalized' => 1,
            ],
        ], $result);
    }

    private function createEmission(
        Categories $categorie,
        string $title,
        \DateTime $datepub,
        int $duration = 60,
        ?string $url = null
    ): Emission {
        $emission = (new Emission())
            ->setTitre($title)
            ->setKeyword('test')
            ->setDatepub($datepub)
            ->setRef('REF-' . $this->unique())
            ->setDuree($duration)
            ->setUrl($url ?? 'https://emission-' . $this->unique() . '.test')
            ->setDescriptif('Description de test')
            ->setCategorie($categorie)
            ->setUpdatedAt(new \DateTime())
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->em->persist($emission);

        return $emission;
    }

    private function createDiffusion(
        Emission $emission,
        \DateTime $startsAt,
        int $number = 1,
        ?int $durationMinutes = null
    ): Diffusion {
        $diffusion = new Diffusion();

        $diffusion
            ->setEmission($emission)
            ->setHoraireDiffusion($startsAt)
            ->setNombreDiffusion($number);

        if (null !== $durationMinutes) {
            $diffusion->setDurationMinutes($durationMinutes);
        }

        $this->em->persist($diffusion);

        return $diffusion;
    }

    private function createCategorie(): Categories
    {
        $categorie = (new Categories())
            ->setTitre('Catégorie test ' . $this->unique())
            ->setDuree(60)
            ->setActive(true)
            ->setSoftDelete(false)
            ->setDescriptif('Description catégorie test')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($this->createEditeur());

        $this->em->persist($categorie);

        return $categorie;
    }

    private function createEditeur(): Editeur
    {
        $editeur = new Editeur();

        $editeur
            ->setName('Éditeur test ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    private function flush(): void
    {
        $this->em->flush();
    }

    private function unique(): string
    {
        return bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->em
            ->createQuery('DELETE FROM App\Entity\Diffusion d')
            ->execute();

        $this->em
            ->createQuery('DELETE FROM App\Entity\Emission e')
            ->execute();

        $this->em->close();

        parent::tearDown();
    }
}
