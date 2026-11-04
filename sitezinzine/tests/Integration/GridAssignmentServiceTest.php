<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Categories;
use App\Entity\DiffusionDraft;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Entity\Diffusion;
use App\Entity\GridSlotArbitration;
use App\Entity\PendingRebroadcast;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\GridSlotArbitrationRepository;
use App\Repository\PendingRebroadcastRepository;
use App\Service\GridPublicationService;
use App\Service\GridAssignmentService;
use App\Service\GridUnpublicationService;
use App\Service\RegularRebroadcastPlacementService;
use App\Service\PendingRebroadcastPopulatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use App\Tests\Support\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GridAssignmentServiceTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private GridAssignmentService $assignmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDoctrine();

        $this->assignmentService = static::getContainer()
            ->get(GridAssignmentService::class);
    }

    protected function tearDown(): void
    {
        $this->closeEntityManager();

        parent::tearDown();
    }

    public function testAssignCreatesFirstBroadcastAndRebroadcastDraftsInSameGroup(): void
    {
        /*
         * ==========================================================
         * EDITEUR
         * ==========================================================
         */
        $editeur = (new Editeur())
            ->setName('Éditeur PHPUnit assignment')
            ->setMail(null)
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        /*
         * ==========================================================
         * CATÉGORIE
         * ==========================================================
         */
        $category = (new Categories())
            ->setTitre('Catégorie PHPUnit assignment')
            ->setSlug('PAS')
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif('Catégorie utilisée pour tester GridAssignmentService.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        /*
         * ==========================================================
         * RÈGLE
         * ==========================================================
         */
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-10')
            )
            ->setIsActive(true);

        $this->entityManager->persist($rule);

        /*
         * ==========================================================
         * PREMIÈRE DIFFUSION
         *
         * Mardi à 14 h.
         * ==========================================================
         */
        $firstSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('14:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $this->entityManager->persist($firstSlot);

        /*
         * ==========================================================
         * REDIFFUSION
         *
         * Jeudi à 20 h.
         * ==========================================================
         */
        $rebroadcastSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(4)
            ->setStartTime(
                new \DateTimeImmutable('20:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $this->entityManager->persist($rebroadcastSlot);

        /*
         * Les slots doivent également appartenir à la collection
         * de la règle puisque assign() parcourt $rule->getSlots().
         */
        $rule->addSlot($firstSlot);
        $rule->addSlot($rebroadcastSlot);

        /*
         * ==========================================================
         * ÉMISSION
         * ==========================================================
         */
        $emission = (new Emission())
            ->setTitre('Émission PHPUnit assignment')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-04 12:00:00')
            )
            ->setRef('PHPUNIT-ASSIGNMENT-1')
            ->setDuree(60)
            ->setDescriptif(
                'Émission utilisée pour tester l’affectation régulière.'
            )
            ->setCategorie($category)
            ->setEditeur($editeur);

        $this->entityManager->persist($emission);

        /*
         * On flush avant assign() afin que la règle possède son ID.
         * Celui-ci est nécessaire pour générer assignmentGroupKey.
         */
        $this->entityManager->flush();

        self::assertNotNull($rule->getId());
        self::assertNotNull($firstSlot->getId());
        self::assertNotNull($rebroadcastSlot->getId());
        self::assertNotNull($emission->getId());

        /*
         * ==========================================================
         * AFFECTATION
         * ==========================================================
         */
        $selectedDate = new \DateTimeImmutable(
            '2099-08-04 14:00:00'
        );

        $result = $this->assignmentService->assign(
            $firstSlot,
            $emission,
            $selectedDate
        );

        self::assertTrue($result);

        /*
         * ==========================================================
         * PREMIER DRAFT
         * ==========================================================
         */
        $firstDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneRegularBySlotAndHoraire(
                $firstSlot,
                new \DateTimeImmutable(
                    '2099-08-04 14:00:00'
                )
            );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $firstDraft
        );

        self::assertSame(
            $emission->getId(),
            $firstDraft->getEmission()?->getId()
        );

        self::assertSame(
            1,
            $firstDraft->getNombreDiffusion()
        );

        self::assertSame(
            DiffusionDraft::TYPE_REGULAR,
            $firstDraft->getDraftType()
        );

        self::assertSame(
            60,
            $firstDraft->getDurationMinutes()
        );

        /*
         * ==========================================================
         * DRAFT DE REDIFFUSION
         * ==========================================================
         */
        $rebroadcastDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneRegularBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rebroadcastDraft
        );

        self::assertSame(
            $emission->getId(),
            $rebroadcastDraft->getEmission()?->getId()
        );

        self::assertSame(
            2,
            $rebroadcastDraft->getNombreDiffusion()
        );

        self::assertSame(
            DiffusionDraft::TYPE_REGULAR,
            $rebroadcastDraft->getDraftType()
        );

        self::assertSame(
            60,
            $rebroadcastDraft->getDurationMinutes()
        );

        /*
         * ==========================================================
         * GROUPE
         * ==========================================================
         */
        $expectedGroupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $rule->getId()
        );

        self::assertSame(
            $expectedGroupKey,
            $firstDraft->getAssignmentGroupKey()
        );

        self::assertSame(
            $expectedGroupKey,
            $rebroadcastDraft->getAssignmentGroupKey()
        );

        self::assertSame(
            $firstDraft->getAssignmentGroupKey(),
            $rebroadcastDraft->getAssignmentGroupKey()
        );

        /*
        * ==========================================================
        * AUCUNE REDIFFUSION SUPPLÉMENTAIRE
        * ==========================================================
        *
        * L'option est désactivée par défaut.
        * L'affectation R1/R2 ne doit donc créer aucun Pending.
        */
        $pendingRebroadcasts = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy([
                'assignmentGroupKey' => $expectedGroupKey,
            ]);

        self::assertCount(0, $pendingRebroadcasts);
    }

    public function testAssignUpdatesExistingDraftsInsteadOfCreatingDuplicates(): void
    {
        /*
     * ==========================================================
     * EDITEUR
     * ==========================================================
     */
        $editeur = (new Editeur())
            ->setName('Éditeur PHPUnit assignment upsert')
            ->setMail(null)
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        /*
     * ==========================================================
     * CATÉGORIE
     * ==========================================================
     */
        $category = (new Categories())
            ->setTitre('Catégorie PHPUnit assignment upsert')
            ->setSlug('PAU')
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie utilisée pour tester l’upsert.'
            )
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        /*
     * ==========================================================
     * RÈGLE
     * ==========================================================
     */
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(2)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-10')
            )
            ->setIsActive(true);

        $this->entityManager->persist($rule);

        /*
     * Première diffusion : mardi 14 h.
     */
        $firstSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('14:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        /*
     * Rediffusion : jeudi 20 h.
     */
        $rebroadcastSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(4)
            ->setStartTime(
                new \DateTimeImmutable('20:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $rule->addSlot($firstSlot);
        $rule->addSlot($rebroadcastSlot);

        $this->entityManager->persist($firstSlot);
        $this->entityManager->persist($rebroadcastSlot);

        /*
     * ==========================================================
     * PREMIÈRE ÉMISSION
     * ==========================================================
     */
        $firstEmission = (new Emission())
            ->setTitre('Émission PHPUnit assignment upsert A')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-04 12:00:00')
            )
            ->setRef('PHPUNIT-ASSIGNMENT-UPSERT-A')
            ->setDuree(60)
            ->setDescriptif(
                'Première émission utilisée pour tester l’upsert.'
            )
            ->setCategorie($category)
            ->setEditeur($editeur);

        /*
     * ==========================================================
     * DEUXIÈME ÉMISSION
     * ==========================================================
     */
        $secondEmission = (new Emission())
            ->setTitre('Émission PHPUnit assignment upsert B')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-04 13:00:00')
            )
            ->setRef('PHPUNIT-ASSIGNMENT-UPSERT-B')
            ->setDuree(60)
            ->setDescriptif(
                'Deuxième émission utilisée pour tester l’upsert.'
            )
            ->setCategorie($category)
            ->setEditeur($editeur);

        $this->entityManager->persist($firstEmission);
        $this->entityManager->persist($secondEmission);

        $this->entityManager->flush();

        /*
     * ==========================================================
     * PREMIÈRE AFFECTATION
     * ==========================================================
     */
        $selectedDate = new \DateTimeImmutable(
            '2099-08-04 14:00:00'
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $firstSlot,
                $firstEmission,
                $selectedDate
            )
        );

        /*
     * On récupère les deux Drafts créés.
     */
        $firstDraftBefore = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneRegularBySlotAndHoraire(
                $firstSlot,
                new \DateTimeImmutable(
                    '2099-08-04 14:00:00'
                )
            );

        $rebroadcastDraftBefore = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneRegularBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $firstDraftBefore
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rebroadcastDraftBefore
        );

        $firstDraftId = $firstDraftBefore->getId();
        $rebroadcastDraftId = $rebroadcastDraftBefore->getId();

        self::assertNotNull($firstDraftId);
        self::assertNotNull($rebroadcastDraftId);

        self::assertSame(
            $firstEmission->getId(),
            $firstDraftBefore->getEmission()?->getId()
        );

        self::assertSame(
            $firstEmission->getId(),
            $rebroadcastDraftBefore->getEmission()?->getId()
        );

        /*
     * ==========================================================
     * DEUXIÈME AFFECTATION
     * ==========================================================
     *
     * Même règle, mêmes créneaux, même date d'origine,
     * mais une autre émission.
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $firstSlot,
                $secondEmission,
                $selectedDate
            )
        );

        /*
     * On clear Doctrine pour être certaine de relire
     * réellement l'état enregistré en base.
     */
        $this->clearEntityManager();

        $firstDraftAfter = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($firstDraftId);

        $rebroadcastDraftAfter = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($rebroadcastDraftId);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $firstDraftAfter
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rebroadcastDraftAfter
        );

        /*
     * ==========================================================
     * MÊMES DRAFTS
     * ==========================================================
     *
     * Les IDs doivent être strictement identiques :
     * assign() a mis à jour les lignes existantes.
     */
        self::assertSame(
            $firstDraftId,
            $firstDraftAfter->getId()
        );

        self::assertSame(
            $rebroadcastDraftId,
            $rebroadcastDraftAfter->getId()
        );

        /*
     * ==========================================================
     * NOUVELLE ÉMISSION
     * ==========================================================
     */
        self::assertSame(
            $secondEmission->getId(),
            $firstDraftAfter->getEmission()?->getId()
        );

        self::assertSame(
            $secondEmission->getId(),
            $rebroadcastDraftAfter->getEmission()?->getId()
        );

        /*
     * Les rangs restent corrects.
     */
        self::assertSame(
            1,
            $firstDraftAfter->getNombreDiffusion()
        );

        self::assertSame(
            2,
            $rebroadcastDraftAfter->getNombreDiffusion()
        );

        /*
     * ==========================================================
     * GROUPE
     * ==========================================================
     */
        $expectedGroupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $rule->getId()
        );

        self::assertSame(
            $expectedGroupKey,
            $firstDraftAfter->getAssignmentGroupKey()
        );

        self::assertSame(
            $expectedGroupKey,
            $rebroadcastDraftAfter->getAssignmentGroupKey()
        );

        /*
     * ==========================================================
     * PAS DE DOUBLON
     * ==========================================================
     *
     * Il doit toujours exister exactement deux Drafts
     * réguliers appartenant à ce groupe.
     */
        $groupDrafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey(
                $expectedGroupKey
            );

        self::assertCount(
            2,
            $groupDrafts
        );
    }

    public function testRemoveDeletesWholeAssignmentGroup(): void
    {
        /*
     * ==========================================================
     * EDITEUR
     * ==========================================================
     */
        $editeur = (new Editeur())
            ->setName('Éditeur PHPUnit assignment remove')
            ->setMail(null)
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        /*
     * ==========================================================
     * CATÉGORIE
     * ==========================================================
     */
        $category = (new Categories())
            ->setTitre('Catégorie PHPUnit assignment remove')
            ->setSlug('PAR')
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie utilisée pour tester la suppression d’un groupe.'
            )
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        /*
     * ==========================================================
     * RÈGLE
     * ==========================================================
     */
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(3)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-10')
            )
            ->setIsActive(true);

        $this->entityManager->persist($rule);

        /*
     * Première diffusion : mardi 14 h.
     */
        $firstSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('14:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        /*
     * Rediffusion : jeudi 20 h.
     */
        $rebroadcastSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(4)
            ->setStartTime(
                new \DateTimeImmutable('20:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $rule->addSlot($firstSlot);
        $rule->addSlot($rebroadcastSlot);

        $this->entityManager->persist($firstSlot);
        $this->entityManager->persist($rebroadcastSlot);

        /*
     * ==========================================================
     * ÉMISSION
     * ==========================================================
     */
        $emission = (new Emission())
            ->setTitre('Émission PHPUnit assignment remove')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-04 12:00:00')
            )
            ->setRef('PHPUNIT-ASSIGNMENT-REMOVE')
            ->setDuree(60)
            ->setDescriptif(
                'Émission utilisée pour tester la suppression du groupe.'
            )
            ->setCategorie($category)
            ->setEditeur($editeur);

        $this->entityManager->persist($emission);

        $this->entityManager->flush();

        /*
     * ==========================================================
     * CRÉATION DU GROUPE
     * ==========================================================
     */
        $selectedDate = new \DateTimeImmutable(
            '2099-08-04 14:00:00'
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $firstSlot,
                $emission,
                $selectedDate
            )
        );

        $expectedGroupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $rule->getId()
        );

        $groupDraftsBefore = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey(
                $expectedGroupKey
            );

        /*
     * On vérifie notre précondition :
     * première diffusion + rediffusion.
     */
        self::assertCount(
            2,
            $groupDraftsBefore
        );

        $draftIds = array_map(
            static fn(DiffusionDraft $draft): ?int => $draft->getId(),
            $groupDraftsBefore
        );

        self::assertCount(
            2,
            $draftIds
        );

        self::assertNotNull(
            $draftIds[0]
        );

        self::assertNotNull(
            $draftIds[1]
        );

        /*
     * ==========================================================
     * SUPPRESSION
     * ==========================================================
     *
     * remove() reçoit le slot de première diffusion
     * et son horaire.
     */
        $result = $this->assignmentService->remove(
            $firstSlot,
            $selectedDate
        );

        self::assertTrue(
            $result
        );

        /*
     * ==========================================================
     * VÉRIFICATION EN BASE
     * ==========================================================
     */
        $this->clearEntityManager();

        /*
     * Aucun Draft du groupe ne doit subsister.
     */
        $groupDraftsAfter = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey(
                $expectedGroupKey
            );

        self::assertCount(
            0,
            $groupDraftsAfter
        );

        /*
     * On vérifie aussi directement les IDs des deux Drafts
     * qui existaient avant remove().
     */
        self::assertNull(
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->find($draftIds[0])
        );

        self::assertNull(
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->find($draftIds[1])
        );

        /*
     * Et aucune affectation régulière ne doit rester
     * ni sur la première diffusion ni sur la rediffusion.
     */
        $firstSlotReloaded = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($firstSlot->getId());

        $rebroadcastSlotReloaded = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($rebroadcastSlot->getId());

        self::assertNotNull(
            $firstSlotReloaded
        );

        self::assertNotNull(
            $rebroadcastSlotReloaded
        );

        self::assertNull(
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->findOneRegularBySlotAndHoraire(
                    $firstSlotReloaded,
                    new \DateTimeImmutable(
                        '2099-08-04 14:00:00'
                    )
                )
        );

        self::assertNull(
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->findOneRegularBySlotAndHoraire(
                    $rebroadcastSlotReloaded,
                    new \DateTimeImmutable(
                        '2099-08-06 20:00:00'
                    )
                )
        );
    }

    public function testAssignRejectsDirectAssignmentOnRebroadcastSlot(): void
    {
        /*
     * ==========================================================
     * EDITEUR
     * ==========================================================
     */
        $editeur = (new Editeur())
            ->setName('Éditeur PHPUnit assignment rank')
            ->setMail(null)
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        /*
     * ==========================================================
     * CATÉGORIE
     * ==========================================================
     */
        $category = (new Categories())
            ->setTitre('Catégorie PHPUnit assignment rank')
            ->setSlug('PRK')
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie utilisée pour tester le refus d’affectation directe sur une rediffusion.'
            )
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        /*
     * ==========================================================
     * RÈGLE
     * ==========================================================
     */
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(4)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-10')
            )
            ->setIsActive(true);

        $this->entityManager->persist($rule);

        /*
     * ==========================================================
     * SLOT DE REDIFFUSION
     *
     * Rang 2 : assign() doit le refuser.
     * ==========================================================
     */
        $rebroadcastSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(4)
            ->setStartTime(
                new \DateTimeImmutable('20:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $rule->addSlot($rebroadcastSlot);

        $this->entityManager->persist($rebroadcastSlot);

        /*
     * ==========================================================
     * ÉMISSION
     * ==========================================================
     */
        $emission = (new Emission())
            ->setTitre('Émission PHPUnit assignment rank')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-04 12:00:00')
            )
            ->setRef('PHPUNIT-ASSIGNMENT-RANK')
            ->setDuree(60)
            ->setDescriptif(
                'Émission utilisée pour vérifier le refus sur un rang 2.'
            )
            ->setCategorie($category)
            ->setEditeur($editeur);

        $this->entityManager->persist($emission);

        $this->entityManager->flush();

        self::assertNotNull($rule->getId());
        self::assertNotNull($rebroadcastSlot->getId());
        self::assertNotNull($emission->getId());

        /*
     * ==========================================================
     * AFFECTATION INTERDITE
     * ==========================================================
     */
        try {
            $this->assignmentService->assign(
                $rebroadcastSlot,
                $emission,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

            self::fail(
                'Une affectation directe sur une rediffusion aurait dû lever une RuntimeException.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'L’affectation doit se faire sur la première diffusion. Les rediffusions sont générées automatiquement.',
                $exception->getMessage()
            );
        }

        /*
     * ==========================================================
     * AUCUN DRAFT NE DOIT AVOIR ÉTÉ CRÉÉ
     * ==========================================================
     */
        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneRegularBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertNull($draft);
    }

    public function testRemoveReturnsFalseWhenNoDraftExists(): void
    {
        /*
     * ==========================================================
     * EDITEUR
     * ==========================================================
     */
        $editeur = (new Editeur())
            ->setName('Éditeur PHPUnit remove absent')
            ->setMail(null)
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        /*
     * ==========================================================
     * CATÉGORIE
     * ==========================================================
     */
        $category = (new Categories())
            ->setTitre('Catégorie PHPUnit remove absent')
            ->setSlug('PRA')
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie utilisée pour tester remove sans Draft.'
            )
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        /*
     * ==========================================================
     * RÈGLE
     * ==========================================================
     */
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(5)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-10')
            )
            ->setIsActive(true);

        $this->entityManager->persist($rule);

        /*
     * ==========================================================
     * SLOT
     * ==========================================================
     */
        $firstSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('14:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $rule->addSlot($firstSlot);

        $this->entityManager->persist($firstSlot);

        $this->entityManager->flush();

        self::assertNotNull(
            $firstSlot->getId()
        );

        /*
     * ==========================================================
     * PRÉCONDITION
     * ==========================================================
     *
     * Aucun Draft ne doit exister à cet horaire.
     */
        $selectedDate = new \DateTimeImmutable(
            '2099-08-04 14:00:00'
        );

        $draftBefore = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneRegularBySlotAndHoraire(
                $firstSlot,
                $selectedDate
            );

        self::assertNull(
            $draftBefore
        );

        /*
     * ==========================================================
     * REMOVE
     * ==========================================================
     *
     * Il n'y a rien à supprimer :
     * remove() doit simplement retourner false.
     */
        $result = $this->assignmentService->remove(
            $firstSlot,
            $selectedDate
        );

        self::assertFalse(
            $result
        );

        /*
     * ==========================================================
     * VÉRIFICATION
     * ==========================================================
     *
     * L'appel ne doit évidemment avoir créé aucun Draft.
     */
        $draftAfter = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneRegularBySlotAndHoraire(
                $firstSlot,
                $selectedDate
            );

        self::assertNull(
            $draftAfter
        );
    }

    public function testRemoveDeletesOnlyLegacyDraftWithoutAssignmentGroupKeyAndReturnsFalse(): void
    {
        /*
     * ==========================================================
     * EDITEUR
     * ==========================================================
     */
        $editeur = (new Editeur())
            ->setName('Éditeur PHPUnit remove legacy')
            ->setMail(null)
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        /*
     * ==========================================================
     * CATÉGORIE
     * ==========================================================
     */
        $category = (new Categories())
            ->setTitre('Catégorie PHPUnit remove legacy')
            ->setSlug('PRL')
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie utilisée pour tester la suppression d’un Draft legacy sans clé de groupe.'
            )
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        /*
     * ==========================================================
     * RÈGLE
     * ==========================================================
     */
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(6)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-10')
            )
            ->setIsActive(true);

        $this->entityManager->persist($rule);

        /*
     * ==========================================================
     * SLOT
     * ==========================================================
     */
        $firstSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('14:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $rule->addSlot($firstSlot);

        $this->entityManager->persist($firstSlot);

        /*
     * ==========================================================
     * ÉMISSION
     * ==========================================================
     */
        $emission = (new Emission())
            ->setTitre('Émission PHPUnit remove legacy')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-04 12:00:00')
            )
            ->setRef('PHPUNIT-REMOVE-LEGACY')
            ->setDuree(60)
            ->setDescriptif(
                'Émission utilisée pour tester un ancien Draft sans assignmentGroupKey.'
            )
            ->setCategorie($category)
            ->setEditeur($editeur);

        $this->entityManager->persist($emission);

        /*
     * ==========================================================
     * DRAFT LEGACY
     *
     * Il est volontairement créé SANS assignmentGroupKey.
     * ==========================================================
     */
        $selectedDate = new \DateTimeImmutable(
            '2099-08-04 14:00:00'
        );

        $legacyDraft = (new DiffusionDraft())
            ->setSlot($firstSlot)
            ->setSchedule(
                $selectedDate,
                60
            )
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setDraftType(
                DiffusionDraft::TYPE_REGULAR
            );

        $this->entityManager->persist($legacyDraft);

        $this->entityManager->flush();

        self::assertNotNull(
            $legacyDraft->getId()
        );

        self::assertNull(
            $legacyDraft->getAssignmentGroupKey()
        );

        $legacyDraftId = $legacyDraft->getId();

        /*
     * ==========================================================
     * REMOVE
     * ==========================================================
     *
     * Le Draft existe mais il n'a pas de groupe.
     *
     * Le service doit :
     * - supprimer uniquement ce Draft ;
     * - retourner false car aucune suppression de groupe
     *   complète n'a pu être effectuée.
     * ==========================================================
     */
        $result = $this->assignmentService->remove(
            $firstSlot,
            $selectedDate
        );

        self::assertFalse(
            $result
        );

        /*
     * ==========================================================
     * VÉRIFICATION
     * ==========================================================
     */
        $this->clearEntityManager();

        $deletedDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($legacyDraftId);

        self::assertNull(
            $deletedDraft
        );

        /*
     * Le créneau ne doit plus avoir de Draft régulier.
     */
        $reloadedSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($firstSlot->getId());

        self::assertInstanceOf(
            ProgrammationRuleSlot::class,
            $reloadedSlot
        );

        $draftAtSlot = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneRegularBySlotAndHoraire(
                $reloadedSlot,
                $selectedDate
            );

        self::assertNull(
            $draftAtSlot
        );
    }

    /**
     *
     *
     * R1 appartient à une semaine non publiée.
     * R2 appartient à la semaine suivante, déjà publiée.
     *
     * Remplacer A par B doit :
     * - conserver R1 en brouillon ;
     * - mettre à jour R2 ;
     * - republier la semaine de R2 ;
     * - réutiliser la Diffusion existante ;
     * - conserver les identifiants des Drafts.
     */
    public function testAssignRepublishesPublishedRebroadcastWithoutPublishingDraftWeek(): void
    {
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit remplacement partiellement publié')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie remplacement partiellement publié')
            ->setSlug('PRP')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Test de remplacement avec R2 publiée.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(7)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $rank1Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rank2Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(4)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(1)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($rank1Slot);
        $rule->addSlot($rank2Slot);

        $emissionA = (new Emission())
            ->setTitre('Émission A')
            ->setKeyword('test-remplacement-a')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-REPLACE-A')
            ->setDuree(60)
            ->setDescriptif('Émission initiale.')
            ->setCategorie($category)
            ->setEditeur($editor);

        $emissionB = (new Emission())
            ->setTitre('Émission B')
            ->setKeyword('test-remplacement-b')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-REPLACE-B')
            ->setDuree(60)
            ->setDescriptif('Émission de remplacement.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $rule,
                $rank1Slot,
                $rank2Slot,
                $emissionA,
                $emissionB,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        // Le 4 août 2099 est un mardi.
        $origin = new \DateTimeImmutable('2099-08-04 14:00:00');
        $rank2Date = new \DateTimeImmutable('2099-08-13 20:00:00');

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $diffusionRepository = static::getContainer()
            ->get(DiffusionRepository::class);

        // Première affectation : émission A.
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emissionA,
                $origin
            )
        );

        $rank1Draft = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank1Slot, $origin);

        $rank2Draft = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank2Slot, $rank2Date);

        self::assertInstanceOf(DiffusionDraft::class, $rank1Draft);
        self::assertInstanceOf(DiffusionDraft::class, $rank2Draft);

        $rank1DraftId = $rank1Draft->getId();
        $rank2DraftId = $rank2Draft->getId();

        // On simule la publication de R2 uniquement.
        $diffusion = (new Diffusion())
            ->setEmission($emissionA)
            ->setSchedule(
                \DateTime::createFromInterface($rank2Date),
                60
            )
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($rank2Draft->getAssignmentGroupKey())
            ->markAsPublished();

        $this->entityManager->persist($diffusion);

        $rank2Draft->markAsPublished(
            $diffusion,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        $diffusionId = $diffusion->getId();
        $emissionBId = $emissionB->getId();

        // Vérification de l'état initial.
        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $rank1Draft->getPublicationStatus()
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $rank2Draft->getPublicationStatus()
        );

        self::assertTrue($diffusion->isPublished());

        // ACTION : remplacement de A par B.
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emissionB,
                $origin
            )
        );

        // Relecture réelle depuis la base.
        $this->clearEntityManager();

        $rank1After = $draftRepository->find($rank1DraftId);
        $rank2After = $draftRepository->find($rank2DraftId);
        $diffusionAfter = $diffusionRepository->find($diffusionId);

        self::assertInstanceOf(DiffusionDraft::class, $rank1After);
        self::assertInstanceOf(DiffusionDraft::class, $rank2After);
        self::assertInstanceOf(Diffusion::class, $diffusionAfter);

        // Les deux Drafts portent maintenant B.
        self::assertSame($emissionBId, $rank1After->getEmission()?->getId());
        self::assertSame($emissionBId, $rank2After->getEmission()?->getId());

        // R1 reste non publiée.
        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $rank1After->getPublicationStatus()
        );

        self::assertNull($rank1After->getPublishedDiffusion());

        // R2 est toujours publiée, avec B.
        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $rank2After->getPublicationStatus()
        );

        self::assertTrue($diffusionAfter->isPublished());

        self::assertSame(
            $emissionBId,
            $diffusionAfter->getEmission()?->getId()
        );

        // La publication a réutilisé la Diffusion existante.
        self::assertSame(
            $diffusionId,
            $rank2After->getPublishedDiffusion()?->getId()
        );

        // Aucun Draft supplémentaire dans le groupe.
        self::assertCount(
            2,
            $draftRepository->findByAssignmentGroupKey(
                $rank1After->getAssignmentGroupKey()
            )
        );
    }

    public function testRemoveUnpublishesLastPublishedRebroadcastAndKeepsHistoricalDiffusion(): void
    {
        /*
     * ==========================================================
     * PRÉPARATION DES ENTITÉS
     * ==========================================================
     */
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit suppression publiée')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie suppression publiée')
            ->setSlug('PSP')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Test de suppression d’une rediffusion publiée.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(8)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $rank1Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rank2Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(4)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(1)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($rank1Slot);
        $rule->addSlot($rank2Slot);

        $emission = (new Emission())
            ->setTitre('Émission PHPUnit suppression publiée')
            ->setKeyword('test-suppression-publiee')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-REMOVE-PUBLISHED')
            ->setDuree(60)
            ->setDescriptif('Émission destinée au test de suppression.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $rule,
                $rank1Slot,
                $rank2Slot,
                $emission,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        /*
     * ==========================================================
     * AFFECTATION R1 / R2
     * ==========================================================
     */
        $origin = new \DateTimeImmutable('2099-08-04 14:00:00');
        $rank2Date = new \DateTimeImmutable('2099-08-13 20:00:00');

        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emission,
                $origin
            )
        );

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $diffusionRepository = static::getContainer()
            ->get(DiffusionRepository::class);

        $rank1Draft = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank1Slot, $origin);

        $rank2Draft = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank2Slot, $rank2Date);

        self::assertInstanceOf(DiffusionDraft::class, $rank1Draft);
        self::assertInstanceOf(DiffusionDraft::class, $rank2Draft);

        $rank1DraftId = $rank1Draft->getId();
        $rank2DraftId = $rank2Draft->getId();
        $assignmentGroupKey = $rank1Draft->getAssignmentGroupKey();

        /*
     * ==========================================================
     * PUBLICATION INITIALE DE R2
     * ==========================================================
     *
     * R1 reste en brouillon.
     * R2 est la seule diffusion publiée de sa semaine.
     */
        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setSchedule(
                \DateTime::createFromInterface($rank2Date),
                60
            )
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($assignmentGroupKey)
            ->markAsPublished();

        $this->entityManager->persist($diffusion);

        $rank2Draft->markAsPublished(
            $diffusion,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        $diffusionId = $diffusion->getId();

        self::assertNotNull($diffusionId);
        self::assertTrue($diffusion->isPublished());

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $rank1Draft->getPublicationStatus()
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $rank2Draft->getPublicationStatus()
        );

        /*
     * ==========================================================
     * ACTION : SUPPRIMER L'AFFECTATION
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->remove(
                $rank1Slot,
                $origin
            )
        );

        /*
     * ==========================================================
     * VÉRIFICATIONS APRÈS RELECTURE EN BASE
     * ==========================================================
     */
        $this->clearEntityManager();

        // Les deux Drafts ont été supprimés.
        self::assertNull($draftRepository->find($rank1DraftId));
        self::assertNull($draftRepository->find($rank2DraftId));

        self::assertCount(
            0,
            $draftRepository->findByAssignmentGroupKey(
                $assignmentGroupKey
            )
        );

        // La Diffusion historique existe toujours.
        $historicalDiffusion = $diffusionRepository->find($diffusionId);

        self::assertInstanceOf(
            Diffusion::class,
            $historicalDiffusion
        );

        // Mais elle n'est plus publiée.
        self::assertFalse(
            $historicalDiffusion->isPublished()
        );

        // Son émission d'origine est conservée.
        self::assertSame(
            $emission->getId(),
            $historicalDiffusion->getEmission()?->getId()
        );
    }

    public function testRemoveRepublishesOtherDiffusionsInSameWeek(): void
    {
        /*
     * ==========================================================
     * PRÉPARATION DES ENTITÉS
     * ==========================================================
     */
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit suppression partielle')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie suppression partielle')
            ->setSlug('PSM')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Test de suppression dans une semaine partagée.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $ruleA = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(9)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $ruleB = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(10)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $slotA = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $slotB = (new ProgrammationRuleSlot())
            ->setDayOfWeek(4)
            ->setStartTime(new \DateTimeImmutable('16:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $ruleA->addSlot($slotA);
        $ruleB->addSlot($slotB);

        $emissionA = (new Emission())
            ->setTitre('Émission A à supprimer')
            ->setKeyword('suppression-partielle-a')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-REMOVE-SHARED-A')
            ->setDuree(60)
            ->setDescriptif('Émission à supprimer de la grille.')
            ->setCategorie($category)
            ->setEditeur($editor);

        $emissionB = (new Emission())
            ->setTitre('Émission B à conserver')
            ->setKeyword('suppression-partielle-b')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-REMOVE-SHARED-B')
            ->setDuree(60)
            ->setDescriptif('Émission devant rester publiée.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $ruleA,
                $ruleB,
                $slotA,
                $slotB,
                $emissionA,
                $emissionB,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        /*
     * ==========================================================
     * AFFECTATIONS INDÉPENDANTES
     * ==========================================================
     */
        $dateA = new \DateTimeImmutable('2099-08-04 14:00:00');
        $dateB = new \DateTimeImmutable('2099-08-06 16:00:00');

        self::assertTrue(
            $this->assignmentService->assign(
                $slotA,
                $emissionA,
                $dateA
            )
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $slotB,
                $emissionB,
                $dateB
            )
        );

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $diffusionRepository = static::getContainer()
            ->get(DiffusionRepository::class);

        $draftA = $draftRepository
            ->findOneRegularBySlotAndHoraire($slotA, $dateA);

        $draftB = $draftRepository
            ->findOneRegularBySlotAndHoraire($slotB, $dateB);

        self::assertInstanceOf(DiffusionDraft::class, $draftA);
        self::assertInstanceOf(DiffusionDraft::class, $draftB);

        $draftAId = $draftA->getId();
        $draftBId = $draftB->getId();

        /*
     * ==========================================================
     * PUBLICATION INITIALE DES DEUX DIFFUSIONS
     * ==========================================================
     */
        $diffusionA = (new Diffusion())
            ->setEmission($emissionA)
            ->setSchedule(
                \DateTime::createFromInterface($dateA),
                60
            )
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($draftA->getAssignmentGroupKey())
            ->markAsPublished();

        $diffusionB = (new Diffusion())
            ->setEmission($emissionB)
            ->setSchedule(
                \DateTime::createFromInterface($dateB),
                60
            )
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($draftB->getAssignmentGroupKey())
            ->markAsPublished();

        $this->entityManager->persist($diffusionA);
        $this->entityManager->persist($diffusionB);

        $publishedAt = new \DateTimeImmutable('2099-08-01 12:00:00');

        $draftA->markAsPublished($diffusionA, $publishedAt);
        $draftB->markAsPublished($diffusionB, $publishedAt);

        $this->entityManager->flush();

        $diffusionAId = $diffusionA->getId();
        $diffusionBId = $diffusionB->getId();
        $emissionBId = $emissionB->getId();

        self::assertNotNull($diffusionAId);
        self::assertNotNull($diffusionBId);
        self::assertTrue($diffusionA->isPublished());
        self::assertTrue($diffusionB->isPublished());

        /*
     * ==========================================================
     * ACTION : SUPPRESSION DE L'AFFECTATION A
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->remove(
                $slotA,
                $dateA
            )
        );

        /*
     * ==========================================================
     * VÉRIFICATIONS APRÈS RELECTURE EN BASE
     * ==========================================================
     */
        $this->clearEntityManager();

        // Le Draft A est supprimé.
        self::assertNull($draftRepository->find($draftAId));

        // Le Draft B est conservé et publié.
        $draftBAfter = $draftRepository->find($draftBId);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $draftBAfter
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $draftBAfter->getPublicationStatus()
        );

        // Les deux Diffusions historiques existent toujours.
        $diffusionAAfter = $diffusionRepository->find($diffusionAId);
        $diffusionBAfter = $diffusionRepository->find($diffusionBId);

        self::assertInstanceOf(Diffusion::class, $diffusionAAfter);
        self::assertInstanceOf(Diffusion::class, $diffusionBAfter);

        // A n'est plus publiée.
        self::assertFalse($diffusionAAfter->isPublished());

        // B reste publiée avec le même identifiant.
        self::assertTrue($diffusionBAfter->isPublished());

        self::assertSame(
            $diffusionBId,
            $draftBAfter->getPublishedDiffusion()?->getId()
        );

        self::assertSame(
            $emissionBId,
            $diffusionBAfter->getEmission()?->getId()
        );

        /*
     * ==========================================================
     * AUCUN DOUBLON DE DIFFUSION
     * ==========================================================
     */
        self::assertCount(
            2,
            $diffusionRepository->findBy([
                'assignmentGroupKey' => [
                    $diffusionAAfter->getAssignmentGroupKey(),
                    $diffusionBAfter->getAssignmentGroupKey(),
                ],
            ])
        );
    }

    public function testRemoveUnpublishesRescheduledRebroadcastInAnotherWeek(): void
    {
        /*
     * ==========================================================
     * PRÉPARATION DES ENTITÉS
     * ==========================================================
     */
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit suppression arbitrage')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie suppression arbitrage')
            ->setSlug('PSA')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Test de suppression après déplacement.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(11)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $rank1Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rank2Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(4)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($rank1Slot);
        $rule->addSlot($rank2Slot);

        $emission = (new Emission())
            ->setTitre('Émission déplacée par arbitrage')
            ->setKeyword('suppression-arbitrage')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-REMOVE-ARBITRATION')
            ->setDuree(60)
            ->setDescriptif('Émission utilisée pour tester la suppression.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $rule,
                $rank1Slot,
                $rank2Slot,
                $emission,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        /*
     * ==========================================================
     * AFFECTATION INITIALE
     * ==========================================================
     *
     * R1 : mardi 4 août.
     * R2 : jeudi 6 août.
     *
     * L'arbitrage déplacera R2 au jeudi 13 août.
     */
        $origin = new \DateTimeImmutable('2099-08-04 14:00:00');

        $originalRank2Date = new \DateTimeImmutable(
            '2099-08-06 20:00:00'
        );

        $rescheduledRank2Date = new \DateTimeImmutable(
            '2099-08-13 20:00:00'
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emission,
                $origin
            )
        );

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $diffusionRepository = static::getContainer()
            ->get(DiffusionRepository::class);

        $rank1Draft = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank1Slot, $origin);

        $rank2Draft = $draftRepository
            ->findOneRegularBySlotAndHoraire(
                $rank2Slot,
                $originalRank2Date
            );

        self::assertInstanceOf(DiffusionDraft::class, $rank1Draft);
        self::assertInstanceOf(DiffusionDraft::class, $rank2Draft);

        $rank1DraftId = $rank1Draft->getId();
        $rank2DraftId = $rank2Draft->getId();
        $assignmentGroupKey = $rank1Draft->getAssignmentGroupKey();

        /*
     * ==========================================================
     * ARBITRAGE : DÉPLACEMENT DE R2
     * ==========================================================
     */
        $arbitration = (new GridSlotArbitration())
            ->setSlot($rank2Slot)
            ->setOriginalStartsAt($originalRank2Date)
            ->setOriginalEndsAt(
                $originalRank2Date->modify('+60 minutes')
            )
            ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
            ->setAction(
                GridSlotArbitration::ACTION_RESCHEDULE_NEXT_WEEK
            )
            ->setRescheduledStartsAt($rescheduledRank2Date)
            ->setRescheduledEndsAt(
                $rescheduledRank2Date->modify('+60 minutes')
            )
            ->setArbitrationGroupKey($assignmentGroupKey);

        $arbitration->markResolved();

        $this->entityManager->persist($arbitration);

        /*
     * ==========================================================
     * DIFFUSION PUBLIÉE À LA DATE DÉPLACÉE
     * ==========================================================
     *
     * R1 reste en brouillon.
     * R2 possède une Diffusion publiée le 13 août.
     *
     * Le Draft conserve son horaire d'origine.
     */
        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setSchedule(
                \DateTime::createFromInterface($rescheduledRank2Date),
                60
            )
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($assignmentGroupKey)
            ->markAsPublished();

        $this->entityManager->persist($diffusion);

        $rank2Draft->markAsPublished(
            $diffusion,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        $diffusionId = $diffusion->getId();

        self::assertNotNull($diffusionId);
        self::assertTrue($diffusion->isPublished());

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $rank1Draft->getPublicationStatus()
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $rank2Draft->getPublicationStatus()
        );

        /*
     * ==========================================================
     * ACTION : SUPPRESSION DE L'AFFECTATION
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->remove(
                $rank1Slot,
                $origin
            )
        );

        /*
     * ==========================================================
     * VÉRIFICATIONS EN BASE
     * ==========================================================
     */
        $this->clearEntityManager();

        // Les deux Drafts du groupe ont disparu.
        self::assertNull(
            $draftRepository->find($rank1DraftId)
        );

        self::assertNull(
            $draftRepository->find($rank2DraftId)
        );

        self::assertCount(
            0,
            $draftRepository->findByAssignmentGroupKey(
                $assignmentGroupKey
            )
        );

        // La Diffusion déplacée existe toujours.
        $historicalDiffusion = $diffusionRepository
            ->find($diffusionId);

        self::assertInstanceOf(
            Diffusion::class,
            $historicalDiffusion
        );

        // Mais elle n'est plus publiée.
        self::assertFalse(
            $historicalDiffusion->isPublished()
        );

        // Elle conserve son horaire déplacé.
        self::assertSame(
            $rescheduledRank2Date->format('Y-m-d H:i:s'),
            $historicalDiffusion
                ->getHoraireDiffusion()
                ->format('Y-m-d H:i:s')
        );

        // Et son émission d'origine.
        self::assertSame(
            $emission->getId(),
            $historicalDiffusion->getEmission()?->getId()
        );

        // L'arbitrage reste enregistré.
        $savedArbitration = $this->entityManager
            ->getRepository(GridSlotArbitration::class)
            ->find($arbitration->getId());

        self::assertInstanceOf(
            GridSlotArbitration::class,
            $savedArbitration
        );

        self::assertSame(
            GridSlotArbitration::STATUS_RESOLVED,
            $savedArbitration->getStatus()
        );
    }

    public function testRemoveRollsBackWhenRepublicationFails(): void
    {
        /*
     * ==========================================================
     * PRÉPARATION
     * ==========================================================
     */
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit rollback')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie rollback')
            ->setSlug('RLB')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Test transactionnel.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(12)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $rank1Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($rank1Slot);

        $emissionToRemove = (new Emission())
            ->setTitre('Émission à supprimer')
            ->setKeyword('rollback-remove')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-ROLLBACK-REMOVE')
            ->setDuree(60)
            ->setDescriptif('Émission à supprimer.')
            ->setCategorie($category)
            ->setEditeur($editor);

        $emissionToKeep = (new Emission())
            ->setTitre('Émission à conserver')
            ->setKeyword('rollback-keep')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-ROLLBACK-KEEP')
            ->setDuree(60)
            ->setDescriptif('Émission à conserver.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $rule,
                $rank1Slot,
                $emissionToRemove,
                $emissionToKeep,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $origin = new \DateTimeImmutable('2099-08-04 14:00:00');
        $otherDate = new \DateTimeImmutable('2099-08-04 16:00:00');

        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emissionToRemove,
                $origin
            )
        );

        /*
     * ==========================================================
     * CRÉATION DES DEUX DIFFUSIONS PUBLIÉES
     * ==========================================================
     */
        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $diffusionRepository = static::getContainer()
            ->get(DiffusionRepository::class);

        $draftToRemove = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank1Slot, $origin);

        self::assertInstanceOf(DiffusionDraft::class, $draftToRemove);

        $draftToRemoveId = $draftToRemove->getId();
        $assignmentGroupKey = $draftToRemove->getAssignmentGroupKey();

        $diffusionToRemove = (new Diffusion())
            ->setEmission($emissionToRemove)
            ->setSchedule(\DateTime::createFromInterface($origin), 60)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($assignmentGroupKey)
            ->markAsPublished();

        $this->entityManager->persist($diffusionToRemove);

        $draftToRemove->markAsPublished(
            $diffusionToRemove,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $otherDraft = (new DiffusionDraft())
            ->setEmission($emissionToKeep)
            ->setHoraireDiffusion($otherDate)
            ->setEndsAt($otherDate->modify('+60 minutes'))
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('PHPUNIT-ROLLBACK-OTHER');

        $otherDiffusion = (new Diffusion())
            ->setEmission($emissionToKeep)
            ->setSchedule(\DateTime::createFromInterface($otherDate), 60)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('PHPUNIT-ROLLBACK-OTHER')
            ->markAsPublished();

        $this->entityManager->persist($otherDraft);
        $this->entityManager->persist($otherDiffusion);

        $otherDraft->markAsPublished(
            $otherDiffusion,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        $diffusionToRemoveId = $diffusionToRemove->getId();
        $otherDiffusionId = $otherDiffusion->getId();
        $otherDraftId = $otherDraft->getId();

        /*
     * ==========================================================
     * INJECTION D'UNE PANNE CONTRÔLÉE
     * ==========================================================
     */
        $failingPopulator = new class implements PendingRebroadcastPopulatorInterface {
            public function populateFromPublishedDiffusions(
                array $diffusions,
                \DateTimeInterface $before
            ): array {
                throw new \RuntimeException(
                    'PHPUNIT: échec simulé après flush de publication'
                );
            }
        };

        $container = static::getContainer();

        $publicationService = new GridPublicationService(
            $draftRepository,
            $diffusionRepository,
            $container->get(GridSlotArbitrationRepository::class),
            $this->entityManager,
            $failingPopulator,
            $container->get(ClockInterface::class)
        );

        $assignmentService = new GridAssignmentService(
            $draftRepository,
            $container->get(PendingRebroadcastRepository::class),
            $container->get(GridSlotArbitrationRepository::class),
            $container->get(GridUnpublicationService::class),
            $publicationService,
            $this->entityManager
        );

        /*
     * ==========================================================
     * SUPPRESSION : LA REPUBLICATION DOIT ÉCHOUER
     * ==========================================================
     */
        try {
            $assignmentService->remove($rank1Slot, $origin);

            self::fail(
                'Une exception aurait dû interrompre la republication.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'PHPUNIT: échec simulé après flush de publication',
                $exception->getMessage()
            );
        }

        /*
     * ==========================================================
     * VÉRIFICATION DU ROLLBACK EN BASE
     * ==========================================================
     *
     * wrapInTransaction() peut fermer l'EntityManager
     * après une exception.
     *
     * On repart donc d'un EntityManager neuf.
     */
        $registry = $container->get('doctrine');
        $registry->resetManager();

        $freshEntityManager = $registry->getManager();

        $restoredDraft = $freshEntityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftToRemoveId);

        $restoredOtherDraft = $freshEntityManager
            ->getRepository(DiffusionDraft::class)
            ->find($otherDraftId);

        $restoredDiffusion = $freshEntityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionToRemoveId);

        $restoredOtherDiffusion = $freshEntityManager
            ->getRepository(Diffusion::class)
            ->find($otherDiffusionId);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $restoredDraft
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $restoredOtherDraft
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $restoredDraft->getPublicationStatus()
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $restoredOtherDraft->getPublicationStatus()
        );

        self::assertInstanceOf(
            Diffusion::class,
            $restoredDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $restoredOtherDiffusion
        );

        self::assertTrue($restoredDiffusion->isPublished());
        self::assertTrue($restoredOtherDiffusion->isPublished());

        self::assertSame(
            $diffusionToRemoveId,
            $restoredDraft->getPublishedDiffusion()?->getId()
        );

        self::assertSame(
            $otherDiffusionId,
            $restoredOtherDraft->getPublishedDiffusion()?->getId()
        );
    }


    public function testAssignRollsBackWhenRepublicationFails(): void
    {
        /*
     * ==========================================================
     * PRÉPARATION
     * ==========================================================
     */
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit rollback assign')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie rollback assign')
            ->setSlug('RBA')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Test transactionnel assign.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(13)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $rank1Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($rank1Slot);

        $originalEmission = (new Emission())
            ->setTitre('Émission initiale')
            ->setKeyword('rollback-assign-original')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-ROLLBACK-ASSIGN-ORIGINAL')
            ->setDuree(60)
            ->setDescriptif('Émission initialement publiée.')
            ->setCategorie($category)
            ->setEditeur($editor);

        $replacementEmission = (new Emission())
            ->setTitre('Émission de remplacement')
            ->setKeyword('rollback-assign-replacement')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-ROLLBACK-ASSIGN-REPLACEMENT')
            ->setDuree(60)
            ->setDescriptif('Émission dont l’affectation doit être annulée.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $rule,
                $rank1Slot,
                $originalEmission,
                $replacementEmission,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $origin = new \DateTimeImmutable('2099-08-04 14:00:00');

        $originalEmissionId = $originalEmission->getId();
        $replacementEmissionId = $replacementEmission->getId();

        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $originalEmission,
                $origin
            )
        );

        /*
     * ==========================================================
     * PUBLICATION DE L'AFFECTATION INITIALE
     * ==========================================================
     */
        $container = static::getContainer();

        $draftRepository = $container->get(
            DiffusionDraftRepository::class
        );

        $diffusionRepository = $container->get(
            DiffusionRepository::class
        );

        $originalDraft = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank1Slot, $origin);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $originalDraft
        );

        $originalDraftId = $originalDraft->getId();
        $assignmentGroupKey = $originalDraft->getAssignmentGroupKey();

        $originalDiffusion = (new Diffusion())
            ->setEmission($originalEmission)
            ->setSchedule(
                \DateTime::createFromInterface($origin),
                60
            )
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($assignmentGroupKey)
            ->markAsPublished();

        $this->entityManager->persist($originalDiffusion);

        $originalDraft->markAsPublished(
            $originalDiffusion,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        $originalDiffusionId = $originalDiffusion->getId();

        /*
     * ==========================================================
     * INJECTION D'UNE PANNE APRÈS LE FLUSH
     * ==========================================================
     */
        $failingPopulator = new class implements PendingRebroadcastPopulatorInterface {
            public function populateFromPublishedDiffusions(
                array $diffusions,
                \DateTimeInterface $before
            ): array {
                throw new \RuntimeException(
                    'PHPUNIT: échec simulé après flush de publication'
                );
            }
        };

        $publicationService = new GridPublicationService(
            $draftRepository,
            $diffusionRepository,
            $container->get(GridSlotArbitrationRepository::class),
            $this->entityManager,
            $failingPopulator,
            $container->get(ClockInterface::class)
        );

        $assignmentService = new GridAssignmentService(
            $draftRepository,
            $container->get(PendingRebroadcastRepository::class),
            $container->get(GridSlotArbitrationRepository::class),
            $container->get(GridUnpublicationService::class),
            $publicationService,
            $this->entityManager
        );

        /*
     * ==========================================================
     * REMPLACEMENT : LA REPUBLICATION DOIT ÉCHOUER
     * ==========================================================
     */
        try {
            $assignmentService->assign(
                $rank1Slot,
                $replacementEmission,
                $origin
            );

            self::fail(
                'Une exception aurait dû interrompre la republication.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'PHPUNIT: échec simulé après flush de publication',
                $exception->getMessage()
            );
        }

        /*
     * ==========================================================
     * VÉRIFICATION DU ROLLBACK EN BASE
     * ==========================================================
     */
        $registry = $container->get('doctrine');
        $registry->resetManager();

        $freshEntityManager = $registry->getManager();

        $restoredDraft = $freshEntityManager
            ->getRepository(DiffusionDraft::class)
            ->find($originalDraftId);

        $restoredDiffusion = $freshEntityManager
            ->getRepository(Diffusion::class)
            ->find($originalDiffusionId);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $restoredDraft
        );

        self::assertInstanceOf(
            Diffusion::class,
            $restoredDiffusion
        );

        /*
     * Le Draft doit conserver son émission initiale.
     */
        self::assertSame(
            $originalEmissionId,
            $restoredDraft->getEmission()?->getId()
        );

        self::assertNotSame(
            $replacementEmissionId,
            $restoredDraft->getEmission()?->getId()
        );

        /*
     * Le Draft doit toujours être publié.
     */
        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $restoredDraft->getPublicationStatus()
        );

        /*
     * La Diffusion initiale doit toujours être publiée
     * et conserver la bonne émission.
     */
        self::assertTrue(
            $restoredDiffusion->isPublished()
        );

        self::assertSame(
            $originalEmissionId,
            $restoredDiffusion->getEmission()?->getId()
        );

        /*
     * Le lien Draft -> Diffusion doit être préservé.
     */
        self::assertSame(
            $originalDiffusionId,
            $restoredDraft->getPublishedDiffusion()?->getId()
        );

        /*
     * Le groupe d'affectation doit être conservé.
     */
        self::assertSame(
            $assignmentGroupKey,
            $restoredDraft->getAssignmentGroupKey()
        );
    }


    public function testAssignAcrossPublishedAndUnpublishedWeeks(): void
    {
        $fixture = $this->createMixedWeeksFixture();

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['rank1Slot'],
                $fixture['replacementEmission'],
                $fixture['weekA']
            )
        );

        $this->entityManager->clear();

        $draftA = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($fixture['draftAId']);

        $draftB = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($fixture['draftBId']);

        $diffusionA = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($fixture['diffusionAId']);

        self::assertInstanceOf(DiffusionDraft::class, $draftA);
        self::assertInstanceOf(DiffusionDraft::class, $draftB);
        self::assertInstanceOf(Diffusion::class, $diffusionA);

        self::assertSame(
            $fixture['replacementEmissionId'],
            $draftA->getEmission()?->getId()
        );

        self::assertSame(
            $fixture['replacementEmissionId'],
            $draftB->getEmission()?->getId()
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $draftA->getPublicationStatus()
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $draftB->getPublicationStatus()
        );

        self::assertTrue($diffusionA->isPublished());

        self::assertSame(
            $fixture['replacementEmissionId'],
            $diffusionA->getEmission()?->getId()
        );

        self::assertSame(
            $fixture['diffusionAId'],
            $draftA->getPublishedDiffusion()?->getId()
        );

        self::assertNull($draftB->getPublishedDiffusion());
    }


    public function testRemoveAcrossPublishedAndUnpublishedWeeks(): void
    {
        $fixture = $this->createMixedWeeksFixture();

        self::assertTrue(
            $this->assignmentService->remove(
                $fixture['rank1Slot'],
                $fixture['weekA']
            )
        );

        $this->entityManager->clear();

        $draftA = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($fixture['draftAId']);

        $draftB = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($fixture['draftBId']);

        $historicalDiffusion = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($fixture['diffusionAId']);

        self::assertNull($draftA);
        self::assertNull($draftB);

        self::assertInstanceOf(
            Diffusion::class,
            $historicalDiffusion
        );

        self::assertFalse(
            $historicalDiffusion->isPublished()
        );

        self::assertSame(
            $fixture['originalEmissionId'],
            $historicalDiffusion->getEmission()?->getId()
        );
    }


    public function testAssignRollsBackAcrossMultipleWeeksWhenRepublicationFails(): void
    {
        $fixture = $this->createMixedWeeksFixture();

        $container = static::getContainer();

        $failingPopulator = new class implements PendingRebroadcastPopulatorInterface {
            public function populateFromPublishedDiffusions(
                array $diffusions,
                \DateTimeInterface $before
            ): array {
                throw new \RuntimeException(
                    'PHPUNIT: échec simulé sur semaines mixtes'
                );
            }
        };

        $publicationService = new GridPublicationService(
            $container->get(DiffusionDraftRepository::class),
            $container->get(DiffusionRepository::class),
            $container->get(GridSlotArbitrationRepository::class),
            $this->entityManager,
            $failingPopulator,
            $container->get(ClockInterface::class)
        );

        $assignmentService = new GridAssignmentService(
            $container->get(DiffusionDraftRepository::class),
            $container->get(PendingRebroadcastRepository::class),
            $container->get(GridSlotArbitrationRepository::class),
            $container->get(GridUnpublicationService::class),
            $publicationService,
            $this->entityManager
        );

        try {
            $assignmentService->assign(
                $fixture['rank1Slot'],
                $fixture['replacementEmission'],
                $fixture['weekA']
            );

            self::fail('La republication aurait dû échouer.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'PHPUNIT: échec simulé sur semaines mixtes',
                $exception->getMessage()
            );
        }

        $registry = $container->get('doctrine');
        $registry->resetManager();

        $freshEntityManager = $registry->getManager();

        $draftA = $freshEntityManager
            ->getRepository(DiffusionDraft::class)
            ->find($fixture['draftAId']);

        $draftB = $freshEntityManager
            ->getRepository(DiffusionDraft::class)
            ->find($fixture['draftBId']);

        $diffusionA = $freshEntityManager
            ->getRepository(Diffusion::class)
            ->find($fixture['diffusionAId']);

        self::assertInstanceOf(DiffusionDraft::class, $draftA);
        self::assertInstanceOf(DiffusionDraft::class, $draftB);
        self::assertInstanceOf(Diffusion::class, $diffusionA);

        self::assertSame(
            $fixture['originalEmissionId'],
            $draftA->getEmission()?->getId()
        );

        self::assertSame(
            $fixture['originalEmissionId'],
            $draftB->getEmission()?->getId()
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $draftA->getPublicationStatus()
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $draftB->getPublicationStatus()
        );

        self::assertTrue($diffusionA->isPublished());

        self::assertSame(
            $fixture['originalEmissionId'],
            $diffusionA->getEmission()?->getId()
        );

        self::assertSame(
            $fixture['diffusionAId'],
            $draftA->getPublishedDiffusion()?->getId()
        );

        self::assertNull($draftB->getPublishedDiffusion());
    }

    public function testAssignCreatesThreeBroadcasts(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $groupKeys = [];

        foreach ([1, 2, 3] as $rank) {
            $draft = $repository->findOneRegularBySlotAndHoraire(
                $fixture['slots'][$rank],
                $fixture['dates'][$rank]
            );

            self::assertInstanceOf(DiffusionDraft::class, $draft);

            self::assertSame(
                $rank,
                $draft->getNombreDiffusion()
            );

            self::assertSame(
                $fixture['originalEmission']->getId(),
                $draft->getEmission()?->getId()
            );

            self::assertSame(
                DiffusionDraft::STATUS_DRAFT,
                $draft->getPublicationStatus()
            );

            $groupKeys[] = $draft->getAssignmentGroupKey();
        }

        self::assertCount(1, array_unique($groupKeys));
        self::assertNotEmpty($groupKeys[0]);
    }


    public function testReassignUpdatesThreeBroadcastsWithoutDuplicates(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $originalDraftIds = [];
        $originalGroupKey = null;

        foreach ([1, 2, 3] as $rank) {
            $draft = $repository->findOneRegularBySlotAndHoraire(
                $fixture['slots'][$rank],
                $fixture['dates'][$rank]
            );

            self::assertInstanceOf(DiffusionDraft::class, $draft);

            $originalDraftIds[$rank] = $draft->getId();
            $originalGroupKey ??= $draft->getAssignmentGroupKey();
        }

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['replacementEmission'],
                $fixture['dates'][1]
            )
        );

        $this->entityManager->clear();

        foreach ([1, 2, 3] as $rank) {
            $draft = $repository->findOneRegularBySlotAndHoraire(
                $fixture['slots'][$rank],
                $fixture['dates'][$rank]
            );

            self::assertInstanceOf(DiffusionDraft::class, $draft);

            self::assertSame(
                $originalDraftIds[$rank],
                $draft->getId()
            );

            self::assertSame(
                $fixture['replacementEmission']->getId(),
                $draft->getEmission()?->getId()
            );

            self::assertSame(
                $originalGroupKey,
                $draft->getAssignmentGroupKey()
            );
        }

        self::assertCount(
            3,
            $repository->findByAssignmentGroupKey($originalGroupKey)
        );
    }


    public function testRemoveDeletesThreeBroadcasts(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $draft = $repository->findOneRegularBySlotAndHoraire(
            $fixture['slots'][1],
            $fixture['dates'][1]
        );

        self::assertInstanceOf(DiffusionDraft::class, $draft);

        $groupKey = $draft->getAssignmentGroupKey();

        self::assertTrue(
            $this->assignmentService->remove(
                $fixture['slots'][1],
                $fixture['dates'][1]
            )
        );

        $this->entityManager->clear();

        foreach ([1, 2, 3] as $rank) {
            self::assertNull(
                $repository->findOneRegularBySlotAndHoraire(
                    $fixture['slots'][$rank],
                    $fixture['dates'][$rank]
                )
            );
        }

        self::assertCount(
            0,
            $repository->findByAssignmentGroupKey($groupKey)
        );
    }


    public function testAssignRejectsDirectRebroadcastAssignment(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        foreach ([2, 3] as $rank) {
            try {
                $this->assignmentService->assign(
                    $fixture['slots'][$rank],
                    $fixture['originalEmission'],
                    $fixture['dates'][$rank]
                );

                self::fail(
                    sprintf('L’affectation directe de R%d aurait dû être refusée.', $rank)
                );
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'L’affectation doit se faire sur la première diffusion. Les rediffusions sont générées automatiquement.',
                    $exception->getMessage()
                );
            }
        }

        $this->entityManager->clear();

        foreach ([1, 2, 3] as $rank) {
            self::assertNull(
                $repository->findOneRegularBySlotAndHoraire(
                    $fixture['slots'][$rank],
                    $fixture['dates'][$rank]
                )
            );
        }
    }

    private function createMixedWeeksFixture(): array
    {
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit semaines mixtes')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie semaines mixtes')
            ->setSlug('MIX')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Tests des semaines mixtes.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(14)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $rank1Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rank2Slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(1)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($rank1Slot);
        $rule->addSlot($rank2Slot);

        $originalEmission = (new Emission())
            ->setTitre('Émission initiale semaines mixtes')
            ->setKeyword('mixed-weeks-original')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-MIXED-ORIGINAL')
            ->setDuree(60)
            ->setDescriptif('Émission initiale.')
            ->setCategorie($category)
            ->setEditeur($editor);

        $replacementEmission = (new Emission())
            ->setTitre('Émission remplaçante semaines mixtes')
            ->setKeyword('mixed-weeks-replacement')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-MIXED-REPLACEMENT')
            ->setDuree(60)
            ->setDescriptif('Émission remplaçante.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $rule,
                $rank1Slot,
                $rank2Slot,
                $originalEmission,
                $replacementEmission,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $weekA = new \DateTimeImmutable('2099-08-04 14:00:00');
        $weekB = new \DateTimeImmutable('2099-08-11 14:00:00');

        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $originalEmission,
                $weekA
            )
        );

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $draftA = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank1Slot, $weekA);

        $draftB = $draftRepository
            ->findOneRegularBySlotAndHoraire($rank2Slot, $weekB);

        self::assertInstanceOf(DiffusionDraft::class, $draftA);
        self::assertInstanceOf(DiffusionDraft::class, $draftB);

        self::assertSame(
            $draftA->getAssignmentGroupKey(),
            $draftB->getAssignmentGroupKey()
        );

        /*
     * Seule la semaine A est publiée.
     * La semaine B conserve son Draft non publié.
     */
        $diffusionA = (new Diffusion())
            ->setEmission($originalEmission)
            ->setSchedule(\DateTime::createFromInterface($weekA), 60)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($draftA->getAssignmentGroupKey())
            ->markAsPublished();

        $this->entityManager->persist($diffusionA);

        $draftA->markAsPublished(
            $diffusionA,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        return [
            'rank1Slot' => $rank1Slot,
            'rank2Slot' => $rank2Slot,
            'weekA' => $weekA,
            'weekB' => $weekB,
            'draftAId' => $draftA->getId(),
            'draftBId' => $draftB->getId(),
            'diffusionAId' => $diffusionA->getId(),
            'originalEmissionId' => $originalEmission->getId(),
            'replacementEmissionId' => $replacementEmission->getId(),
            'replacementEmission' => $replacementEmission,
        ];
    }


    public function testAssignWithRescheduledR2AcrossWeeks(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $destination = new \DateTimeImmutable('2099-08-25 16:00:00');

        $this->createRescheduledArbitration(
            $fixture['slots'][2],
            $fixture['dates'][2],
            $destination
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $drafts = [];

        foreach ([1, 2, 3] as $rank) {
            $draft = $repository->findOneRegularBySlotAndHoraire(
                $fixture['slots'][$rank],
                $fixture['dates'][$rank]
            );

            self::assertInstanceOf(DiffusionDraft::class, $draft);
            self::assertSame($rank, $draft->getNombreDiffusion());

            $drafts[$rank] = $draft;
        }

        self::assertSame(
            $drafts[1]->getAssignmentGroupKey(),
            $drafts[2]->getAssignmentGroupKey()
        );

        self::assertSame(
            $drafts[1]->getAssignmentGroupKey(),
            $drafts[3]->getAssignmentGroupKey()
        );

        self::assertEquals(
            $fixture['dates'][2],
            $drafts[2]->getHoraireDiffusion()
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $drafts[2]->getPublicationStatus()
        );

        self::assertNull($drafts[2]->getPublishedDiffusion());
    }


    public function testReassignWithRescheduledR2PreservesDiffusion(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $destination = new \DateTimeImmutable('2099-08-25 16:00:00');

        $this->createRescheduledArbitration(
            $fixture['slots'][2],
            $fixture['dates'][2],
            $destination
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $draftR2 = $draftRepository->findOneRegularBySlotAndHoraire(
            $fixture['slots'][2],
            $fixture['dates'][2]
        );

        self::assertInstanceOf(DiffusionDraft::class, $draftR2);

        $diffusion = (new Diffusion())
            ->setEmission($fixture['originalEmission'])
            ->setSchedule(\DateTime::createFromInterface($destination), 60)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($draftR2->getAssignmentGroupKey())
            ->markAsPublished();

        $this->entityManager->persist($diffusion);

        $draftR2->markAsPublished(
            $diffusion,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        $draftId = $draftR2->getId();
        $diffusionId = $diffusion->getId();

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['replacementEmission'],
                $fixture['dates'][1]
            )
        );

        $this->entityManager->clear();

        $updatedDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $updatedDiffusion = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        self::assertInstanceOf(DiffusionDraft::class, $updatedDraft);
        self::assertInstanceOf(Diffusion::class, $updatedDiffusion);

        self::assertSame(
            $fixture['replacementEmission']->getId(),
            $updatedDraft->getEmission()?->getId()
        );

        self::assertSame(
            $fixture['replacementEmission']->getId(),
            $updatedDiffusion->getEmission()?->getId()
        );

        self::assertSame(
            $diffusionId,
            $updatedDraft->getPublishedDiffusion()?->getId()
        );

        self::assertTrue($updatedDiffusion->isPublished());

        self::assertEquals(
            $destination,
            \DateTimeImmutable::createFromInterface(
                $updatedDiffusion->getHoraireDiffusion()
            )
        );
    }


    public function testRemoveWithRescheduledR2PreservesHistoricalDiffusion(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $destination = new \DateTimeImmutable('2099-08-25 16:00:00');

        $this->createRescheduledArbitration(
            $fixture['slots'][2],
            $fixture['dates'][2],
            $destination
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $draftR2 = $repository->findOneRegularBySlotAndHoraire(
            $fixture['slots'][2],
            $fixture['dates'][2]
        );

        self::assertInstanceOf(DiffusionDraft::class, $draftR2);

        $diffusion = (new Diffusion())
            ->setEmission($fixture['originalEmission'])
            ->setSchedule(\DateTime::createFromInterface($destination), 60)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($draftR2->getAssignmentGroupKey())
            ->markAsPublished();

        $this->entityManager->persist($diffusion);

        $draftR2->markAsPublished(
            $diffusion,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        $draftId = $draftR2->getId();
        $diffusionId = $diffusion->getId();

        self::assertTrue(
            $this->assignmentService->remove(
                $fixture['slots'][1],
                $fixture['dates'][1]
            )
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->find($draftId)
        );

        $historicalDiffusion = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        self::assertInstanceOf(Diffusion::class, $historicalDiffusion);
        self::assertFalse($historicalDiffusion->isPublished());

        self::assertEquals(
            $destination,
            \DateTimeImmutable::createFromInterface(
                $historicalDiffusion->getHoraireDiffusion()
            )
        );
    }


    public function testAssignRollsBackWhenDestinationWeekRepublicationFails(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $destination = new \DateTimeImmutable('2099-08-25 16:00:00');

        $this->createRescheduledArbitration(
            $fixture['slots'][2],
            $fixture['dates'][2],
            $destination
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $draftR2 = $draftRepository->findOneRegularBySlotAndHoraire(
            $fixture['slots'][2],
            $fixture['dates'][2]
        );

        self::assertInstanceOf(DiffusionDraft::class, $draftR2);

        $diffusion = (new Diffusion())
            ->setEmission($fixture['originalEmission'])
            ->setSchedule(\DateTime::createFromInterface($destination), 60)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($draftR2->getAssignmentGroupKey())
            ->markAsPublished();

        $this->entityManager->persist($diffusion);

        $draftR2->markAsPublished(
            $diffusion,
            new \DateTimeImmutable('2099-08-01 12:00:00')
        );

        $this->entityManager->flush();

        $draftId = $draftR2->getId();
        $diffusionId = $diffusion->getId();

        $container = static::getContainer();

        $failingPopulator = new class implements PendingRebroadcastPopulatorInterface {
            public function populateFromPublishedDiffusions(
                array $diffusions,
                \DateTimeInterface $before
            ): array {
                throw new \RuntimeException(
                    'PHPUNIT: échec republication destination'
                );
            }
        };

        $publicationService = new GridPublicationService(
            $container->get(DiffusionDraftRepository::class),
            $container->get(DiffusionRepository::class),
            $container->get(GridSlotArbitrationRepository::class),
            $this->entityManager,
            $failingPopulator,
            $container->get(ClockInterface::class)
        );

        $assignmentService = new GridAssignmentService(
            $container->get(DiffusionDraftRepository::class),
            $container->get(PendingRebroadcastRepository::class),
            $container->get(GridSlotArbitrationRepository::class),
            $container->get(GridUnpublicationService::class),
            $publicationService,
            $this->entityManager
        );

        try {
            $assignmentService->assign(
                $fixture['slots'][1],
                $fixture['replacementEmission'],
                $fixture['dates'][1]
            );

            self::fail('La republication aurait dû échouer.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'PHPUNIT: échec republication destination',
                $exception->getMessage()
            );
        }

        $registry = $container->get('doctrine');
        $registry->resetManager();

        $freshEntityManager = $registry->getManager();

        $restoredDraft = $freshEntityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $restoredDiffusion = $freshEntityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        self::assertInstanceOf(DiffusionDraft::class, $restoredDraft);
        self::assertInstanceOf(Diffusion::class, $restoredDiffusion);

        self::assertSame(
            $fixture['originalEmission']->getId(),
            $restoredDraft->getEmission()?->getId()
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $restoredDraft->getPublicationStatus()
        );

        self::assertSame(
            $diffusionId,
            $restoredDraft->getPublishedDiffusion()?->getId()
        );

        self::assertTrue($restoredDiffusion->isPublished());

        self::assertSame(
            $fixture['originalEmission']->getId(),
            $restoredDiffusion->getEmission()?->getId()
        );
    }


    public function testAssignSameEmissionTwiceDoesNotCreateDuplicates(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $originalIds = [];
        $groupKey = null;

        foreach ([1, 2, 3] as $rank) {
            $draft = $repository->findOneRegularBySlotAndHoraire(
                $fixture['slots'][$rank],
                $fixture['dates'][$rank]
            );

            self::assertInstanceOf(DiffusionDraft::class, $draft);

            $originalIds[$rank] = $draft->getId();
            $groupKey ??= $draft->getAssignmentGroupKey();
        }

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $this->entityManager->clear();

        foreach ([1, 2, 3] as $rank) {
            $draft = $repository->findOneRegularBySlotAndHoraire(
                $fixture['slots'][$rank],
                $fixture['dates'][$rank]
            );

            self::assertInstanceOf(DiffusionDraft::class, $draft);

            self::assertSame($originalIds[$rank], $draft->getId());

            self::assertSame(
                $fixture['originalEmission']->getId(),
                $draft->getEmission()?->getId()
            );

            self::assertSame(
                $groupKey,
                $draft->getAssignmentGroupKey()
            );
        }

        self::assertCount(
            3,
            $repository->findByAssignmentGroupKey($groupKey)
        );
    }


    public function testAssignIgnoresInactiveSlots(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $fixture['slots'][2]->setIsActive(false);

        $this->entityManager->flush();

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $draftR1 = $repository->findOneRegularBySlotAndHoraire(
            $fixture['slots'][1],
            $fixture['dates'][1]
        );

        $draftR2 = $repository->findOneRegularBySlotAndHoraire(
            $fixture['slots'][2],
            $fixture['dates'][2]
        );

        $draftR3 = $repository->findOneRegularBySlotAndHoraire(
            $fixture['slots'][3],
            $fixture['dates'][3]
        );

        self::assertInstanceOf(DiffusionDraft::class, $draftR1);
        self::assertNull($draftR2);
        self::assertInstanceOf(DiffusionDraft::class, $draftR3);

        self::assertSame(
            $draftR1->getAssignmentGroupKey(),
            $draftR3->getAssignmentGroupKey()
        );

        self::assertCount(
            2,
            $repository->findByAssignmentGroupKey(
                $draftR1->getAssignmentGroupKey()
            )
        );
    }


    public function testRemoveSameAssignmentTwiceReturnsFalse(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        self::assertTrue(
            $this->assignmentService->remove(
                $fixture['slots'][1],
                $fixture['dates'][1]
            )
        );

        self::assertFalse(
            $this->assignmentService->remove(
                $fixture['slots'][1],
                $fixture['dates'][1]
            )
        );

        $this->entityManager->clear();

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        foreach ([1, 2, 3] as $rank) {
            self::assertNull(
                $repository->findOneRegularBySlotAndHoraire(
                    $fixture['slots'][$rank],
                    $fixture['dates'][$rank]
                )
            );
        }
    }


    public function testAssignIgnoresCancelledArbitration(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $destination = new \DateTimeImmutable('2099-08-25 16:00:00');

        $arbitration = $this->createRescheduledArbitration(
            $fixture['slots'][2],
            $fixture['dates'][2],
            $destination
        );

        $arbitration->markCancelled();

        $this->entityManager->flush();

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $draftR2 = $repository->findOneRegularBySlotAndHoraire(
            $fixture['slots'][2],
            $fixture['dates'][2]
        );

        self::assertInstanceOf(DiffusionDraft::class, $draftR2);

        self::assertEquals(
            $fixture['dates'][2],
            $draftR2->getHoraireDiffusion()
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $draftR2->getPublicationStatus()
        );

        self::assertNull($draftR2->getPublishedDiffusion());

        self::assertSame(
            GridSlotArbitration::STATUS_CANCELLED,
            $arbitration->getStatus()
        );
    }


    public function testAssignRejectsWeekThatCannotBeUnpublished(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        self::assertTrue(
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['originalEmission'],
                $fixture['dates'][1]
            )
        );

        $draftIds = [];

        foreach ([1, 2, 3] as $rank) {
            $draft = $draftRepository->findOneRegularBySlotAndHoraire(
                $fixture['slots'][$rank],
                $fixture['dates'][$rank]
            );

            self::assertInstanceOf(DiffusionDraft::class, $draft);

            $draftIds[$rank] = $draft->getId();
        }

        /*
     * Diffusion historique publiée sans Draft lié.
     * Elle rend la semaine A impossible à dévalider.
     */
        $legacyDiffusion = (new Diffusion())
            ->setEmission($fixture['originalEmission'])
            ->setSchedule(
                new \DateTime('2099-08-04 18:00:00'),
                60
            )
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('PHPUNIT-LEGACY-WITHOUT-DRAFT')
            ->markAsPublished();

        $this->entityManager->persist($legacyDiffusion);
        $this->entityManager->flush();

        $legacyDiffusionId = $legacyDiffusion->getId();
        $originalEmissionId = $fixture['originalEmission']->getId();

        $preview = static::getContainer()
            ->get(GridUnpublicationService::class)
            ->previewWeek($fixture['dates'][1]);

        self::assertSame(1, $preview['publishedDiffusionCount']);
        self::assertSame(1, $preview['missingDraftCount']);
        self::assertFalse($preview['canUnpublish']);
        self::assertSame(
            'legacy_week_without_drafts',
            $preview['reason']
        );

        try {
            $this->assignmentService->assign(
                $fixture['slots'][1],
                $fixture['replacementEmission'],
                $fixture['dates'][1]
            );

            self::fail(
                'L’affectation aurait dû être refusée.'
            );
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'ne peut pas être dévalidée automatiquement',
                $exception->getMessage()
            );
        }

        /*
     * wrapInTransaction ferme l'EntityManager
     * lorsqu'une exception est levée.
     *
     * On recharge donc les données depuis la BDD.
     */
        $registry = static::getContainer()->get('doctrine');
        $registry->resetManager();

        $freshEntityManager = $registry->getManager();

        foreach ([1, 2, 3] as $rank) {
            $draft = $freshEntityManager
                ->getRepository(DiffusionDraft::class)
                ->find($draftIds[$rank]);

            self::assertInstanceOf(DiffusionDraft::class, $draft);

            self::assertSame(
                $originalEmissionId,
                $draft->getEmission()?->getId()
            );

            self::assertSame(
                DiffusionDraft::STATUS_DRAFT,
                $draft->getPublicationStatus()
            );
        }

        $restoredLegacyDiffusion = $freshEntityManager
            ->getRepository(Diffusion::class)
            ->find($legacyDiffusionId);

        self::assertInstanceOf(
            Diffusion::class,
            $restoredLegacyDiffusion
        );

        self::assertTrue(
            $restoredLegacyDiffusion->isPublished()
        );

        self::assertSame(
            $originalEmissionId,
            $restoredLegacyDiffusion->getEmission()?->getId()
        );
    }


    public function testAssignThrowsWhenSlotHasNoRule(): void
    {
        $slot = new ProgrammationRuleSlot();
        $emission = new Emission();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Règle introuvable.');

        $this->assignmentService->assign(
            $slot,
            $emission,
            new \DateTimeImmutable('2099-08-04 14:00:00')
        );
    }



    public function testRemovePreservesDismissedPendingAndDeletesActivePendingForAssignedGroup(): void
    {
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit pending cleanup')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie PHPUnit pending cleanup')
            ->setSlug('PPC')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Test de suppression des PendingRebroadcast.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(11)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-10'))
            ->setIsActive(true);

        $slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($slot);

        $emission = (new Emission())
            ->setTitre('Émission PHPUnit pending cleanup')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-08-04 12:00:00'))
            ->setRef('PHPUNIT-PENDING-CLEANUP')
            ->setDuree(60)
            ->setDescriptif('Émission de test du nettoyage des Pending.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach ([$editor, $category, $rule, $slot, $emission] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $selectedDate = new \DateTimeImmutable('2099-08-04 14:00:00');

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emission,
                $selectedDate
            )
        );

        $draftRepository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        $pendingRepository = static::getContainer()
            ->get(PendingRebroadcastRepository::class);

        $draft = $draftRepository->findOneRegularBySlotAndHoraire(
            $slot,
            $selectedDate
        );

        self::assertInstanceOf(DiffusionDraft::class, $draft);

        $groupKey = $draft->getAssignmentGroupKey();

        self::assertNotNull($groupKey);

        /*
     * Deux Pending actifs appartenant au groupe supprimé.
     * Ils doivent disparaître lors de remove().
     */
        $pendingA = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $pendingB = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        /*
     * Un Pending du même groupe a été volontairement exclu.
     * Son statut dismissed doit être conservé.
     */
        $pendingDismissed = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $pendingDismissed->dismiss();

        /*
     * Un Pending appartenant à un autre groupe.
     * Il ne doit pas être affecté.
     */
        $otherGroupKey = 'rule_other_origin_20990804_1400';

        $unrelatedPending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($otherGroupKey);

        foreach (
            [
                $pendingA,
                $pendingB,
                $pendingDismissed,
                $unrelatedPending,
            ] as $pending
        ) {
            $this->entityManager->persist($pending);
        }

        $this->entityManager->flush();

        $pendingAId = $pendingA->getId();
        $pendingBId = $pendingB->getId();
        $pendingDismissedId = $pendingDismissed->getId();
        $unrelatedPendingId = $unrelatedPending->getId();

        /*
     * PRÉCONDITIONS
     */
        self::assertCount(
            3,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $groupKey,
            ])
        );

        self::assertTrue($pendingDismissed->isDismissed());
        self::assertTrue($pendingA->isPending());
        self::assertTrue($pendingB->isPending());

        /*
     * ACTION : suppression de l'affectation.
     */
        self::assertTrue(
            $this->assignmentService->remove(
                $slot,
                $selectedDate
            )
        );

        $this->clearEntityManager();

        /*
     * Les deux Pending actifs du groupe ont disparu.
     */
        self::assertNull(
            $pendingRepository->find($pendingAId)
        );

        self::assertNull(
            $pendingRepository->find($pendingBId)
        );

        /*
     * Le Pending dismissed est toujours en base.
     */
        $preservedDismissed = $pendingRepository->find(
            $pendingDismissedId
        );

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $preservedDismissed
        );

        self::assertTrue(
            $preservedDismissed->isDismissed()
        );

        /*
     * Il ne reste que l'exclusion volontaire
     * dans le groupe supprimé.
     */
        $remainingPending = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        self::assertCount(1, $remainingPending);

        self::assertSame(
            $pendingDismissedId,
            $remainingPending[0]->getId()
        );

        /*
     * Le Pending de l'autre groupe est intact.
     */
        $unrelatedAfterRemoval = $pendingRepository->find(
            $unrelatedPendingId
        );

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $unrelatedAfterRemoval
        );

        self::assertTrue(
            $unrelatedAfterRemoval->isPending()
        );

        self::assertCount(
            1,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $otherGroupKey,
            ])
        );

        /*
     * Le groupe de Drafts a bien été supprimé.
     */
        self::assertCount(
            0,
            $draftRepository->findByAssignmentGroupKey($groupKey)
        );
    }


    public function testAssignThrowsWhenRuleHasNoId(): void
    {
        $rule = new ProgrammationRule();

        $slot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setBroadcastRank(1);

        $emission = new Emission();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Impossible de générer une clé de groupe sans ID de règle.'
        );

        $this->assignmentService->assign(
            $slot,
            $emission,
            new \DateTimeImmutable('2099-08-04 14:00:00')
        );
    }

    public function testAssignCreatesPendingRebroadcastWhenRuleRequestsAdditionalRebroadcast(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $rank1Slot = $fixture['slots'][1];
        $selectedDate = $fixture['dates'][1];
        $emission = $fixture['originalEmission'];
        $rule = $rank1Slot->getRule();

        self::assertInstanceOf(ProgrammationRule::class, $rule);

        // La règle demande une rediffusion supplémentaire.
        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        // Affectation de l'émission en R1.
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emission,
                $selectedDate
            )
        );

        $expectedGroupKey = sprintf(
            'rule_%d_origin_%s',
            $rule->getId(),
            $selectedDate->format('Ymd_Hi')
        );

        $emissionId = $emission->getId();

        $this->clearEntityManager();

        // Un Pending doit être créé immédiatement.
        $pendingRebroadcasts = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy([
                'assignmentGroupKey' => $expectedGroupKey,
            ]);

        self::assertCount(1, $pendingRebroadcasts);

        self::assertSame(
            $emissionId,
            $pendingRebroadcasts[0]->getEmission()?->getId()
        );
    }

    public function testReassignUpdatesPendingRebroadcastWithoutDuplicates(): void
    {
        /*
     * ==========================================================
     * 1. PRÉPARATION
     * ==========================================================
     */
        $fixture = $this->createThreeBroadcastsFixture();

        $rank1Slot = $fixture['slots'][1];
        $selectedDate = $fixture['dates'][1];

        $emissionA = $fixture['originalEmission'];
        $emissionB = $fixture['replacementEmission'];

        $rule = $rank1Slot->getRule();

        self::assertInstanceOf(ProgrammationRule::class, $rule);

        $rule->setHasAdditionalRebroadcast(true);

        $this->entityManager->flush();

        $emissionAId = $emissionA->getId();
        $emissionBId = $emissionB->getId();

        $assignmentGroupKey = sprintf(
            'rule_%d_origin_%s',
            $rule->getId(),
            $selectedDate->format('Ymd_Hi')
        );

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        /*
     * ==========================================================
     * 2. PREMIÈRE AFFECTATION : ÉMISSION A
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emissionA,
                $selectedDate
            )
        );

        $pendingBefore = $pendingRepository->findBy([
            'assignmentGroupKey' => $assignmentGroupKey,
        ]);

        self::assertCount(1, $pendingBefore);

        self::assertSame(
            $emissionAId,
            $pendingBefore[0]->getEmission()?->getId()
        );

        $pendingId = $pendingBefore[0]->getId();

        /*
     * ==========================================================
     * 3. REMPLACEMENT : ÉMISSION B
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emissionB,
                $selectedDate
            )
        );

        $this->clearEntityManager();

        /*
     * ==========================================================
     * 4. VÉRIFIER LE PENDING
     * ==========================================================
     */
        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $pendingAfter = $pendingRepository->findBy([
            'assignmentGroupKey' => $assignmentGroupKey,
        ]);

        // Un seul Pending pour ce groupe.
        self::assertCount(1, $pendingAfter);

        // Le Pending concerne maintenant B.
        self::assertSame(
            $emissionBId,
            $pendingAfter[0]->getEmission()?->getId()
        );

        // On a réutilisé le Pending existant.
        self::assertSame(
            $pendingId,
            $pendingAfter[0]->getId()
        );

        // Aucun Pending de A ne subsiste dans ce groupe.
        self::assertCount(
            0,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $assignmentGroupKey,
                'emission' => $emissionAId,
            ])
        );

        /*
     * ==========================================================
     * 5. VÉRIFIER LES DRAFTS R1/R2/R3
     * ==========================================================
     */
        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $drafts = $draftRepository->findBy([
            'assignmentGroupKey' => $assignmentGroupKey,
        ]);

        self::assertCount(3, $drafts);

        foreach ($drafts as $draft) {
            self::assertSame(
                $emissionBId,
                $draft->getEmission()?->getId()
            );
        }
    }

    public function testReassignPropagatesEmissionToPlacedManualRebroadcast(): void
    {
        /*
     * ==========================================================
     * 1. PRÉPARATION
     * ==========================================================
     */
        $fixture = $this->createThreeBroadcastsFixture();

        $rank1Slot = $fixture['slots'][1];
        $selectedDate = $fixture['dates'][1];

        $emissionA = $fixture['originalEmission'];
        $emissionB = $fixture['replacementEmission'];

        $rule = $rank1Slot->getRule();

        self::assertInstanceOf(ProgrammationRule::class, $rule);

        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        $emissionAId = $emissionA->getId();
        $emissionBId = $emissionB->getId();

        $assignmentGroupKey = sprintf(
            'rule_%d_origin_%s',
            $rule->getId(),
            $selectedDate->format('Ymd_Hi')
        );

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $placementService = static::getContainer()
            ->get(RegularRebroadcastPlacementService::class);

        /*
     * ==========================================================
     * 2. AFFECTATION DE A
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emissionA,
                $selectedDate
            )
        );

        $pending = $pendingRepository->findOneBy([
            'assignmentGroupKey' => $assignmentGroupKey,
        ]);

        self::assertInstanceOf(PendingRebroadcast::class, $pending);

        self::assertSame(
            $emissionAId,
            $pending->getEmission()?->getId()
        );

        /*
     * ==========================================================
     * 3. PLACEMENT DE LA REDIFFUSION SUPPLÉMENTAIRE
     * ==========================================================
     *
     * R3 : 18 août 2099 à 14 h.
     *
     * On place la rediffusion supplémentaire le lendemain,
     * à 18 h, après toutes les diffusions régulières.
     */
        $manualStartsAt = new \DateTimeImmutable(
            '2099-08-19 18:00:00'
        );

        $manualDraft = $placementService->place(
            $pending,
            $manualStartsAt
        );

        self::assertInstanceOf(DiffusionDraft::class, $manualDraft);

        self::assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $manualDraft->getDraftType()
        );

        self::assertSame(
            $emissionAId,
            $manualDraft->getEmission()?->getId()
        );

        self::assertSame(
            $assignmentGroupKey,
            $manualDraft->getAssignmentGroupKey()
        );

        $manualDraftId = $manualDraft->getId();
        $manualRank = $manualDraft->getNombreDiffusion();

        self::assertNotNull($manualDraftId);

        // Le Pending a été consommé lors du placement.
        self::assertCount(
            0,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $assignmentGroupKey,
            ])
        );

        /*
     * ==========================================================
     * 4. REMPLACEMENT DE A PAR B
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emissionB,
                $selectedDate
            )
        );

        $this->clearEntityManager();

        /*
     * ==========================================================
     * 5. VÉRIFICATION DE LA REDIFFUSION SUPPLÉMENTAIRE
     * ==========================================================
     */
        $manualDraftAfter = $draftRepository->find($manualDraftId);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $manualDraftAfter
        );

        // Le Draft existant est conservé.
        self::assertSame(
            $manualDraftId,
            $manualDraftAfter->getId()
        );

        // Son émission est désormais B.
        self::assertSame(
            $emissionBId,
            $manualDraftAfter->getEmission()?->getId()
        );

        // Le type de diffusion ne change pas.
        self::assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $manualDraftAfter->getDraftType()
        );

        // Le créneau reste identique.
        self::assertEquals(
            $manualStartsAt,
            $manualDraftAfter->getHoraireDiffusion()
        );

        // Le rang est conservé.
        self::assertSame(
            $manualRank,
            $manualDraftAfter->getNombreDiffusion()
        );

        // Le groupe reste identique.
        self::assertSame(
            $assignmentGroupKey,
            $manualDraftAfter->getAssignmentGroupKey()
        );

        /*
     * ==========================================================
     * 6. VÉRIFICATION DES DRAFTS R1/R2/R3
     * ==========================================================
     */
        $groupDrafts = $draftRepository->findByAssignmentGroupKey(
            $assignmentGroupKey
        );

        self::assertCount(4, $groupDrafts);

        foreach ($groupDrafts as $draft) {
            self::assertSame(
                $emissionBId,
                $draft->getEmission()?->getId()
            );
        }

        /*
     * ==========================================================
     * 7. AUCUN NOUVEAU PENDING
     * ==========================================================
     *
     * La rediffusion supplémentaire est déjà placée.
     * Le remplacement ne doit pas en recréer une.
     */
        self::assertCount(
            0,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $assignmentGroupKey,
            ])
        );
    }

    public function testReassignPreservesPublishedManualRebroadcastDiffusion(): void
    {
        /*
     * ==========================================================
     * 1. PRÉPARATION
     * ==========================================================
     */
        $fixture = $this->createThreeBroadcastsFixture();

        $rank1Slot = $fixture['slots'][1];
        $selectedDate = $fixture['dates'][1];

        $emissionA = $fixture['originalEmission'];
        $emissionB = $fixture['replacementEmission'];

        $rule = $rank1Slot->getRule();

        self::assertInstanceOf(ProgrammationRule::class, $rule);

        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        $emissionAId = $emissionA->getId();
        $emissionBId = $emissionB->getId();

        $rank1SlotId = $rank1Slot->getId();

        self::assertNotNull($rank1SlotId);
        self::assertNotNull($emissionBId);

        $assignmentGroupKey = sprintf(
            'rule_%d_origin_%s',
            $rule->getId(),
            $selectedDate->format('Ymd_Hi')
        );

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $diffusionRepository = $this->entityManager
            ->getRepository(Diffusion::class);

        $placementService = static::getContainer()
            ->get(RegularRebroadcastPlacementService::class);

        $publicationService = static::getContainer()
            ->get(GridPublicationService::class);

        /*
     * ==========================================================
     * 2. AFFECTER A À LA RÈGLE
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emissionA,
                $selectedDate
            )
        );

        $pending = $pendingRepository->findOneBy([
            'assignmentGroupKey' => $assignmentGroupKey,
        ]);

        self::assertInstanceOf(PendingRebroadcast::class, $pending);

        /*
     * ==========================================================
     * 3. PLACER LA REDIFFUSION SUPPLÉMENTAIRE
     * ==========================================================
     */
        $manualStartsAt = new \DateTimeImmutable(
            '2099-08-19 18:00:00'
        );

        $manualDraft = $placementService->place(
            $pending,
            $manualStartsAt
        );

        $manualDraftId = $manualDraft->getId();
        $manualRank = $manualDraft->getNombreDiffusion();

        self::assertNotNull($manualDraftId);

        self::assertSame(
            $emissionAId,
            $manualDraft->getEmission()?->getId()
        );

        self::assertCount(
            0,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $assignmentGroupKey,
            ])
        );

        /*
     * ==========================================================
     * 4. PUBLIER LA SEMAINE DE LA REDIFFUSION
     * ==========================================================
     *
     * On utilise le début de semaine radio pour publier
     * le créneau manuel.
     *
     * La date du 19 août 2099 appartient à la semaine
     * radio commençant le 17 août 2099.
     */
        $manualWeekStart = $manualStartsAt
            ->setTime(0, 0, 0)
            ->modify(
                sprintf(
                    '-%d days',
                    ((int) $manualStartsAt->format('N') + 5) % 7
                )
            );
        $publicationPreview = $publicationService
            ->previewWeekPublication($manualWeekStart);

        self::assertFalse(
            $publicationPreview['hasBlockingConflicts']
        );

        self::assertTrue(
            $publicationPreview['canPublish']
        );

        $publicationService->applyWeekPublication(
            $manualWeekStart
        );

        $this->entityManager->flush();

        /*
     * ==========================================================
     * 5. MÉMORISER LA DIFFUSION HISTORIQUE
     * ==========================================================
     */
        $this->clearEntityManager();

        $manualDraftPublished = $draftRepository->find(
            $manualDraftId
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $manualDraftPublished
        );

        $historicalDiffusion = $manualDraftPublished
            ->getPublishedDiffusion();

        self::assertInstanceOf(
            Diffusion::class,
            $historicalDiffusion
        );

        $historicalDiffusionId = $historicalDiffusion->getId();

        self::assertNotNull($historicalDiffusionId);

        self::assertSame(
            $emissionAId,
            $historicalDiffusion->getEmission()?->getId()
        );

        /*
     * ==========================================================
     * 6. REMPLACER A PAR B
     * ==========================================================
     */
        /*
        * Après clearEntityManager(), les entités de la fixture
        * sont détachées. On les recharge pour que Doctrine
        * puisse gérer correctement leurs associations.
        */
        $rank1SlotManaged = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($rank1SlotId);

        $emissionBManaged = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionBId);

        self::assertInstanceOf(
            ProgrammationRuleSlot::class,
            $rank1SlotManaged
        );

        self::assertInstanceOf(
            Emission::class,
            $emissionBManaged
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $rank1SlotManaged,
                $emissionBManaged,
                $selectedDate
            )
        );

        $this->clearEntityManager();

        /*
     * ==========================================================
     * 7. VÉRIFIER LE DRAFT MANUEL
     * ==========================================================
     */
        $manualDraftAfter = $draftRepository->find(
            $manualDraftId
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $manualDraftAfter
        );

        self::assertSame(
            $manualDraftId,
            $manualDraftAfter->getId()
        );

        self::assertSame(
            $emissionBId,
            $manualDraftAfter->getEmission()?->getId()
        );

        self::assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $manualDraftAfter->getDraftType()
        );

        self::assertEquals(
            $manualStartsAt,
            $manualDraftAfter->getHoraireDiffusion()
        );

        self::assertSame(
            $manualRank,
            $manualDraftAfter->getNombreDiffusion()
        );

        /*
     * ==========================================================
     * 8. VÉRIFIER LA DIFFUSION HISTORIQUE
     * ==========================================================
     */
        $diffusionAfter = $diffusionRepository->find(
            $historicalDiffusionId
        );

        self::assertInstanceOf(
            Diffusion::class,
            $diffusionAfter
        );

        // L'identifiant historique est conservé.
        self::assertSame(
            $historicalDiffusionId,
            $diffusionAfter->getId()
        );

        // La Diffusion publiée référence désormais B.
        self::assertSame(
            $emissionBId,
            $diffusionAfter->getEmission()?->getId()
        );

        // Le Draft conserve son lien historique.
        self::assertSame(
            $historicalDiffusionId,
            $manualDraftAfter->getPublishedDiffusion()?->getId()
        );

        // Le créneau publié reste identique.
        self::assertEquals(
            $manualStartsAt,
            $diffusionAfter->getHoraireDiffusion()
        );

        /*
     * ==========================================================
     * 9. VÉRIFIER LES QUATRE DRAFTS DU GROUPE
     * ==========================================================
     */
        $groupDrafts = $draftRepository
            ->findByAssignmentGroupKey($assignmentGroupKey);

        self::assertCount(4, $groupDrafts);

        foreach ($groupDrafts as $draft) {
            self::assertSame(
                $emissionBId,
                $draft->getEmission()?->getId()
            );
        }

        /*
     * ==========================================================
     * 10. VÉRIFIER L'ABSENCE DE NOUVEAU PENDING
     * ==========================================================
     */
        self::assertCount(
            0,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $assignmentGroupKey,
            ])
        );
    }

    public function testPublicationDoesNotCreatePendingWhenRuleDoesNotRequestAdditionalRebroadcast(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $rank1Slot = $fixture['slots'][1];
        $selectedDate = $fixture['dates'][1];
        $emission = $fixture['originalEmission'];
        $rule = $rank1Slot->getRule();

        self::assertInstanceOf(ProgrammationRule::class, $rule);

        // La règle ne demande aucune rediffusion supplémentaire.
        $rule->setHasAdditionalRebroadcast(false);
        $this->entityManager->flush();

        // Affectation de l'émission au créneau R1.
        self::assertTrue(
            $this->assignmentService->assign(
                $rank1Slot,
                $emission,
                $selectedDate
            )
        );

        $expectedGroupKey = sprintf(
            'rule_%d_origin_%s',
            $rule->getId(),
            $selectedDate->format('Ymd_Hi')
        );

        // Aucun Pending ne doit être créé lors de l'affectation.
        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        self::assertCount(
            0,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $expectedGroupKey,
            ])
        );

        // Calcul du mardi de début de semaine radio.
        $weekStart = $selectedDate
            ->setTime(0, 0, 0)
            ->modify(sprintf(
                '-%d days',
                ((int) $selectedDate->format('N') + 5) % 7
            ));

        // Publication de la semaine contenant R1.
        $publicationService = static::getContainer()
            ->get(GridPublicationService::class);

        $preview = $publicationService->previewWeekPublication($weekStart);

        self::assertTrue($preview['canPublish']);

        $publicationService->applyWeekPublication($weekStart);
        $this->entityManager->flush();

        $this->clearEntityManager();

        // La publication ne doit pas contourner l'option de la règle.
        $pendingRebroadcasts = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy([
                'assignmentGroupKey' => $expectedGroupKey,
            ]);

        self::assertCount(
            0,
            $pendingRebroadcasts,
            'Une règle sans rediffusion supplémentaire ne doit pas générer de Pending lors de la publication.'
        );
    }

    public function testAssignExceptionalEmissionDoesNotCreatePendingWithoutAdditionalOption(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $rule = $fixture['rule'];
        $slot = $fixture['slots'][1];
        $date = $fixture['dates'][1];
        $emission = $fixture['exceptionalEmission'];

        self::assertFalse($rule->hasAdditionalRebroadcast());

        self::assertNotSame(
            $rule->getCategory()?->getId(),
            $emission->getCategorie()?->getId()
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emission,
                $date
            )
        );

        $groupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $rule->getId()
        );

        $drafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(3, $drafts);

        foreach ($drafts as $draft) {
            self::assertSame(
                $emission->getId(),
                $draft->getEmission()?->getId()
            );
        }

        $pending = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy([
                'assignmentGroupKey' => $groupKey,
            ]);

        self::assertCount(0, $pending, 'An exceptional assignment must not bypass the disabled option.');
    }

    public function testAssignExceptionalEmissionWithAdditionalOptionCreatesOnlyOnePending(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $rule = $fixture['rule'];
        $slot = $fixture['slots'][1];
        $date = $fixture['dates'][1];
        $emission = $fixture['exceptionalEmission'];

        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        self::assertTrue($rule->hasAdditionalRebroadcast());

        self::assertNotSame(
            $rule->getCategory()?->getId(),
            $emission->getCategorie()?->getId()
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emission,
                $date
            )
        );

        $groupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $rule->getId()
        );

        $drafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(3, $drafts);

        foreach ($drafts as $draft) {
            self::assertSame(
                $emission->getId(),
                $draft->getEmission()?->getId()
            );
        }

        $pending = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy([
                'assignmentGroupKey' => $groupKey,
            ]);

        self::assertCount(1, $pending);

        self::assertSame(
            $emission->getId(),
            $pending[0]->getEmission()?->getId()
        );
    }

    public function testReassignToExceptionalWithoutOptionPreservesDraftsWithoutPending(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $rule = $fixture['rule'];
        $slot = $fixture['slots'][1];
        $date = $fixture['dates'][1];

        $originalEmission = $fixture['originalEmission'];
        $exceptionalEmission = $fixture['exceptionalEmission'];

        self::assertFalse($rule->hasAdditionalRebroadcast());

        $groupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $rule->getId()
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $originalEmission,
                $date
            )
        );

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftIdsBefore = [];

        foreach ($fixture['slots'] as $rank => $rankSlot) {
            $draft = $draftRepository->findOneRegularBySlotAndHoraire(
                $rankSlot,
                $fixture['dates'][$rank]
            );

            self::assertInstanceOf(DiffusionDraft::class, $draft);

            $draftIdsBefore[$rank] = $draft->getId();
        }

        self::assertCount(
            0,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $groupKey,
            ])
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $exceptionalEmission,
                $date
            )
        );

        $this->clearEntityManager();

        $drafts = $draftRepository->findByAssignmentGroupKey($groupKey);

        self::assertCount(3, $drafts);

        foreach ($drafts as $draft) {
            self::assertContains(
                $draft->getId(),
                $draftIdsBefore
            );

            self::assertSame(
                $exceptionalEmission->getId(),
                $draft->getEmission()?->getId()
            );
        }

        $pending = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        self::assertCount(0, $pending, 'An exceptional assignment must not bypass the disabled option.');
    }


    public function testNewRegularEmissionWithoutOptionHasNoPendingBeforeOrAfterPartialPublication(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();
        $rule = $fixture['rule'];
        $emission = $fixture['originalEmission'];
        $rule->setHasAdditionalRebroadcast(false);
        $fixture['slots'][2]->setWeekOffset(1);
        $fixture['slots'][3]->setWeekOffset(2);
        $this->entityManager->flush();
        self::assertCount(0, $this->entityManager->getRepository(Diffusion::class)->findBy(['emission' => $emission]));
        self::assertTrue($this->assignmentService->assign($fixture['slots'][1], $emission, $fixture['dates'][1]));
        $key = sprintf('rule_%d_origin_%s', $rule->getId(), $fixture['dates'][1]->format('Ymd_Hi'));
        $pendingRepository = $this->entityManager->getRepository(PendingRebroadcast::class);
        self::assertCount(0, $pendingRepository->findBy(['assignmentGroupKey' => $key]));
        $publication = static::getContainer()->get(GridPublicationService::class);
        $weekStart = $fixture['dates'][1]->setTime(0, 0);
        self::assertTrue($publication->previewWeekPublication($weekStart)['canPublish']);
        $publication->publishWeek($weekStart);
        $this->clearEntityManager();
        $diffusions = $this->entityManager->getRepository(Diffusion::class)->findBy([
            'assignmentGroupKey' => $key,
            'publicationStatus' => Diffusion::STATUS_PUBLISHED,
        ]);
        self::assertCount(1, $diffusions, 'Only R1 is published; future regular ranks are still drafts.');
        self::assertCount(0, $pendingRepository->findBy(['assignmentGroupKey' => $key]), 'A partial regular publication must not create a pending.');
    }

    public function testReassignWithoutOptionDoesNotTransferLegacyPendingToExceptionalEmission(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();
        $rule = $fixture['rule'];
        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();
        $this->assignmentService->assign($fixture['slots'][1], $fixture['originalEmission'], $fixture['dates'][1]);
        $key = sprintf('rule_%d_origin_%s', $rule->getId(), $fixture['dates'][1]->format('Ymd_Hi'));
        $repository = $this->entityManager->getRepository(PendingRebroadcast::class);
        self::assertCount(1, $repository->findBy(['assignmentGroupKey' => $key, 'status' => PendingRebroadcast::STATUS_PENDING]));
        $rule->setHasAdditionalRebroadcast(false);
        $this->entityManager->flush();
        $this->assignmentService->assign($fixture['slots'][1], $fixture['exceptionalEmission'], $fixture['dates'][1]);
        $this->clearEntityManager();
        self::assertCount(0, $repository->findBy(['assignmentGroupKey' => $key, 'status' => PendingRebroadcast::STATUS_PENDING]), 'An ineligible regular group must not transfer an active pending.');
    }

    private function createThreeBroadcastsFixture(): array
    {
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit R123')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie R123')
            ->setSlug('RBT')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Tests affectations R1 R2 R3.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $exceptionalCategory = (new Categories())
            ->setTitre('Catégorie exceptionnelle R123')
            ->setSlug('RBE')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Catégorie différente de celle de la règle.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(15)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-09-30'))
            ->setIsActive(true);

        $slots = [];

        foreach ([1, 2, 3] as $rank) {
            $slot = (new ProgrammationRuleSlot())
                ->setDayOfWeek(2)
                ->setStartTime(new \DateTimeImmutable('14:00:00'))
                ->setDurationMinutes(60)
                ->setBroadcastRank($rank)
                ->setWeekOffset($rank - 1)
                ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
                ->setIsActive(true);

            $rule->addSlot($slot);
            $slots[$rank] = $slot;
        }

        $originalEmission = (new Emission())
            ->setTitre('Émission originale R123')
            ->setKeyword('r123-original')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-R123-ORIGINAL')
            ->setDuree(60)
            ->setDescriptif('Émission originale.')
            ->setCategorie($category)
            ->setEditeur($editor);

        $replacementEmission = (new Emission())
            ->setTitre('Émission remplaçante R123')
            ->setKeyword('r123-replacement')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-R123-REPLACEMENT')
            ->setDuree(60)
            ->setDescriptif('Émission remplaçante.')
            ->setCategorie($category)
            ->setEditeur($editor);

        $exceptionalEmission = (new Emission())
            ->setTitre('Émission exceptionnelle R123')
            ->setKeyword('r123-exceptional')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-R123-EXCEPTIONAL')
            ->setDuree(60)
            ->setDescriptif('Émission affectée sur une règle d’une autre catégorie.')
            ->setCategorie($exceptionalCategory)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $exceptionalCategory,
                $rule,
                ...array_values($slots),
                $originalEmission,
                $replacementEmission,
                $exceptionalEmission,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        return [
            'rule' => $rule,
            'category' => $category,
            'exceptionalCategory' => $exceptionalCategory,
            'slots' => $slots,
            'dates' => [
                1 => new \DateTimeImmutable('2099-08-04 14:00:00'),
                2 => new \DateTimeImmutable('2099-08-11 14:00:00'),
                3 => new \DateTimeImmutable('2099-08-18 14:00:00'),
            ],
            'originalEmission' => $originalEmission,
            'replacementEmission' => $replacementEmission,
            'exceptionalEmission' => $exceptionalEmission,
        ];
    }

    public function testReassignExceptionalToNormalRemovesUnconsumedPending(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $rule = $fixture['rule'];
        $slot = $fixture['slots'][1];
        $date = $fixture['dates'][1];

        $exceptionalEmission = $fixture['exceptionalEmission'];
        $normalEmission = $fixture['originalEmission'];

        self::assertFalse($rule->hasAdditionalRebroadcast());

        $groupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $rule->getId()
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $exceptionalEmission,
                $date
            )
        );

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $pending = $pendingRepository->findOneBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        if ($pending === null) {
            $pending = (new PendingRebroadcast())
                ->setEmission($exceptionalEmission)
                ->setAssignmentGroupKey($groupKey);

            $this->entityManager->persist($pending);
            $this->entityManager->flush();
        }

        $pendingId = $pending->getId();

        self::assertNotNull($pendingId);

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $normalEmission,
                $date
            )
        );

        $this->clearEntityManager();

        $drafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(3, $drafts);

        foreach ($drafts as $draft) {
            self::assertSame(
                $normalEmission->getId(),
                $draft->getEmission()?->getId()
            );
        }

        $pendingAfter = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        self::assertCount(0, $pendingAfter);

        self::assertNull(
            $pendingRepository->find($pendingId)
        );
    }

    public function testRepeatedExceptionalAssignmentKeepsSinglePendingAndSameId(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $rule = $fixture['rule'];
        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();
        $slot = $fixture['slots'][1];
        $date = $fixture['dates'][1];
        $emission = $fixture['exceptionalEmission'];

        $groupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $rule->getId()
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emission,
                $date
            )
        );

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $pendingBefore = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        self::assertCount(1, $pendingBefore);

        $pendingId = $pendingBefore[0]->getId();

        self::assertNotNull($pendingId);

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emission,
                $date
            )
        );

        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emission,
                $date
            )
        );

        $this->clearEntityManager();

        $pendingAfter = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        self::assertCount(1, $pendingAfter);

        self::assertSame(
            $pendingId,
            $pendingAfter[0]->getId()
        );

        self::assertSame(
            $emission->getId(),
            $pendingAfter[0]->getEmission()?->getId()
        );

        $drafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(3, $drafts);

        $draftIds = array_map(
            static fn(DiffusionDraft $draft): ?int => $draft->getId(),
            $drafts
        );

        self::assertCount(
            3,
            array_unique($draftIds)
        );
    }

    public function testReassignToIneligibleEmissionPreservesOriginalDismissedWithoutCreatingPending(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $rule = $fixture['rule'];
        $slot = $fixture['slots'][1];
        $selectedDate = $fixture['dates'][1];

        $emissionA = $fixture['exceptionalEmission'];
        $emissionB = $fixture['originalEmission'];

        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        $emissionAId = $emissionA->getId();
        $emissionBId = $emissionB->getId();

        $groupKey = sprintf(
            'rule_%d_origin_%s',
            $rule->getId(),
            $selectedDate->format('Ymd_Hi')
        );

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        /*
     * 1. A est exceptionnelle : Pending autorisé.
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emissionA,
                $selectedDate
            )
        );

        $pendingA = $pendingRepository->findOneBy([
            'assignmentGroupKey' => $groupKey,
            'emission' => $emissionAId,
        ]);

        self::assertInstanceOf(PendingRebroadcast::class, $pendingA);
        self::assertTrue($pendingA->isPending());

        /*
     * 2. Exclusion volontaire.
     */
        $pendingA->dismiss();
        $rule->setHasAdditionalRebroadcast(false);
        $this->entityManager->flush();

        $dismissedId = $pendingA->getId();

        self::assertNotNull($dismissedId);

        /*
     * 3. Remplacement par une émission normale.
     *
     * B ne doit pas recevoir de Pending supplémentaire.
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emissionB,
                $selectedDate
            )
        );

        $this->clearEntityManager();

        /*
     * 4. L'exclusion de A reste intacte.
     */
        $dismissedAfter = $pendingRepository->find($dismissedId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $dismissedAfter
        );

        self::assertTrue($dismissedAfter->isDismissed());

        self::assertSame(
            $emissionAId,
            $dismissedAfter->getEmission()?->getId()
        );

        /*
     * 5. Aucun Pending pour B.
     */
        self::assertCount(
            0,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $groupKey,
                'emission' => $emissionBId,
            ])
        );

        /*
     * 6. Seule l'exclusion historique de A subsiste.
     */
        $remaining = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        self::assertCount(1, $remaining);

        self::assertSame(
            $dismissedId,
            $remaining[0]->getId()
        );

        self::assertTrue($remaining[0]->isDismissed());

        /*
     * 7. Les Drafts réguliers ont bien été réaffectés à B.
     */
        $drafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(3, $drafts);

        foreach ($drafts as $draft) {
            self::assertSame(
                $emissionBId,
                $draft->getEmission()?->getId()
            );
        }
    }

    public function testReassignPreservesDismissedForOriginalEmissionAndCreatesPendingForReplacement(): void
    {
        $fixture = $this->createThreeBroadcastsFixture();

        $slot = $fixture['slots'][1];
        $selectedDate = $fixture['dates'][1];
        $rule = $fixture['rule'];

        $emissionA = $fixture['originalEmission'];
        $emissionB = $fixture['replacementEmission'];

        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        $emissionAId = $emissionA->getId();
        $emissionBId = $emissionB->getId();

        $groupKey = sprintf(
            'rule_%d_origin_%s',
            $rule->getId(),
            $selectedDate->format('Ymd_Hi')
        );

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        /*
     * 1. Affectation initiale de A.
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emissionA,
                $selectedDate
            )
        );

        $pendingA = $pendingRepository->findOneBy([
            'assignmentGroupKey' => $groupKey,
            'emission' => $emissionAId,
        ]);

        self::assertInstanceOf(PendingRebroadcast::class, $pendingA);
        self::assertTrue($pendingA->isPending());

        /*
     * 2. Exclusion volontaire de A.
     */
        $pendingA->dismiss();
        $this->entityManager->flush();

        $dismissedId = $pendingA->getId();

        self::assertNotNull($dismissedId);
        self::assertTrue($pendingA->isDismissed());

        /*
     * 3. Réaffectation du même groupe à B.
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emissionB,
                $selectedDate
            )
        );

        $this->clearEntityManager();

        /*
     * 4. A conserve son exclusion.
     */
        $dismissedAfter = $pendingRepository->find($dismissedId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $dismissedAfter
        );

        self::assertTrue($dismissedAfter->isDismissed());

        self::assertSame(
            $emissionAId,
            $dismissedAfter->getEmission()?->getId()
        );

        self::assertSame(
            $groupKey,
            $dismissedAfter->getAssignmentGroupKey()
        );

        /*
     * 5. B reçoit un nouveau Pending actif.
     */
        $pendingB = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
            'emission' => $emissionBId,
        ]);

        self::assertCount(1, $pendingB);

        self::assertTrue($pendingB[0]->isPending());

        self::assertNotSame(
            $dismissedId,
            $pendingB[0]->getId()
        );

        /*
     * 6. Aucun doublon pour B.
     */
        self::assertCount(
            2,
            $pendingRepository->findBy([
                'assignmentGroupKey' => $groupKey,
            ])
        );

        /*
     * 7. Les Drafts réguliers concernent B.
     */
        $drafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(3, $drafts);

        foreach ($drafts as $draft) {
            self::assertSame(
                $emissionBId,
                $draft->getEmission()?->getId()
            );
        }
    }

    public function testReassignDoesNotTransferActivePendingToDismissedEmission(): void
    {
        /*
     * ==========================================================
     * 1. PRÉPARATION
     * ==========================================================
     */
        $fixture = $this->createThreeBroadcastsFixture();

        $slot = $fixture['slots'][1];
        $selectedDate = $fixture['dates'][1];
        $rule = $fixture['rule'];

        $emissionA = $fixture['originalEmission'];
        $emissionB = $fixture['replacementEmission'];

        $rule->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        $groupKey = sprintf(
            'rule_%d_origin_%s',
            $rule->getId(),
            $selectedDate->format('Ymd_Hi')
        );

        $emissionAId = $emissionA->getId();
        $emissionBId = $emissionB->getId();

        $repository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        /*
     * ==========================================================
     * 2. AFFECTER B ET EXCLURE SA REDIFFUSION
     * ==========================================================
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emissionB,
                $selectedDate
            )
        );

        $pendingB = $repository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        self::assertCount(1, $pendingB);
        self::assertTrue($pendingB[0]->isPending());

        $dismissedId = $pendingB[0]->getId();

        $pendingB[0]->dismiss();
        $this->entityManager->flush();

        /*
     * ==========================================================
     * 3. RÉAFFECTER À A
     * ==========================================================
     *
     * B conserve son exclusion.
     * A doit recevoir un Pending actif.
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emissionA,
                $selectedDate
            )
        );

        $pendingAfterA = $repository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        self::assertCount(2, $pendingAfterA);

        $activeA = null;

        foreach ($pendingAfterA as $pending) {
            if (
                $pending->isPending()
                && $pending->getEmission()?->getId() === $emissionAId
            ) {
                $activeA = $pending;
            }
        }

        self::assertInstanceOf(PendingRebroadcast::class, $activeA);

        $activeAId = $activeA->getId();

        self::assertNotSame($dismissedId, $activeAId);

        /*
     * ==========================================================
     * 4. RÉAFFECTER À B
     * ==========================================================
     *
     * B a déjà été exclue volontairement.
     * Le Pending actif de A ne doit pas devenir
     * un nouveau Pending actif de B.
     */
        self::assertTrue(
            $this->assignmentService->assign(
                $slot,
                $emissionB,
                $selectedDate
            )
        );

        $this->clearEntityManager();

        /*
     * ==========================================================
     * 5. VÉRIFIER L'EXCLUSION DE B
     * ==========================================================
     */
        $dismissed = $repository->find($dismissedId);

        self::assertInstanceOf(PendingRebroadcast::class, $dismissed);
        self::assertTrue($dismissed->isDismissed());

        self::assertSame(
            $emissionBId,
            $dismissed->getEmission()?->getId()
        );

        self::assertSame(
            $groupKey,
            $dismissed->getAssignmentGroupKey()
        );

        /*
     * ==========================================================
     * 6. VÉRIFIER QU'AUCUN PENDING ACTIF
     *    N'EXISTE POUR B
     * ==========================================================
     */
        $allPending = $repository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        $activeForB = array_filter(
            $allPending,
            static fn(PendingRebroadcast $pending): bool =>
            $pending->isPending()
                && $pending->getEmission()?->getId() === $emissionBId
        );

        self::assertCount(0, $activeForB);

        /*
     * ==========================================================
     * 7. VÉRIFIER QUE LE PENDING ACTIF DE A
     *    N'A PAS ÉTÉ TRANSFÉRÉ À B
     * ==========================================================
     */
        $previouslyActive = $repository->find($activeAId);

        self::assertTrue(
            $previouslyActive === null
                || !$previouslyActive->isPending()
                || $previouslyActive->getEmission()?->getId() !== $emissionBId
        );
    }


    public function testAssignRestoresSoftDeletedRegularDraftsWithoutDuplicates(): void
    {
        $editor = (new Editeur())
            ->setName('Éditeur PHPUnit restauration')
            ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

        $category = (new Categories())
            ->setTitre('Catégorie PHPUnit restauration')
            ->setSlug('RST')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Test de restauration des drafts.')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(13)
            ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
            ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
            ->setIsActive(true);

        $firstSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rebroadcastSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(4)
            ->setStartTime(new \DateTimeImmutable('20:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($firstSlot);
        $rule->addSlot($rebroadcastSlot);

        $emissionA = (new Emission())
            ->setTitre('Émission restauration A')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-RESTORE-A')
            ->setDuree(60)
            ->setDescriptif('Émission initiale.')
            ->setCategorie($category)
            ->setEditeur($editor);

        $emissionB = (new Emission())
            ->setTitre('Émission restauration B')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-08-04'))
            ->setRef('PHPUNIT-RESTORE-B')
            ->setDuree(45)
            ->setDescriptif('Émission de remplacement.')
            ->setCategorie($category)
            ->setEditeur($editor);

        foreach (
            [
                $editor,
                $category,
                $rule,
                $firstSlot,
                $rebroadcastSlot,
                $emissionA,
                $emissionB,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $origin = new \DateTimeImmutable('2099-08-04 14:00:00');
        $rebroadcastDate = new \DateTimeImmutable('2099-08-06 20:00:00');

        $repository = static::getContainer()
            ->get(DiffusionDraftRepository::class);

        // Première affectation.
        self::assertTrue(
            $this->assignmentService->assign(
                $firstSlot,
                $emissionA,
                $origin
            )
        );

        $firstDraft = $repository
            ->findOneRegularBySlotAndHoraire($firstSlot, $origin);

        $rebroadcastDraft = $repository
            ->findOneRegularBySlotAndHoraire(
                $rebroadcastSlot,
                $rebroadcastDate
            );

        self::assertInstanceOf(DiffusionDraft::class, $firstDraft);
        self::assertInstanceOf(DiffusionDraft::class, $rebroadcastDraft);

        $firstDraftId = $firstDraft->getId();
        $rebroadcastDraftId = $rebroadcastDraft->getId();
        $expectedGroupKey = $firstDraft->getAssignmentGroupKey();

        // Suppression logique des deux drafts.
        $firstDraft->softDelete();
        $rebroadcastDraft->softDelete();

        $this->entityManager->flush();
        $this->clearEntityManager();

        // Recharger les entités après clearEntityManager().
        // Les anciennes références ne sont plus gérées par Doctrine.
        $firstSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($firstSlot->getId());

        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($rebroadcastSlot->getId());

        $emissionB = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionB->getId());

        self::assertInstanceOf(ProgrammationRuleSlot::class, $firstSlot);
        self::assertInstanceOf(ProgrammationRuleSlot::class, $rebroadcastSlot);
        self::assertInstanceOf(Emission::class, $emissionB);

        // Les recherches opérationnelles ne doivent plus les retrouver.

        // Les recherches opérationnelles ne doivent plus les retrouver.
        self::assertNull(
            $repository->findOneRegularBySlotAndHoraire(
                $firstSlot,
                $origin
            )
        );

        self::assertNull(
            $repository->findOneRegularBySlotAndHoraire(
                $rebroadcastSlot,
                $rebroadcastDate
            )
        );

        // Réaffectation avec une autre émission.
        self::assertTrue(
            $this->assignmentService->assign(
                $firstSlot,
                $emissionB,
                $origin
            )
        );

        $this->clearEntityManager();

        $firstAfter = $repository->find($firstDraftId);
        $rebroadcastAfter = $repository->find($rebroadcastDraftId);

        self::assertInstanceOf(DiffusionDraft::class, $firstAfter);
        self::assertInstanceOf(DiffusionDraft::class, $rebroadcastAfter);

        // Identifiants conservés.
        self::assertSame($firstDraftId, $firstAfter->getId());
        self::assertSame($rebroadcastDraftId, $rebroadcastAfter->getId());

        // Les deux drafts sont restaurés.
        self::assertFalse($firstAfter->isDeleted());
        self::assertFalse($rebroadcastAfter->isDeleted());

        // L'émission B remplace A sur les deux diffusions.
        self::assertSame(
            $emissionB->getId(),
            $firstAfter->getEmission()?->getId()
        );

        self::assertSame(
            $emissionB->getId(),
            $rebroadcastAfter->getEmission()?->getId()
        );

        // Groupe et rangs conservés.
        self::assertSame(
            $expectedGroupKey,
            $firstAfter->getAssignmentGroupKey()
        );

        self::assertSame(
            $expectedGroupKey,
            $rebroadcastAfter->getAssignmentGroupKey()
        );

        self::assertSame(1, $firstAfter->getNombreDiffusion());
        self::assertSame(2, $rebroadcastAfter->getNombreDiffusion());

        // Aucun doublon dans le groupe.
        self::assertCount(
            2,
            $repository->findByAssignmentGroupKey($expectedGroupKey)
        );
    }

public function testAssignRestoresSoftDeletedDraftAndPreservesHistoricalDiffusion(): void
{
    $editor = (new Editeur())
        ->setName('Éditeur PHPUnit restauration historique')
        ->setUpdateAt(new \DateTime('2099-08-04 10:00:00'));

    $category = (new Categories())
        ->setTitre('Catégorie restauration historique')
        ->setSlug('RSH')
        ->setEditeur($editor)
        ->setDuree(60)
        ->setDescriptif('Test de restauration avec diffusion historique.')
        ->setActive(true)
        ->setSoftDelete(false)
        ->setUpdatedAt(new \DateTime('2099-08-04 10:00:00'));

    $rule = (new ProgrammationRule())
        ->setCategory($category)
        ->setRuleNumber(14)
        ->setValidFrom(new \DateTimeImmutable('2099-08-04'))
        ->setValidUntil(new \DateTimeImmutable('2099-08-31'))
        ->setIsActive(true);

    $slot = (new ProgrammationRuleSlot())
        ->setDayOfWeek(2)
        ->setStartTime(new \DateTimeImmutable('14:00:00'))
        ->setDurationMinutes(60)
        ->setBroadcastRank(1)
        ->setWeekOffset(0)
        ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
        ->setIsActive(true);

    $rule->addSlot($slot);

    $emissionA = (new Emission())
        ->setTitre('Émission historique A')
        ->setKeyword('')
        ->setDatepub(new \DateTime('2099-08-04'))
        ->setRef('PHPUNIT-HISTORY-A')
        ->setDuree(60)
        ->setDescriptif('Émission initiale.')
        ->setCategorie($category)
        ->setEditeur($editor);

    $emissionB = (new Emission())
        ->setTitre('Émission historique B')
        ->setKeyword('')
        ->setDatepub(new \DateTime('2099-08-04'))
        ->setRef('PHPUNIT-HISTORY-B')
        ->setDuree(60)
        ->setDescriptif('Émission de remplacement.')
        ->setCategorie($category)
        ->setEditeur($editor);

    foreach ([
        $editor,
        $category,
        $rule,
        $slot,
        $emissionA,
        $emissionB,
    ] as $entity) {
        $this->entityManager->persist($entity);
    }

    $this->entityManager->flush();

    $origin = new \DateTimeImmutable('2099-08-04 14:00:00');

    $repository = static::getContainer()
        ->get(DiffusionDraftRepository::class);

    // Création initiale du draft.
    self::assertTrue(
        $this->assignmentService->assign(
            $slot,
            $emissionA,
            $origin
        )
    );

    $draft = $repository->findOneRegularBySlotAndHoraire(
        $slot,
        $origin
    );

    self::assertInstanceOf(DiffusionDraft::class, $draft);

    $draftId = $draft->getId();

    // Création d'une diffusion historique déjà dépubliée.
    $diffusion = (new Diffusion())
        ->setEmission($emissionA)
        ->setSchedule(
            \DateTime::createFromInterface($origin),
            60
        )
        ->setNombreDiffusion(1)
        ->setAssignmentGroupKey($draft->getAssignmentGroupKey())
        ->markAsPublished();

    $this->entityManager->persist($diffusion);

    $draft->markAsPublished(
        $diffusion,
        new \DateTimeImmutable('2099-08-01 12:00:00')
    );

    // Simulation de la dévalidation.
    $diffusion->markAsUnpublished();

    $draft
        ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)
        ->setPublishedAt(null);

    // Suppression logique après dévalidation.
    $draft->softDelete();

    $this->entityManager->flush();

    $diffusionId = $diffusion->getId();

    self::assertNotNull($draftId);
    self::assertNotNull($diffusionId);

    $this->clearEntityManager();

    // Recharger les entités gérées.
    $slot = $this->entityManager
        ->getRepository(ProgrammationRuleSlot::class)
        ->find($slot->getId());

    $emissionB = $this->entityManager
        ->getRepository(Emission::class)
        ->find($emissionB->getId());

    self::assertInstanceOf(ProgrammationRuleSlot::class, $slot);
    self::assertInstanceOf(Emission::class, $emissionB);

    // Réaffectation : le draft supprimé doit être restauré.
    self::assertTrue(
        $this->assignmentService->assign(
            $slot,
            $emissionB,
            $origin
        )
    );

    $this->clearEntityManager();

    $restoredDraft = $repository->find($draftId);

    $historicalDiffusion = $this->entityManager
        ->getRepository(Diffusion::class)
        ->find($diffusionId);

    self::assertInstanceOf(
        DiffusionDraft::class,
        $restoredDraft
    );

    self::assertInstanceOf(
        Diffusion::class,
        $historicalDiffusion
    );

    // Le même draft a été restauré.
    self::assertFalse($restoredDraft->isDeleted());
    self::assertSame($draftId, $restoredDraft->getId());

    // La nouvelle émission est affectée au draft.
    self::assertSame(
        $emissionB->getId(),
        $restoredDraft->getEmission()?->getId()
    );

    // La diffusion historique reste liée.
    self::assertSame(
        $diffusionId,
        $restoredDraft->getPublishedDiffusion()?->getId()
    );

    // La semaine n'a pas été publiée automatiquement.
    self::assertSame(
        DiffusionDraft::STATUS_DRAFT,
        $restoredDraft->getPublicationStatus()
    );

    self::assertNull($restoredDraft->getPublishedAt());

    self::assertFalse($historicalDiffusion->isPublished());
}

    private function createRescheduledArbitration(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $originalStartsAt,
        \DateTimeImmutable $destinationStartsAt
    ): GridSlotArbitration {
        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt($originalStartsAt)
            ->setOriginalEndsAt($originalStartsAt->modify('+60 minutes'))
            ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt($destinationStartsAt)
            ->setRescheduledEndsAt($destinationStartsAt->modify('+60 minutes'))
            ->setNotes('Arbitrage créé par PHPUnit');

        $arbitration->markResolved();

        $this->entityManager->persist($arbitration);
        $this->entityManager->flush();

        return $arbitration;
    }
}
