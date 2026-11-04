<?php

namespace App\Tests\Repository;

use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\PendingRebroadcastRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PendingRebroadcastRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PendingRebroadcastRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(PendingRebroadcastRepository::class);

        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }

        $this->entityManager->clear();

        parent::tearDown();
    }

    public function testFindByAssignmentGroupKeyReturnsAllPendingRebroadcastsOfGroup(): void
    {
        $emission = $this->createEmission('Émission parc');

        $first = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('rule_70_origin_20260918_2000');

        $second = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('rule_70_origin_20260918_2000');

        $otherGroup = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('rule_71_origin_20260919_2000');

        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->persist($otherGroup);
        $this->entityManager->flush();

        $results = $this->repository->findByAssignmentGroupKey(
            'rule_70_origin_20260918_2000'
        );

        self::assertCount(2, $results);
        self::assertContains($first, $results);
        self::assertContains($second, $results);
        self::assertNotContains($otherGroup, $results);
    }

    public function testExistsForAssignmentGroupKeyReturnsTrueWhenPendingExists(): void
    {
        $emission = $this->createEmission('Émission existante');

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('rule_70_origin_20260918_2000');

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        self::assertTrue(
            $this->repository->existsForAssignmentGroupKey(
                'rule_70_origin_20260918_2000'
            )
        );
    }

    public function testExistsForAssignmentGroupKeyReturnsFalseWhenPendingDoesNotExist(): void
    {
        self::assertFalse(
            $this->repository->existsForAssignmentGroupKey(
                'rule_999_origin_20260918_2000'
            )
        );
    }

    public function testExistsForEmissionReturnsTrueWhenEmissionIsAlreadyInPool(): void
    {
        $emission = $this->createEmission('Émission déjà dans le parc');

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('rule_70_origin_20260918_2000');

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        self::assertTrue(
            $this->repository->existsForEmission($emission)
        );
    }

    public function testExistsForEmissionReturnsFalseWhenOnlyAnotherEmissionIsInPool(): void
    {
        $emission = $this->createEmission('Émission absente du parc');
        $otherEmission = $this->createEmission('Autre émission');

        $pending = (new PendingRebroadcast())
            ->setEmission($otherEmission)
            ->setAssignmentGroupKey('rule_70_origin_20260918_2000');

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        self::assertFalse(
            $this->repository->existsForEmission($emission)
        );
    }

    public function testFindEmissionIdsPresentInPoolReturnsOnlyRequestedEmissionsPresentInPool(): void
    {
        $firstEmission = $this->createEmission('Première émission');
        $secondEmission = $this->createEmission('Deuxième émission');
        $otherEmission = $this->createEmission('Autre émission');

        $firstPending = (new PendingRebroadcast())
            ->setEmission($firstEmission)
            ->setAssignmentGroupKey('rule_70_origin_20260918_2000');

        $duplicatePending = (new PendingRebroadcast())
            ->setEmission($firstEmission)
            ->setAssignmentGroupKey('rule_70_origin_20260918_2000');

        $otherPending = (new PendingRebroadcast())
            ->setEmission($otherEmission)
            ->setAssignmentGroupKey('rule_71_origin_20260919_2000');

        $this->entityManager->persist($firstPending);
        $this->entityManager->persist($duplicatePending);
        $this->entityManager->persist($otherPending);
        $this->entityManager->flush();

        $results = $this->repository->findEmissionIdsPresentInPool([
            $firstEmission->getId(),
            $secondEmission->getId(),
        ]);

        self::assertSame(
            [$firstEmission->getId()],
            $results
        );
    }

    public function testFindEmissionIdsPresentInPoolReturnsEmptyArrayForEmptyOrInvalidInput(): void
    {
        self::assertSame(
            [],
            $this->repository->findEmissionIdsPresentInPool([])
        );

        self::assertSame(
            [],
            $this->repository->findEmissionIdsPresentInPool([
                0,
                -1,
                '0',
            ])
        );
    }

    public function testExistsForAssignmentGroupKeyIncludesDismissedPending(): void
{
    $emission = $this->createEmission('Émission exclue');

    $pending = (new PendingRebroadcast())
        ->setEmission($emission)
        ->setAssignmentGroupKey('manual_100')
        ->dismiss();

    $this->entityManager->persist($pending);
    $this->entityManager->flush();
    $this->entityManager->clear();

    self::assertTrue(
        $this->repository->existsForAssignmentGroupKey('manual_100')
    );
}

public function testFindAllForPoolExcludesDismissedPending(): void
{
    $emission = $this->createEmission('Émission du parc');

    $active = (new PendingRebroadcast())
        ->setEmission($emission)
        ->setAssignmentGroupKey('manual_101');

    $dismissed = (new PendingRebroadcast())
        ->setEmission($emission)
        ->setAssignmentGroupKey('manual_102')
        ->dismiss();

    $this->entityManager->persist($active);
    $this->entityManager->persist($dismissed);
    $this->entityManager->flush();

    $results = $this->repository->findAllForPool();

    self::assertContains($active, $results);
    self::assertNotContains($dismissed, $results);
}

public function testFindEmissionIdsPresentInPoolExcludesDismissedPending(): void
{
    $activeEmission = $this->createEmission('Émission active');
    $dismissedEmission = $this->createEmission('Émission exclue');

    $active = (new PendingRebroadcast())
        ->setEmission($activeEmission)
        ->setAssignmentGroupKey('manual_103');

    $dismissed = (new PendingRebroadcast())
        ->setEmission($dismissedEmission)
        ->setAssignmentGroupKey('manual_104')
        ->dismiss();

    $this->entityManager->persist($active);
    $this->entityManager->persist($dismissed);
    $this->entityManager->flush();

    $results = $this->repository->findEmissionIdsPresentInPool([
        $activeEmission->getId(),
        $dismissedEmission->getId(),
    ]);

    self::assertSame(
        [$activeEmission->getId()],
        $results
    );
}

public function testRestoredPendingReappearsInPool(): void
{
    $emission = $this->createEmission('Émission restaurée');

    $pending = (new PendingRebroadcast())
        ->setEmission($emission)
        ->setAssignmentGroupKey('manual_105')
        ->dismiss();

    $this->entityManager->persist($pending);
    $this->entityManager->flush();

    self::assertNotContains(
        $pending,
        $this->repository->findAllForPool()
    );

    $pending->restore();
    $this->entityManager->flush();

    self::assertContains(
        $pending,
        $this->repository->findAllForPool()
    );
}

    public function testFindAllForPoolIncludesActiveRegularPendingRebroadcast(): void
    {
        $emission = $this->createEmission('Émission régulière et ponctuelle du parc');
        $regular = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('rule_123_origin_20261006_1000');
        $manual = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('manual_12345');
        $dismissedRegular = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('rule_124_origin_20261006_1000')
            ->dismiss();
        $dismissedManual = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('manual_12346')
            ->dismiss();

        foreach ([$regular, $manual, $dismissedRegular, $dismissedManual] as $pending) {
            $this->entityManager->persist($pending);
        }
        $this->entityManager->flush();

        $visibleIds = array_map(
            static fn(PendingRebroadcast $pending): ?int => $pending->getId(),
            $this->repository->findAllForPool()
        );

        self::assertContains($manual->getId(), $visibleIds);
        self::assertNotContains($dismissedRegular->getId(), $visibleIds);
        self::assertNotContains($dismissedManual->getId(), $visibleIds);
        self::assertContains($regular->getId(), $visibleIds, 'Un Pending actif doit être visible même si sa clé commence par rule_.');
    }

    private function createEmission(string $title): Emission
    {
        $emission = (new Emission())
            ->setTitre($title)
            ->setKeyword('')
            ->setDatepub(new \DateTime())
            ->setDuree(60)
            ->setDescriptif('Émission de test');

        $this->entityManager->persist($emission);

        return $emission;
    }
}
