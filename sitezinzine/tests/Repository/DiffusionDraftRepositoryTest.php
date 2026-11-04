<?php

namespace App\Tests\Repository;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DiffusionDraftRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DiffusionDraftRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(DiffusionDraftRepository::class);

        /*
         * On neutralise les DiffusionDraft déjà présents dans symfony_test
         * uniquement pour la durée du test. Le rollback du tearDown restaure
         * l'état initial de la base et supprime également toutes les fixtures
         * créées par le test courant.
         */
        $this->em->getConnection()->beginTransaction();

        $this->em
            ->createQuery('DELETE FROM App\Entity\DiffusionDraft d')
            ->execute();
    }

    public function testFindOneBySlotAndHoraireReturnsExactDraft(): void
    {
        $context = $this->createProgrammingContext();

        $expected = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context['slot']
        );

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 11:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context['slot']
        );

        $otherContext = $this->createProgrammingContext();

        $this->createDraft(
            $otherContext['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $otherContext['slot']
        );

        $this->em->flush();

        $result = $this->repository->findOneBySlotAndHoraire(
            $context['slot'],
            new \DateTimeImmutable('2026-09-08 10:00:00')
        );

        $this->assertInstanceOf(DiffusionDraft::class, $result);
        $this->assertSame($expected->getId(), $result->getId());
    }

    public function testFindOneBySlotAndHoraireReturnsNullWhenNoDraftMatches(): void
    {
        $context = $this->createProgrammingContext();

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context['slot']
        );

        $this->em->flush();

        $result = $this->repository->findOneBySlotAndHoraire(
            $context['slot'],
            new \DateTimeImmutable('2026-09-08 12:00:00')
        );

        $this->assertNull($result);
    }

    public function testFindByWeekUsesStartInclusiveAndEndExclusiveAndOrdersAscending(): void
    {
        $context = $this->createProgrammingContext();

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-07 23:45:00'),
            15,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $first = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            30,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $second = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-10 14:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-15 00:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->em->flush();

        /** @var DiffusionDraft[] $results */
        $results = $this->repository->findByWeek(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertCount(2, $results);
        $this->assertSame($first->getId(), $results[0]->getId());
        $this->assertSame($second->getId(), $results[1]->getId());
    }

    public function testFindOverlappingDraftsUsesStrictOverlapBoundariesAndOrdersAscending(): void
    {
        $context = $this->createProgrammingContext();

        $overlapFirst = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 09:30:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $overlapSecond = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:15:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        // Se termine exactement au début de la fenêtre : pas de chevauchement.
        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 09:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        // Commence exactement à la fin de la fenêtre : pas de chevauchement.
        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 11:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->em->flush();

        /** @var DiffusionDraft[] $results */
        $results = $this->repository->findOverlappingDrafts(
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            new \DateTimeImmutable('2026-09-08 11:00:00')
        );

        $this->assertCount(2, $results);
        $this->assertSame($overlapFirst->getId(), $results[0]->getId());
        $this->assertSame($overlapSecond->getId(), $results[1]->getId());
    }

    public function testFindOverlappingDraftsCanExcludeOneDraft(): void
    {
        $context = $this->createProgrammingContext();

        $excluded = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $kept = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:30:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->em->flush();

        /** @var DiffusionDraft[] $results */
        $results = $this->repository->findOverlappingDrafts(
            new \DateTimeImmutable('2026-09-08 10:15:00'),
            new \DateTimeImmutable('2026-09-08 11:00:00'),
            $excluded->getId()
        );

        $this->assertCount(1, $results);
        $this->assertSame($kept->getId(), $results[0]->getId());
    }

    public function testFindByAssignmentGroupKeyFiltersAndOrdersChronologically(): void
    {
        $context = $this->createProgrammingContext();

        $second = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-10 12:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setAssignmentGroupKey('group-test');

        $first = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-09 12:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setAssignmentGroupKey('group-test');

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 12:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setAssignmentGroupKey('other-group');

        $this->em->flush();

        /** @var DiffusionDraft[] $results */
        $results = $this->repository->findByAssignmentGroupKey('group-test');

        $this->assertCount(2, $results);
        $this->assertSame($first->getId(), $results[0]->getId());
        $this->assertSame($second->getId(), $results[1]->getId());
    }

    public function testFindOneRegularBySlotAndHoraireReturnsRegularDraft(): void
    {
        $context = $this->createProgrammingContext();

        $expected = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context['slot']
        );

        $this->em->flush();

        $result = $this->repository->findOneRegularBySlotAndHoraire(
            $context['slot'],
            new \DateTimeImmutable('2026-09-08 10:00:00')
        );

        $this->assertInstanceOf(DiffusionDraft::class, $result);
        $this->assertSame($expected->getId(), $result->getId());
    }

    public function testFindOneRegularBySlotAndHoraireIgnoresManualDraft(): void
    {
        $context = $this->createProgrammingContext();

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $context['slot']
        );

        $this->em->flush();

        $result = $this->repository->findOneRegularBySlotAndHoraire(
            $context['slot'],
            new \DateTimeImmutable('2026-09-08 10:00:00')
        );

        $this->assertNull($result);
    }

    public function testFindRegularDraftsBySlotAndStartsAtPairsReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame(
            [],
            $this->repository->findRegularDraftsBySlotAndStartsAtPairs([])
        );
    }

    public function testFindRegularDraftsBySlotAndStartsAtPairsReturnsOnlyExactPairs(): void
    {
        $contextA = $this->createProgrammingContext();
        $contextB = $this->createProgrammingContext();

        $draftA = $this->createDraft(
            $contextA['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $contextA['slot']
        );

        $draftB = $this->createDraft(
            $contextB['emission'],
            new \DateTimeImmutable('2026-09-09 11:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $contextB['slot']
        );

        $this->createDraft(
            $contextA['emission'],
            new \DateTimeImmutable('2026-09-08 12:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $contextA['slot']
        );

        $this->createDraft(
            $contextA['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->em->flush();

        /** @var DiffusionDraft[] $results */
        $results = $this->repository->findRegularDraftsBySlotAndStartsAtPairs([
            [
                'slotId' => $contextA['slot']->getId(),
                'startsAt' => new \DateTimeImmutable('2026-09-08 10:00:00'),
            ],
            [
                'slotId' => $contextB['slot']->getId(),
                'startsAt' => new \DateTimeImmutable('2026-09-09 11:00:00'),
            ],
        ]);

        $ids = array_map(
            static fn(DiffusionDraft $draft): int => (int) $draft->getId(),
            $results
        );

        sort($ids);

        $expectedIds = [
            (int) $draftA->getId(),
            (int) $draftB->getId(),
        ];
        sort($expectedIds);

        $this->assertSame($expectedIds, $ids);
    }

    /**
     * Ce test formalise le contrat annoncé par le nom de la méthode.
     *
     * S'il échoue, le repository retourne actuellement aussi les drafts manuels
     * qui possèdent un slot : il manque alors un filtre sur d.draftType.
     */
    public function testFindRegularDraftsBySlotAndStartsAtPairsIgnoresManualDraftWithSlot(): void
    {
        $context = $this->createProgrammingContext();

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $context['slot']
        );

        $this->em->flush();

        $results = $this->repository->findRegularDraftsBySlotAndStartsAtPairs([
            [
                'slotId' => $context['slot']->getId(),
                'startsAt' => new \DateTimeImmutable('2026-09-08 10:00:00'),
            ],
        ]);

        $this->assertSame([], $results);
    }

    public function testFindPublishableByWeekReturnsOnlyActiveDraftsAndKeepsPreviouslyPublishedLink(): void
    {
        $context = $this->createProgrammingContext();

        $publishedDiffusion = $this->createDiffusion(
            $context['emission'],
            new \DateTime('2026-08-20 10:00:00'),
            1
        );

        $first = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $second = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-09 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        )->setPublishedDiffusion($publishedDiffusion);

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-10 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        )->setPublicationStatus(DiffusionDraft::STATUS_PUBLISHED);

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-11 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        )->softDelete();

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-15 00:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->em->flush();

        /** @var DiffusionDraft[] $results */
        $results = $this->repository->findPublishableByWeek(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertCount(2, $results);
        $this->assertSame($first->getId(), $results[0]->getId());
        $this->assertSame($second->getId(), $results[1]->getId());
        $this->assertSame(
            $publishedDiffusion->getId(),
            $results[1]->getPublishedDiffusion()?->getId()
        );
    }

    public function testFindFutureDraftsByAssignmentGroupKeysFiltersKeysDateStatusAndDeletion(): void
    {
        $context = $this->createProgrammingContext();

        $first = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-10 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setAssignmentGroupKey('group-a');

        $second = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-11 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setAssignmentGroupKey('group-b');

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-09 09:59:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setAssignmentGroupKey('group-a');

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-12 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )
            ->setAssignmentGroupKey('group-a')
            ->setPublicationStatus(DiffusionDraft::STATUS_PUBLISHED);

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-13 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )
            ->setAssignmentGroupKey('group-b')
            ->softDelete();

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-14 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setAssignmentGroupKey('group-c');

        $this->em->flush();

        /** @var DiffusionDraft[] $results */
        $results = $this->repository->findFutureDraftsByAssignmentGroupKeys(
            ['group-a', 'group-b', 'group-a', '', '   '],
            new \DateTimeImmutable('2026-09-10 10:00:00')
        );

        $this->assertCount(2, $results);
        $this->assertSame($first->getId(), $results[0]->getId());
        $this->assertSame($second->getId(), $results[1]->getId());
    }

    public function testFindFutureDraftsByAssignmentGroupKeysReturnsEmptyArrayForInvalidKeys(): void
    {
        $this->assertSame(
            [],
            $this->repository->findFutureDraftsByAssignmentGroupKeys(
                ['', '   ', null, 123],
                new \DateTimeImmutable('2026-09-10 10:00:00')
            )
        );
    }

    public function testFindOneActiveDraftBySlotAndHoraireReturnsActiveDraft(): void
    {
        $context = $this->createProgrammingContext();

        $expected = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context['slot']
        );

        $this->em->flush();

        $result = $this->repository->findOneActiveDraftBySlotAndHoraire(
            $context['slot'],
            new \DateTimeImmutable('2026-09-08 10:00:00')
        );

        $this->assertInstanceOf(DiffusionDraft::class, $result);
        $this->assertSame($expected->getId(), $result->getId());
    }

    public function testFindOneActiveDraftBySlotAndHoraireIgnoresDeletedAndNonDraftStatuses(): void
    {
        $context = $this->createProgrammingContext();

        $deleted = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context['slot']
        )->softDelete();

        $this->em->flush();

        $this->assertNull(
            $this->repository->findOneActiveDraftBySlotAndHoraire(
                $context['slot'],
                $deleted->getHoraireDiffusion()
            )
        );

        $context2 = $this->createProgrammingContext();

        $published = $this->createDraft(
            $context2['emission'],
            new \DateTimeImmutable('2026-09-08 11:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context2['slot']
        )->setPublicationStatus(DiffusionDraft::STATUS_PUBLISHED);

        $this->em->flush();

        $this->assertNull(
            $this->repository->findOneActiveDraftBySlotAndHoraire(
                $context2['slot'],
                $published->getHoraireDiffusion()
            )
        );
    }

    public function testFindByPublishedDiffusionIdsFiltersIdsAndOrdersChronologically(): void
    {
        $context = $this->createProgrammingContext();

        $diffusionA = $this->createDiffusion(
            $context['emission'],
            new \DateTime('2026-08-20 10:00:00'),
            1
        );

        $diffusionB = $this->createDiffusion(
            $context['emission'],
            new \DateTime('2026-08-21 10:00:00'),
            2
        );

        $this->em->flush();

        $second = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-11 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setPublishedDiffusion($diffusionB);

        $first = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-10 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setPublishedDiffusion($diffusionA);

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-09 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        );

        $this->em->flush();

        /** @var DiffusionDraft[] $results */
        $results = $this->repository->findByPublishedDiffusionIds([
            $diffusionA->getId(),
            $diffusionB->getId(),
            $diffusionA->getId(),
            0,
            -1,
            (string) $diffusionB->getId(),
        ]);

        $this->assertCount(2, $results);
        $this->assertSame($first->getId(), $results[0]->getId());
        $this->assertSame($second->getId(), $results[1]->getId());
    }

    public function testFindByPublishedDiffusionIdsReturnsEmptyArrayForEmptyOrInvalidInput(): void
    {
        $this->assertSame(
            [],
            $this->repository->findByPublishedDiffusionIds([])
        );

        $this->assertSame(
            [],
            $this->repository->findByPublishedDiffusionIds([0, -1, '0'])
        );
    }

    public function testFindByWeekExcludesSoftDeletedDrafts(): void
    {
        $context = $this->createProgrammingContext();

        $active = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 12:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        )->softDelete();

        $this->em->flush();

        $results = $this->repository->findByWeek(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        $this->assertCount(1, $results);
        $this->assertSame($active->getId(), $results[0]->getId());
    }

    public function testFindOverlappingDraftsIgnoresSoftDeletedDrafts(): void
    {
        $context = $this->createProgrammingContext();

        $active = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:30:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        )->softDelete();

        $this->em->flush();

        $results = $this->repository->findOverlappingDrafts(
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            new \DateTimeImmutable('2026-09-08 11:00:00')
        );

        $this->assertCount(1, $results);
        $this->assertSame($active->getId(), $results[0]->getId());
    }


    public function testFindOneRegularBySlotAndHoraireIgnoresSoftDeletedDraft(): void
    {
        $context = $this->createProgrammingContext();

        $deleted = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context['slot']
        )->softDelete();

        $this->em->flush();

        $result = $this->repository->findOneRegularBySlotAndHoraire(
            $context['slot'],
            $deleted->getHoraireDiffusion()
        );

        $this->assertNull($result);
    }

    public function testFindRegularDraftsBySlotAndStartsAtPairsIgnoresSoftDeletedDraft(): void
    {
        $context = $this->createProgrammingContext();

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_REGULAR,
            $context['slot']
        )->softDelete();

        $this->em->flush();

        $results = $this->repository->findRegularDraftsBySlotAndStartsAtPairs([
            [
                'slotId' => $context['slot']->getId(),
                'startsAt' => new \DateTimeImmutable('2026-09-08 10:00:00'),
            ],
        ]);

        $this->assertSame([], $results);
    }

    public function testFindByAssignmentGroupKeyIgnoresSoftDeletedDraft(): void
    {
        $context = $this->createProgrammingContext();

        $active = $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-08 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )->setAssignmentGroupKey('group-soft-delete');

        $this->createDraft(
            $context['emission'],
            new \DateTimeImmutable('2026-09-09 10:00:00'),
            60,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        )
            ->setAssignmentGroupKey('group-soft-delete')
            ->softDelete();

        $this->em->flush();

        $results = $this->repository->findByAssignmentGroupKey(
            'group-soft-delete'
        );

        $this->assertCount(1, $results);
        $this->assertSame($active->getId(), $results[0]->getId());
    }

public function testFindOneRegularIncludingDeletedBySlotAndHoraireReturnsSoftDeletedDraft(): void
{
    $context = $this->createProgrammingContext();

    $deleted = $this->createDraft(
        $context['emission'],
        new \DateTimeImmutable('2026-09-08 10:00:00'),
        60,
        DiffusionDraft::TYPE_REGULAR,
        $context['slot']
    )->softDelete();

    $this->em->flush();

    $result = $this->repository
        ->findOneRegularIncludingDeletedBySlotAndHoraire(
            $context['slot'],
            new \DateTimeImmutable('2026-09-08 10:00:00')
        );

    $this->assertSame($deleted->getId(), $result?->getId());
    $this->assertTrue($result->isDeleted());
}

public function testFindOneRegularIncludingDeletedBySlotAndHoraireReturnsActiveDraft(): void
{
    $context = $this->createProgrammingContext();

    $active = $this->createDraft(
        $context['emission'],
        new \DateTimeImmutable('2026-09-08 10:00:00'),
        60,
        DiffusionDraft::TYPE_REGULAR,
        $context['slot']
    );

    $this->em->flush();

    $result = $this->repository
        ->findOneRegularIncludingDeletedBySlotAndHoraire(
            $context['slot'],
            new \DateTimeImmutable('2026-09-08 10:00:00')
        );

    $this->assertSame($active->getId(), $result?->getId());
    $this->assertFalse($result->isDeleted());
}

    /**
     * @return array{
     *     category: Categories,
     *     emission: Emission,
     *     rule: ProgrammationRule,
     *     slot: ProgrammationRuleSlot
     * }
     */
    private function createProgrammingContext(): array
    {
        $category = $this->createCategorie();

        $emission = $this->createEmission(
            $category,
            'Emission test ' . $this->unique(),
            new \DateTime('2026-09-01')
        );

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1)
            ->setValidFrom(new \DateTimeImmutable('2026-01-01'))
            ->setIsActive(true);

        $slot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('10:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $this->em->persist($rule);
        $this->em->persist($slot);

        return [
            'category' => $category,
            'emission' => $emission,
            'rule' => $rule,
            'slot' => $slot,
        ];
    }

    private function createDraft(
        Emission $emission,
        \DateTimeImmutable $startsAt,
        int $durationMinutes = 60,
        string $draftType = DiffusionDraft::TYPE_REGULAR,
        ?ProgrammationRuleSlot $slot = null,
        int $nombreDiffusion = 1
    ): DiffusionDraft {
        $draft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setSlot($slot)
            ->setSchedule($startsAt, $durationMinutes)
            ->setNombreDiffusion($nombreDiffusion)
            ->setDraftType($draftType)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT);

        $this->em->persist($draft);

        return $draft;
    }

    private function createDiffusion(
        Emission $emission,
        \DateTime $startsAt,
        int $nombreDiffusion = 1
    ): Diffusion {
        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setHoraireDiffusion($startsAt)
            ->setNombreDiffusion($nombreDiffusion)
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->em->persist($diffusion);

        return $diffusion;
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
        $editeur = (new Editeur())
            ->setName('Éditeur test ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    private function unique(): string
    {
        return bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->em->close();

        parent::tearDown();
    }
}
