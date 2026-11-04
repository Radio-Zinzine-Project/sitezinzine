<?php

namespace App\Tests\Repository;

use App\Entity\Categories;
use App\Entity\Editeur;
use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\GridSlotArbitrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GridSlotArbitrationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GridSlotArbitrationRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(GridSlotArbitrationRepository::class);

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

    public function testFindRelevantForWeekReturnsArbitrationWithOriginalDateInsideWeek(): void
    {
        $slot = $this->createSlot();

        $start = new \DateTimeImmutable('2199-01-06 00:00:00');
        $end = $start->modify('+7 days');

        $inside = $this->createArbitration(
            $slot,
            $start->modify('+2 days 10 hours')
        );

        $outside = $this->createArbitration(
            $slot,
            $start->modify('+10 days')
        );

        $this->em->flush();

        $results = $this->repository->findRelevantForWeek($start, $end);
        $ids = $this->extractIds($results);

        self::assertContains($inside->getId(), $ids);
        self::assertNotContains($outside->getId(), $ids);
    }

    public function testFindRelevantForWeekReturnsRescheduledArbitrationWhenNewDateIsInsideWeek(): void
    {
        $slot = $this->createSlot();

        $start = new \DateTimeImmutable('2199-02-03 00:00:00');
        $end = $start->modify('+7 days');

        $rescheduled = $this->createArbitration(
            $slot,
            $start->modify('-14 days'),
            GridSlotArbitration::STATUS_RESOLVED,
            $start->modify('+3 days 12 hours')
        );

        $outside = $this->createArbitration(
            $slot,
            $start->modify('-20 days'),
            GridSlotArbitration::STATUS_RESOLVED,
            $start->modify('+10 days')
        );

        $this->em->flush();

        $results = $this->repository->findRelevantForWeek($start, $end);
        $ids = $this->extractIds($results);

        self::assertContains($rescheduled->getId(), $ids);
        self::assertNotContains($outside->getId(), $ids);
    }

    public function testFindRelevantForWeekExcludesCancelledArbitrations(): void
    {
        $slot = $this->createSlot();

        $start = new \DateTimeImmutable('2199-03-03 00:00:00');
        $end = $start->modify('+7 days');

        $active = $this->createArbitration(
            $slot,
            $start->modify('+1 day'),
            GridSlotArbitration::STATUS_PENDING
        );

        $cancelled = $this->createArbitration(
            $slot,
            $start->modify('+2 days'),
            GridSlotArbitration::STATUS_CANCELLED
        );

        $this->em->flush();

        $results = $this->repository->findRelevantForWeek($start, $end);
        $ids = $this->extractIds($results);

        self::assertContains($active->getId(), $ids);
        self::assertNotContains($cancelled->getId(), $ids);
    }

    public function testFindRelevantForWeekUsesInclusiveStartAndExclusiveEndAndOrdersByOriginalDate(): void
    {
        $slot = $this->createSlot();

        $start = new \DateTimeImmutable('2199-04-07 00:00:00');
        $end = $start->modify('+7 days');

        $atStart = $this->createArbitration(
            $slot,
            $start
        );

        $later = $this->createArbitration(
            $slot,
            $start->modify('+4 days')
        );

        $atEnd = $this->createArbitration(
            $slot,
            $end
        );

        $this->em->flush();

        $results = $this->repository->findRelevantForWeek($start, $end);
        $ids = $this->extractIds($results);

        self::assertContains($atStart->getId(), $ids);
        self::assertContains($later->getId(), $ids);
        self::assertNotContains($atEnd->getId(), $ids);

        $positionStart = array_search($atStart->getId(), $ids, true);
        $positionLater = array_search($later->getId(), $ids, true);

        self::assertIsInt($positionStart);
        self::assertIsInt($positionLater);

        self::assertLessThan($positionLater, $positionStart);
    }

    public function testFindOneActiveForOccurrenceReturnsMatchingArbitration(): void
    {
        $slot = $this->createSlot();

        $startsAt = new \DateTimeImmutable('2199-05-10 14:00:00');

        $expected = $this->createArbitration(
            $slot,
            $startsAt,
            GridSlotArbitration::STATUS_PENDING
        );

        $this->createArbitration(
            $slot,
            $startsAt->modify('+1 day'),
            GridSlotArbitration::STATUS_PENDING
        );

        $this->em->flush();

        $result = $this->repository->findOneActiveForOccurrence(
            $slot,
            $startsAt
        );

        self::assertNotNull($result);
        self::assertSame($expected->getId(), $result->getId());
    }

    public function testFindOneActiveForOccurrenceDoesNotReturnArbitrationFromAnotherSlot(): void
    {
        $rule = $this->createRule();

        $slot1 = $this->createSlot($rule, 2, '10:00');
        $slot2 = $this->createSlot($rule, 3, '10:00');

        $startsAt = new \DateTimeImmutable('2199-06-12 10:00:00');

        $this->createArbitration(
            $slot2,
            $startsAt,
            GridSlotArbitration::STATUS_PENDING
        );

        $this->em->flush();

        $result = $this->repository->findOneActiveForOccurrence(
            $slot1,
            $startsAt
        );

        self::assertNull($result);
    }

    public function testFindOneActiveForOccurrenceIgnoresCancelledArbitration(): void
    {
        $slot = $this->createSlot();

        $startsAt = new \DateTimeImmutable('2199-07-15 16:00:00');

        $this->createArbitration(
            $slot,
            $startsAt,
            GridSlotArbitration::STATUS_CANCELLED
        );

        $this->em->flush();

        $result = $this->repository->findOneActiveForOccurrence(
            $slot,
            $startsAt
        );

        self::assertNull($result);
    }

    public function testFindOneActiveForOccurrenceReturnsNullWhenOccurrenceDoesNotExist(): void
    {
        $slot = $this->createSlot();

        $this->em->flush();

        $result = $this->repository->findOneActiveForOccurrence(
            $slot,
            new \DateTimeImmutable('2199-08-20 09:00:00')
        );

        self::assertNull($result);
    }

    private function createArbitration(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $originalStartsAt,
        string $status = GridSlotArbitration::STATUS_PENDING,
        ?\DateTimeImmutable $rescheduledStartsAt = null
    ): GridSlotArbitration {
        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt($originalStartsAt)
            ->setOriginalEndsAt($originalStartsAt->modify('+1 hour'))
            ->setStatus($status)
            ->setAction(
                $rescheduledStartsAt !== null
                    ? GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM
                    : GridSlotArbitration::ACTION_KEEP
            );

        if ($rescheduledStartsAt !== null) {
            $arbitration
                ->setRescheduledStartsAt($rescheduledStartsAt)
                ->setRescheduledEndsAt($rescheduledStartsAt->modify('+1 hour'));
        }

        $this->em->persist($arbitration);

        return $arbitration;
    }

    private function createSlot(
        ?ProgrammationRule $rule = null,
        int $dayOfWeek = 2,
        string $startTime = '10:00'
    ): ProgrammationRuleSlot {
        $rule ??= $this->createRule();

        $slot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable($startTime))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $this->em->persist($slot);

        return $slot;
    }

    private function createRule(): ProgrammationRule
    {
        $rule = (new ProgrammationRule())
            ->setCategory($this->createCategorie())
            ->setRuleNumber(1)
            ->setIsActive(true);

        $this->em->persist($rule);

        return $rule;
    }

    private function createCategorie(): Categories
    {
        $categorie = (new Categories())
            ->setTitre('Catégorie arbitrage ' . $this->unique())
            ->setDuree(60)
            ->setDescriptif('Description test')
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
            ->setName('Éditeur arbitrage ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    /**
     * @param GridSlotArbitration[] $arbitrations
     *
     * @return int[]
     */
    private function extractIds(array $arbitrations): array
    {
        return array_map(
            static fn(GridSlotArbitration $arbitration): ?int => $arbitration->getId(),
            $arbitrations
        );
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}