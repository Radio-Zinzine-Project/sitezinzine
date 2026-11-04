<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Entity\PendingRebroadcast;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\PendingRebroadcastRepository;
use App\Repository\ProgrammationRuleRepository;
use App\Service\GridUnpublicationService;
use App\Service\PendingRebroadcastService;
use App\Service\ProgrammationAssignmentSynchronizer;
use App\Service\ProgrammationGridBuilder;
use App\Service\ProgrammationRuleMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProgrammationRuleMutationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DiffusionDraftRepository $draftRepository;
    private DiffusionRepository $diffusionRepository;
    private ProgrammationRuleMutationService $mutationService;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->entityManager = $container->get(
            EntityManagerInterface::class
        );

        $this->draftRepository = $container->get(
            DiffusionDraftRepository::class
        );

        $this->diffusionRepository = $container->get(
            DiffusionRepository::class
        );

        $pendingRepository = $container->get(
            PendingRebroadcastRepository::class
        );

        $gridBuilder = new ProgrammationGridBuilder(
            $container->get(
                ProgrammationRuleRepository::class
            )
        );

        $pendingRebroadcastService = new PendingRebroadcastService(
            $this->entityManager,
            $this->diffusionRepository,
            $pendingRepository,
            $this->draftRepository
        );

        $synchronizer = new ProgrammationAssignmentSynchronizer(
            $this->draftRepository,
            $this->diffusionRepository,
            $pendingRepository,
            $gridBuilder,
            $pendingRebroadcastService,
            $this->entityManager
        );

        $unpublicationService = new GridUnpublicationService(
            $this->diffusionRepository,
            $this->draftRepository,
            $this->entityManager
        );

        $this->mutationService = new ProgrammationRuleMutationService(
            $this->draftRepository,
            $this->diffusionRepository,
            $gridBuilder,
            $unpublicationService,
            $synchronizer,
            $this->entityManager
        );

        $this->entityManager
            ->getConnection()
            ->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (
            isset($this->entityManager)
            && $this->entityManager
            ->getConnection()
            ->isTransactionActive()
        ) {
            $this->entityManager
                ->getConnection()
                ->rollBack();
        }

        if (isset($this->entityManager)) {
            $this->entityManager->clear();
        }

        parent::tearDown();
    }

    public function testPublishedDraftMovedInsideSameWeekUnpublishesWeekBeforeSynchronization(): void
    {
        $fixture = $this->createRegularGroup();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        /*
         * Mercredi 09h -> mercredi 10h.
         *
         * Les deux horaires appartiennent à la même semaine radio.
         * La semaine doit être dévalidée une seule fois avant que le Draft
         * soit déplacé.
         */
        $fixture['rank2Slot']->setStartTime(
            new \DateTimeImmutable('10:00:00')
        );

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        $rank2Draft = $this->draftRepository->find(
            $rank2DraftId
        );

        $reloadedFirstDiffusion = $this->diffusionRepository->find(
            $firstDiffusionId
        );

        $reloadedSecondDiffusion = $this->diffusionRepository->find(
            $secondDiffusionId
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rank2Draft
        );

        self::assertSame(
            '2099-01-07 10:00:00',
            $rank2Draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $rank2Draft->getPublicationStatus()
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isUnpublished()
        );

        self::assertCount(
            1,
            $result['unpublishedWeeks']
        );

        self::assertSame(
            '2099-01-06',
            $result['unpublishedWeeks'][0]->format('Y-m-d')
        );

        self::assertSame(
            1,
            $result['synchronization']['movedRegularDrafts']
        );
    }

    public function testPublishedDraftMovedToAnotherPublishedWeekUnpublishesBothWeeks(): void
    {
        $fixture = $this->createRegularGroup();

        /*
         * Groupe concerné :
         * semaine radio du mardi 06/01.
         */
        $this->publishDraft(
            $fixture['rank1Draft']
        );

        $rank2Diffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $rank2DiffusionId = $rank2Diffusion->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        /*
         * On crée une programmation indépendante déjà publiée
         * dans la semaine de destination.
         *
         * Ainsi la semaine du 13/01 est réellement validée et doit
         * elle aussi être dévalidée avant le déplacement.
         */
        $destinationFixture = $this->createStandalonePublishedDraft(
            '2099-01-13 12:00:00'
        );

        $destinationDiffusionId =
            $destinationFixture['diffusion']->getId();

        /*
         * Le rang 2 quitte la semaine radio du 06/01
         * pour celle du 13/01.
         */
        $fixture['rank2Slot']
            ->setDayOfWeek(3)
            ->setWeekOffset(1)
            ->setStartTime(
                new \DateTimeImmutable('10:00:00')
            );

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        $rank2Draft = $this->draftRepository->find(
            $rank2DraftId
        );

        $reloadedRank2Diffusion =
            $this->diffusionRepository->find(
                $rank2DiffusionId
            );

        $reloadedDestinationDiffusion =
            $this->diffusionRepository->find(
                $destinationDiffusionId
            );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rank2Draft
        );

        self::assertSame(
            '2099-01-14 10:00:00',
            $rank2Draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedRank2Diffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedDestinationDiffusion
        );

        self::assertTrue(
            $reloadedRank2Diffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedDestinationDiffusion->isUnpublished()
        );

        $weeks = array_map(
            static fn(\DateTimeImmutable $week): string =>
            $week->format('Y-m-d'),
            $result['unpublishedWeeks']
        );

        sort($weeks);

        self::assertSame(
            [
                '2099-01-06',
                '2099-01-13',
            ],
            $weeks
        );

        self::assertSame(
            1,
            $result['synchronization']['movedRegularDrafts']
        );
    }

    public function testPublishedDraftMovedToUnpublishedWeekOnlyUnpublishesSourceWeek(): void
    {
        $fixture = $this->createRegularGroup();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        /*
         * Aucune Diffusion publiée n'existe dans la semaine du 13/01.
         *
         * Seule la semaine source doit donc être dévalidée.
         */
        $fixture['rank2Slot']
            ->setDayOfWeek(3)
            ->setWeekOffset(1)
            ->setStartTime(
                new \DateTimeImmutable('10:00:00')
            );

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        $rank2Draft = $this->draftRepository->find(
            $rank2DraftId
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rank2Draft
        );

        self::assertSame(
            '2099-01-14 10:00:00',
            $rank2Draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isUnpublished()
        );

        self::assertCount(
            1,
            $result['unpublishedWeeks']
        );

        self::assertSame(
            '2099-01-06',
            $result['unpublishedWeeks'][0]->format('Y-m-d')
        );

        self::assertSame(
            1,
            $result['synchronization']['movedRegularDrafts']
        );
    }

    public function testUnpublishedGroupSynchronizesWithoutUnpublishingAnyWeek(): void
    {
        $fixture = $this->createRegularGroup();

        $rank2DraftId = $fixture['rank2Draft']->getId();

        $fixture['rank2Slot']->setStartTime(
            new \DateTimeImmutable('10:00:00')
        );

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        $rank2Draft = $this->draftRepository->find(
            $rank2DraftId
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rank2Draft
        );

        self::assertSame(
            '2099-01-07 10:00:00',
            $rank2Draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            [],
            $result['unpublishedWeeks']
        );

        self::assertSame(
            1,
            $result['synchronization']['movedRegularDrafts']
        );
    }

    public function testNoStructuralChangeDoesNotUnpublishPublishedWeek(): void
    {
        $fixture = $this->createRegularGroup();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        /*
         * Aucune modification de slot.
         *
         * Une simple sauvegarde ne doit surtout pas faire repasser
         * une semaine validée en brouillon.
         */
        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isPublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isPublished()
        );

        self::assertSame(
            [],
            $result['unpublishedWeeks']
        );

        self::assertSame(
            0,
            $result['synchronization']['movedRegularDrafts']
        );

        self::assertSame(
            0,
            $result['synchronization']['invalidatedGroups']
        );
    }

    public function testPublishedDraftReplacementByUnpersistedIdenticalSlotUnpublishesWeek(): void
    {
        $fixture = $this->createRegularGroup();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        $oldRank2Slot = $fixture['rank2Slot'];

        /*
     * L'ancien slot sort de la structure attendue.
     *
     * On le remplace ensuite par un nouvel objet portant exactement
     * la même programmation :
     *
     * - mercredi ;
     * - 09h00 ;
     * - 60 minutes ;
     * - rang 2 ;
     * - même semaine ;
     * - même récurrence.
     *
     * Le changement est donc uniquement structurel :
     * l'identité du slot a changé.
     */
        $oldRank2Slot->softDelete();

        $replacementSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(
                $oldRank2Slot->getDayOfWeek()
            )
            ->setStartTime(
                clone $oldRank2Slot->getStartTime()
            )
            ->setDurationMinutes(
                $oldRank2Slot->getDurationMinutes()
            )
            ->setBroadcastRank(
                $oldRank2Slot->getBroadcastRank()
            )
            ->setWeekOffset(
                $oldRank2Slot->getWeekOffset()
            )
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $fixture['rule']->addSlot($replacementSlot);

        /*
     * Point essentiel du test :
     *
     * aucun flush ne doit être nécessaire avant l'orchestration.
     */
        self::assertNull(
            $replacementSlot->getId()
        );

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable(
                    '2099-01-01 00:00:00'
                )
            );

        $this->entityManager->clear();

        $rank2Draft = $this->draftRepository->find(
            $rank2DraftId
        );

        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        /*
     * La semaine entière devait être dévalidée,
     * même si aucun horaire n'a changé.
     */
        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isUnpublished()
        );

        self::assertCount(
            1,
            $result['unpublishedWeeks']
        );

        self::assertSame(
            '2099-01-06',
            $result['unpublishedWeeks'][0]
                ->format('Y-m-d')
        );

        /*
     * Le Draft conserve exactement son planning,
     * mais il est maintenant rattaché au nouveau slot.
     */
        self::assertInstanceOf(
            DiffusionDraft::class,
            $rank2Draft
        );

        self::assertSame(
            '2099-01-07 09:00:00',
            $rank2Draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $rank2Draft->getPublicationStatus()
        );

        self::assertSame(
            2,
            $rank2Draft->getNombreDiffusion()
        );

        self::assertSame(
            1,
            $result['synchronization']['movedRegularDrafts']
        );

        self::assertSame(
            0,
            $result['synchronization']['createdRegularDrafts']
        );

        self::assertSame(
            0,
            $result['synchronization']['removedRegularDrafts']
        );
    }

    public function testStructuralChangeDoesNotPartiallyUnpublishWhenOneAffectedWeekCannotBeUnpublished(): void
    {
        $fixture = $this->createRegularGroup();

        /*
     * Semaine source :
     *
     * le groupe testé est entièrement publié et correctement lié
     * à ses Drafts.
     *
     * Cette semaine pourrait donc normalement être dévalidée.
     */
        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        $rank1DraftId = $fixture['rank1Draft']->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        /*
     * Semaine de destination :
     *
     * on crée volontairement une Diffusion publiée "legacy",
     * sans aucun Draft lié.
     *
     * GridUnpublicationService doit donc considérer cette semaine
     * comme non dévalidable automatiquement.
     */
        $legacyEmission = $this->createEmission(
            $fixture['emission']->getCategorie(),
            $fixture['emission']->getEditeur()
        );

        $this->entityManager->persist($legacyEmission);
        $this->entityManager->flush();

        $legacyDiffusion = (new Diffusion())
            ->setEmission($legacyEmission)
            ->setSchedule(
                new \DateTime('2099-01-13 12:00:00'),
                60
            )
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey(
                'legacy_destination_without_draft'
            )
            ->markAsPublished();

        $this->entityManager->persist($legacyDiffusion);
        $this->entityManager->flush();

        $legacyDiffusionId = $legacyDiffusion->getId();

        /*
     * Le rang 2 doit quitter la semaine radio du 06/01
     * pour celle du 13/01.
     *
     * Les deux semaines deviennent donc candidates à la dévalidation.
     *
     * Mais la semaine de destination est impossible à dévalider
     * automatiquement à cause de la Diffusion legacy ci-dessus.
     */
        $fixture['rank2Slot']
            ->setDayOfWeek(3)
            ->setWeekOffset(1)
            ->setStartTime(
                new \DateTimeImmutable('10:00:00')
            );

        try {
            $this->mutationService
                ->synchronizeAfterStructuralChange(
                    $fixture['rule'],
                    new \DateTimeImmutable(
                        '2099-01-01 00:00:00'
                    )
                );

            self::fail(
                'La modification structurelle aurait dû être refusée.'
            );
        } catch (\DomainException) {
            /*
         * Comportement attendu :
         *
         * aucune semaine ne doit avoir été dévalidée avant
         * la détection de la semaine legacy non dévalidable.
         */
        }

        /*
     * On abandonne l'état actuellement chargé en mémoire afin
     * de relire réellement les valeurs présentes en base.
     */
        $this->entityManager->clear();

        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        $reloadedLegacyDiffusion =
            $this->diffusionRepository->find(
                $legacyDiffusionId
            );

        $reloadedRank1Draft =
            $this->draftRepository->find(
                $rank1DraftId
            );

        $reloadedRank2Draft =
            $this->draftRepository->find(
                $rank2DraftId
            );

        /*
     * Aucune Diffusion ne doit avoir été dévalidée.
     */
        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedLegacyDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isPublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isPublished()
        );

        self::assertTrue(
            $reloadedLegacyDiffusion->isPublished()
        );

        /*
     * Les Drafts source doivent eux aussi être restés publiés.
     */
        self::assertInstanceOf(
            DiffusionDraft::class,
            $reloadedRank1Draft
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $reloadedRank2Draft
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $reloadedRank1Draft->getPublicationStatus()
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $reloadedRank2Draft->getPublicationStatus()
        );

        /*
     * Et surtout : le rang 2 ne doit pas avoir commencé
     * sa synchronisation vers la nouvelle semaine.
     */
        self::assertSame(
            '2099-01-07 09:00:00',
            $reloadedRank2Draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testAddingRegularRankIntoPublishedWeekUnpublishesDestinationWeekBeforeSynchronization(): void
    {
        $fixture = $this->createRegularGroup();

        /*
     * Le groupe de départ est publié dans la semaine radio
     * du mardi 06/01/2099.
     */
        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        /*
     * On valide également la semaine radio suivante avec
     * une programmation indépendante.
     *
     * Le nouveau rang 3 sera ensuite ajouté dans cette semaine.
     */
        $destinationFixture = $this->createStandalonePublishedDraft(
            '2099-01-15 14:00:00'
        );

        $destinationDiffusionId =
            $destinationFixture['diffusion']->getId();

        /*
     * Nouveau rang régulier :
     *
     * rang 3 : jeudi 15/01/2099 à 10h00.
     *
     * weekOffset = 1 place cette occurrence dans la semaine
     * radio suivante.
     *
     * Aucun Draft de rang 3 n'existe encore : c'est donc bien
     * le cas "nouveau rang régulier".
     */
        $rank3Slot = $this->createSlot(
            $fixture['rule'],
            4,
            '10:00:00',
            60,
            3,
            1
        );

        self::assertNull(
            $rank3Slot->getId()
        );

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        /*
     * La semaine contenant le nouveau rang était déjà publiée.
     * Elle devait donc être dévalidée avant la synchronisation.
     */
        $reloadedDestinationDiffusion =
            $this->diffusionRepository->find(
                $destinationDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedDestinationDiffusion
        );

        self::assertTrue(
            $reloadedDestinationDiffusion->isUnpublished()
        );

        /*
     * Le groupe source n'a pas changé sur ses rangs existants :
     * sa semaine doit rester publiée.
     */
        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isPublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isPublished()
        );

        /*
     * Une seule semaine devait être dévalidée :
     * celle du nouveau rang.
     */
        self::assertCount(
            1,
            $result['unpublishedWeeks']
        );

        self::assertSame(
            '2099-01-13',
            $result['unpublishedWeeks'][0]->format('Y-m-d')
        );

        /*
     * Après dévalidation, le synchronizer doit créer
     * le Draft correspondant au nouveau rang 3.
     */
        self::assertSame(
            1,
            $result['synchronization']['createdRegularDrafts']
        );

        $drafts = $this->draftRepository
            ->findByAssignmentGroupKey(
                $fixture['groupKey']
            );

        $rank3Draft = null;

        foreach ($drafts as $draft) {
            if (
                $draft instanceof DiffusionDraft
                && 3 === $draft->getNombreDiffusion()
            ) {
                $rank3Draft = $draft;
                break;
            }
        }

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rank3Draft
        );

        self::assertSame(
            '2099-01-15 10:00:00',
            $rank3Draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $rank3Draft->getPublicationStatus()
        );
    }

    public function testRemovingPublishedRegularRankUnpublishesItsWeekBeforeSynchronization(): void
    {
        $fixture = $this->createRegularGroup();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        $rank1DraftId = $fixture['rank1Draft']->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        /*
     * Le rang 2 disparaît de la nouvelle structure.
     *
     * Son Draft existe encore au moment où le service analyse
     * l'impact de la modification : il doit donc constater que
     * ce rang publié va être supprimé.
     */
        $fixture['rank2Slot']->softDelete();

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        /*
     * La semaine radio du groupe était publiée.
     *
     * La suppression du rang 2 doit donc provoquer sa
     * dévalidation avant la synchronisation.
     */
        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isUnpublished()
        );

        self::assertCount(
            1,
            $result['unpublishedWeeks']
        );

        self::assertSame(
            '2099-01-06',
            $result['unpublishedWeeks'][0]->format('Y-m-d')
        );

        /*
     * Le rang 1 reste valide et doit toujours posséder
     * son Draft après synchronisation.
     */
        $reloadedRank1Draft =
            $this->draftRepository->find(
                $rank1DraftId
            );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $reloadedRank1Draft
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $reloadedRank1Draft->getPublicationStatus()
        );

        /*
     * Le rang 2 n'existe plus dans la structure attendue :
     * son Draft doit donc avoir été supprimé.
     */
        self::assertNull(
            $this->draftRepository->find(
                $rank2DraftId
            )
        );

        self::assertSame(
            1,
            $result['synchronization']['removedRegularDrafts']
        );

        self::assertSame(
            0,
            $result['synchronization']['createdRegularDrafts']
        );
    }

    public function testAddingRegularRankRenumbersRemainingPublishedManualRebroadcast(): void
    {
        $fixture = $this->createRegularGroup();
        $firstDraftId = $fixture['rank1Draft']->getId();
        $secondDraftId = $fixture['rank2Draft']->getId();

        // Les régulières sont les 6 et 7 janvier ; la manuelle est
        // dans la semaine radio suivante, après tous les réguliers.
        $manualStartsAt = new \DateTimeImmutable('2099-01-15 12:00:00');
        $manualDraft = (new DiffusionDraft())
            ->setEmission($fixture['emission'])
            ->setSchedule($manualStartsAt, 60)
            ->setNombreDiffusion(3)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setAssignmentGroupKey($fixture['groupKey']);
        $this->entityManager->persist($manualDraft);
        $this->entityManager->flush();

        $manualDiffusion = $this->publishDraft($manualDraft);
        $manualDraftId = $manualDraft->getId();
        $manualDiffusionId = $manualDiffusion->getId();
        $manualEndsAt = $manualDiffusion->getEndsAt()?->format('Y-m-d H:i:s');
        self::assertTrue($manualDiffusion->isPublished());
        self::assertSame(3, $manualDraft->getNombreDiffusion());
        self::assertSame(3, $manualDiffusion->getNombreDiffusion());

        // R3 est ajouté le 8 janvier, sans rendre la manuelle incompatible.
        $rank3Slot = $this->createSlot($fixture['rule'], 4, '12:00:00', 60, 3);
        $result = $this->mutationService->synchronizeAfterStructuralChange(
            $fixture['rule'],
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );
        $rank3SlotId = $rank3Slot->getId();
        $this->entityManager->clear();

        // Vérifier d'abord que la vraie mutation a eu lieu sans dévalidation
        // de la semaine distincte ni suppression de la manuelle publiée.
        self::assertSame([], $result['unpublishedWeeks']);
        self::assertSame(1, $result['synchronization']['createdRegularDrafts']);
        self::assertSame(0, $result['synchronization']['removedManualRebroadcasts']);
        self::assertSame(1, $this->draftRepository->find($firstDraftId)->getNombreDiffusion());
        self::assertSame(2, $this->draftRepository->find($secondDraftId)->getNombreDiffusion());

        $rank3Draft = $this->draftRepository->findOneBy([
            'slot' => $rank3SlotId,
            'assignmentGroupKey' => $fixture['groupKey'],
        ]);
        self::assertInstanceOf(DiffusionDraft::class, $rank3Draft);
        self::assertSame(3, $rank3Draft->getNombreDiffusion());
        self::assertSame('2099-01-08 12:00:00', $rank3Draft->getHoraireDiffusion()?->format('Y-m-d H:i:s'));
        self::assertLessThanOrEqual($manualStartsAt, $rank3Draft->getEndsAt());

        $manualAfter = $this->draftRepository->find($manualDraftId);
        $diffusionAfter = $this->diffusionRepository->find($manualDiffusionId);
        self::assertInstanceOf(DiffusionDraft::class, $manualAfter);
        self::assertInstanceOf(Diffusion::class, $diffusionAfter);
        self::assertSame($manualDiffusionId, $diffusionAfter->getId());
        self::assertSame($manualDiffusionId, $manualAfter->getPublishedDiffusion()?->getId());
        self::assertTrue($diffusionAfter->isPublished());
        self::assertSame(DiffusionDraft::STATUS_PUBLISHED, $manualAfter->getPublicationStatus());
        self::assertSame($manualStartsAt->format('Y-m-d H:i:s'), $manualAfter->getHoraireDiffusion()?->format('Y-m-d H:i:s'));
        self::assertSame($manualStartsAt->format('Y-m-d H:i:s'), $diffusionAfter->getHoraireDiffusion()?->format('Y-m-d H:i:s'));
        self::assertSame($manualEndsAt, $diffusionAfter->getEndsAt()?->format('Y-m-d H:i:s'));
        self::assertSame($fixture['groupKey'], $manualAfter->getAssignmentGroupKey());
        self::assertSame($fixture['groupKey'], $diffusionAfter->getAssignmentGroupKey());

        self::assertSame(
            [4, 4],
            [$manualAfter->getNombreDiffusion(), $diffusionAfter->getNombreDiffusion()],
            'Le brouillon manuel et sa Diffusion publiée doivent suivre les trois rangs réguliers.'
        );
    }

    public function testAddingRegularRankAfterPublishedManualRebroadcastUnpublishesItsWeekAndReturnsItToPending(): void
    {
        $fixture = $this->createRegularGroup();
        $fixture['rule']->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        /*
     * Le groupe régulier initial possède :
     *
     * rang 1 : 06/01
     * rang 2 : 07/01
     *
     * On publie les deux passages réguliers.
     */
        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        /*
     * Une rediffusion manuelle est placée le 08/01 à 10h.
     *
     * Dans la structure actuelle elle est bien située après
     * tous les passages réguliers.
     */
        $manualRebroadcast = (new DiffusionDraft())
            ->setEmission($fixture['emission'])
            ->setSchedule(
                new \DateTimeImmutable('2099-01-08 10:00:00'),
                60
            )
            ->setNombreDiffusion(3)
            ->setDraftType(
                DiffusionDraft::TYPE_MANUAL_REBROADCAST
            )
            ->setAssignmentGroupKey(
                $fixture['groupKey']
            )
            ->setPublicationStatus(
                DiffusionDraft::STATUS_DRAFT
            );

        $this->entityManager->persist(
            $manualRebroadcast
        );

        $this->entityManager->flush();

        $manualDraftId = $manualRebroadcast->getId();

        $manualDiffusion = $this->publishDraft(
            $manualRebroadcast
        );

        $manualDiffusionId = $manualDiffusion->getId();

        /*
        * On ajoute maintenant un rang 3 le jeudi à 12h.
        *
        * Pour le groupe d'origine du 06/01 :
        *
        * rang 3 : 08/01 à 12h -> 13h
        *
        * La rediffusion manuelle de 10h se retrouve donc AVANT
        * la fin de la nouvelle chaîne régulière.
        *
        * Elle doit retourner dans le Parc.
        */
        $rank3Slot = $this->createSlot(
            $fixture['rule'],
            4,
            '12:00:00',
            60,
            3
        );

        self::assertNull(
            $rank3Slot->getId()
        );

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        /*
        * Les trois Diffusion publiées se trouvent dans la même
        * semaine radio, débutant le 06/01.
        *
        * Cette semaine doit avoir été dévalidée avant que le
        * synchronizer ne modifie les Drafts.
        */
        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        $reloadedManualDiffusion =
            $this->diffusionRepository->find(
                $manualDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedManualDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedManualDiffusion->isUnpublished()
        );

        /*
        * Une seule semaine radio est concernée.
        */
        self::assertCount(
            1,
            $result['unpublishedWeeks']
        );

        self::assertSame(
            '2099-01-06',
            $result['unpublishedWeeks'][0]->format('Y-m-d')
        );

        /*
        * Le nouveau rang 3 doit avoir été créé.
        */
        self::assertSame(
            1,
            $result['synchronization']['createdRegularDrafts']
        );

        /*
        * La rediffusion manuelle devenue incohérente doit être
        * retirée du groupe.
        */
        self::assertSame(
            1,
            $result['synchronization']['removedManualRebroadcasts']
        );

        self::assertNull(
            $this->draftRepository->find(
                $manualDraftId
            )
        );

        /*
        * Sa suppression doit créer une entrée dans le Parc
        * pour que cette rediffusion reste à reprogrammer.
        */

        self::assertSame(
            1,
            $result['synchronization']['createdPendingRebroadcasts']
        );
    }

    public function testRemovingFirstBroadcastUnpublishesWholeFutureGroupBeforeInvalidation(): void
    {
        $fixture = $this->createRegularGroup();

        /*
     * Le groupe possède initialement deux passages réguliers :
     *
     * rang 1 : 06/01/2099 à 20h
     * rang 2 : 07/01/2099 à 09h
     *
     * Les deux sont publiés.
     */
        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        $rank1DraftId = $fixture['rank1Draft']->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        /*
     * On supprime structurellement le rang 1.
     *
     * L'origine historique du groupe était précisément cette
     * occurrence du 06/01/2099 à 20h.
     *
     * La nouvelle structure ne produit donc plus la première
     * diffusion du groupe existant.
     */
        $fixture['rank1Slot']->softDelete();

        $result = $this->mutationService
            ->synchronizeAfterStructuralChange(
                $fixture['rule'],
                new \DateTimeImmutable('2099-01-01 00:00:00')
            );

        $this->entityManager->clear();

        /*
     * Les Diffusion publiées appartiennent à la même semaine radio.
     *
     * Cette semaine doit être dévalidée AVANT l'invalidation
     * des données de travail du groupe.
     */
        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isUnpublished()
        );

        /*
     * Une seule semaine radio était publiée pour ce groupe.
     */
        self::assertCount(
            1,
            $result['unpublishedWeeks']
        );

        self::assertSame(
            '2099-01-06',
            $result['unpublishedWeeks'][0]->format('Y-m-d')
        );

        /*
     * Puisque la première diffusion n'existe plus dans la nouvelle
     * structure, le groupe lui-même doit être invalidé.
     */
        self::assertSame(
            1,
            $result['synchronization']['invalidatedGroups']
        );

        /*
     * Tous les Drafts réguliers du groupe doivent disparaître.
     *
     * On ne conserve notamment pas arbitrairement l'ancien rang 2
     * comme nouvelle origine du groupe.
     */
        self::assertNull(
            $this->draftRepository->find(
                $rank1DraftId
            )
        );

        self::assertNull(
            $this->draftRepository->find(
                $rank2DraftId
            )
        );

        self::assertSame(
            2,
            $result['synchronization']['removedRegularDrafts']
        );

        /*
     * La disparition complète du groupe ne crée pas de Pending :
     * il n'existe plus d'origine régulière valide à laquelle
     * rattacher une rediffusion.
     */
        self::assertSame(
            0,
            $result['synchronization']['createdPendingRebroadcasts']
        );
    }

    public function testExplicitInvalidationUnpublishesWholeWeekAndRemovesFutureGroup(): void
    {
        $fixture = $this->createRegularGroup();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        /*
     * Programmation indépendante dans la même semaine radio.
     *
     * Elle n'appartient pas à la règle invalidée, mais puisque la semaine
     * entière doit être dévalidée, elle doit elle aussi revenir en Draft.
     */
        $independentFixture = $this->createStandalonePublishedDraft(
            '2099-01-08 14:00:00'
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey(
                $fixture['groupKey']
            );

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        $independentDiffusionId =
            $independentFixture['diffusion']->getId();

        $independentDraftId =
            $independentFixture['draft']->getId();

        $result = $this->mutationService
            ->invalidateRule(
                $fixture['rule'],
                new \DateTimeImmutable(
                    '2099-01-05 12:00:00'
                )
            );

        $this->entityManager->clear();

        /*
     * Le groupe invalidé disparaît complètement côté Draft.
     */
        self::assertSame(
            [],
            $this->draftRepository
                ->findByAssignmentGroupKey(
                    $fixture['groupKey']
                )
        );

        $pendingRepository = static::getContainer()->get(
            PendingRebroadcastRepository::class
        );

        self::assertSame(
            [],
            $pendingRepository
                ->findByAssignmentGroupKey(
                    $fixture['groupKey']
                )
        );

        /*
     * Les Diffusion du groupe restent en base mais deviennent unpublished.
     */
        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isUnpublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isUnpublished()
        );

        /*
     * La programmation indépendante est elle aussi dévalidée,
     * puisque la semaine entière constitue l'unité de validation.
     */
        $reloadedIndependentDiffusion =
            $this->diffusionRepository->find(
                $independentDiffusionId
            );

        $reloadedIndependentDraft =
            $this->draftRepository->find(
                $independentDraftId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedIndependentDiffusion
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $reloadedIndependentDraft
        );

        self::assertTrue(
            $reloadedIndependentDiffusion->isUnpublished()
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $reloadedIndependentDraft
                ->getPublicationStatus()
        );

        /*
     * Une seule semaine radio était concernée.
     */
        self::assertCount(
            1,
            $result['unpublishedWeeks']
        );

        self::assertSame(
            '2099-01-06',
            $result['unpublishedWeeks'][0]
                ->format('Y-m-d')
        );

        self::assertSame(
            1,
            $result['invalidation']['invalidatedGroups']
        );

        self::assertSame(
            2,
            $result['invalidation']['removedRegularDrafts']
        );
    }

    public function testExplicitInvalidationUnpublishesEveryPublishedWeekOfFutureGroup(): void
    {
        $fixture = $this->createRegularGroup();

        /*
     * On place le rang 2 dans la semaine radio suivante avant publication.
     */
        $fixture['rank2Slot']
            ->setDayOfWeek(3)
            ->setWeekOffset(1)
            ->setStartTime(
                new \DateTimeImmutable('09:00:00')
            );

        $fixture['rank2Draft']->setSchedule(
            new \DateTimeImmutable(
                '2099-01-14 09:00:00'
            ),
            60
        );

        $this->entityManager->flush();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        /*
     * Chaque semaine contient également une programmation indépendante.
     * Cela vérifie que les deux semaines complètes sont dévalidées.
     */
        $firstIndependent =
            $this->createStandalonePublishedDraft(
                '2099-01-08 14:00:00'
            );

        $secondIndependent =
            $this->createStandalonePublishedDraft(
                '2099-01-15 14:00:00'
            );

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        $firstIndependentDiffusionId =
            $firstIndependent['diffusion']->getId();

        $secondIndependentDiffusionId =
            $secondIndependent['diffusion']->getId();

        $result = $this->mutationService
            ->invalidateRule(
                $fixture['rule'],
                new \DateTimeImmutable(
                    '2099-01-05 12:00:00'
                )
            );

        $this->entityManager->clear();

        self::assertSame(
            [],
            $this->draftRepository
                ->findByAssignmentGroupKey(
                    $fixture['groupKey']
                )
        );

        foreach (
            [
                $firstDiffusionId,
                $secondDiffusionId,
                $firstIndependentDiffusionId,
                $secondIndependentDiffusionId,
            ] as $diffusionId
        ) {
            $diffusion =
                $this->diffusionRepository->find(
                    $diffusionId
                );

            self::assertInstanceOf(
                Diffusion::class,
                $diffusion
            );

            self::assertTrue(
                $diffusion->isUnpublished()
            );
        }

        $weeks = array_map(
            static fn(\DateTimeImmutable $week): string =>
            $week->format('Y-m-d'),
            $result['unpublishedWeeks']
        );

        sort($weeks);

        self::assertSame(
            [
                '2099-01-06',
                '2099-01-13',
            ],
            $weeks
        );

        self::assertSame(
            1,
            $result['invalidation']['invalidatedGroups']
        );

        self::assertSame(
            2,
            $result['invalidation']['removedRegularDrafts']
        );
    }

    public function testExplicitInvalidationKeepsHistoricalPublishedGroupFrozen(): void
    {
        $fixture = $this->createRegularGroup();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey(
                $fixture['groupKey']
            );

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $rank1DraftId = $fixture['rank1Draft']->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        /*
     * La première Diffusion du groupe est déjà passée.
     *
     * Le groupe entier est donc historique et doit être gelé,
     * même si le rang 2 est encore futur.
     */
        $result = $this->mutationService
            ->invalidateRule(
                $fixture['rule'],
                new \DateTimeImmutable(
                    '2099-01-07 08:00:00'
                )
            );

        $this->entityManager->clear();

        self::assertInstanceOf(
            DiffusionDraft::class,
            $this->draftRepository->find(
                $rank1DraftId
            )
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $this->draftRepository->find(
                $rank2DraftId
            )
        );

        $reloadedFirstDiffusion =
            $this->diffusionRepository->find(
                $firstDiffusionId
            );

        $reloadedSecondDiffusion =
            $this->diffusionRepository->find(
                $secondDiffusionId
            );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecondDiffusion
        );

        self::assertTrue(
            $reloadedFirstDiffusion->isPublished()
        );

        self::assertTrue(
            $reloadedSecondDiffusion->isPublished()
        );

        $pendingRepository = static::getContainer()->get(
            PendingRebroadcastRepository::class
        );

        self::assertCount(
            1,
            $pendingRepository
                ->findByAssignmentGroupKey(
                    $fixture['groupKey']
                )
        );

        self::assertSame(
            [],
            $result['unpublishedWeeks']
        );

        self::assertSame(
            1,
            $result['invalidation']['historicalGroups']
        );

        self::assertSame(
            0,
            $result['invalidation']['invalidatedGroups']
        );
    }

    public function testExplicitInvalidationDoesNothingWhenAffectedWeekCannotBeUnpublished(): void
    {
        $fixture = $this->createRegularGroup();

        $firstDiffusion = $this->publishDraft(
            $fixture['rank1Draft']
        );

        $secondDiffusion = $this->publishDraft(
            $fixture['rank2Draft']
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey(
                $fixture['groupKey']
            );

        $this->entityManager->persist($pending);

        /*
     * Diffusion legacy publiée dans la même semaine,
     * volontairement sans Draft lié.
     *
     * La semaine ne peut donc pas être dévalidée automatiquement.
     */
        $legacyEmission = $this->createEmission(
            $fixture['emission']->getCategorie(),
            $fixture['emission']->getEditeur()
        );

        $this->entityManager->persist(
            $legacyEmission
        );

        $this->entityManager->flush();

        $legacyDiffusion = (new Diffusion())
            ->setEmission($legacyEmission)
            ->setSchedule(
                new \DateTime(
                    '2099-01-08 16:00:00'
                ),
                60
            )
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey(
                'legacy_invalidation_without_draft'
            )
            ->markAsPublished();

        $this->entityManager->persist(
            $legacyDiffusion
        );

        $this->entityManager->flush();

        $rank1DraftId = $fixture['rank1Draft']->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();
        $legacyDiffusionId = $legacyDiffusion->getId();

        try {
            $this->mutationService
                ->invalidateRule(
                    $fixture['rule'],
                    new \DateTimeImmutable(
                        '2099-01-05 12:00:00'
                    )
                );

            self::fail(
                'L’invalidation aurait dû être refusée.'
            );
        } catch (\DomainException) {
            /*
         * Comportement attendu.
         *
         * Toutes les semaines sont prévalidées avant la moindre mutation.
         */
        }

        $this->entityManager->clear();

        /*
     * Le groupe ne doit avoir subi aucune invalidation.
     */
        self::assertInstanceOf(
            DiffusionDraft::class,
            $this->draftRepository->find(
                $rank1DraftId
            )
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $this->draftRepository->find(
                $rank2DraftId
            )
        );

        foreach (
            [
                $firstDiffusionId,
                $secondDiffusionId,
                $legacyDiffusionId,
            ] as $diffusionId
        ) {
            $diffusion =
                $this->diffusionRepository->find(
                    $diffusionId
                );

            self::assertInstanceOf(
                Diffusion::class,
                $diffusion
            );

            self::assertTrue(
                $diffusion->isPublished()
            );
        }

        $pendingRepository = static::getContainer()->get(
            PendingRebroadcastRepository::class
        );

        self::assertCount(
            1,
            $pendingRepository
                ->findByAssignmentGroupKey(
                    $fixture['groupKey']
                )
        );
    }

    /**
     * Groupe de base :
     *
     * rang 1 : mardi 06/01/2099 à 20h
     * rang 2 : mercredi 07/01/2099 à 09h
     *
     * @return array{
     *     rule:ProgrammationRule,
     *     rank1Slot:ProgrammationRuleSlot,
     *     rank2Slot:ProgrammationRuleSlot,
     *     emission:Emission,
     *     rank1Draft:DiffusionDraft,
     *     rank2Draft:DiffusionDraft,
     *     groupKey:string
     * }
     */

    public function testDisablingAdditionalOptionLeavesNoActivePending(): void
    {
        $fixture = $this->createRegularGroup();
        $rule = $fixture['rule'];
        $rule->setHasAdditionalRebroadcast(true);
        $pending = (new PendingRebroadcast())->setEmission($fixture['emission'])->setAssignmentGroupKey($fixture['groupKey']);
        $dismissed = (new PendingRebroadcast())->setEmission($fixture['emission'])->setAssignmentGroupKey($fixture['groupKey'])->dismiss();
        $this->entityManager->persist($pending);
        $this->entityManager->persist($dismissed);
        $this->entityManager->flush();
        $dismissedId = $dismissed->getId();
        $rule->setHasAdditionalRebroadcast(false);
        $this->mutationService->synchronizeAfterStructuralChange($rule, new \DateTimeImmutable('2099-01-01'));
        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(PendingRebroadcast::class, $dismissedId)->isDismissed());
        self::assertNotNull($this->draftRepository->find($fixture['rank1Draft']->getId()));
        self::assertNotNull($this->draftRepository->find($fixture['rank2Draft']->getId()));
        self::assertCount(0, $this->entityManager->getRepository(PendingRebroadcast::class)->findBy([
            'assignmentGroupKey' => $fixture['groupKey'], 'status' => PendingRebroadcast::STATUS_PENDING,
        ]), 'Disabling the additional option must leave no active proposal.');
    }

    public function testDeletingRegularSlotDoesNotRestorePendingEvenWhenAdditionalOptionEnabled(): void
    {
        $fixture = $this->createRegularGroup();
        $fixture['rule']->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();
        $draftId = $fixture['rank2Draft']->getId();
        $fixture['rank2Slot']->softDelete();
        $result = $this->mutationService->synchronizeAfterStructuralChange($fixture['rule'], new \DateTimeImmutable('2099-01-01'));
        $this->entityManager->clear();
        self::assertNull($this->draftRepository->find($draftId));
        self::assertNotNull($this->draftRepository->find($fixture['rank1Draft']->getId()));
        self::assertSame(1, $result['synchronization']['removedRegularDrafts']);
        self::assertCount(0, $this->entityManager->getRepository(PendingRebroadcast::class)->findBy([
            'assignmentGroupKey' => $fixture['groupKey'],
        ]), 'Removing a rule-controlled broadcast must not create an additional proposal.');
    }

    private function createRegularGroup(): array
    {
        $editor = $this->createEditor();
        $category = $this->createCategory($editor);
        $rule = $this->createRule($category);

        $rank1Slot = $this->createSlot(
            $rule,
            2,
            '20:00:00',
            60,
            1
        );

        $rank2Slot = $this->createSlot(
            $rule,
            3,
            '09:00:00',
            60,
            2
        );

        $emission = $this->createEmission(
            $category,
            $editor
        );

        $this->entityManager->persist($editor);
        $this->entityManager->persist($category);
        $this->entityManager->persist($rule);
        $this->entityManager->persist($rank1Slot);
        $this->entityManager->persist($rank2Slot);
        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        $groupKey = sprintf(
            'rule_%d_origin_20990106_2000',
            $rule->getId()
        );

        $rank1Draft = $this->createRegularDraft(
            $emission,
            $rank1Slot,
            '2099-01-06 20:00:00',
            1,
            $groupKey
        );

        $rank2Draft = $this->createRegularDraft(
            $emission,
            $rank2Slot,
            '2099-01-07 09:00:00',
            2,
            $groupKey
        );

        $this->entityManager->persist($rank1Draft);
        $this->entityManager->persist($rank2Draft);
        $this->entityManager->flush();

        return [
            'rule' => $rule,
            'rank1Slot' => $rank1Slot,
            'rank2Slot' => $rank2Slot,
            'emission' => $emission,
            'rank1Draft' => $rank1Draft,
            'rank2Draft' => $rank2Draft,
            'groupKey' => $groupKey,
        ];
    }

    /**
     * Crée un Draft + Diffusion publiés indépendants.
     *
     * Cela permet de représenter une semaine de destination déjà validée
     * sans mélanger ce groupe avec la règle testée.
     *
     * @return array{
     *     draft:DiffusionDraft,
     *     diffusion:Diffusion
     * }
     */
    private function createStandalonePublishedDraft(
        string $startsAt
    ): array {
        $editor = $this->createEditor();
        $category = $this->createCategory($editor);
        $rule = $this->createRule($category);

        $date = new \DateTimeImmutable($startsAt);

        $slot = $this->createSlot(
            $rule,
            (int) $date->format('N'),
            $date->format('H:i:s'),
            60,
            1
        );

        $emission = $this->createEmission(
            $category,
            $editor
        );

        $this->entityManager->persist($editor);
        $this->entityManager->persist($category);
        $this->entityManager->persist($rule);
        $this->entityManager->persist($slot);
        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        $groupKey = sprintf(
            'rule_%d_standalone_%s',
            $rule->getId(),
            $date->format('Ymd_Hi')
        );

        $draft = $this->createRegularDraft(
            $emission,
            $slot,
            $startsAt,
            1,
            $groupKey
        );

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        $diffusion = $this->publishDraft($draft);

        return [
            'draft' => $draft,
            'diffusion' => $diffusion,
        ];
    }

    private function publishDraft(
        DiffusionDraft $draft
    ): Diffusion {
        $emission = $draft->getEmission();

        if (!$emission instanceof Emission) {
            throw new \LogicException(
                'Le Draft de test doit posséder une émission.'
            );
        }

        $startsAt = $draft->getHoraireDiffusion();

        if (!$startsAt instanceof \DateTimeImmutable) {
            throw new \LogicException(
                'Le Draft de test doit posséder un horaire.'
            );
        }

        $duration = $draft->getDurationMinutes() ?? 60;

        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setSchedule(
                \DateTime::createFromInterface($startsAt),
                $duration
            )
            ->setNombreDiffusion(
                $draft->getNombreDiffusion() ?? 1
            )
            ->setAssignmentGroupKey(
                $draft->getAssignmentGroupKey()
            )
            ->markAsPublished();

        $this->entityManager->persist($diffusion);

        $draft->markAsPublished(
            $diffusion,
            new \DateTimeImmutable('2098-12-31 12:00:00')
        );

        $this->entityManager->flush();

        return $diffusion;
    }

    private function createEditor(): Editeur
    {
        $editor = new Editeur();

        if (method_exists($editor, 'setNom')) {
            $editor->setNom(
                'Mutation test ' . uniqid()
            );
        }

        if (method_exists($editor, 'setName')) {
            $editor->setName(
                'Mutation test ' . uniqid()
            );
        }

        $editor->setUpdateAt(new \DateTime());

        return $editor;
    }

    private function createCategory(
        Editeur $editor
    ): Categories {
        return (new Categories())
            ->setTitre(
                'Catégorie mutation ' . uniqid()
            )
            ->setSlug('MUT')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie de test mutation'
            )
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime());
    }

    private function createRule(
        Categories $category
    ): ProgrammationRule {
        return (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1)
            ->setValidFrom(
                new \DateTimeImmutable('2099-01-01')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-12-31')
            )
            ->setIsActive(true);
    }

    private function createSlot(
        ProgrammationRule $rule,
        int $dayOfWeek,
        string $startTime,
        int $duration,
        int $rank,
        int $weekOffset = 0
    ): ProgrammationRuleSlot {
        $slot = (new ProgrammationRuleSlot())
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(
                new \DateTimeImmutable($startTime)
            )
            ->setDurationMinutes($duration)
            ->setBroadcastRank($rank)
            ->setWeekOffset($weekOffset)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setIsActive(true);

        $rule->addSlot($slot);

        return $slot;
    }

    private function createEmission(
        Categories $category,
        Editeur $editor
    ): Emission {
        $suffix = uniqid();

        return (new Emission())
            ->setTitre(
                'Emission mutation ' . $suffix
            )
            ->setKeyword(
                'mutation-' . $suffix
            )
            ->setDatepub(
                new \DateTime('2099-01-01')
            )
            ->setRef(
                'MUT-' . $suffix
            )
            ->setDuree(60)
            ->setDescriptif(
                'Emission de test mutation'
            )
            ->setCategorie($category)
            ->setEditeur($editor);
    }

    private function createRegularDraft(
        Emission $emission,
        ProgrammationRuleSlot $slot,
        string $startsAt,
        int $rank,
        string $groupKey
    ): DiffusionDraft {
        return (new DiffusionDraft())
            ->setEmission($emission)
            ->setSlot($slot)
            ->setSchedule(
                new \DateTimeImmutable($startsAt),
                $slot->getDurationMinutes() ?? 60
            )
            ->setNombreDiffusion($rank)
            ->setDraftType(
                DiffusionDraft::TYPE_REGULAR
            )
            ->setAssignmentGroupKey($groupKey)
            ->setPublicationStatus(
                DiffusionDraft::STATUS_DRAFT
            );
    }
}
