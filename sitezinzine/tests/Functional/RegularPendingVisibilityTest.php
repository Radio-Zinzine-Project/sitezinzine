<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\GridSlotArbitration;
use App\Entity\PendingRebroadcast;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Service\GridAssignmentService;
use App\Service\GridOccurrenceProjectionService;
use App\Service\PendingRebroadcastService;
use App\Service\ProgrammationGridBuilder;
use App\Tests\Support\FunctionalTestCase;

/**
 * Acceptation RED sur la route existante, sans API de service anticipée.
 * weekStart désigne le contexte de grille (grid.weekStartValue). Ce choix
 * de transport reste isolé dans pool(), sans changement de production.
 */
final class RegularPendingVisibilityTest extends FunctionalTestCase
{
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

    /** @dataProvider calendars */
    public function testCalendarHidesUntilTheFollowingRadioWeek(array $dates, string $lastWeek, string $eligibleWeek): void
    {
        $group = $this->assignedGroup($dates);
        $actual = $this->visibility($group, [$lastWeek, $eligibleWeek]);
        self::assertSame([false, true], $actual, 'Présent en base immédiatement, invisible avant le mardi éligible, visible à partir de celui-ci.');
    }

    public static function calendars(): iterable
    {
        yield 'S1 seule donne S2' => [['2099-01-06 10:00:00'], '2099-01-06', '2099-01-13'];
        yield 'S1 S2 donne S3' => [['2099-01-06 10:00:00', '2099-01-13 10:00:00'], '2099-01-13', '2099-01-20'];
        yield 'S1 S2 S4 donne S5' => [['2099-01-06 10:00:00', '2099-01-13 10:00:00', '2099-01-27 10:00:00'], '2099-01-27', '2099-02-03'];
        yield 'plusieurs régulières dans S1' => [['2099-01-06 10:00:00', '2099-01-07 14:00:00', '2099-01-09 18:00:00'], '2099-01-06', '2099-01-13'];
        yield 'dernière régulière lundi soir' => [['2099-01-06 10:00:00', '2099-01-12 22:00:00'], '2099-01-06', '2099-01-13'];
        yield 'dernier début exactement mardi 00h appartient à S2' => [['2099-01-06 10:00:00', '2099-01-13 00:00:00'], '2099-01-13', '2099-01-20'];
        yield 'rang 2 en S4 après rang 3 en S2' => [['2099-01-06 10:00:00', '2099-01-27 10:00:00', '2099-01-13 10:00:00'], '2099-01-27', '2099-02-03'];
    }

    public function testAssignmentCreatesPendingImmediatelyBeforeAnyPublication(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-27 10:00:00']);
        $connection = $this->entityManager->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM pending_rebroadcast WHERE assignment_group_key = ? AND status = ?', [$group['key'], 'pending']));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM diffusion WHERE assignment_group_key = ?', [$group['key']]));
        self::assertCount(2, $group['drafts']);
    }

    /** @dataProvider displayedCalendars */
    public function testDisplayedWeekControlsVisibilityAndBackNavigationIndependentlyOfToday(array $dates, array $weeks): void
    {
        $group = $this->assignedGroup($dates);
        self::assertSame([false, true, true, false], $this->visibility($group, $weeks),
            'S2 masquée, S3 et S5 visibles, retour S2 masqué ; même contrat pour un calendrier passé ou futur.');
    }

    public static function displayedCalendars(): iterable
    {
        yield 'calendrier passé' => [['2000-01-04 10:00:00', '2000-01-11 10:00:00'], ['2000-01-11', '2000-01-18', '2000-02-01', '2000-01-11']];
        yield 'calendrier futur' => [['2099-01-06 10:00:00', '2099-01-13 10:00:00'], ['2099-01-13', '2099-01-20', '2099-02-03', '2099-01-13']];
    }

    public function testTuesdayMidnightChangesTheDisplayedRadioWeek(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-12 22:00:00']);
        $weeks = [];
        // Le contexte provient réellement du normaliseur de la page de grille,
        // et non d'une fonction de calcul d'éligibilité copiée dans le test.
        foreach (['2099-01-12 23:59:59', '2099-01-13 00:00:00'] as $instant) {
            $date = new \DateTimeImmutable($instant);
            $crawler = $this->client->request('GET', '/admin/grille/' . $date->format('Y-m-d'));
            self::assertResponseIsSuccessful();
            $weeks[] = $crawler->filter('[data-controller="grid"]')->attr('data-grid-week-start-value');
        }
        self::assertSame(['2099-01-06', '2099-01-13'], $weeks);
        self::assertSame([false, true], $this->visibility($group, $weeks));
    }

    public function testTwoGroupsOfTheSameEmissionKeepTheirOwnEligibility(): void
    {
        $first = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-13 10:00:00']);
        $secondOrigin = new \DateTimeImmutable('2099-01-20 10:00:00');
        self::assertTrue(static::getContainer()->get(GridAssignmentService::class)->assign($first['slots'][0], $first['emission'], $secondOrigin));
        $secondKey = sprintf('rule_%d_origin_%s', $first['rule']->getId(), $secondOrigin->format('Ymd_Hi'));
        $second = $this->entityManager->getRepository(PendingRebroadcast::class)->findOneBy(['assignmentGroupKey' => $secondKey]);
        self::assertNotNull($second);
        $before = $this->businessSnapshot();
        $ids = $this->pendingIds($this->pool('2099-01-20'));
        self::assertSame($before, $this->businessSnapshot());
        self::assertContains($first['pending']->getId(), $ids);
        self::assertNotContains($second->getId(), $ids, 'Le groupe S3/S4 de la même émission ne devient visible qu’en S5.');
    }

    public function testManualAdditionalRebroadcastDoesNotPostponeRegularEligibility(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-13 10:00:00']);
        // Fixture de lecture avec une supplémentaire tardive ; aucun appel au
        // placement, qui consommerait le Pending et testerait un autre mécanisme.
        $extra = (new DiffusionDraft())->setEmission($group['emission'])->setAssignmentGroupKey($group['key'])
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)->setNombreDiffusion(3)
            ->setSchedule(new \DateTimeImmutable('2099-03-03 10:00:00'), 60);
        $this->entityManager->persist($extra);
        $this->entityManager->flush();
        self::assertSame([false, true], $this->visibility($group, ['2099-01-13', '2099-01-20']));
    }

    public function testPublishedDraftsAndTheirLinkedDiffusionsKeepOneRegularCalendar(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-13 10:00:00']);
        foreach ($group['drafts'] as $draft) {
            $this->publishFixture($draft, $draft->getHoraireDiffusion());
        }
        self::assertSame([false, true], $this->visibility($group, ['2099-01-13', '2099-01-20']));
    }

    public function testPublishedMovedOccurrenceUsesPublishedTimeInsteadOfDraftOrigin(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-13 10:00:00']);
        $this->publishFixture($group['drafts'][1], new \DateTimeImmutable('2099-01-27 14:00:00'));
        self::assertSame('2099-01-13 10:00:00', $group['drafts'][1]->getHoraireDiffusion()->format('Y-m-d H:i:s'));
        self::assertSame([false, false, true], $this->visibility($group, ['2099-01-20', '2099-01-27', '2099-02-03']));
    }

    public function testArbitratedUnpublishedOccurrenceUsesItsEffectiveDestination(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-13 10:00:00']);
        $this->rescheduleFixture($group['drafts'][1], new \DateTimeImmutable('2099-01-27 14:00:00'));
        self::assertSame('2099-01-13 10:00:00', $group['drafts'][1]->getHoraireDiffusion()->format('Y-m-d H:i:s'));
        self::assertSame([false, false, true], $this->visibility($group, ['2099-01-20', '2099-01-27', '2099-02-03']));
    }

    public function testPostponingLastRegularOccurrenceCanHideAnAlreadyVisiblePending(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-13 10:00:00']);
        $actual = $this->visibility($group, ['2099-01-20']);
        $this->rescheduleFixture($group['drafts'][1], new \DateTimeImmutable('2099-01-27 14:00:00'));
        $actual = array_merge($actual, $this->visibility($group, ['2099-01-20', '2099-01-27', '2099-02-03']));
        self::assertSame([true, false, false, true], $actual);
    }

    public function testLogicallyDeletedUnpublishedDraftDoesNotPostponeEligibility(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-27 10:00:00']);
        // Pas de Diffusion liée : le cas historique ambigu est traité séparément.
        $group['drafts'][1]->softDelete();
        $this->entityManager->flush();
        self::assertSame([false, true], $this->visibility($group, ['2099-01-06', '2099-01-13']));
    }

    public function testCancelledLastOccurrenceLeavesEligibilityBasedOnSurvivingRegulars(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-27 10:00:00']);
        $cancelledId = $group['drafts'][1]->getId();
        $this->client->request('POST', '/admin/grille/cancel-occurrence', [
            'slotId' => $group['slots'][1]->getId(), 'startsAt' => '2099-01-27 10:00:00',
            'rebroadcastStrategy' => 'keep',
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->entityManager->getConnection()->fetchOne('SELECT id FROM diffusion_draft WHERE id = ?', [$cancelledId]));
        self::assertSame([false, true], $this->visibility($group, ['2099-01-06', '2099-01-13']));
    }

    public function testManualPendingIsDeferredWhileNewNonRegularEmissionRemainsIndependent(): void
    {
        $manualEmission = $this->createEmission($this->marker . ' manual');
        $manual = (new PendingRebroadcast())->setEmission($manualEmission)->setAssignmentGroupKey('manual_' . $this->marker);
        $draft = (new DiffusionDraft())->setEmission($manualEmission)->setAssignmentGroupKey($manual->getAssignmentGroupKey())
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)->setNombreDiffusion(1)
            ->setSchedule(new \DateTimeImmutable('2099-01-06 10:00:00'), 60);
        $fresh = $this->createEmission($this->marker . ' fresh');
        $this->entityManager->persist($manual);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();
        $before = $this->businessSnapshot();
        $selections = [];
        foreach (['2000-01-04' => false, '2099-01-06' => false, '2099-02-03' => true] as $week => $manualVisible) {
            $items = $this->pool($week)['items'];
            $selected = array_values(array_filter($items, static fn(array $item): bool =>
                ($item['type'] === 'pending_rebroadcast' && $item['id'] === $manual->getId())
                || ($item['type'] === 'non_regular_emission' && $item['emissionId'] === $fresh->getId())
            ));
            self::assertCount($manualVisible ? 2 : 1, $selected);
            self::assertCount(1, array_filter($selected, static fn(array $item): bool => $item['type'] === 'non_regular_emission'));
            self::assertSame($manualVisible, in_array($manual->getId(), $this->pendingIds(['items' => $selected]), true));
            $selections[] = array_values(array_filter($selected, static fn(array $item): bool => $item['type'] === 'non_regular_emission'));
        }
        self::assertSame($selections[0], $selections[1]);
        self::assertSame($selections[0], $selections[2]);
        self::assertSame($before, $this->businessSnapshot());
    }

    public function testIncompleteHistoricalCalendarKeepsCurrentVisibilityWithoutMutation(): void
    {
        $group = $this->assignedGroup(['2000-01-04 10:00:00']);
        $this->publishFixture($group['drafts'][0], new \DateTimeImmutable('2000-01-04 10:00:00'));
        $this->entityManager->remove($group['drafts'][0]);
        $this->entityManager->flush();
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM diffusion_draft WHERE assignment_group_key = ?', [$group['key']]
        ));
        $before = $this->pendingRows($group);
        self::assertCount(1, $before);
        self::assertSame('pending', $before[0]['status']);

        self::assertSame([true, true, true, true, true], $this->visibility($group, [
            '1999-12-28', '2000-01-04', '2000-01-11', '2099-02-03', '1999-12-28',
        ]), 'Un calendrier historique non identifiable conserve sa visibilité, même avant son origine et après un retour arrière.');
        self::assertSame($before, $this->pendingRows($group));
    }

    public function testVoluntaryDismissalRemainsHiddenForEveryDisplayedWeek(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-13 10:00:00']);
        $this->dismissThroughApplication($group);
        $before = $this->pendingRows($group);
        self::assertSame([false, false, false, false, false], $this->visibility($group, [
            '2099-01-06', '2099-01-13', '2099-01-20', '2099-02-03', '2099-01-13',
        ]), 'L’exclusion volontaire reste prioritaire avant et après l’éligibilité théorique S3.');
        self::assertSame($before, $this->pendingRows($group));
        $this->assertOnlyDismissedPending($group);
    }

    public function testDismissedRegularPendingIsNotRecreatedByRestorationOrRepeatedSameEmissionAssignment(): void
    {
        $group = $this->assignedGroup(['2099-01-06 10:00:00', '2099-01-13 10:00:00']);
        $this->dismissThroughApplication($group);
        $originalRows = $this->pendingRows($group);
        $emissionId = $group['emission']->getId();
        $slotId = $group['slots'][0]->getId();
        $origin = $group['drafts'][0]->getHoraireDiffusion();
        // La protection doit survivre à un rechargement, pas seulement à
        // l'identité des objets PHP portant l'exclusion en mémoire.
        $this->entityManager->clear();
        $emission = $this->entityManager->find(\App\Entity\Emission::class, $emissionId);
        $slot = $this->entityManager->find(ProgrammationRuleSlot::class, $slotId);
        self::assertNotNull($emission);
        self::assertNotNull($slot);
        self::assertTrue($slot->getRule()->hasAdditionalRebroadcast());
        $beforeRestore = $this->businessSnapshot();
        self::assertNull(static::getContainer()->get(PendingRebroadcastService::class)
            ->restoreToPoolIfEligible($emission, $group['key']));
        $scheduledPending = array_filter(
            $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions(),
            static fn(object $entity): bool => $entity instanceof PendingRebroadcast
        );
        self::assertCount(0, $scheduledPending, 'La restauration ne doit pas préparer une insertion invisible aux requêtes SQL.');
        $this->entityManager->flush();
        self::assertSame($beforeRestore, $this->businessSnapshot());

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertTrue(static::getContainer()->get(GridAssignmentService::class)->assign($slot, $emission, $origin));
            self::assertSame($originalRows, $this->pendingRows($group), 'Réaffecter la même émission ne recrée ni ne restaure son Pending exclu.');
            $this->assertOnlyDismissedPending($group);
        }
        self::assertSame([false, false], $this->visibility($group, ['2099-01-13', '2099-02-03']));
        self::assertSame($originalRows, $this->pendingRows($group));
    }

    /**
     * Caractérisation de l'information disponible, sans attente de visibilité.
     * Le repli de visibilité confirmé est vérifié par le test fonctionnel dédié.
     */
    public function testCharacterizesHistoricalDiffusionWithoutIdentifiableRegularDraft(): void
    {
        $group = $this->assignedGroup(['2000-01-04 10:00:00']);
        $diffusion = $this->publishFixture($group['drafts'][0], new \DateTimeImmutable('2000-01-04 10:00:00'));
        $this->entityManager->remove($group['drafts'][0]);
        $this->entityManager->flush();
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM diffusion_draft WHERE assignment_group_key = ?', [$group['key']]));
        self::assertSame($group['key'], $diffusion->getAssignmentGroupKey());
        self::assertTrue($diffusion->isPublished());
        $metadata = $this->entityManager->getClassMetadata(Diffusion::class);
        self::assertFalse($metadata->hasField('draftType'));
        self::assertFalse($metadata->hasAssociation('slot'));
        self::assertTrue($group['pending']->isPending());
    }

    private function assignedGroup(array $dates): array
    {
        $category = $this->createCategory();
        $origin = new \DateTimeImmutable($dates[0]);
        $originWeek = $this->radioWeek($origin);
        $year = $origin->format('Y');
        $rule = (new ProgrammationRule())->setCategory($category)->setRuleNumber(1)->setIsActive(true)
            ->setHasAdditionalRebroadcast(true)->setValidFrom(new \DateTimeImmutable($year . '-01-01'))
            ->setValidUntil(new \DateTimeImmutable($year . '-12-31'));
        $slots = [];
        foreach ($dates as $index => $date) {
            $startsAt = new \DateTimeImmutable($date);
            $slot = (new ProgrammationRuleSlot())->setBroadcastRank($index + 1)
                ->setDayOfWeek((int) $startsAt->format('N'))->setStartTime($startsAt)
                ->setDurationMinutes(60)->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
                ->setWeekOffset(intdiv((int) $originWeek->diff($this->radioWeek($startsAt))->days, 7))->setIsActive(true);
            $rule->addSlot($slot);
            $slots[] = $slot;
        }
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
        $emission = $this->createEmission($this->marker . ' regular', null, $category);
        self::assertTrue(static::getContainer()->get(GridAssignmentService::class)->assign($slots[0], $emission, $origin));
        $key = sprintf('rule_%d_origin_%s', $rule->getId(), $origin->format('Ymd_Hi'));
        $pending = $this->entityManager->getRepository(PendingRebroadcast::class)->findOneBy(['assignmentGroupKey' => $key]);
        self::assertNotNull($pending, 'L’affectation crée immédiatement le Pending, indépendamment de sa visibilité.');
        self::assertTrue($pending->isPending());
        $drafts = $this->entityManager->getRepository(DiffusionDraft::class)->findBy(['assignmentGroupKey' => $key], ['nombreDiffusion' => 'ASC']);
        self::assertCount(count($dates), $drafts);
        self::assertSame($dates, array_map(static fn(DiffusionDraft $draft): string => $draft->getHoraireDiffusion()->format('Y-m-d H:i:s'), $drafts));
        return compact('rule', 'slots', 'emission', 'key', 'pending', 'drafts');
    }

    private function dismissThroughApplication(array $group): void
    {
        $before = $this->pendingRows($group);
        self::assertCount(1, $before);
        self::assertSame('pending', $before[0]['status']);
        $this->client->request('DELETE', '/admin/pending-rebroadcasts/' . $group['pending']->getId());
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        $this->assertOnlyDismissedPending($group);
    }

    private function pendingRows(array $group): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT * FROM pending_rebroadcast WHERE assignment_group_key = ? AND emission_id = ? ORDER BY id',
            [$group['key'], $group['emission']->getId()]
        );
    }

    private function assertOnlyDismissedPending(array $group): void
    {
        $rows = $this->pendingRows($group);
        self::assertCount(1, $rows, 'Un seul Pending conservé pour le couple groupe / émission, sans nouveau Pending actif.');
        self::assertSame($group['pending']->getId(), (int) $rows[0]['id']);
        self::assertSame('dismissed', $rows[0]['status']);
    }

    private function publishFixture(DiffusionDraft $draft, \DateTimeImmutable $effective): Diffusion
    {
        $diffusion = (new Diffusion())->setEmission($draft->getEmission())->setAssignmentGroupKey($draft->getAssignmentGroupKey())
            ->setNombreDiffusion($draft->getNombreDiffusion())->setSchedule(\DateTime::createFromImmutable($effective), 60)->markAsPublished();
        $this->entityManager->persist($diffusion);
        $draft->markAsPublished($diffusion, new \DateTimeImmutable());
        $this->entityManager->flush();
        return $diffusion;
    }

    private function rescheduleFixture(DiffusionDraft $draft, \DateTimeImmutable $destination): void
    {
        $arbitration = (new GridSlotArbitration())->setSlot($draft->getSlot())
            ->setOriginalStartsAt($draft->getHoraireDiffusion())->setOriginalEndsAt($draft->getEndsAt())
            ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt($destination)->setRescheduledEndsAt($destination->modify('+60 minutes'));
        $arbitration->markResolved();
        $this->entityManager->persist($arbitration);
        $this->entityManager->flush();
        $week = $this->radioWeek($destination);
        $end = $week->modify('+7 days');
        $segments = static::getContainer()->get(ProgrammationGridBuilder::class)->buildForWeek($week, $end);
        $projected = static::getContainer()->get(GridOccurrenceProjectionService::class)->applyForWeek($segments, $week, $end);
        $destinations = [];
        foreach ($projected as $day) {
            foreach ($day as $segment) {
                if (($segment['slotId'] ?? null) === $draft->getSlot()->getId()
                    && ($segment['isProjectedOverride'] ?? false)
                    && ($segment['originalStartsAt'] ?? null) === $draft->getHoraireDiffusion()->format('Y-m-d H:i:s')) {
                    $destinations[] = $segment['startsAt'];
                }
            }
        }
        self::assertSame([$destination->format('Y-m-d H:i:s')], $destinations, 'La fixture utilise bien le déplacement effectif du moteur de grille existant.');
    }

    private function visibility(array $group, array $weeks): array
    {
        $before = $this->businessSnapshot();
        $visible = [];
        foreach ($weeks as $week) {
            $visible[] = in_array($group['pending']->getId(), $this->pendingIds($this->pool($week)), true);
            self::assertSame($before, $this->businessSnapshot(), 'Lire le parc ne modifie aucun Pending, Draft, Diffusion ou arbitrage.');
        }
        return $visible;
    }

    private function pool(string $week): array
    {
        $this->client->request('GET', '/admin/pending-rebroadcasts', ['weekStart' => $week]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame(count($data['items']), $data['count']);
        return $data;
    }

    private function pendingIds(array $data): array
    {
        return array_column(array_values(array_filter($data['items'], static fn(array $item): bool => $item['type'] === 'pending_rebroadcast')), 'id');
    }

    private function radioWeek(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0)->modify(sprintf('-%d days', ((int) $date->format('N') + 5) % 7));
    }

    private function businessSnapshot(): array
    {
        $state = [];
        foreach (['pending_rebroadcast', 'diffusion_draft', 'diffusion', 'grid_slot_arbitration'] as $table) {
            $state[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        }
        return $state;
    }
}
