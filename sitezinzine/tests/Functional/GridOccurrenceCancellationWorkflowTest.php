<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Diffusion;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Service\GridAssignmentService;
use App\Service\GridPublicationService;
use App\Tests\Support\FunctionalTestCase;
use Doctrine\ORM\Events;

final class GridOccurrenceCancellationWorkflowTest extends FunctionalTestCase
{
    public function testDirectCancellationMatchesRemovalThenCancellation(): void
    {
        $reference = $this->assignedGroup();
        $this->post('/admin/grille/remove', $this->occurrenceParameters($reference));
        $this->cancel($reference);
        $expected = $this->businessState($reference);
        self::assertCount(0, $expected['drafts']);
        self::assertCount(0, $expected['pending']);
        self::assertCount(2, $expected['arbitrations']);

        $direct = $this->assignedGroup();
        $this->cancel($direct);

        self::assertSame($expected, $this->businessState($direct), 'Direct cancellation must have the same business state as removal followed by cancellation.');
    }

    public function testCancellationLeavesNoAssignmentOnCancelledOccurrences(): void
    {
        $fixture = $this->assignedGroup();
        $this->cancel($fixture);
        $state = $this->businessState($fixture);
        self::assertCount(2, $state['arbitrations']);
        foreach ($state['arbitrations'] as $arbitration) {
            self::assertSame('cancel', $arbitration['action']);
            self::assertSame('resolved', $arbitration['status']);
        }
        self::assertCount(0, $state['drafts'], 'Cancelled occurrences must not retain the removed group assignments.');
    }

    /** @dataProvider additionalOptions */
    public function testCancellationWithdrawsPendingOfRemovedAssignment(bool $enabled): void
    {
        $fixture = $this->assignedGroup($enabled);
        self::assertCount(1, $this->businessState($fixture)['pending']);
        $this->cancel($fixture);
        self::assertCount(0, $this->businessState($fixture)['pending'], 'A cancelled and unassigned group must not keep an additional proposal.');
    }

    public static function additionalOptions(): array
    {
        return ['authorized_pending' => [true], 'legacy_pending_option_now_disabled' => [false]];
    }

    public function testRestoreDoesNotReassignCancelledEmission(): void
    {
        $fixture = $this->assignedGroup();
        $this->cancel($fixture);
        $this->post('/admin/grille/restore-occurrence', [
            'slotId' => $fixture['slotId'],
            'originalStartsAt' => $fixture['startsAt'],
            'restoreLinkedRebroadcasts' => '1',
        ]);
        $state = $this->businessState($fixture);
        self::assertCount(0, $state['arbitrations']);
        self::assertCount(0, $state['drafts'], 'Restoring an occurrence must not resurrect its previous assignment.');
        self::assertCount(0, $state['pending']);
    }

    public function testRepeatedCancellationIsIdempotent(): void
    {
        $fixture = $this->assignedGroup();
        $this->cancel($fixture);
        $before = $this->businessState($fixture);
        $ids = $this->arbitrationIds($fixture);
        $this->cancel($fixture);
        self::assertSame($before, $this->businessState($fixture));
        self::assertSame($ids, $this->arbitrationIds($fixture), 'Repeated cancellation must reuse the existing arbitrations.');
    }

    public function testCancellationPreservesOtherGroupAndHistoricalDiffusion(): void
    {
        $fixture = $this->assignedGroup();
        $before = $this->preservedState($fixture);
        $this->cancel($fixture);
        self::assertSame($before, $this->preservedState($fixture));
        $slot = $this->entityManager->find(ProgrammationRuleSlot::class, $fixture['slotId']);
        self::assertTrue($slot->isActive());
        self::assertFalse($slot->isDeleted(), 'Occurrence cancellation must not delete the recurrent slot.');
    }

    public function testCancellationFailureRollsBackAllBusinessWrites(): void
    {
        $fixture = $this->assignedGroup();
        $before = $this->businessState($fixture);
        $preserved = $this->preservedState($fixture);
        $eventManager = $this->entityManager->getEventManager();
        // Inject a failure after SQL has been flushed. No business collaborator is mocked.
        $listener = new class {
            public function postFlush(): void
            {
                throw new \RuntimeException('Simulated cancellation failure after flush');
            }
        };
        $eventManager->addEventListener([Events::postFlush], $listener);
        $this->client->catchExceptions(false);
        try {
            $this->client->request('POST', '/admin/grille/cancel-occurrence', $this->cancellationParameters($fixture));
            self::fail('The injected failure must reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated cancellation failure after flush', $exception->getMessage());
        } finally {
            $eventManager->removeEventListener([Events::postFlush], $listener);
            $this->client->catchExceptions(true);
        }
        // Read through DBAL: rollback may close the EntityManager.
        self::assertSame($preserved, $this->preservedState($fixture));
        self::assertSame($before, $this->businessState($fixture), 'A failed cancellation must leave neither partial arbitrations nor partial assignment removal.');
    }

    public function testCancellationKeepsPublishedDiffusionIdsButUnpublishesRemovedGroup(): void
    {
        $fixture = $this->assignedGroup();
        static::getContainer()->get(GridPublicationService::class)->publishWeek(new \DateTimeImmutable('2099-01-06'));
        $connection = $this->entityManager->getConnection();
        $before = $connection->fetchAllAssociative(
            'SELECT id, emission_id, horaire_diffusion, nombre_diffusion FROM diffusion WHERE assignment_group_key = ? ORDER BY id',
            [$fixture['groupKey']]
        );
        self::assertCount(2, $before);
        $preserved = $this->preservedState($fixture);
        $this->cancel($fixture);
        self::assertSame($before, $connection->fetchAllAssociative(
            'SELECT id, emission_id, horaire_diffusion, nombre_diffusion FROM diffusion WHERE assignment_group_key = ? ORDER BY id',
            [$fixture['groupKey']]
        ));
        self::assertSame($preserved, $this->preservedState($fixture));
        $statuses = $connection->fetchFirstColumn('SELECT publication_status FROM diffusion WHERE assignment_group_key = ? ORDER BY id', [$fixture['groupKey']]);
        self::assertSame(['unpublished', 'unpublished'], $statuses, 'Published lines must remain in the database but follow the existing unassignment cycle.');
        self::assertCount(0, $this->businessState($fixture)['drafts']);
    }

    /** @dataProvider publicationModes */
    public function testCancellingR1WithKeepRenumbersSurvivorsWithoutMovingThem(bool $published): void
    {
        $fixture = $this->assignedGroup(true, true);
        if ($published) {
            static::getContainer()->get(GridPublicationService::class)->publishWeek($fixture['weekStart']);
        }
        $before = $this->passages($fixture);
        self::assertCount(3, $before);
        $preserved = $this->preservedState($fixture);
        $pending = $this->businessState($fixture)['pending'];
        $this->post('/admin/grille/cancel-occurrence', $this->occurrenceParameters($fixture) + ['rebroadcastStrategy' => 'keep']);
        self::assertSame($preserved, $this->preservedState($fixture));
        self::assertSame($pending, $this->businessState($fixture)['pending'], 'The retained, authorized group keeps its single pending.');
        self::assertCount(1, $this->businessState($fixture)['arbitrations']);
        $this->assertSurvivingPassages($fixture, $before, 1, $published);
    }

    /** @dataProvider publicationModes */
    public function testIsolatedR2CancellationPreservesR1AndRenumbersR3(bool $published): void
    {
        $fixture = $this->assignedGroup(true, true);
        if ($published) {
            static::getContainer()->get(GridPublicationService::class)->publishWeek($fixture['weekStart']);
        }
        $before = $this->passages($fixture);
        self::assertCount(3, $before);
        $preserved = $this->preservedState($fixture);
        $pending = $this->businessState($fixture)['pending'];
        $this->post('/admin/grille/cancel-occurrence', [
            'slotId' => $fixture['slotIds'][2],
            'startsAt' => $before[1]['horaire_diffusion'],
            'rebroadcastStrategy' => 'keep',
        ]);
        self::assertSame($preserved, $this->preservedState($fixture));
        self::assertSame($pending, $this->businessState($fixture)['pending']);
        $arbitrations = $this->businessState($fixture)['arbitrations'];
        self::assertCount(1, $arbitrations);
        self::assertSame(2, (int) $arbitrations[0]['broadcast_rank']);
        $this->assertSurvivingPassages($fixture, $before, 2, $published);
    }

    public static function publicationModes(): array
    {
        return ['draft_week' => [false], 'published_week' => [true]];
    }

    public function testKeepCancellationWithdrawsLegacyPendingWhenOptionDisabled(): void
    {
        $fixture = $this->assignedGroup(false, true);
        $before = $this->passages($fixture);
        $this->post('/admin/grille/cancel-occurrence', $this->occurrenceParameters($fixture) + ['rebroadcastStrategy' => 'keep']);
        self::assertCount(0, $this->businessState($fixture)['pending'], 'Keeping regular passages must not authorize an extra when the option is disabled.');
        $this->assertSurvivingPassages($fixture, $before, 1, false);
    }

    /** @dataProvider pastCancellationRanks */
    public function testPastPublishedCancellationKeepsHistoryAndSupportsRevalidation(int $cancelledRank): void
    {
        $fixture = $this->assignedGroup(true, true, '2021-01-05 20:00:00');
        $publication = static::getContainer()->get(GridPublicationService::class);
        $unpublication = static::getContainer()->get(\App\Service\GridUnpublicationService::class);
        $publication->publishWeek($fixture['weekStart']);
        $before = $this->passages($fixture);
        self::assertCount(3, $before);
        $preserved = $this->preservedState($fixture);
        $cancelledId = (int) $before[$cancelledRank - 1]['published_diffusion_id'];
        $this->post('/admin/grille/cancel-occurrence', [
            'slotId' => $fixture['slotIds'][$cancelledRank],
            'startsAt' => $before[$cancelledRank - 1]['horaire_diffusion'],
            'rebroadcastStrategy' => 'keep',
        ]);
        self::assertSame($preserved, $this->preservedState($fixture));
        self::assertSame('unpublished', $this->entityManager->getConnection()->fetchOne(
            'SELECT publication_status FROM diffusion WHERE id = ?', [$cancelledId]
        ), 'A cancelled past broadcast must remain recorded without being presented as published.');
        $this->assertSurvivingPassages($fixture, $before, $cancelledRank, true);

        // Both an initially published and an automatically unpublished week are acceptable.
        // Every surviving published row must still have its linked draft.
        $preview = $unpublication->previewWeek($fixture['weekStart']);
        self::assertSame(0, $preview['missingDraftCount']);
        if ($preview['publishedDiffusionCount'] > 0) {
            self::assertTrue($preview['canUnpublish']);
            $unpublication->unpublishWeek($fixture['weekStart']);
        }
        self::assertTrue($publication->previewWeekPublication($fixture['weekStart'])['canPublish']);
        $publication->publishWeek($fixture['weekStart']);
        $this->assertSurvivingPassages($fixture, $before, $cancelledRank, true);
        self::assertTrue($unpublication->previewWeek($fixture['weekStart'])['canUnpublish']);
        $unpublication->unpublishWeek($fixture['weekStart']);
        self::assertTrue($publication->previewWeekPublication($fixture['weekStart'])['canPublish']);
        $publication->publishWeek($fixture['weekStart']);
        $this->assertSurvivingPassages($fixture, $before, $cancelledRank, true);
        self::assertSame($preserved, $this->preservedState($fixture));
    }

    public static function pastCancellationRanks(): array
    {
        return ['past_R1_keep' => [1], 'past_R2_only' => [2]];
    }

    private function passages(array $fixture): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, slot_id, emission_id, assignment_group_key, horaire_diffusion, nombre_diffusion, draft_type, published_diffusion_id FROM diffusion_draft WHERE assignment_group_key = ? AND deleted_at IS NULL ORDER BY horaire_diffusion, id',
            [$fixture['groupKey']]
        );
    }

    private function assertSurvivingPassages(array $fixture, array $before, int $cancelledRank, bool $published): void
    {
        $connection = $this->entityManager->getConnection();
        // Slot definitions retain their structural ranks. Only concrete passage numbers change.
        self::assertSame([1, 2, 3], array_map('intval', $connection->fetchFirstColumn(
            'SELECT broadcast_rank FROM programmation_rule_slot WHERE rule_id = ? ORDER BY broadcast_rank', [$fixture['ruleId']]
        )));
        $expected = [];
        $cancelled = null;
        foreach ($before as $passage) {
            if ((int) $passage['nombre_diffusion'] === $cancelledRank) {
                $cancelled = $passage;
                continue;
            }
            $passage['nombre_diffusion'] = count($expected) + 1;
            $expected[] = $passage;
        }
        self::assertNotNull($cancelled);
        self::assertSame($expected, $this->passages($fixture), 'Only the cancelled assignment is removed; surviving IDs, slots, times and links are preserved with chronological numbers.');
        if (!$published) {
            return;
        }
        foreach ($expected as $passage) {
            $diffusion = $connection->fetchAssociative('SELECT * FROM diffusion WHERE id = ?', [$passage['published_diffusion_id']]);
            self::assertIsArray($diffusion);
            self::assertSame((int) $passage['published_diffusion_id'], (int) $diffusion['id']);
            self::assertSame($passage['horaire_diffusion'], $diffusion['horaire_diffusion']);
            self::assertSame((int) $passage['emission_id'], (int) $diffusion['emission_id']);
            self::assertSame($passage['assignment_group_key'], $diffusion['assignment_group_key']);
            self::assertSame((int) $passage['nombre_diffusion'], (int) $diffusion['nombre_diffusion']);
            $draftStatus = $connection->fetchOne('SELECT publication_status FROM diffusion_draft WHERE id = ?', [$passage['id']]);
            self::assertContains($diffusion['publication_status'], ['published', 'unpublished']);
            self::assertSame($diffusion['publication_status'] === 'published' ? 'published' : 'draft', $draftStatus);
        }
        $history = $connection->fetchAssociative('SELECT * FROM diffusion WHERE id = ?', [$cancelled['published_diffusion_id']]);
        self::assertIsArray($history);
        self::assertSame($cancelled['horaire_diffusion'], $history['horaire_diffusion']);
        self::assertSame((int) $cancelled['emission_id'], (int) $history['emission_id']);
        self::assertSame('unpublished', $history['publication_status']);
    }

    private function assignedGroup(bool $enabled = true, bool $threeRanks = false, string $origin = '2099-01-06 20:00:00'): array
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $category = $this->createCategory();
        $originDate = new \DateTimeImmutable($origin);
        $year = $originDate->format('Y');
        $rule = (new ProgrammationRule())->setCategory($category)->setRuleNumber(1)
            ->setValidFrom(new \DateTimeImmutable($year . '-01-01'))
            ->setValidUntil(new \DateTimeImmutable($year . '-12-31'))
            ->setHasAdditionalRebroadcast(true);
        $definitions = [[1, 2, '20:00'], [2, 3, '09:00']];
        if ($threeRanks) {
            $definitions[] = [3, 4, '18:00'];
        }
        $slots = [];
        foreach ($definitions as [$rank, $day, $time]) {
            $slot = (new ProgrammationRuleSlot())->setBroadcastRank($rank)->setDayOfWeek($day)
                ->setStartTime(new \DateTimeImmutable($time))->setDurationMinutes(60)
                ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
                ->setWeekOffset(0)->setIsActive(true);
            $rule->addSlot($slot);
            $slots[$rank] = $slot;
            if (1 === $rank) {
                $firstSlot = $slot;
            }
        }
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
        $emission = $this->createEmission($this->marker . ' cancelled', null, $category);
        $otherEmission = $this->createEmission($this->marker . ' preserved', null, $category);
        $assignment = static::getContainer()->get(GridAssignmentService::class);
        self::assertTrue($assignment->assign($firstSlot, $emission, $originDate));
        self::assertTrue($assignment->assign($firstSlot, $otherEmission, $originDate->modify('+7 days')));
        $history = (new Diffusion())->setEmission($otherEmission)->setNombreDiffusion(1)
            ->setAssignmentGroupKey('historical_' . $rule->getId())
            ->setSchedule(new \DateTimeImmutable('2020-01-07 20:00:00'), 60)->markAsPublished();
        $this->entityManager->persist($history);
        // The disabled case represents a pending created before the option changed.
        $rule->setHasAdditionalRebroadcast($enabled);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        return [
            'ruleId' => $rule->getId(), 'slotId' => $firstSlot->getId(),
            'startsAt' => $originDate->format('Y-m-d H:i:s'),
            'weekStart' => $originDate->setTime(0, 0),
            'slotIds' => array_map(static fn(ProgrammationRuleSlot $slot): int => $slot->getId(), $slots),
            'groupKey' => sprintf('rule_%d_origin_%s', $rule->getId(), $originDate->format('Ymd_Hi')),
            'otherGroupKey' => sprintf('rule_%d_origin_%s', $rule->getId(), $originDate->modify('+7 days')->format('Ymd_Hi')),
            'historyId' => $history->getId(),
        ];
    }

    private function occurrenceParameters(array $fixture): array
    {
        return ['slotId' => $fixture['slotId'], 'startsAt' => $fixture['startsAt']];
    }

    private function cancellationParameters(array $fixture): array
    {
        return $this->occurrenceParameters($fixture) + ['rebroadcastStrategy' => 'cancel'];
    }

    private function cancel(array $fixture): void
    {
        $this->post('/admin/grille/cancel-occurrence', $this->cancellationParameters($fixture));
    }

    private function post(string $path, array $parameters): void
    {
        $this->client->request('POST', $path, $parameters);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
    }

    private function businessState(array $fixture): array
    {
        $connection = $this->entityManager->getConnection();
        return [
            'drafts' => $connection->fetchAllAssociative(
                'SELECT horaire_diffusion, nombre_diffusion, draft_type, publication_status, deleted_at FROM diffusion_draft WHERE assignment_group_key = ? ORDER BY nombre_diffusion, id', [$fixture['groupKey']]
            ),
            'pending' => $connection->fetchAllAssociative(
                'SELECT status FROM pending_rebroadcast WHERE assignment_group_key = ? ORDER BY id', [$fixture['groupKey']]
            ),
            'arbitrations' => $connection->fetchAllAssociative(
                'SELECT s.broadcast_rank, a.original_starts_at, a.action, a.status FROM grid_slot_arbitration a INNER JOIN programmation_rule_slot s ON s.id = a.slot_id WHERE s.rule_id = ? ORDER BY s.broadcast_rank, a.id', [$fixture['ruleId']]
            ),
        ];
    }

    private function arbitrationIds(array $fixture): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT a.id FROM grid_slot_arbitration a INNER JOIN programmation_rule_slot s ON s.id = a.slot_id WHERE s.rule_id = ? ORDER BY a.id', [$fixture['ruleId']]
        );
    }

    private function preservedState(array $fixture): array
    {
        $connection = $this->entityManager->getConnection();
        return [
            'drafts' => $connection->fetchAllAssociative('SELECT * FROM diffusion_draft WHERE assignment_group_key = ? ORDER BY id', [$fixture['otherGroupKey']]),
            'pending' => $connection->fetchAllAssociative('SELECT * FROM pending_rebroadcast WHERE assignment_group_key = ? ORDER BY id', [$fixture['otherGroupKey']]),
            'history' => $connection->fetchAssociative('SELECT * FROM diffusion WHERE id = ?', [$fixture['historyId']]),
        ];
    }
}
