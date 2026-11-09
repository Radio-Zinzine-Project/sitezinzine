<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Service\GridPublicationService;
use App\Service\GridUnpublicationService;
use App\Service\RadioWeek;
use App\Tests\Support\FunctionalTestCase;

/** Real HTTP/Doctrine acceptance tests. No anticipated production API or mocks. */
final class NonRegularPendingWorkflowTest extends FunctionalTestCase
{
    private const S1 = '2099-01-06';
    private const S2 = '2099-01-13';
    private const S3 = '2099-01-20';
    private const S4 = '2099-01-27';
    private const S5 = '2099-02-03';
    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');
        parent::setUp();
        $this->client->loginUser($this->createUser('ROLE_ADMIN'));
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            date_default_timezone_set($this->previousTimezone);
        }
    }

    /**
     * @group characterization
     * The RED revision recorded zero successors here. GREEN characterizes
     * the corrected cycle, including its stability after publication.
     */
    public function testCharacterizesSuccessorBeforeAndAfterPublication(): void
    {
        $group = $this->manualGroup();
        self::assertCount(1, $this->activePending($group));
        $consumedId = $this->activePending($group)[0]->getId();
        $rebroadcast = $this->place($consumedId, self::S3 . ' 10:00:00');
        self::assertSame(DiffusionDraft::TYPE_MANUAL_REBROADCAST, $rebroadcast->getDraftType());
        self::assertSame($group['key'], $rebroadcast->getAssignmentGroupKey());
        self::assertCount(2, $this->drafts($group));
        self::assertNull($this->entityManager->find(PendingRebroadcast::class, $consumedId));
        $successorId = $this->onlyPending($group)->getId();
        self::assertNotSame($consumedId, $successorId);
        $this->publish(self::S1);
        $this->publish(self::S3);
        self::assertNotNull($rebroadcast->getPublishedDiffusion());
        self::assertTrue($rebroadcast->getPublishedDiffusion()->isPublished());
        self::assertCount(2, $this->entityManager->getRepository(Diffusion::class)->findBy([
            'emission' => $group['emission'], 'assignmentGroupKey' => $group['key'], 'publicationStatus' => Diffusion::STATUS_PUBLISHED,
        ]));
        self::assertSame($successorId, $this->onlyPending($group)->getId(), 'Publication preserves the single successor.');
    }

    public function testCompleteDraftCycleCreatesNextProposalUntilThreeScheduledPassages(): void
    {
        $group = $this->manualGroup();
        $initial = $this->onlyPending($group);
        self::assertCount(1, $this->drafts($group));
        $this->place($initial->getId(), self::S3 . ' 10:00:00');
        self::assertCount(2, $this->drafts($group));
        $next = $this->onlyPending($group, 'Two scheduled drafts require a new automatic proposal.');
        self::assertNotSame($initial->getId(), $next->getId());
        $this->place($next->getId(), self::S5 . ' 10:00:00');
        self::assertCount(3, $this->drafts($group));
        self::assertSame([1, 2, 3], array_map(static fn(DiffusionDraft $d): int => $d->getNombreDiffusion(), $this->drafts($group)));
        self::assertCount(0, $this->activePending($group));
    }

    /** @dataProvider incompleteCalendars */
    public function testScheduledCountRequiresNextProposalWithoutDoubleCounting(string $state): void
    {
        $group = $this->manualGroup();
        $originalId = $group['first']->getId();
        $historical = null;
        if ($state === 'published-only' || $state === 'linked-published') {
            $this->publish(self::S1);
            $historical = $group['first']->getPublishedDiffusion();
            self::assertNotNull($historical);
            if ($state === 'published-only') {
                $this->entityManager->remove($group['first']);
                $this->entityManager->flush();
            }
        } elseif ($state === 'deleted-draft') {
            $this->extraDraft($group, '2099-01-10 10:00:00', 2)->softDelete();
            $this->entityManager->flush();
        } elseif ($state === 'unpublished-history' || $state === 'linked-unpublished') {
            $this->publish(self::S1);
            $historical = $group['first']->getPublishedDiffusion();
            static::getContainer()->get(GridUnpublicationService::class)->unpublishWeek(new \DateTimeImmutable(self::S1));
            self::assertTrue($historical->isUnpublished());
            if ($state === 'unpublished-history') {
                $group['first']->setPublishedDiffusion(null);
                $this->entityManager->flush();
            }
        }
        $this->place($this->onlyPending($group)->getId(), self::S3 . ' 10:00:00');
        if ($historical !== null) {
            self::assertSame($historical->getId(), $this->entityManager->find(Diffusion::class, $historical->getId())->getId());
            self::assertSame(self::S1 . ' 10:00:00', $historical->getHoraireDiffusion()->format('Y-m-d H:i:s'));
        }
        if ($state !== 'published-only') {
            self::assertSame($originalId, $group['first']->getId());
        }
        self::assertCount(1, $this->activePending($group), $state . ': only two distinct active scheduled passages; one next proposal expected.');
    }

    public static function incompleteCalendars(): iterable
    {
        foreach (['draft-only', 'linked-published', 'published-only', 'deleted-draft', 'unpublished-history', 'linked-unpublished'] as $state) {
            yield $state => [$state];
        }
    }

    public function testThirdScheduledPassageStopsAutomaticProposalsBeforePublication(): void
    {
        $group = $this->manualGroup();
        $this->extraDraft($group, self::S2 . ' 10:00:00', 2);
        $this->place($this->onlyPending($group)->getId(), self::S3 . ' 10:00:00');
        self::assertCount(3, $this->drafts($group));
        self::assertCount(0, $this->activePending($group));
    }

    /** @dataProvider publishedThresholds */
    public function testPublishedPassagesCountTowardThreeBeforeNewDraftIsPublished(bool $keepLinkedDraft): void
    {
        $group = $this->manualGroup();
        $this->publish(self::S1);
        $publishedId = $group['first']->getPublishedDiffusion()->getId();
        if (!$keepLinkedDraft) {
            $this->entityManager->remove($group['first']);
            $this->entityManager->flush();
        }
        $this->extraDraft($group, self::S2 . ' 10:00:00', 2);
        $this->place($this->onlyPending($group)->getId(), self::S3 . ' 10:00:00');
        self::assertTrue($this->entityManager->find(Diffusion::class, $publishedId)->isPublished());
        self::assertCount($keepLinkedDraft ? 3 : 2, $this->drafts($group));
        self::assertCount(0, $this->activePending($group), 'One published passage plus two other drafts meets the target, linked or not.');
    }

    public static function publishedThresholds(): iterable
    {
        yield 'published passage without active draft' => [false];
        yield 'published passage and its linked draft count once' => [true];
    }

    public function testSuccessorIsNotDuplicatedByPublicationAndRevalidation(): void
    {
        $group = $this->manualGroup();
        $this->place($this->onlyPending($group)->getId(), self::S3 . ' 10:00:00');
        $nextId = $this->onlyPending($group, 'A successor must exist before testing its idempotence.')->getId();
        $this->publish(self::S1);
        $this->publish(self::S3);
        self::assertSame($nextId, $this->onlyPending($group)->getId());
        static::getContainer()->get(GridUnpublicationService::class)->unpublishWeek(new \DateTimeImmutable(self::S3));
        $this->publish(self::S3);
        self::assertSame($nextId, $this->onlyPending($group)->getId());
    }

    public function testGroupsOfSameEmissionDoNotShareTheirScheduledCount(): void
    {
        $a = $this->manualGroup();
        $b = $this->manualGroup(self::S2 . ' 12:00:00', $a['emission']);
        $this->extraDraft($b, '2099-01-14 12:00:00', 2);
        $this->extraDraft($b, '2099-01-15 12:00:00', 3);
        $bPending = $this->onlyPending($b)->getId();
        $this->place($this->onlyPending($a)->getId(), self::S3 . ' 10:00:00');
        self::assertSame($bPending, $this->onlyPending($b)->getId());
        self::assertCount(3, $this->drafts($b));
        self::assertCount(1, $this->activePending($a), 'The three passages of another group must not suppress the next proposal.');
    }

    public function testManualDuplicationAllowsFourthPassageWithoutAutomaticFifthProposal(): void
    {
        $group = $this->manualGroup();
        $this->extraDraft($group, self::S2 . ' 10:00:00', 2);
        $id = $this->onlyPending($group)->getId();
        $duplicate = $this->jsonPost('/admin/pending-rebroadcasts/' . $id . '/duplicate', []);
        self::assertNotSame($id, $duplicate['pendingRebroadcastId']);
        $this->place($id, self::S3 . ' 10:00:00');
        $this->place((int) $duplicate['pendingRebroadcastId'], self::S5 . ' 10:00:00');
        self::assertCount(4, $this->drafts($group), 'Three is a target, not a placement cap.');
        self::assertCount(0, $this->activePending($group));
    }

    public function testRepeatedPublicationCycleDoesNotDuplicateInitialProposal(): void
    {
        $group = $this->manualGroup();
        $id = $this->onlyPending($group)->getId();
        $this->publish(self::S1);
        static::getContainer()->get(GridUnpublicationService::class)->unpublishWeek(new \DateTimeImmutable(self::S1));
        $this->publish(self::S1);
        self::assertSame($id, $this->onlyPending($group)->getId());
        self::assertCount(1, $this->entityManager->getRepository(Diffusion::class)->findBy(['assignmentGroupKey' => $group['key']]));
    }

    public function testDismissedProposalPreventsAutomaticReplacementAfterConsumingManualDuplicate(): void
    {
        $group = $this->manualGroup();
        $id = $this->onlyPending($group)->getId();
        $duplicate = $this->jsonPost('/admin/pending-rebroadcasts/' . $id . '/duplicate', []);
        $this->client->request('DELETE', '/admin/pending-rebroadcasts/' . $id);
        $this->response();
        $this->place((int) $duplicate['pendingRebroadcastId'], self::S3 . ' 10:00:00');
        $this->publish(self::S1);
        $this->publish(self::S3);
        $dismissed = $this->entityManager->find(PendingRebroadcast::class, $id);
        self::assertNotNull($dismissed);
        self::assertTrue($dismissed->isDismissed());
        self::assertCount(0, $this->activePending($group));
        self::assertSame([false, false], $this->visibility($id, [self::S3, self::S5]));
    }

    public function testRemovalDoesNotRestoreVoluntarilyDismissedProposal(): void
    {
        $group = $this->manualGroup();
        $id = $this->onlyPending($group)->getId();
        $duplicate = $this->jsonPost('/admin/pending-rebroadcasts/' . $id . '/duplicate', []);
        $this->client->request('DELETE', '/admin/pending-rebroadcasts/' . $id);
        $this->response();
        $draft = $this->place((int) $duplicate['pendingRebroadcastId'], self::S3 . ' 10:00:00');
        $this->jsonPost('/admin/grid-drafts/delete', ['draftId' => $draft->getId(), 'deleteMode' => 'single']);
        self::assertCount(0, $this->activePending($group));
        $rows = $this->entityManager->getRepository(PendingRebroadcast::class)->findBy(['assignmentGroupKey' => $group['key']]);
        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]->getId());
        self::assertTrue($rows[0]->isDismissed());
    }

    public function testRemovingManualRebroadcastRestoresOneProposalAndMovingItDoesNotDuplicateIt(): void
    {
        $group = $this->manualGroup();
        $rebroadcast = $this->place($this->onlyPending($group)->getId(), self::S3 . ' 10:00:00');
        $this->jsonPost('/admin/grid-drafts/delete', ['draftId' => $rebroadcast->getId(), 'deleteMode' => 'single']);
        $pendingId = $this->onlyPending($group)->getId();
        foreach ([self::S2, self::S3] as $week) {
            $this->move($group['first'], $week . ' 10:00:00');
            self::assertSame($pendingId, $this->onlyPending($group)->getId());
        }
        self::assertCount(1, $this->drafts($group));
    }

    /** @dataProvider displayedCalendars */
    public function testInitialPendingVisibilityUsesDisplayedRadioWeekAndAllowsBackNavigation(string $first, array $weeks): void
    {
        $group = $this->manualGroup($first);
        self::assertSame([false, true, true, false], $this->visibility($this->onlyPending($group)->getId(), $weeks));
    }

    public static function displayedCalendars(): iterable
    {
        yield 'future, Monday remains in S1' => [self::S1 . ' 10:00:00', ['2099-01-12', self::S2, self::S5, self::S1]];
        yield 'past, independent of today' => ['2000-01-04 10:00:00', ['2000-01-10', '2000-01-11', '2000-02-01', '2000-01-04']];
    }

    public function testNextPendingVisibilityStartsAfterSecondScheduledPassage(): void
    {
        $group = $this->manualGroup();
        $this->place($this->onlyPending($group)->getId(), self::S3 . ' 10:00:00');
        $next = $this->onlyPending($group, 'Visibility of the real next proposal requires its creation first.');
        self::assertSame([false, true, false], $this->visibility($next->getId(), [self::S3, self::S4, self::S2]));
    }

    public function testSecondPublishedPassageStillRequiresNextProposal(): void
    {
        $group = $this->manualGroup();
        $this->place($this->onlyPending($group)->getId(), self::S3 . ' 10:00:00');
        $this->publish(self::S1);
        $this->publish(self::S3);
        self::assertCount(2, $this->entityManager->getRepository(Diffusion::class)->findBy([
            'emission' => $group['emission'], 'assignmentGroupKey' => $group['key'], 'publicationStatus' => Diffusion::STATUS_PUBLISHED,
        ]));
        self::assertCount(1, $this->activePending($group), 'Publishing the second passage must not leave the cycle interrupted.');
    }

    public function testExistingNextProposalVisibilityUsesLastManualRebroadcast(): void
    {
        $group = $this->manualGroup();
        $this->place($this->onlyPending($group)->getId(), self::S3 . ' 10:00:00');
        // Isolate visibility from the missing automatic creation. This fixture is
        // NOT evidence that placement creates its successor (tested separately).
        $next = static::getContainer()->get(\App\Service\PendingRebroadcastService::class)->createForGroup(
            $this->entityManager->find(Emission::class, $group['emission']->getId()), $group['key']
        );
        $this->entityManager->flush();
        self::assertSame([false, true, false], $this->visibility($next->getId(), [self::S3, self::S4, self::S2]));
    }

    public function testPublicationAndUnpublicationDoNotShiftVisibility(): void
    {
        $group = $this->manualGroup();
        $id = $this->onlyPending($group)->getId();
        $states = [$this->visibility($id, [self::S1, self::S2])];
        $this->publish(self::S1);
        $states[] = $this->visibility($id, [self::S1, self::S2]);
        static::getContainer()->get(GridUnpublicationService::class)->unpublishWeek(new \DateTimeImmutable(self::S1));
        $states[] = $this->visibility($id, [self::S1, self::S2]);
        self::assertSame([[false, true], [false, true], [false, true]], $states);
    }

    public function testMovingInitialPassagePostponesVisibilityWithoutCreatingAnotherProposal(): void
    {
        $group = $this->manualGroup();
        $id = $this->onlyPending($group)->getId();
        $this->move($group['first'], self::S3 . ' 10:00:00');
        self::assertSame($id, $this->onlyPending($group)->getId());
        self::assertSame([false, false, true, false], $this->visibility($id, [self::S2, self::S3, self::S4, self::S1]));
    }

    public function testSameEmissionGroupsHaveIndependentVisibility(): void
    {
        $a = $this->manualGroup();
        $b = $this->manualGroup(self::S3 . ' 12:00:00', $a['emission']);
        $data = $this->pool(self::S2);
        $ids = $this->poolPendingIds($data);
        self::assertTrue(in_array($this->onlyPending($a)->getId(), $ids, true));
        self::assertFalse(in_array($this->onlyPending($b)->getId(), $ids, true), 'The later group remains hidden.');
    }

    public function testIncompleteHistoryIsReadWithoutHeuristicWrites(): void
    {
        $group = $this->manualGroup();
        // An orphaned unpublished historical row cannot establish an active trigger.
        $this->entityManager->remove($group['first']);
        $old = (new Diffusion())->setEmission($group['emission'])->setAssignmentGroupKey($group['key'])
            ->setNombreDiffusion(37)->setSchedule(new \DateTime('2020-01-07 10:00:00'), 60)->markAsUnpublished();
        $this->entityManager->persist($old);
        $this->entityManager->flush();
        $before = $this->snapshot();
        self::assertSame($this->pool(self::S2), $this->pool(self::S2));
        self::assertSame($before, $this->snapshot(), 'No invented trigger, new Pending or historical rewrite at GET.');
        // Visibility fallback intentionally left open until the business decision.
    }

    public function testSharedDiffusionDraftsCountOnceAfterUnpublicationAndAllowSuccessor(): void
    {
        $group = $this->manualGroup();
        $consumedId = $this->onlyPending($group)->getId();
        $this->publish(self::S1);
        $published = $group['first']->getPublishedDiffusion();
        self::assertNotNull($published);
        $publishedId = $published->getId();

        // Two persisted active representations, explicitly linked, not a date/rank heuristic.
        $duplicate = (new DiffusionDraft())->setEmission($group['emission'])->setAssignmentGroupKey($group['key'])
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)->setNombreDiffusion(1)
            ->setSchedule(new \DateTimeImmutable(self::S1 . ' 10:00:00'), 60)
            ->markAsPublished($published);
        $this->entityManager->persist($duplicate);
        $this->entityManager->flush();
        $draftIds = [$group['first']->getId(), $duplicate->getId()];
        $summary = function () use ($group): array {
            $drafts = $this->entityManager->getRepository(DiffusionDraft::class)->findByAssignmentGroupKey($group['key']);
            $diffusions = $this->entityManager->getRepository(Diffusion::class)->findByAssignmentGroupKey($group['key']);
            return \App\Service\NonRegularScheduledOccurrences::summarize($group['emission']->getId(), $group['key'], $drafts, $diffusions);
        };
        self::assertCount(2, $this->drafts($group));
        self::assertSame(1, $summary()['count'], 'The published passage has two linked representations, but counts once.');

        static::getContainer()->get(GridUnpublicationService::class)->unpublishWeek(new \DateTimeImmutable(self::S1));
        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(Diffusion::class, $publishedId)->isUnpublished());
        foreach ($draftIds as $id) {
            $draft = $this->entityManager->find(DiffusionDraft::class, $id);
            self::assertFalse($draft->isDeleted());
            self::assertNotSame(DiffusionDraft::STATUS_CANCELLED, $draft->getPublicationStatus());
            self::assertSame($publishedId, $draft->getPublishedDiffusion()->getId());
        }
        self::assertSame(1, $summary()['count'], 'Unpublication must not turn one explicitly linked occurrence into two.');

        // The real placement consumes the first Pending and creates the distinct second passage.
        $second = $this->place($consumedId, self::S3 . ' 10:00:00');
        self::assertSame(2, $summary()['count'], 'Three draft rows represent only two scheduled occurrences.');
        self::assertNotContains($second->getId(), $draftIds);
        $successor = $this->onlyPending($group, 'Two distinct scheduled occurrences still require a successor.');
        self::assertNotSame($consumedId, $successor->getId());
        self::assertNull($this->entityManager->find(PendingRebroadcast::class, $consumedId));
        self::assertSame([false, true], $this->visibility($successor->getId(), [self::S3, self::S4]));

        // Moving a linked representation changes the latest relevant date, not its identity.
        $this->move($this->entityManager->find(DiffusionDraft::class, $draftIds[1]), self::S5 . ' 10:00:00');
        self::assertSame(2, $summary()['count']);
        self::assertSame(self::S5 . ' 10:00:00', $summary()['last']->format('Y-m-d H:i:s'));
        self::assertSame([false, true], $this->visibility($successor->getId(), [self::S5, '2099-02-10']));
    }

    public function testSuccessorCreationFailureRollsBackPlacementAndConsumption(): void
    {
        $group = $this->manualGroup();
        $pendingId = $this->onlyPending($group)->getId();
        $before = $this->snapshot();
        $listener = new class($group['key']) {
            public function __construct(private string $key) {}
            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $event): void
            {
                foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof PendingRebroadcast && $entity->getAssignmentGroupKey() === $this->key) {
                        throw new \RuntimeException('Simulated successor persistence failure');
                    }
                }
            }
        };
        $events = $this->entityManager->getEventManager();
        $events->addEventListener([\Doctrine\ORM\Events::onFlush], $listener);
        $this->client->catchExceptions(false);
        try {
            $this->client->jsonRequest('POST', '/admin/pending-rebroadcasts/' . $pendingId . '/place', [
                'startsAt' => self::S3 . ' 10:00:00',
            ]);
            self::fail('The injected persistence error must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Simulated successor persistence failure', $error->getMessage());
        } finally {
            $events->removeEventListener([\Doctrine\ORM\Events::onFlush], $listener);
        }
        self::assertSame($before, $this->snapshot(), 'A failed successor must leave no placement and preserve the consumed Pending in SQL.');
    }

    public function testPartiallyUnidentifiableHistoryRetainsLegacyVisibility(): void
    {
        $group = $this->manualGroup();
        $old = (new Diffusion())->setEmission($group['emission'])->setAssignmentGroupKey($group['key'])
            ->setNombreDiffusion(37)->setSchedule(new \DateTime('2020-01-07 10:00:00'), 60)->markAsUnpublished();
        $this->entityManager->persist($old);
        $this->entityManager->flush();
        self::assertSame([true, true], $this->visibility($this->onlyPending($group)->getId(), [self::S1, self::S2]),
            'The explicit fallback preserves legacy visibility instead of assigning an uncertain historical trigger.');
    }

    public function testNewUnassignedNonRegularEmissionRemainsIndependentOfDisplayedWeek(): void
    {
        $emission = $this->createEmission($this->marker . ' new');
        foreach ([self::S1, self::S5] as $week) {
            $items = array_values(array_filter($this->pool($week)['items'], static fn(array $item): bool =>
                $item['type'] === 'non_regular_emission' && $item['emissionId'] === $emission->getId()
            ));
            self::assertCount(1, $items);
        }
        self::assertCount(0, $this->entityManager->getRepository(PendingRebroadcast::class)->findBy(['emission' => $emission]));
    }

    private function manualGroup(string $startsAt = self::S1 . ' 10:00:00', ?Emission $emission = null): array
    {
        $emission ??= $this->createEmission($this->marker . ' manual');
        $this->client->request('POST', '/admin/grid-drafts/manual', [
            'emissionId' => $emission->getId(), 'startsAt' => $startsAt,
            'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL, 'durationMinutes' => 60,
        ]);
        $data = $this->response();
        $first = $this->entityManager->find(DiffusionDraft::class, (int) $data['draftId']);
        self::assertInstanceOf(DiffusionDraft::class, $first);
        self::assertSame(DiffusionDraft::TYPE_MANUAL_SPECIAL, $first->getDraftType());
        $key = 'manual_' . $first->getId();
        self::assertSame($key, $first->getAssignmentGroupKey());
        return compact('emission', 'first', 'key');
    }

    private function extraDraft(array $group, string $startsAt, int $rank): DiffusionDraft
    {
        // Persisted fixture isolates the scheduled-count threshold, without inventing a service API.
        $draft = (new DiffusionDraft())->setEmission($this->entityManager->find(Emission::class, $group['emission']->getId()))->setAssignmentGroupKey($group['key'])
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)->setNombreDiffusion($rank)
            ->setSchedule(new \DateTimeImmutable($startsAt), 60);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();
        return $draft;
    }

    private function place(int $id, string $startsAt): DiffusionDraft
    {
        $data = $this->jsonPost('/admin/pending-rebroadcasts/' . $id . '/place', ['startsAt' => $startsAt]);
        $draft = $this->entityManager->find(DiffusionDraft::class, (int) $data['draftId']);
        self::assertInstanceOf(DiffusionDraft::class, $draft);
        return $draft;
    }

    private function move(DiffusionDraft $draft, string $startsAt): void
    {
        $this->client->request('POST', '/admin/grid-drafts/move', ['draftId' => $draft->getId(), 'startsAt' => $startsAt]);
        $this->response();
        self::assertSame($startsAt, $this->entityManager->find(DiffusionDraft::class, $draft->getId())->getHoraireDiffusion()->format('Y-m-d H:i:s'));
    }

    private function publish(string $week): void
    {
        $result = static::getContainer()->get(GridPublicationService::class)->publishWeek(RadioWeek::start(new \DateTimeImmutable($week)));
        self::assertTrue($result['published']);
    }

    private function jsonPost(string $url, array $payload): array
    {
        $this->client->jsonRequest('POST', $url, $payload);
        return $this->response();
    }

    private function response(): array
    {
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        return $data;
    }

    private function activePending(array $group): array
    {
        return $this->entityManager->getRepository(PendingRebroadcast::class)->findBy([
            'emission' => $group['emission'], 'assignmentGroupKey' => $group['key'], 'status' => PendingRebroadcast::STATUS_PENDING,
        ], ['id' => 'ASC']);
    }

    private function onlyPending(array $group, string $message = 'Exactly one active proposal expected.'): PendingRebroadcast
    {
        $pending = $this->activePending($group);
        self::assertCount(1, $pending, $message);
        return $pending[0];
    }

    private function drafts(array $group): array
    {
        return $this->entityManager->getRepository(DiffusionDraft::class)->findBy([
            'emission' => $group['emission'], 'assignmentGroupKey' => $group['key'], 'deletedAt' => null,
        ], ['horaireDiffusion' => 'ASC']);
    }

    private function pool(string $week): array
    {
        $this->client->request('GET', '/admin/pending-rebroadcasts', ['weekStart' => $week]);
        return $this->response();
    }

    private function poolPendingIds(array $pool): array
    {
        return array_column(array_values(array_filter($pool['items'], static fn(array $item): bool => $item['type'] === 'pending_rebroadcast')), 'id');
    }

    private function visibility(int $pendingId, array $weeks): array
    {
        $before = $this->snapshot();
        $result = array_map(fn(string $week): bool => in_array($pendingId, $this->poolPendingIds($this->pool($week)), true), $weeks);
        self::assertSame($before, $this->snapshot(), 'Visibility is read-only.');
        return $result;
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (['pending_rebroadcast', 'diffusion_draft', 'diffusion', 'grid_slot_arbitration'] as $table) {
            $state[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        }
        return $state;
    }
}
