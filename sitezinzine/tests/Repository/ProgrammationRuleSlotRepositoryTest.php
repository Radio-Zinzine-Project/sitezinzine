<?php

namespace App\Tests\Repository;

use App\Entity\Categories;
use App\Entity\Editeur;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\ProgrammationRuleSlotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProgrammationRuleSlotRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProgrammationRuleSlotRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(ProgrammationRuleSlotRepository::class);

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

    public function testSavePersistsEntityWithoutFlush(): void
    {
        $rule = $this->createRule();

        $slot = $this->buildSlot(
            $rule,
            2,
            '10:00',
            1
        );

        $this->repository->save($slot, false);

        self::assertTrue($this->em->contains($slot));
        self::assertNull($slot->getId());

        $this->em->flush();

        self::assertNotNull($slot->getId());
    }

    public function testSaveCanFlushImmediately(): void
    {
        $rule = $this->createRule();

        $slot = $this->buildSlot(
            $rule,
            2,
            '10:00',
            1
        );

        $this->repository->save($slot, true);

        self::assertNotNull($slot->getId());
    }

    public function testRemoveSchedulesEntityForRemovalWithoutFlush(): void
    {
        $slot = $this->createSlot(
            $this->createRule(),
            2,
            '10:00',
            1
        );

        $this->em->flush();

        $id = $slot->getId();

        $this->repository->remove($slot, false);

        self::assertNotNull(
            $this->em->find(ProgrammationRuleSlot::class, $id)
        );

        $this->em->flush();
        $this->em->clear();

        self::assertNull(
            $this->em->find(ProgrammationRuleSlot::class, $id)
        );
    }

    public function testRemoveCanFlushImmediately(): void
    {
        $slot = $this->createSlot(
            $this->createRule(),
            2,
            '10:00',
            1
        );

        $this->em->flush();

        $id = $slot->getId();

        $this->repository->remove($slot, true);
        $this->em->clear();

        self::assertNull(
            $this->em->find(ProgrammationRuleSlot::class, $id)
        );
    }

    public function testCreateNotDeletedQueryBuilderExcludesDeletedSlots(): void
    {
        $rule = $this->createRule();

        $visible = $this->createSlot($rule, 2, '10:00', 1);

        $deleted = $this->createSlot($rule, 3, '10:00', 1);
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        $results = $this->repository
            ->createNotDeletedQueryBuilder('testSlot')
            ->getQuery()
            ->getResult();

        $ids = $this->extractIds($results);

        self::assertContains($visible->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testCreateActiveQueryBuilderReturnsOnlyActiveNotDeletedSlots(): void
    {
        $rule = $this->createRule();

        $active = $this->createSlot(
            $rule,
            2,
            '10:00',
            1,
            0,
            true
        );

        $inactive = $this->createSlot(
            $rule,
            3,
            '10:00',
            1,
            0,
            false
        );

        $deleted = $this->createSlot(
            $rule,
            4,
            '10:00',
            1,
            0,
            true
        );
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        $results = $this->repository
            ->createActiveQueryBuilder('activeSlot')
            ->getQuery()
            ->getResult();

        $ids = $this->extractIds($results);

        self::assertContains($active->getId(), $ids);
        self::assertNotContains($inactive->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testFindNotDeletedByRuleFiltersRuleAndDeletedSlots(): void
    {
        $rule = $this->createRule();
        $otherRule = $this->createRule();

        $expected = $this->createSlot(
            $rule,
            2,
            '10:00',
            1
        );

        $deleted = $this->createSlot(
            $rule,
            3,
            '10:00',
            1
        );
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $other = $this->createSlot(
            $otherRule,
            2,
            '10:00',
            1
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findNotDeletedByRule($rule)
        );

        self::assertContains($expected->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testFindNotDeletedByRuleOrdersByRankDayAndStartTime(): void
    {
        $rule = $this->createRule();

        $rank2 = $this->createSlot(
            $rule,
            2,
            '08:00',
            2
        );

        $day3 = $this->createSlot(
            $rule,
            3,
            '08:00',
            1
        );

        $late = $this->createSlot(
            $rule,
            2,
            '12:00',
            1
        );

        $early = $this->createSlot(
            $rule,
            2,
            '08:00',
            1
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findNotDeletedByRule($rule)
        );

        self::assertSame(
            [
                $early->getId(),
                $late->getId(),
                $day3->getId(),
                $rank2->getId(),
            ],
            $ids
        );
    }

    public function testFindActiveByRuleFiltersInactiveDeletedAndOtherRuleSlots(): void
    {
        $rule = $this->createRule();
        $otherRule = $this->createRule();

        $active = $this->createSlot(
            $rule,
            2,
            '10:00',
            1
        );

        $inactive = $this->createSlot(
            $rule,
            3,
            '10:00',
            1,
            0,
            false
        );

        $deleted = $this->createSlot(
            $rule,
            4,
            '10:00',
            1
        );
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $other = $this->createSlot(
            $otherRule,
            2,
            '10:00',
            1
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findActiveByRule($rule)
        );

        self::assertContains($active->getId(), $ids);
        self::assertNotContains($inactive->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testFindActiveByRuleOrdersByRankWeekOffsetDayAndStartTime(): void
    {
        $rule = $this->createRule();

        $rank2 = $this->createSlot(
            $rule,
            2,
            '08:00',
            2,
            0
        );

        $offset1 = $this->createSlot(
            $rule,
            2,
            '08:00',
            1,
            1
        );

        $day3 = $this->createSlot(
            $rule,
            3,
            '08:00',
            1,
            0
        );

        $late = $this->createSlot(
            $rule,
            2,
            '12:00',
            1,
            0
        );

        $early = $this->createSlot(
            $rule,
            2,
            '08:00',
            1,
            0
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findActiveByRule($rule)
        );

        self::assertSame(
            [
                $early->getId(),
                $late->getId(),
                $day3->getId(),
                $offset1->getId(),
                $rank2->getId(),
            ],
            $ids
        );
    }

    public function testFindActiveByDayReturnsOnlySlotsForRequestedDay(): void
    {
        $rule = $this->createRule();

        $expected = $this->createSlot(
            $rule,
            3,
            '10:00',
            1
        );

        $otherDay = $this->createSlot(
            $rule,
            4,
            '10:00',
            1
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findActiveByDay(3)
        );

        self::assertContains($expected->getId(), $ids);
        self::assertNotContains($otherDay->getId(), $ids);
    }

    public function testFindActiveByDayExcludesInactiveAndDeletedSlots(): void
    {
        $rule = $this->createRule();

        $active = $this->createSlot(
            $rule,
            5,
            '10:00',
            1
        );

        $inactive = $this->createSlot(
            $rule,
            5,
            '11:00',
            1,
            0,
            false
        );

        $deleted = $this->createSlot(
            $rule,
            5,
            '12:00',
            1
        );
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findActiveByDay(5)
        );

        self::assertContains($active->getId(), $ids);
        self::assertNotContains($inactive->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testFindActiveByDayExcludesSlotsWhoseRuleIsInactiveOrDeleted(): void
    {
        $activeRule = $this->createRule(true);

        $inactiveRule = $this->createRule(false);

        $deletedRule = $this->createRule(true);
        $deletedRule->setDeletedAt(new \DateTimeImmutable());

        $expected = $this->createSlot(
            $activeRule,
            6,
            '10:00',
            1
        );

        $fromInactiveRule = $this->createSlot(
            $inactiveRule,
            6,
            '11:00',
            1
        );

        $fromDeletedRule = $this->createSlot(
            $deletedRule,
            6,
            '12:00',
            1
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findActiveByDay(6)
        );

        self::assertContains($expected->getId(), $ids);
        self::assertNotContains($fromInactiveRule->getId(), $ids);
        self::assertNotContains($fromDeletedRule->getId(), $ids);
    }

    public function testFindActiveByDayOrdersByStartTimeThenBroadcastRank(): void
    {
        $rule = $this->createRule();

        $late = $this->createSlot(
            $rule,
            7,
            '12:00',
            1
        );

        $sameTimeRank2 = $this->createSlot(
            $rule,
            7,
            '08:00',
            2
        );

        $sameTimeRank1 = $this->createSlot(
            $rule,
            7,
            '08:00',
            1
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findActiveByDay(7)
        );

        $positionRank1 = array_search(
            $sameTimeRank1->getId(),
            $ids,
            true
        );

        $positionRank2 = array_search(
            $sameTimeRank2->getId(),
            $ids,
            true
        );

        $positionLate = array_search(
            $late->getId(),
            $ids,
            true
        );

        self::assertIsInt($positionRank1);
        self::assertIsInt($positionRank2);
        self::assertIsInt($positionLate);

        self::assertLessThan($positionRank2, $positionRank1);
        self::assertLessThan($positionLate, $positionRank2);
    }

    private function buildSlot(
        ProgrammationRule $rule,
        int $dayOfWeek,
        string $startTime,
        int $broadcastRank = 1,
        int $weekOffset = 0,
        bool $active = true
    ): ProgrammationRuleSlot {
        return (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable($startTime))
            ->setDurationMinutes(60)
            ->setBroadcastRank($broadcastRank)
            ->setWeekOffset($weekOffset)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive($active);
    }

    private function createSlot(
        ProgrammationRule $rule,
        int $dayOfWeek,
        string $startTime,
        int $broadcastRank = 1,
        int $weekOffset = 0,
        bool $active = true
    ): ProgrammationRuleSlot {
        $slot = $this->buildSlot(
            $rule,
            $dayOfWeek,
            $startTime,
            $broadcastRank,
            $weekOffset,
            $active
        );

        $this->em->persist($slot);

        return $slot;
    }

    private function createRule(
        bool $active = true
    ): ProgrammationRule {
        $rule = (new ProgrammationRule())
            ->setCategory($this->createCategorie())
            ->setRuleNumber(1)
            ->setIsActive($active);

        $this->em->persist($rule);

        return $rule;
    }

    private function createCategorie(): Categories
    {
        $categorie = (new Categories())
            ->setTitre('Catégorie slot ' . $this->unique())
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
            ->setName('Éditeur slot ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    /**
     * @param ProgrammationRuleSlot[] $slots
     *
     * @return int[]
     */
    private function extractIds(array $slots): array
    {
        return array_map(
            static fn(ProgrammationRuleSlot $slot): ?int => $slot->getId(),
            $slots
        );
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}