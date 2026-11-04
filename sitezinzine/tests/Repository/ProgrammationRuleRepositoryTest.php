<?php

namespace App\Tests\Repository;

use App\Entity\Categories;
use App\Entity\Editeur;
use App\Entity\ProgrammationRule;
use App\Repository\ProgrammationRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProgrammationRuleRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProgrammationRuleRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(ProgrammationRuleRepository::class);

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
        $category = $this->createCategorie();

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1);

        $this->repository->save($rule, false);

        self::assertTrue($this->em->contains($rule));
        self::assertNull($rule->getId());

        $this->em->flush();

        self::assertNotNull($rule->getId());
    }

    public function testSaveCanFlushImmediately(): void
    {
        $category = $this->createCategorie();

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1);

        $this->repository->save($rule, true);

        self::assertNotNull($rule->getId());
    }

    public function testRemoveSchedulesEntityForRemovalWithoutFlush(): void
    {
        $rule = $this->createRule(
            $this->createCategorie(),
            1
        );

        $this->em->flush();

        $id = $rule->getId();

        $this->repository->remove($rule, false);

        self::assertNotNull($this->em->find(ProgrammationRule::class, $id));

        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(ProgrammationRule::class, $id));
    }

    public function testRemoveCanFlushImmediately(): void
    {
        $rule = $this->createRule(
            $this->createCategorie(),
            1
        );

        $this->em->flush();

        $id = $rule->getId();

        $this->repository->remove($rule, true);
        $this->em->clear();

        self::assertNull($this->em->find(ProgrammationRule::class, $id));
    }

    public function testCreateNotDeletedQueryBuilderExcludesDeletedRules(): void
    {
        $category = $this->createCategorie();

        $active = $this->createRule($category, 1);

        $deleted = $this->createRule($category, 2);
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        $results = $this->repository
            ->createNotDeletedQueryBuilder('testRule')
            ->getQuery()
            ->getResult();

        $ids = $this->extractIds($results);

        self::assertContains($active->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testCreateActiveQueryBuilderReturnsOnlyActiveNotDeletedRules(): void
    {
        $category = $this->createCategorie();

        $active = $this->createRule($category, 1, true);

        $inactive = $this->createRule($category, 2, false);

        $deleted = $this->createRule($category, 3, true);
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        $results = $this->repository
            ->createActiveQueryBuilder('activeRule')
            ->getQuery()
            ->getResult();

        $ids = $this->extractIds($results);

        self::assertContains($active->getId(), $ids);
        self::assertNotContains($inactive->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testFindAllNotDeletedExcludesDeletedRules(): void
    {
        $category = $this->createCategorie();

        $visible = $this->createRule($category, 1);

        $deleted = $this->createRule($category, 2);
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findAllNotDeleted()
        );

        self::assertContains($visible->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testFindAllNotDeletedOrdersByCategoryThenRuleNumber(): void
    {
        $categoryA = $this->createCategorie('AAA-' . $this->unique());
        $categoryZ = $this->createCategorie('ZZZ-' . $this->unique());

        $aRule2 = $this->createRule($categoryA, 2);
        $aRule1 = $this->createRule($categoryA, 1);
        $zRule1 = $this->createRule($categoryZ, 1);

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findAllNotDeleted()
        );

        $positionA1 = array_search($aRule1->getId(), $ids, true);
        $positionA2 = array_search($aRule2->getId(), $ids, true);
        $positionZ1 = array_search($zRule1->getId(), $ids, true);

        self::assertIsInt($positionA1);
        self::assertIsInt($positionA2);
        self::assertIsInt($positionZ1);

        self::assertLessThan($positionA2, $positionA1);
        self::assertLessThan($positionZ1, $positionA2);
    }

    public function testFindDeletedRulesReturnsOnlyDeletedRules(): void
    {
        $category = $this->createCategorie();

        $visible = $this->createRule($category, 1);

        $deleted = $this->createRule($category, 2);
        $deleted->setDeletedAt(
            new \DateTimeImmutable('2199-01-01 12:00:00')
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findDeletedRules()
        );

        self::assertContains($deleted->getId(), $ids);
        self::assertNotContains($visible->getId(), $ids);
    }

    public function testFindDeletedRulesOrdersByDeletedAtDescendingForSameCategoryAndRuleNumber(): void
    {
        $category = $this->createCategorie();

        /*
         * La contrainte unique interdit deux règles avec le même
         * category + ruleNumber. On teste donc deletedAt DESC avec
         * des ruleNumber différents mais en conservant l'ordre
         * prioritaire ruleNumber ASC.
         *
         * Le tri deletedAt ne peut réellement départager que des lignes
         * ayant déjà les mêmes catégorie et ruleNumber, état interdit
         * par la contrainte unique de la table.
         */
        $older = $this->createRule($category, 1);
        $older->setDeletedAt(
            new \DateTimeImmutable('2199-01-01 10:00:00')
        );

        $newer = $this->createRule($category, 2);
        $newer->setDeletedAt(
            new \DateTimeImmutable('2199-01-02 10:00:00')
        );

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findDeletedRules()
        );

        $positionOlder = array_search($older->getId(), $ids, true);
        $positionNewer = array_search($newer->getId(), $ids, true);

        self::assertIsInt($positionOlder);
        self::assertIsInt($positionNewer);

        // ruleNumber ASC est prioritaire sur deletedAt DESC.
        self::assertLessThan($positionNewer, $positionOlder);
    }

    public function testFindActiveRulesReturnsOnlyActiveNotDeletedRules(): void
    {
        $category = $this->createCategorie();

        $active = $this->createRule($category, 1, true);
        $inactive = $this->createRule($category, 2, false);

        $deleted = $this->createRule($category, 3, true);
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findActiveRules()
        );

        self::assertContains($active->getId(), $ids);
        self::assertNotContains($inactive->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testFindActiveRulesForDateHandlesValidityBounds(): void
    {
        $category = $this->createCategorie();

        $date = new \DateTimeImmutable('2199-06-15');

        $withoutBounds = $this->createRule($category, 1);

        $validOnDate = $this->createRule($category, 2);
        $validOnDate
            ->setValidFrom(new \DateTimeImmutable('2199-06-01'))
            ->setValidUntil(new \DateTimeImmutable('2199-06-30'));

        $startsOnDate = $this->createRule($category, 3);
        $startsOnDate
            ->setValidFrom($date)
            ->setValidUntil(new \DateTimeImmutable('2199-07-01'));

        $endsOnDate = $this->createRule($category, 4);
        $endsOnDate
            ->setValidFrom(new \DateTimeImmutable('2199-05-01'))
            ->setValidUntil($date);

        $startsAfter = $this->createRule($category, 5);
        $startsAfter->setValidFrom(
            new \DateTimeImmutable('2199-06-16')
        );

        $endedBefore = $this->createRule($category, 6);
        $endedBefore->setValidUntil(
            new \DateTimeImmutable('2199-06-14')
        );

        $inactive = $this->createRule($category, 7, false);

        $deleted = $this->createRule($category, 8, true);
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        $ids = $this->extractIds(
            $this->repository->findActiveRulesForDate($date)
        );

        self::assertContains($withoutBounds->getId(), $ids);
        self::assertContains($validOnDate->getId(), $ids);
        self::assertContains($startsOnDate->getId(), $ids);
        self::assertContains($endsOnDate->getId(), $ids);

        self::assertNotContains($startsAfter->getId(), $ids);
        self::assertNotContains($endedBefore->getId(), $ids);
        self::assertNotContains($inactive->getId(), $ids);
        self::assertNotContains($deleted->getId(), $ids);
    }

    public function testFindMaxRuleNumberByCategoryReturnsMaximum(): void
    {
        $category = $this->createCategorie();
        $otherCategory = $this->createCategorie();

        $this->createRule($category, 2);
        $this->createRule($category, 7);
        $this->createRule($category, 4);

        // Ne doit pas influencer le résultat.
        $this->createRule($otherCategory, 99);

        $this->em->flush();

        self::assertSame(
            7,
            $this->repository->findMaxRuleNumberByCategory($category)
        );
    }

    public function testFindMaxRuleNumberByCategoryReturnsZeroWhenCategoryHasNoRules(): void
    {
        $category = $this->createCategorie();

        $this->em->flush();

        self::assertSame(
            0,
            $this->repository->findMaxRuleNumberByCategory($category)
        );
    }

    public function testHasActiveRuleForCategoryReturnsTrueWhenActiveRuleExists(): void
    {
        $category = $this->createCategorie();

        $this->createRule($category, 1, true);

        $this->em->flush();

        self::assertTrue(
            $this->repository->hasActiveRuleForCategory($category)
        );
    }

    public function testHasActiveRuleForCategoryReturnsFalseForInactiveOrDeletedRules(): void
    {
        $inactiveCategory = $this->createCategorie();
        $deletedCategory = $this->createCategorie();

        $this->createRule(
            $inactiveCategory,
            1,
            false
        );

        $deleted = $this->createRule(
            $deletedCategory,
            1,
            true
        );

        $deleted->setDeletedAt(new \DateTimeImmutable());

        $this->em->flush();

        self::assertFalse(
            $this->repository->hasActiveRuleForCategory($inactiveCategory)
        );

        self::assertFalse(
            $this->repository->hasActiveRuleForCategory($deletedCategory)
        );
    }

    public function testHasActiveRuleForCategoryDoesNotUseRuleFromAnotherCategory(): void
    {
        $category = $this->createCategorie();
        $otherCategory = $this->createCategorie();

        $this->createRule($otherCategory, 1, true);

        $this->em->flush();

        self::assertFalse(
            $this->repository->hasActiveRuleForCategory($category)
        );
    }

    private function createRule(
        Categories $category,
        int $ruleNumber,
        bool $active = true
    ): ProgrammationRule {
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber($ruleNumber)
            ->setIsActive($active);

        $this->em->persist($rule);

        return $rule;
    }

    private function createCategorie(?string $titre = null): Categories
    {
        $categorie = (new Categories())
            ->setTitre($titre ?? 'Catégorie règle ' . $this->unique())
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
            ->setName('Éditeur règle ' . $this->unique())
            ->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    /**
     * @param ProgrammationRule[] $rules
     *
     * @return int[]
     */
    private function extractIds(array $rules): array
    {
        return array_map(
            static fn(ProgrammationRule $rule): ?int => $rule->getId(),
            $rules
        );
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}