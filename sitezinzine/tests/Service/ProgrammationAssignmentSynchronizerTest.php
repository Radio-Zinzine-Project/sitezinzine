<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\PendingRebroadcastRepository;
use App\Service\ProgrammationAssignmentSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProgrammationAssignmentSynchronizerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ProgrammationAssignmentSynchronizer $synchronizer;
    private DiffusionDraftRepository $draftRepository;
    private DiffusionRepository $diffusionRepository;
    private PendingRebroadcastRepository $pendingRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->draftRepository = $container->get(
            DiffusionDraftRepository::class
        );

        $this->diffusionRepository = $container->get(
            DiffusionRepository::class
        );

        $this->pendingRepository = $container->get(
            PendingRebroadcastRepository::class
        );

        $gridBuilder = new \App\Service\ProgrammationGridBuilder(
            $container->get(
                \App\Repository\ProgrammationRuleRepository::class
            )
        );

        $pendingRebroadcastService = $container->get(
            \App\Service\PendingRebroadcastService::class
        );

        $this->synchronizer = new ProgrammationAssignmentSynchronizer(
            $this->draftRepository,
            $this->diffusionRepository,
            $this->pendingRepository,
            $gridBuilder,
            $pendingRebroadcastService,
            $this->entityManager
        );

        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (
            isset($this->entityManager)
            && $this->entityManager->getConnection()->isTransactionActive()
        ) {
            $this->entityManager->getConnection()->rollBack();
        }

        if (isset($this->entityManager)) {
            $this->entityManager->clear();
        }

        parent::tearDown();
    }

    /**
     * @dataProvider monthlyOriginChangeCases
     */
    public function testMonthlyOriginChangePreservesHistoricalGroupsAndInvalidatesFutureGroups(
        bool $historical,
        bool $withFirstDraft
    ): void {
        $fixture = $this->createRegularGroup();
        $rule = $fixture['rule'];
        $rule->setValidFrom(new \DateTimeImmutable('2026-01-01'))
            ->setValidUntil(new \DateTimeImmutable('2026-12-31'));
        $fixture['rank1Slot']->setDayOfWeek(7)
            ->setStartTime(new \DateTimeImmutable('22:00:00'))
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_MONTHLY)
            ->setMonthlyOccurrence(2);
        $fixture['rank2Slot']->setDayOfWeek(3)
            ->setStartTime(new \DateTimeImmutable('14:00:00'))
            ->setWeekOffset(4);
        $groupKey = sprintf('rule_%d_origin_20260809_2200', $rule->getId());
        $fixture['rank1Draft']->setAssignmentGroupKey($groupKey)
            ->setSchedule(new \DateTimeImmutable('2026-08-09 22:00:00'), 60);
        $fixture['rank2Draft']->setAssignmentGroupKey($groupKey)
            ->setSchedule(new \DateTimeImmutable('2026-09-02 14:00:00'), 60);

        if (!$withFirstDraft) {
            // Fixture de couverture historique : seule la rediffusion a un Draft.
            $this->entityManager->remove($fixture['rank1Draft']);
        }
        $this->entityManager->flush();

        $source = $this->createPublishedDiffusion(
            $fixture['emission'], $groupKey, '2026-08-09 22:00:00',
            $withFirstDraft ? 1 : 2
        );
        $rebroadcast = $this->createPublishedDiffusion(
            $fixture['emission'], $groupKey, '2026-09-02 14:00:00', 2
        );
        if ($withFirstDraft) {
            $fixture['rank1Draft']->markAsPublished($source, new \DateTimeImmutable('2026-08-01'));
        }
        $fixture['rank2Draft']->markAsPublished($rebroadcast, new \DateTimeImmutable('2026-08-01'));
        $pending = (new PendingRebroadcast())->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $snapshot = function () use ($groupKey): array {
            $rows = [];
            foreach (['diffusion_draft', 'diffusion', 'pending_rebroadcast'] as $table) {
                $rows[$table] = $this->entityManager->getConnection()->fetchAllAssociative(
                    'SELECT * FROM ' . $table . ' WHERE assignment_group_key = ? ORDER BY id',
                    [$groupKey]
                );
            }
            return $rows;
        };
        $before = $snapshot();
        self::assertCount($withFirstDraft ? 2 : 1, $before['diffusion_draft']);

        $fixture['rank1Slot']->setMonthlyOccurrence(3);
        $this->entityManager->flush();
        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable($historical ? '2026-08-10 12:00:00' : '2026-08-01 12:00:00')
        );
        $after = $snapshot();

        self::assertSame(1, $result['processedGroups']);
        self::assertSame($historical ? 1 : 0, $result['historicalGroups']);
        self::assertSame($historical ? 0 : 1, $result['invalidatedGroups']);
        self::assertSame($historical ? 0 : 2, $result['removedRegularDrafts']);
        self::assertSame(0, $result['createdRegularDrafts']);
        self::assertSame(0, $result['movedRegularDrafts']);
        self::assertSame(0, $result['unpublishedDiffusions']);
        if ($historical) {
            self::assertSame($before, $after);
        } else {
            self::assertSame([], $after['diffusion_draft']);
            self::assertSame([], $after['pending_rebroadcast']);
            self::assertSame($before['diffusion'], $after['diffusion']);
        }
    }

    public static function monthlyOriginChangeCases(): iterable
    {
        yield 'historical rebroadcast only' => [true, false];
        yield 'historical complete group' => [true, true];
        yield 'future complete group' => [false, true];
    }

    public function testFutureGroupWithoutFirstDraftStillRejectsSynchronization(): void
    {
        $fixture = $this->createRegularGroup();
        $this->entityManager->remove($fixture['rank1Draft']);
        $this->entityManager->flush();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'Le groupe régulier "%s" ne possède aucun Draft de rang 1.', $fixture['groupKey']
        ));
        $this->synchronizer->synchronizeRule(
            $fixture['rule'], new \DateTimeImmutable('2099-01-01')
        );
    }

    public function testRegularRebroadcastMovesWhenSlotScheduleChanges(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $rank2Slot = $fixture['rank2Slot'];
        $rank2DraftId = $fixture['rank2Draft']->getId();

        $rank2Slot->setStartTime(
            new \DateTimeImmutable('10:00:00')
        );

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        $rank2Draft = $this->draftRepository->find($rank2DraftId);

        self::assertInstanceOf(DiffusionDraft::class, $rank2Draft);
        self::assertSame(
            '2099-01-07 10:00:00',
            $rank2Draft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        self::assertSame(1, $result['processedGroups']);
        self::assertSame(1, $result['movedRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
        self::assertSame(0, $result['invalidatedGroups']);
    }

    public function testNewRegularRebroadcastSlotReceivesSameEmission(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $emissionId = $fixture['emission']->getId();
        $groupKey = $fixture['groupKey'];

        $rank3Slot = $this->createSlot(
            $rule,
            4,
            '12:00:00',
            60,
            3
        );

        $this->entityManager->persist($rank3Slot);
        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        $groupDrafts = $this->draftRepository
            ->findByAssignmentGroupKey($groupKey);

        $rank3Draft = null;

        foreach ($groupDrafts as $draft) {
            if (
                $draft instanceof DiffusionDraft
                && $draft->isRegular()
                && 3 === $draft->getNombreDiffusion()
            ) {
                $rank3Draft = $draft;
                break;
            }
        }

        self::assertInstanceOf(DiffusionDraft::class, $rank3Draft);
        self::assertSame(
            $emissionId,
            $rank3Draft->getEmission()?->getId()
        );
        self::assertSame(
            '2099-01-08 12:00:00',
            $rank3Draft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );
        self::assertSame(3, $rank3Draft->getNombreDiffusion());

        self::assertSame(1, $result['createdRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testRemovedRegularRebroadcastDoesNotCreateAdditionalPending(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        self::assertFalse($rule->hasAdditionalRebroadcast());
        $rank2Slot = $fixture['rank2Slot'];
        $rank2DraftId = $fixture['rank2Draft']->getId();
        $groupKey = $fixture['groupKey'];

        $rank2Slot->softDelete();

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->draftRepository->find($rank2DraftId)
        );

        $pending = $this->pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(0, $pending, 'Regular scheduling must not create an unauthorized pending.');

        self::assertSame(1, $result['removedRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
        self::assertSame(0, $result['invalidatedGroups']);
    }

    public function testChronologicallyInvalidRegularRankDoesNotCreateAdditionalPending(): void
    {
        $fixture = $this->createRegularGroupWithThreeRanks();

        $rule = $fixture['rule'];
        self::assertFalse($rule->hasAdditionalRebroadcast());
        $rank2Slot = $fixture['rank2Slot'];
        $rank3DraftId = $fixture['rank3Draft']->getId();
        $groupKey = $fixture['groupKey'];

        /*
         * Avant :
         * rang 1 mardi 20h
         * rang 2 mercredi 10h
         * rang 3 jeudi 18h
         *
         * Après :
         * rang 2 vendredi 10h
         * rang 3 jeudi 18h
         *
         * Le rang 3 devient chronologiquement incohérent.
         */
        $rank2Slot
            ->setDayOfWeek(5)
            ->setStartTime(
                new \DateTimeImmutable('10:00:00')
            );

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->draftRepository->find($rank3DraftId)
        );

        $pending = $this->pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(0, $pending, 'Regular scheduling must not create an unauthorized pending.');

        self::assertSame(1, $result['movedRegularDrafts']);
        self::assertSame(1, $result['removedRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testIncompatibleManualRebroadcastWithoutOptionDoesNotReturnToPool(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        self::assertFalse($rule->hasAdditionalRebroadcast());
        $rank2Slot = $fixture['rank2Slot'];
        $groupKey = $fixture['groupKey'];

        $manualDraft = (new DiffusionDraft())
            ->setEmission($fixture['emission'])
            ->setSlot(null)
            ->setSchedule(
                new \DateTimeImmutable('2099-01-08 12:00:00'),
                60
            )
            ->setNombreDiffusion(3)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setAssignmentGroupKey($groupKey)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT);

        $this->entityManager->persist($manualDraft);
        $this->entityManager->flush();

        $manualDraftId = $manualDraft->getId();

        /*
         * La régulière de rang 2 est déplacée après la rediffusion manuelle.
         * La rediffusion manuelle n'est donc plus valide.
         */
        $rank2Slot
            ->setDayOfWeek(5)
            ->setStartTime(
                new \DateTimeImmutable('18:00:00')
            );

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->draftRepository->find($manualDraftId)
        );

        $pending = $this->pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(0, $pending, 'Regular scheduling must not create an unauthorized pending.');

        self::assertSame(1, $result['movedRegularDrafts']);
        self::assertSame(1, $result['removedManualRebroadcasts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testFirstOccurrenceDisappearingRemovesWholeFutureGroupAndExistingPending(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];

        $pending = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        /*
         * Le rang 1 ne produit plus l'ancienne occurrence du mardi 20h.
         * Le groupe existant ne doit pas être migré vers mercredi.
         */
        $fixture['rank1Slot']->setDayOfWeek(3);

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertSame(
            [],
            $this->draftRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        self::assertSame(
            [],
            $this->pendingRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        self::assertSame(1, $result['invalidatedGroups']);
        self::assertSame(2, $result['removedRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testFuturePublishedGroupInvalidationDoesNotDirectlyUnpublishDiffusions(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];

        $firstDiffusion = $this->createPublishedDiffusion(
            $fixture['emission'],
            $groupKey,
            '2099-01-06 20:00:00',
            1
        );

        $secondDiffusion = $this->createPublishedDiffusion(
            $fixture['emission'],
            $groupKey,
            '2099-01-07 09:00:00',
            2
        );

        $fixture['rank1Draft']->markAsPublished(
            $firstDiffusion,
            new \DateTimeImmutable('2098-12-31 12:00:00')
        );

        $fixture['rank2Draft']->markAsPublished(
            $secondDiffusion,
            new \DateTimeImmutable('2098-12-31 12:00:00')
        );

        $this->entityManager->flush();

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        /*
     * Le rang 1 ne produit plus l'occurrence d'origine.
     *
     * Le groupe futur doit donc être invalidé structurellement.
     *
     * IMPORTANT :
     * le synchronizer n'est cependant plus responsable de la
     * dévalidation des Diffusion publiées.
     *
     * En production, ProgrammationRuleMutationService doit avoir
     * dévalidé la semaine complète AVANT d'appeler le synchronizer.
     */
        $fixture['rank1Slot']->setDayOfWeek(3);

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-05 12:00:00')
        );

        $this->entityManager->clear();

        /*
     * Le travail structurel du synchronizer est bien effectué :
     * le groupe de Drafts disparaît.
     */
        self::assertSame(
            [],
            $this->draftRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        /*
     * En revanche, les Diffusion ne doivent plus être modifiées
     * directement par le synchronizer.
     *
     * Leur dépublication appartient désormais exclusivement à
     * ProgrammationRuleMutationService + GridUnpublicationService.
     */
        $reloadedFirst = $this->diffusionRepository->find(
            $firstDiffusionId
        );

        $reloadedSecond = $this->diffusionRepository->find(
            $secondDiffusionId
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirst
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecond
        );

        self::assertTrue(
            $reloadedFirst->isPublished()
        );

        self::assertTrue(
            $reloadedSecond->isPublished()
        );

        self::assertSame(
            1,
            $result['invalidatedGroups']
        );

        self::assertSame(
            2,
            $result['removedRegularDrafts']
        );

        self::assertSame(
            0,
            $result['createdPendingRebroadcasts']
        );

        self::assertSame(
            0,
            $result['unpublishedDiffusions']
        );
    }

    public function testHistoricalPublishedGroupIsCompletelyFrozen(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];

        $firstDiffusion = $this->createPublishedDiffusion(
            $fixture['emission'],
            $groupKey,
            '2099-01-06 20:00:00',
            1
        );

        /*
         * Cette rediffusion est encore future au moment du sync.
         * Elle doit néanmoins rester publiée car le groupe a déjà commencé.
         */
        $secondDiffusion = $this->createPublishedDiffusion(
            $fixture['emission'],
            $groupKey,
            '2099-01-08 09:00:00',
            2
        );

        $fixture['rank1Draft']->markAsPublished(
            $firstDiffusion,
            new \DateTimeImmutable('2099-01-06 19:00:00')
        );

        $fixture['rank2Draft']->markAsPublished(
            $secondDiffusion,
            new \DateTimeImmutable('2099-01-06 19:00:00')
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $rank1DraftId = $fixture['rank1Draft']->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();
        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        /*
         * On détruit volontairement la structure théorique du groupe.
         * Cela ne doit avoir aucun effet car sa première Diffusion publiée
         * appartient déjà à l'historique.
         */
        $rule->setIsActive(false);

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-07 12:00:00')
        );

        $this->entityManager->clear();

        self::assertInstanceOf(
            DiffusionDraft::class,
            $this->draftRepository->find($rank1DraftId)
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $this->draftRepository->find($rank2DraftId)
        );

        $reloadedFirst = $this->diffusionRepository->find(
            $firstDiffusionId
        );
        $reloadedSecond = $this->diffusionRepository->find(
            $secondDiffusionId
        );

        self::assertInstanceOf(Diffusion::class, $reloadedFirst);
        self::assertInstanceOf(Diffusion::class, $reloadedSecond);

        self::assertTrue($reloadedFirst->isPublished());
        self::assertTrue($reloadedSecond->isPublished());

        self::assertCount(
            1,
            $this->pendingRepository
                ->findByAssignmentGroupKey($groupKey)
        );

        self::assertSame(1, $result['processedGroups']);
        self::assertSame(1, $result['historicalGroups']);
        self::assertSame(0, $result['invalidatedGroups']);
        self::assertSame(0, $result['unpublishedDiffusions']);
        self::assertSame(0, $result['removedRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testInactiveRuleStillSynchronizesItsStructureWithoutInvalidatingWholeGroup(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $rank2Slot = $fixture['rank2Slot'];
        $rank1DraftId = $fixture['rank1Draft']->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();
        $groupKey = $fixture['groupKey'];

        /*
     * La règle est désactivée comme elle pourrait l'être automatiquement
     * après l'apparition d'un conflit structurel.
     *
     * Cette désactivation ne doit pas, à elle seule, invalider le groupe.
     */
        $rule->setIsActive(false);

        /*
     * La structure change néanmoins réellement :
     * la rediffusion régulière de rang 2 passe de 09h à 10h.
     *
     * synchronizeRule() doit donc continuer à synchroniser la structure,
     * même si la règle est inactive.
     */
        $rank2Slot->setStartTime(
            new \DateTimeImmutable('10:00:00')
        );

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        $rank1Draft = $this->draftRepository->find($rank1DraftId);
        $rank2Draft = $this->draftRepository->find($rank2DraftId);

        self::assertInstanceOf(DiffusionDraft::class, $rank1Draft);
        self::assertInstanceOf(DiffusionDraft::class, $rank2Draft);

        self::assertSame(
            '2099-01-06 20:00:00',
            $rank1Draft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-01-07 10:00:00',
            $rank2Draft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        self::assertCount(
            2,
            $this->draftRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        self::assertSame(1, $result['processedGroups']);
        self::assertSame(0, $result['historicalGroups']);
        self::assertSame(0, $result['invalidatedGroups']);
        self::assertSame(1, $result['movedRegularDrafts']);
        self::assertSame(0, $result['removedRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testExplicitRuleInvalidationRemovesFutureGroupWithoutDirectlyUnpublishingDiffusions(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];

        /*
     * Un Pending existe déjà pour ce groupe.
     *
     * Une invalidation explicite doit supprimer l'ensemble
     * du groupe futur, Pending compris.
     */
        $pending = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);

        /*
     * Le groupe possède également deux Diffusion publiées futures.
     *
     * Elles permettent de vérifier que le synchronizer ne possède
     * plus la responsabilité de leur dépublication.
     */
        $firstDiffusion = $this->createPublishedDiffusion(
            $fixture['emission'],
            $groupKey,
            '2099-01-06 20:00:00',
            1
        );

        $secondDiffusion = $this->createPublishedDiffusion(
            $fixture['emission'],
            $groupKey,
            '2099-01-07 09:00:00',
            2
        );

        $fixture['rank1Draft']->markAsPublished(
            $firstDiffusion,
            new \DateTimeImmutable('2098-12-31 12:00:00')
        );

        $fixture['rank2Draft']->markAsPublished(
            $secondDiffusion,
            new \DateTimeImmutable('2098-12-31 12:00:00')
        );

        $this->entityManager->flush();

        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        /*
     * L'intention métier est explicite : la règle est invalidée.
     *
     * Le synchronizer doit supprimer les données de travail du groupe,
     * mais il ne doit plus modifier directement les Diffusion.
     *
     * La dévalidation des semaines appartient à
     * ProgrammationRuleMutationService.
     */
        $rule->setIsActive(false);

        $this->entityManager->flush();

        $result = $this->synchronizer->invalidateRule(
            $rule,
            new \DateTimeImmutable('2099-01-05 12:00:00')
        );

        $this->entityManager->clear();

        /*
     * Tous les Drafts du groupe futur sont supprimés.
     */
        self::assertSame(
            [],
            $this->draftRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        /*
     * Le Pending appartient lui aussi au groupe invalidé
     * et doit disparaître.
     */
        self::assertSame(
            [],
            $this->pendingRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        /*
     * Les Diffusion restent publiées :
     *
     * ce test appelle volontairement le synchronizer directement,
     * sans passer par ProgrammationRuleMutationService.
     *
     * Le synchronizer ne doit donc plus changer leur statut.
     */
        $reloadedFirst = $this->diffusionRepository->find(
            $firstDiffusionId
        );

        $reloadedSecond = $this->diffusionRepository->find(
            $secondDiffusionId
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedFirst
        );

        self::assertInstanceOf(
            Diffusion::class,
            $reloadedSecond
        );

        self::assertTrue(
            $reloadedFirst->isPublished()
        );

        self::assertTrue(
            $reloadedSecond->isPublished()
        );

        self::assertSame(
            1,
            $result['processedGroups']
        );

        self::assertSame(
            0,
            $result['historicalGroups']
        );

        self::assertSame(
            1,
            $result['invalidatedGroups']
        );

        self::assertSame(
            2,
            $result['removedRegularDrafts']
        );

        self::assertSame(
            0,
            $result['removedManualRebroadcasts']
        );

        self::assertSame(
            0,
            $result['createdPendingRebroadcasts']
        );

        self::assertSame(
            0,
            $result['unpublishedDiffusions']
        );
    }

    public function testExplicitRuleInvalidationKeepsHistoricalPublishedGroupFrozen(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];

        $firstDiffusion = $this->createPublishedDiffusion(
            $fixture['emission'],
            $groupKey,
            '2099-01-06 20:00:00',
            1
        );

        /*
     * Cette rediffusion est encore future au moment de l'invalidation.
     *
     * Elle doit néanmoins rester publiée puisque la première diffusion
     * du groupe a déjà eu lieu.
     */
        $secondDiffusion = $this->createPublishedDiffusion(
            $fixture['emission'],
            $groupKey,
            '2099-01-08 09:00:00',
            2
        );

        $fixture['rank1Draft']->markAsPublished(
            $firstDiffusion,
            new \DateTimeImmutable('2099-01-06 19:00:00')
        );

        $fixture['rank2Draft']->markAsPublished(
            $secondDiffusion,
            new \DateTimeImmutable('2099-01-06 19:00:00')
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $rank1DraftId = $fixture['rank1Draft']->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();
        $firstDiffusionId = $firstDiffusion->getId();
        $secondDiffusionId = $secondDiffusion->getId();

        /*
     * Même une invalidation explicite de la règle ne peut plus modifier
     * un groupe dont la première Diffusion publiée appartient au passé.
     */
        $rule->setIsActive(false);

        $this->entityManager->flush();

        $result = $this->synchronizer->invalidateRule(
            $rule,
            new \DateTimeImmutable('2099-01-07 12:00:00')
        );

        $this->entityManager->clear();

        self::assertInstanceOf(
            DiffusionDraft::class,
            $this->draftRepository->find($rank1DraftId)
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $this->draftRepository->find($rank2DraftId)
        );

        $reloadedFirst = $this->diffusionRepository->find(
            $firstDiffusionId
        );

        $reloadedSecond = $this->diffusionRepository->find(
            $secondDiffusionId
        );

        self::assertInstanceOf(Diffusion::class, $reloadedFirst);
        self::assertInstanceOf(Diffusion::class, $reloadedSecond);

        self::assertTrue($reloadedFirst->isPublished());
        self::assertTrue($reloadedSecond->isPublished());

        self::assertCount(
            1,
            $this->pendingRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        self::assertSame(1, $result['processedGroups']);
        self::assertSame(1, $result['historicalGroups']);
        self::assertSame(0, $result['invalidatedGroups']);
        self::assertSame(0, $result['removedRegularDrafts']);
        self::assertSame(0, $result['removedManualRebroadcasts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
        self::assertSame(0, $result['unpublishedDiffusions']);
    }

    public function testNewRegularSlotWithoutIdReceivesSameEmission(): void
    {
        [
            'rule' => $rule,
            'emission' => $emission,
            'groupKey' => $groupKey,
        ] = $this->createRegularGroup();

        /*
     * La fixture possède déjà :
     *
     * - rang 1 : mardi 20h ;
     * - rang 2 : mercredi 9h.
     *
     * On ajoute donc réellement un NOUVEAU rang structurel : le rang 3.
     *
     * Le slot n'est volontairement pas persisté et n'a donc aucun ID.
     * C'est l'état d'un nouveau slot entre le traitement du formulaire
     * et le flush final de l'orchestration.
     */
        $newSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setDayOfWeek(4)
            ->setStartTime(
                new \DateTimeImmutable('18:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(3)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $rule->addSlot($newSlot);

        self::assertNull($newSlot->getId());

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 12:00:00')
        );

        $drafts = $this->draftRepository
            ->findByAssignmentGroupKey($groupKey);

        $regularDrafts = array_values(
            array_filter(
                $drafts,
                static fn(DiffusionDraft $draft): bool =>
                DiffusionDraft::TYPE_REGULAR
                    === $draft->getDraftType()
            )
        );

        self::assertCount(3, $regularDrafts);

        $createdDraft = null;

        foreach ($regularDrafts as $draft) {
            if (3 === $draft->getNombreDiffusion()) {
                $createdDraft = $draft;

                break;
            }
        }

        self::assertInstanceOf(
            DiffusionDraft::class,
            $createdDraft
        );

        self::assertSame(
            $emission,
            $createdDraft->getEmission()
        );

        self::assertSame(
            $newSlot,
            $createdDraft->getSlot()
        );

        self::assertSame(
            $groupKey,
            $createdDraft->getAssignmentGroupKey()
        );

        self::assertSame(
            '2099-01-08 18:00:00',
            $createdDraft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            3,
            $createdDraft->getNombreDiffusion()
        );

        self::assertSame(
            1,
            $result['createdRegularDrafts']
        );

        self::assertSame(
            0,
            $result['removedRegularDrafts']
        );
    }

    public function testExistingRegularDraftCanUseUnpersistedReplacementSlot(): void
    {
        [
            'rule' => $rule,
            'groupKey' => $groupKey,
        ] = $this->createRegularGroup();

        $drafts = $this->draftRepository
            ->findByAssignmentGroupKey($groupKey);

        $secondDraft = null;
        $oldSecondSlot = null;

        foreach ($drafts as $draft) {
            if (
                DiffusionDraft::TYPE_REGULAR
                !== $draft->getDraftType()
                || 2 !== $draft->getNombreDiffusion()
            ) {
                continue;
            }

            $secondDraft = $draft;
            $oldSecondSlot = $draft->getSlot();

            break;
        }

        self::assertInstanceOf(
            DiffusionDraft::class,
            $secondDraft
        );

        self::assertInstanceOf(
            ProgrammationRuleSlot::class,
            $oldSecondSlot
        );

        /*
     * L'ancien rang 2 sort de la structure attendue.
     */
        $oldSecondSlot->softDelete();

        /*
     * Nouveau slot de même rang, volontairement sans ID.
     *
     * Le synchronizer doit pouvoir le retrouver par son identité
     * structurelle — ici le broadcastRank — sans nécessiter de flush.
     */
        $replacementSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setDayOfWeek(5)
            ->setStartTime(
                new \DateTimeImmutable('10:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $rule->addSlot($replacementSlot);

        self::assertNull($replacementSlot->getId());

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 12:00:00')
        );

        self::assertSame(
            $replacementSlot,
            $secondDraft->getSlot()
        );

        self::assertSame(
            '2099-01-09 10:00:00',
            $secondDraft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            2,
            $secondDraft->getNombreDiffusion()
        );

        self::assertSame(
            1,
            $result['movedRegularDrafts']
        );

        self::assertSame(
            0,
            $result['createdRegularDrafts']
        );

        self::assertSame(
            0,
            $result['removedRegularDrafts']
        );
    }

    public function testExistingRegularDraftDetectsUnpersistedReplacementSlotEvenWhenScheduleIsUnchanged(): void
    {
        [
            'rule' => $rule,
            'groupKey' => $groupKey,
        ] = $this->createRegularGroup();

        $drafts = $this->draftRepository
            ->findByAssignmentGroupKey($groupKey);

        $secondDraft = null;
        $oldSecondSlot = null;

        foreach ($drafts as $draft) {
            if (
                DiffusionDraft::TYPE_REGULAR
                !== $draft->getDraftType()
                || 2 !== $draft->getNombreDiffusion()
            ) {
                continue;
            }

            $secondDraft = $draft;
            $oldSecondSlot = $draft->getSlot();

            break;
        }

        self::assertInstanceOf(
            DiffusionDraft::class,
            $secondDraft
        );

        self::assertInstanceOf(
            ProgrammationRuleSlot::class,
            $oldSecondSlot
        );

        $originalStartsAt = $secondDraft->getHoraireDiffusion();
        $originalDuration = $secondDraft->getDurationMinutes();

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $originalStartsAt
        );

        /*
     * L'ancien slot sort de la structure attendue.
     */
        $oldSecondSlot->softDelete();

        /*
     * On crée un NOUVEL objet slot avec exactement la même structure :
     *
     * - même rang ;
     * - même jour ;
     * - même heure ;
     * - même durée ;
     * - même récurrence.
     *
     * La seule différence est donc l'identité du slot.
     *
     * Il n'est volontairement pas persisté : son ID reste null
     * jusqu'au flush final.
     */
        $replacementSlot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setDayOfWeek(
                $oldSecondSlot->getDayOfWeek()
            )
            ->setStartTime(
                clone $oldSecondSlot->getStartTime()
            )
            ->setDurationMinutes(
                $oldSecondSlot->getDurationMinutes()
            )
            ->setBroadcastRank(
                $oldSecondSlot->getBroadcastRank()
            )
            ->setWeekOffset(
                $oldSecondSlot->getWeekOffset()
            )
            ->setWeekParity(
                $oldSecondSlot->getWeekParity()
            )
            ->setIsActive(true);

        $rule->addSlot($replacementSlot);

        self::assertNull($replacementSlot->getId());

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 12:00:00')
        );

        /*
     * L'horaire et la durée n'ont pas changé.
     */
        self::assertSame(
            $originalStartsAt->format('Y-m-d H:i:s'),
            $secondDraft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            $originalDuration,
            $secondDraft->getDurationMinutes()
        );

        /*
     * Mais le Draft doit maintenant référencer le NOUVEAU slot.
     */
        self::assertSame(
            $replacementSlot,
            $secondDraft->getSlot()
        );

        self::assertNotSame(
            $oldSecondSlot,
            $secondDraft->getSlot()
        );

        self::assertSame(
            2,
            $secondDraft->getNombreDiffusion()
        );

        self::assertSame(
            1,
            $result['movedRegularDrafts']
        );

        self::assertSame(
            0,
            $result['createdRegularDrafts']
        );

        self::assertSame(
            0,
            $result['removedRegularDrafts']
        );
    }

    public function testFirstRegularDraftUpdatesWhenItsDurationChangesWithoutChangingOrigin(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $rank1Slot = $fixture['rank1Slot'];
        $rank1DraftId = $fixture['rank1Draft']->getId();
        $groupKey = $fixture['groupKey'];

        /*
     * L'origine du groupe reste exactement la même :
     * mardi 06/01/2099 à 20h.
     *
     * Seule la durée du premier passage change.
     * Le groupe ne doit donc surtout pas être invalidé.
     */
        $rank1Slot->setDurationMinutes(90);

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        $rank1Draft = $this->draftRepository->find($rank1DraftId);

        self::assertInstanceOf(DiffusionDraft::class, $rank1Draft);

        self::assertSame(
            '2099-01-06 20:00:00',
            $rank1Draft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-01-06 21:30:00',
            $rank1Draft->getEndsAt()?->format('Y-m-d H:i:s')
        );

        self::assertSame(90, $rank1Draft->getDurationMinutes());

        self::assertCount(
            2,
            $this->draftRepository->findByAssignmentGroupKey($groupKey)
        );

        self::assertSame(1, $result['processedGroups']);
        self::assertSame(0, $result['invalidatedGroups']);
        self::assertSame(1, $result['movedRegularDrafts']);
        self::assertSame(0, $result['createdRegularDrafts']);
        self::assertSame(0, $result['removedRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testManualRebroadcastAfterRegularScheduleIsPreserved(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];

        /*
     * Les régulières sont :
     *
     * rang 1 : mardi 06/01 à 20h
     * rang 2 : mercredi 07/01 à 09h
     *
     * Cette rediffusion manuelle a lieu jeudi à 12h :
     * elle reste donc bien après toute la chaîne régulière.
     */
        $manualDraft = (new DiffusionDraft())
            ->setEmission($fixture['emission'])
            ->setSlot(null)
            ->setSchedule(
                new \DateTimeImmutable('2099-01-08 12:00:00'),
                60
            )
            ->setNombreDiffusion(3)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setAssignmentGroupKey($groupKey)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT);

        $this->entityManager->persist($manualDraft);
        $this->entityManager->flush();

        $manualDraftId = $manualDraft->getId();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        $reloadedManualDraft = $this->draftRepository->find(
            $manualDraftId
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $reloadedManualDraft
        );

        self::assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $reloadedManualDraft->getDraftType()
        );

        self::assertSame(
            '2099-01-08 12:00:00',
            $reloadedManualDraft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            [],
            $this->pendingRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        self::assertSame(1, $result['processedGroups']);
        self::assertSame(0, $result['invalidatedGroups']);
        self::assertSame(0, $result['removedManualRebroadcasts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testExplicitRuleInvalidationRemovesManualRebroadcastFromFutureGroup(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];

        $manualDraft = (new DiffusionDraft())
            ->setEmission($fixture['emission'])
            ->setSlot(null)
            ->setSchedule(
                new \DateTimeImmutable('2099-01-08 12:00:00'),
                60
            )
            ->setNombreDiffusion(3)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setAssignmentGroupKey($groupKey)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT);

        $this->entityManager->persist($manualDraft);
        $this->entityManager->flush();

        $manualDraftId = $manualDraft->getId();

        /*
     * Il s'agit ici d'une invalidation structurelle explicite.
     * Le groupe futur complet doit disparaître :
     *
     * - Drafts réguliers ;
     * - rediffusions manuelles ;
     * - éventuels Pending.
     */
        $rule->setIsActive(false);

        $this->entityManager->flush();

        $result = $this->synchronizer->invalidateRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->draftRepository->find($manualDraftId)
        );

        self::assertSame(
            [],
            $this->draftRepository->findByAssignmentGroupKey(
                $groupKey
            )
        );

        self::assertSame(1, $result['processedGroups']);
        self::assertSame(0, $result['historicalGroups']);
        self::assertSame(1, $result['invalidatedGroups']);
        self::assertSame(2, $result['removedRegularDrafts']);
        self::assertSame(1, $result['removedManualRebroadcasts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
        self::assertSame(0, $result['unpublishedDiffusions']);
    }

    public function testStructuralInvalidationPreservesDismissedPending(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];
        $emissionId = $fixture['emission']->getId();

        $pending = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $pending->dismiss();

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();

        // Le rang 1 ne correspond plus à l'origine du groupe.
        $fixture['rank1Slot']->setDayOfWeek(3);
        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        $reloadedPending = $this->pendingRepository->find($pendingId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $reloadedPending
        );

        self::assertTrue($reloadedPending->isDismissed());
        self::assertSame(
            $emissionId,
            $reloadedPending->getEmission()?->getId()
        );
        self::assertSame(
            $groupKey,
            $reloadedPending->getAssignmentGroupKey()
        );

        self::assertSame(
            [],
            $this->draftRepository->findByAssignmentGroupKey($groupKey)
        );

        self::assertSame(1, $result['invalidatedGroups']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testStructuralInvalidationRemovesActivePendingButPreservesDismissed(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];
        $emissionId = $fixture['emission']->getId();

        $dismissed = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $dismissed->dismiss();

        $active = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($dismissed);
        $this->entityManager->persist($active);
        $this->entityManager->flush();

        $dismissedId = $dismissed->getId();
        $activeId = $active->getId();

        // Invalidation structurelle du groupe.
        $fixture['rank1Slot']->setDayOfWeek(3);
        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->pendingRepository->find($activeId)
        );

        $reloadedDismissed = $this->pendingRepository->find($dismissedId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $reloadedDismissed
        );
        self::assertTrue($reloadedDismissed->isDismissed());
        self::assertSame(
            $emissionId,
            $reloadedDismissed->getEmission()?->getId()
        );
        self::assertSame(
            $groupKey,
            $reloadedDismissed->getAssignmentGroupKey()
        );

        $remaining = $this->pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(1, $remaining);
        self::assertSame($dismissedId, $remaining[0]->getId());

        self::assertSame(
            [],
            $this->draftRepository->findByAssignmentGroupKey($groupKey)
        );

        self::assertSame(1, $result['invalidatedGroups']);
    }

    public function testExplicitInvalidationPreservesDismissedPending(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];
        $emissionId = $fixture['emission']->getId();

        $dismissed = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $dismissed->dismiss();

        $this->entityManager->persist($dismissed);
        $this->entityManager->flush();

        $dismissedId = $dismissed->getId();

        $rule->setIsActive(false);
        $this->entityManager->flush();

        $result = $this->synchronizer->invalidateRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        $reloadedDismissed = $this->pendingRepository->find($dismissedId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $reloadedDismissed
        );
        self::assertTrue($reloadedDismissed->isDismissed());
        self::assertSame(
            $emissionId,
            $reloadedDismissed->getEmission()?->getId()
        );
        self::assertSame(
            $groupKey,
            $reloadedDismissed->getAssignmentGroupKey()
        );

        self::assertSame(
            [],
            $this->draftRepository->findByAssignmentGroupKey($groupKey)
        );

        self::assertSame(1, $result['invalidatedGroups']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testRemovedRegularRebroadcastDoesNotRestoreDismissedPending(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $rank2Slot = $fixture['rank2Slot'];
        $rank2DraftId = $fixture['rank2Draft']->getId();
        $groupKey = $fixture['groupKey'];
        $emissionId = $fixture['emission']->getId();

        /*
     * L'utilisateur a volontairement retiré
     * cette émission du parc à rediff.
     */
        $dismissed = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $dismissed->dismiss();

        $this->entityManager->persist($dismissed);
        $this->entityManager->flush();

        $dismissedId = $dismissed->getId();

        /*
     * Suppression de la rediffusion régulière.
     */
        $rank2Slot->softDelete();
        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->draftRepository->find($rank2DraftId)
        );

        $reloadedDismissed = $this->pendingRepository->find($dismissedId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $reloadedDismissed
        );

        self::assertTrue($reloadedDismissed->isDismissed());

        self::assertSame(
            $emissionId,
            $reloadedDismissed->getEmission()?->getId()
        );

        self::assertSame(
            $groupKey,
            $reloadedDismissed->getAssignmentGroupKey()
        );

        $allPending = $this->pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(1, $allPending);
        self::assertSame($dismissedId, $allPending[0]->getId());

        self::assertSame(1, $result['removedRegularDrafts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testInvalidManualRebroadcastDoesNotRestoreDismissedPending(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $rank2Slot = $fixture['rank2Slot'];
        $groupKey = $fixture['groupKey'];
        $emissionId = $fixture['emission']->getId();

        /*
     * Rediffusion manuelle placée après
     * les deux diffusions régulières.
     */
        $manualDraft = (new DiffusionDraft())
            ->setEmission($fixture['emission'])
            ->setSlot(null)
            ->setSchedule(
                new \DateTimeImmutable('2099-01-08 12:00:00'),
                60
            )
            ->setNombreDiffusion(3)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setAssignmentGroupKey($groupKey)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT);

        $this->entityManager->persist($manualDraft);

        /*
     * Exclusion volontaire déjà enregistrée.
     */
        $dismissed = (new PendingRebroadcast())
            ->setEmission($fixture['emission'])
            ->setAssignmentGroupKey($groupKey);

        $dismissed->dismiss();

        $this->entityManager->persist($dismissed);
        $this->entityManager->flush();

        $manualDraftId = $manualDraft->getId();
        $dismissedId = $dismissed->getId();

        /*
     * Le rang 2 passe au vendredi 18h.
     *
     * La rediffusion manuelle du jeudi devient
     * chronologiquement invalide.
     */
        $rank2Slot
            ->setDayOfWeek(5)
            ->setStartTime(
                new \DateTimeImmutable('18:00:00')
            );

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->draftRepository->find($manualDraftId)
        );

        $reloadedDismissed = $this->pendingRepository->find($dismissedId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $reloadedDismissed
        );

        self::assertTrue($reloadedDismissed->isDismissed());

        self::assertSame(
            $emissionId,
            $reloadedDismissed->getEmission()?->getId()
        );

        self::assertSame(
            $groupKey,
            $reloadedDismissed->getAssignmentGroupKey()
        );

        $allPending = $this->pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(1, $allPending);
        self::assertSame($dismissedId, $allPending[0]->getId());

        self::assertSame(1, $result['removedManualRebroadcasts']);
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testRemovedRegularRankWithOptionPreservesOtherEmissionDismissalWithoutNewPending(): void
    {
        $fixture = $this->createRegularGroup();

        $rule = $fixture['rule'];
        $rule->setHasAdditionalRebroadcast(true);
        $groupKey = $fixture['groupKey'];
        $emissionB = $fixture['emission'];
        $emissionBId = $emissionB->getId();
        $rank2DraftId = $fixture['rank2Draft']->getId();

        // Une autre émission A possède une exclusion sur le même groupe.
        $emissionA = $this->createEmission(
            $fixture['category'],
            $fixture['editor']
        );

        $this->entityManager->persist($emissionA);
        $this->entityManager->flush();

        $emissionAId = $emissionA->getId();

        $dismissedA = (new PendingRebroadcast())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey)
            ->dismiss();

        $this->entityManager->persist($dismissedA);
        $this->entityManager->flush();

        $dismissedAId = $dismissedA->getId();

        // Le rang 2 de B disparaît.
        $fixture['rank2Slot']->softDelete();
        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->draftRepository->find($rank2DraftId)
        );

        $pendings = $this->pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(1, $pendings);

        $foundDismissedA = false;
        $activeBCount = 0;

        foreach ($pendings as $pending) {
            if ($pending->getId() === $dismissedAId) {
                $foundDismissedA = true;

                self::assertTrue($pending->isDismissed());
                self::assertSame(
                    $emissionAId,
                    $pending->getEmission()?->getId()
                );
            }

            if ($pending->getEmission()?->getId() === $emissionBId) {
                self::assertTrue($pending->isPending());
                ++$activeBCount;
            }
        }

        self::assertTrue($foundDismissedA);
        self::assertSame(0, $activeBCount);
        self::assertSame(1, $result['removedRegularDrafts']);

        // Une création réelle doit être comptabilisée.
        self::assertSame(0, $result['createdPendingRebroadcasts']);
    }

    public function testInvalidManualRebroadcastCountsNewPendingDespiteOtherEmissionDismissal(): void
    {
        $fixture = $this->createRegularGroup();
        $fixture['rule']->setHasAdditionalRebroadcast(true);
        $this->entityManager->flush();

        $rule = $fixture['rule'];
        $groupKey = $fixture['groupKey'];
        $emissionB = $fixture['emission'];
        $emissionBId = $emissionB->getId();

        // Rediffusion manuelle de B après les passages réguliers.
        $manualDraft = (new DiffusionDraft())
            ->setEmission($emissionB)
            ->setSlot(null)
            ->setSchedule(
                new \DateTimeImmutable('2099-01-08 12:00:00'),
                60
            )
            ->setNombreDiffusion(3)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setAssignmentGroupKey($groupKey)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT);

        $this->entityManager->persist($manualDraft);

        // Une autre émission A est volontairement exclue.
        $emissionA = $this->createEmission(
            $fixture['category'],
            $fixture['editor']
        );

        $this->entityManager->persist($emissionA);
        $this->entityManager->flush();

        $emissionAId = $emissionA->getId();
        $manualDraftId = $manualDraft->getId();

        $dismissedA = (new PendingRebroadcast())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey)
            ->dismiss();

        $this->entityManager->persist($dismissedA);
        $this->entityManager->flush();

        $dismissedAId = $dismissedA->getId();

        // Le rang 2 passe après la rediffusion manuelle de B.
        $fixture['rank2Slot']
            ->setDayOfWeek(5)
            ->setStartTime(
                new \DateTimeImmutable('18:00:00')
            );

        $this->entityManager->flush();

        $result = $this->synchronizer->synchronizeRule(
            $rule,
            new \DateTimeImmutable('2099-01-01 00:00:00')
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->draftRepository->find($manualDraftId)
        );

        $pendings = $this->pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        self::assertCount(2, $pendings);

        $foundDismissedA = false;
        $activeBCount = 0;

        foreach ($pendings as $pending) {
            if ($pending->getId() === $dismissedAId) {
                $foundDismissedA = true;

                self::assertTrue($pending->isDismissed());
                self::assertSame(
                    $emissionAId,
                    $pending->getEmission()?->getId()
                );
            }

            if ($pending->getEmission()?->getId() === $emissionBId) {
                self::assertTrue($pending->isPending());
                ++$activeBCount;
            }
        }

        self::assertTrue($foundDismissedA);
        self::assertSame(1, $activeBCount);
        self::assertSame(1, $result['removedManualRebroadcasts']);

        // Une création réelle doit être comptabilisée.
        self::assertSame(1, $result['createdPendingRebroadcasts']);
    }

    public function testRestoreToPoolDoesNotCreateDuplicateBeforeFlush(): void
    {
        $emission = new Emission();
        $emission->setDuree(60);

        $groupKey = 'phpunit_pending_restore_' . uniqid();

        $pendingService = static::getContainer()->get(
            \App\Service\PendingRebroadcastService::class
        );

        $first = $pendingService->restoreToPoolIfEligible(
            $emission,
            $groupKey
        );

        $second = $pendingService->restoreToPoolIfEligible(
            $emission,
            $groupKey
        );

        self::assertInstanceOf(PendingRebroadcast::class, $first);
        self::assertSame($first, $second);

        /*
     * Vérification sans flush : une seule insertion programmée.
     */
        $scheduledPendings = array_filter(
            $this->entityManager
                ->getUnitOfWork()
                ->getScheduledEntityInsertions(),
            static fn(object $entity): bool =>
            $entity instanceof PendingRebroadcast
                && $entity->getEmission() === $emission
                && $entity->getAssignmentGroupKey() === $groupKey
        );

        self::assertCount(1, $scheduledPendings);
    }

    /**
     * Groupe de base :
     *
     * rang 1 : mardi 06/01/2099 à 20h
     * rang 2 : mercredi 07/01/2099 à 09h
     *
     * @return array{
     *     editor:Editeur,
     *     category:Categories,
     *     rule:ProgrammationRule,
     *     rank1Slot:ProgrammationRuleSlot,
     *     rank2Slot:ProgrammationRuleSlot,
     *     emission:Emission,
     *     rank1Draft:DiffusionDraft,
     *     rank2Draft:DiffusionDraft,
     *     groupKey:string
     * }
     */

    public function testRegularRestoreWithoutAdditionalOptionDoesNotCreatePending(): void
    {
        $fixture = $this->createRegularGroup();
        self::assertFalse($fixture['rule']->hasAdditionalRebroadcast());
        $service = static::getContainer()->get(\App\Service\PendingRebroadcastService::class);
        $pending = $service->restoreToPoolIfEligible($fixture['emission'], $fixture['groupKey']);
        $this->entityManager->flush();
        self::assertCount(0, $this->pendingRepository->findByAssignmentGroupKey($fixture['groupKey']), 'Restoration must honor the disabled regular option.');
        self::assertNull($pending);
    }

    public function testAuthorizedRegularRestoreDoesNotProposeAnotherExtraWhileOneIsPlaced(): void
    {
        $fixture = $this->createRegularGroup();
        $fixture['rule']->setHasAdditionalRebroadcast(true);
        $manual = (new DiffusionDraft())
            ->setEmission($fixture['emission'])
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setAssignmentGroupKey($fixture['groupKey'])
            ->setNombreDiffusion(3)
            ->setSchedule(new \DateTimeImmutable('2099-01-08 12:00:00'), 60);
        $this->entityManager->persist($manual);
        $this->entityManager->flush();
        $id = $manual->getId();
        $service = static::getContainer()->get(\App\Service\PendingRebroadcastService::class);
        $result = $service->restoreToPoolIfEligible($fixture['emission'], $fixture['groupKey']);
        $this->entityManager->flush();
        self::assertSame($manual, $this->draftRepository->find($id));
        self::assertCount(0, $this->pendingRepository->findByAssignmentGroupKey($fixture['groupKey']), 'An already placed extra must not generate another proposal.');
        self::assertNull($result);
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
            'editor' => $editor,
            'category' => $category,
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
     * @return array{
     *     editor:Editeur,
     *     category:Categories,
     *     rule:ProgrammationRule,
     *     rank1Slot:ProgrammationRuleSlot,
     *     rank2Slot:ProgrammationRuleSlot,
     *     rank3Slot:ProgrammationRuleSlot,
     *     emission:Emission,
     *     rank1Draft:DiffusionDraft,
     *     rank2Draft:DiffusionDraft,
     *     rank3Draft:DiffusionDraft,
     *     groupKey:string
     * }
     */
    private function createRegularGroupWithThreeRanks(): array
    {
        $fixture = $this->createRegularGroup();

        $rank3Slot = $this->createSlot(
            $fixture['rule'],
            4,
            '18:00:00',
            60,
            3
        );

        $this->entityManager->persist($rank3Slot);
        $this->entityManager->flush();

        $rank3Draft = $this->createRegularDraft(
            $fixture['emission'],
            $rank3Slot,
            '2099-01-08 18:00:00',
            3,
            $fixture['groupKey']
        );

        $this->entityManager->persist($rank3Draft);
        $this->entityManager->flush();

        return array_merge(
            $fixture,
            [
                'rank3Slot' => $rank3Slot,
                'rank3Draft' => $rank3Draft,
            ]
        );
    }

    private function createEditor(): Editeur
    {
        $editor = new Editeur();

        if (method_exists($editor, 'setNom')) {
            $editor->setNom(
                'Synchronizer test ' . uniqid()
            );
        }

        if (method_exists($editor, 'setName')) {
            $editor->setName(
                'Synchronizer test ' . uniqid()
            );
        }

        $editor->setUpdateAt(new \DateTime());

        return $editor;
    }

    private function createCategory(
        Editeur $editor
    ): Categories {
        return (new Categories())
            ->setTitre('Catégorie synchronizer ' . uniqid())
            ->setSlug('SYN')
            ->setEditeur($editor)
            ->setDuree(60)
            ->setDescriptif('Catégorie de test synchronizer')
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
            ->setTitre('Emission synchronizer ' . $suffix)
            ->setKeyword('synchronizer-' . $suffix)
            ->setDatepub(
                new \DateTime('2099-01-01')
            )
            ->setRef('SYNC-' . $suffix)
            ->setDuree(60)
            ->setDescriptif('Emission de test synchronizer')
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
            ->setDraftType(DiffusionDraft::TYPE_REGULAR)
            ->setAssignmentGroupKey($groupKey)
            ->setPublicationStatus(
                DiffusionDraft::STATUS_DRAFT
            );
    }

    private function createPublishedDiffusion(
        Emission $emission,
        string $groupKey,
        string $startsAt,
        int $rank
    ): Diffusion {
        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setSchedule(
                new \DateTime($startsAt),
                60
            )
            ->setNombreDiffusion($rank)
            ->setAssignmentGroupKey($groupKey)
            ->markAsPublished();

        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();

        return $diffusion;
    }
}
