<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\GridSlotArbitrationRepository;
use App\Service\GridOccurrenceProjectionService;
use App\Service\ProgrammationGridBuilder;
use App\Service\PublicDailyScheduleBuilder;
use App\Service\PublicScheduleBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PublicScheduleBuilderTest extends TestCase
{
    private DiffusionRepository&MockObject $diffusionRepository;
    private ProgrammationGridBuilder&MockObject $programmationGridBuilder;
    private GridOccurrenceProjectionService&MockObject $projectionService;

    private PublicScheduleBuilder $builder;

    protected function setUp(): void
    {
        $this->diffusionRepository = $this->createMock(
            DiffusionRepository::class
        );

        $this->programmationGridBuilder = $this->createMock(
            ProgrammationGridBuilder::class
        );

        $this->projectionService = $this->createMock(
            GridOccurrenceProjectionService::class
        );

        $this->builder = new PublicScheduleBuilder(
            $this->diffusionRepository,
            $this->programmationGridBuilder,
            $this->projectionService,
            $this->createMock(DiffusionDraftRepository::class)
        );
    }

    public function testBuildCreatesSevenDaysAndNormalizesStartTime(): void
    {
        $this->programmationGridBuilder
            ->expects(self::once())
            ->method('buildForWeek')
            ->with(
                self::callback(
                    static fn(\DateTimeImmutable $date): bool =>
                        $date->format('Y-m-d H:i:s')
                        === '2026-09-08 00:00:00'
                ),
                self::callback(
                    static fn(\DateTimeImmutable $date): bool =>
                        $date->format('Y-m-d H:i:s')
                        === '2026-09-15 00:00:00'
                )
            )
            ->willReturn($this->emptyWeek());

        $this->projectionService
            ->expects(self::once())
            ->method('applyForWeek')
            ->willReturn($this->emptyWeek());

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08 15:42:31')
        );

        self::assertSame(
            '2026-09-08 00:00:00',
            $result['startOfWeek']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-09-14 00:00:00',
            $result['endOfWeek']->format('Y-m-d H:i:s')
        );

        self::assertCount(7, $result['days']);

        foreach ($result['days'] as $day) {
            self::assertSame([], $day['items']);
        }
    }

    public function testBuildCreatesCorrectSevenConsecutiveDays(): void
    {
        $this->configureEmptySchedule();

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            [
                '2026-09-08',
                '2026-09-09',
                '2026-09-10',
                '2026-09-11',
                '2026-09-12',
                '2026-09-13',
                '2026-09-14',
            ],
            array_column($result['days'], 'key')
        );
    }

    public function testEmptyRegularSlotIsVisibleWithItsCategory(): void
    {
        $category = $this->createMock(Categories::class);

        $segments = $this->emptyWeek();

        $segments[0][] = $this->createRegularSegment(
            '2026-09-08 10:00:00',
            '2026-09-08 11:00:00',
            $category
        );

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertCount(1, $result['days'][0]['items']);

        $item = $result['days'][0]['items'][0];

        self::assertSame('empty_regular', $item['type']);
        self::assertSame(
            '2026-09-08 10:00:00',
            $item['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-08 11:00:00',
            $item['endsAt']->format('Y-m-d H:i:s')
        );
        self::assertNull($item['emission']);
        self::assertSame($category, $item['category']);
        self::assertNull($item['diffusion']);
        self::assertTrue($item['isRegular']);
    }

    public function testPublishedDiffusionFillsRegularSlot(): void
    {
        $ruleCategory = $this->createMock(Categories::class);
        $emissionCategory = $this->createMock(Categories::class);
        $emission = $this->createMock(Emission::class);
        $diffusion = $this->createMock(Diffusion::class);

        $emission
            ->method('getCategorie')
            ->willReturn($emissionCategory);

        $diffusion
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-08 10:00:00')
            );

        $diffusion
            ->method('getEmission')
            ->willReturn($emission);

        $segments = $this->emptyWeek();

        $segments[0][] = $this->createRegularSegment(
            '2026-09-08 10:00:00',
            '2026-09-08 11:00:00',
            $ruleCategory
        );

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertCount(1, $result['days'][0]['items']);

        $item = $result['days'][0]['items'][0];

        self::assertSame('diffusion', $item['type']);
        self::assertSame($emission, $item['emission']);
        self::assertSame($emissionCategory, $item['category']);
        self::assertSame($diffusion, $item['diffusion']);
        self::assertTrue($item['isRegular']);
    }

    public function testUnmatchedPublishedDiffusionIsVisibleAsManualItem(): void
    {
        $category = $this->createMock(Categories::class);
        $emission = $this->createMock(Emission::class);
        $diffusion = $this->createMock(Diffusion::class);

        $emission
            ->method('getCategorie')
            ->willReturn($category);

        $emission
            ->method('getDuree')
            ->willReturn(60);

        $diffusion
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 14:30:00')
            );

        $diffusion
            ->method('getEndsAt')
            ->willReturn(
                new \DateTimeImmutable('2026-09-09 15:30:00')
            );

        $diffusion
            ->method('getEmission')
            ->willReturn($emission);

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($this->emptyWeek());

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($this->emptyWeek());

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertCount(1, $result['days'][1]['items']);

        $item = $result['days'][1]['items'][0];

        self::assertSame('diffusion', $item['type']);
        self::assertSame($emission, $item['emission']);
        self::assertSame($category, $item['category']);
        self::assertSame($diffusion, $item['diffusion']);
        self::assertFalse($item['isRegular']);

        self::assertSame(
            '2026-09-09 14:30:00',
            $item['startsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-09-09 15:30:00',
            $item['endsAt']->format('Y-m-d H:i:s')
        );
    }

    public function testManualDiffusionComputesEndFromDurationWhenEndsAtIsMissing(): void
    {
        $emission = $this->createMock(Emission::class);
        $diffusion = $this->createMock(Diffusion::class);

        $emission
            ->method('getDuree')
            ->willReturn(45);

        $diffusion
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-10 08:00:00')
            );

        $diffusion
            ->method('getEndsAt')
            ->willReturn(null);

        $diffusion
            ->method('getDurationMinutes')
            ->willReturn(null);

        $diffusion
            ->method('getEmission')
            ->willReturn($emission);

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($this->emptyWeek());

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($this->emptyWeek());

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        $item = $result['days'][2]['items'][0];

        self::assertSame(
            '2026-09-10 08:45:00',
            $item['endsAt']->format('Y-m-d H:i:s')
        );
    }

    public function testCancelledRegularSlotIsNotVisible(): void
    {
        $category = $this->createMock(Categories::class);

        $theoretical = $this->emptyWeek();

        $theoretical[0][] = $this->createRegularSegment(
            '2026-09-08 10:00:00',
            '2026-09-08 11:00:00',
            $category
        );

        $projected = $theoretical;

        $projected[0][0]['isCancelled'] = true;
        $projected[0][0]['isBlocking'] = false;

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($theoretical);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($projected);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame([], $result['days'][0]['items']);
    }

    public function testRescheduledOriginIsHiddenAndNewPositionIsVisible(): void
    {
        $category = $this->createMock(Categories::class);

        $theoretical = $this->emptyWeek();

        $theoretical[0][] = $this->createRegularSegment(
            '2026-09-08 10:00:00',
            '2026-09-08 11:00:00',
            $category
        );

        $projected = $this->emptyWeek();

        /*
         * Ancienne position conservée par la projection pour les besoins
         * administratifs, mais invisible sur le programme public.
         */
        $origin = $this->createRegularSegment(
            '2026-09-08 10:00:00',
            '2026-09-08 11:00:00',
            $category
        );

        $origin['isRescheduledOrigin'] = true;
        $origin['isBlocking'] = false;

        $projected[0][] = $origin;

        /*
         * Nouvelle position réellement visible publiquement.
         */
        $rescheduled = $this->createRegularSegment(
            '2026-09-08 12:00:00',
            '2026-09-08 13:00:00',
            $category
        );

        $rescheduled['isProjectedOverride'] = true;
        $rescheduled['isRescheduled'] = true;

        $projected[0][] = $rescheduled;

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($theoretical);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($projected);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertCount(1, $result['days'][0]['items']);

        $item = $result['days'][0]['items'][0];

        self::assertSame('empty_regular', $item['type']);

        self::assertSame(
            '2026-09-08 12:00:00',
            $item['startsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame($category, $item['category']);
    }

    public function testItemsAreSortedChronologicallyInsideDay(): void
    {
        $category = $this->createMock(Categories::class);
        $emission = $this->createMock(Emission::class);
        $diffusion = $this->createMock(Diffusion::class);

        $emission
            ->method('getCategorie')
            ->willReturn($category);

        $diffusion
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTimeImmutable('2026-09-08 08:00:00')
            );

        $diffusion
            ->method('getEndsAt')
            ->willReturn(
                new \DateTimeImmutable('2026-09-08 09:00:00')
            );

        $diffusion
            ->method('getEmission')
            ->willReturn($emission);

        $segments = $this->emptyWeek();

        $segments[0][] = $this->createRegularSegment(
            '2026-09-08 18:00:00',
            '2026-09-08 19:00:00',
            $category
        );

        $segments[0][] = $this->createRegularSegment(
            '2026-09-08 12:00:00',
            '2026-09-08 13:00:00',
            $category
        );

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        $items = $result['days'][0]['items'];

        self::assertCount(3, $items);

        self::assertSame(
            [
                '08:00',
                '12:00',
                '18:00',
            ],
            array_map(
                static fn(array $item): string =>
                    $item['startsAt']->format('H:i'),
                $items
            )
        );
    }

    public function testBuildHandlesItemOnLastDayOfWeek(): void
    {
        $category = $this->createMock(Categories::class);

        $segments = $this->emptyWeek();

        $segments[6][] = $this->createRegularSegment(
            '2026-09-14 23:00:00',
            '2026-09-14 23:30:00',
            $category
        );

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertCount(1, $result['days'][6]['items']);

        self::assertSame(
            '2026-09-14 23:00:00',
            $result['days'][6]['items'][0]['startsAt']
                ->format('Y-m-d H:i:s')
        );
    }

    public function testPublishedDraftLinkWinsWhenTheRegularStartHasDrifted(): void
    {
        [$builder, $diffusion] = $this->scheduleWithDriftedRegularStart();
        $week = $builder->build(new \DateTimeImmutable('2026-09-08', new \DateTimeZone('Europe/Paris')));
        $items = $week['days'][0]['items'];

        self::assertSame([
            ['diffusion', true, $diffusion->getId(), '09:45:00', '10:30:00'],
        ], array_map(static fn (array $item): array => [
            $item['type'], $item['isRegular'], $item['diffusion']?->getId(),
            $item['startsAt']->format('H:i:s'), $item['endsAt']->format('H:i:s'),
        ], $items));
        self::assertSame($diffusion->getEmission(), $items[0]['emission']);
    }

    public function testPublishedDraftLinkSurvivesAChangeToThePublishedRank(): void
    {
        $slot = new ProgrammationRuleSlot();
        (new \ReflectionProperty($slot, 'id'))->setValue($slot, 31);
        $published = $this->publishedDiffusion(101, '10:00');
        $draft = $this->publishedDraft($slot, $published);
        $published->setNombreDiffusion(2);
        $segments = $this->emptyWeek();
        $segments[0][] = $this->createRegularSegment(
            '2026-09-08 10:00:00', '2026-09-08 10:30:00', new Categories()
        ) + ['slotId' => 31];
        $builder = $this->scheduleWithPublishedFixtures($segments, [$published], [$draft]);

        $items = $builder->build(new \DateTimeImmutable('2026-09-08 Europe/Paris'))['days'][0]['items'];

        self::assertSame(['diffusion'], array_column($items, 'type'));
        self::assertTrue($items[0]['isRegular']);
        self::assertSame($published, $items[0]['diffusion']);
        self::assertSame($published->getEmission(), $items[0]['emission']);
        self::assertSame('2026-09-08 10:00:00', $items[0]['startsAt']->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-08 10:30:00', $items[0]['endsAt']->format('Y-m-d H:i:s'));
        self::assertSame(2, $published->getNombreDiffusion());
        self::assertSame(1, $draft->getNombreDiffusion());
    }

    public function testAWeeklyOccurrenceMovedOntoTheNextOccurrenceOfTheSameSlotIsAmbiguous(): void
    {
        $slot = new ProgrammationRuleSlot();
        (new \ReflectionProperty($slot, 'id'))->setValue($slot, 31);
        $published = $this->publishedDiffusion(101, '10:00');
        $draft = $this->publishedDraft($slot, $published);
        $segments = $this->emptyWeek();
        $segments[0][] = $this->createRegularSegment('2026-09-08 10:00:00', '2026-09-08 10:30:00', new Categories())
            + ['slotId' => 31];
        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt(new \DateTimeImmutable('2026-09-01 10:00:00 Europe/Paris'))
            ->setOriginalEndsAt(new \DateTimeImmutable('2026-09-01 10:30:00 Europe/Paris'))
            ->setRescheduledStartsAt(new \DateTimeImmutable('2026-09-08 10:00:00 Europe/Paris'))
            ->setRescheduledEndsAt(new \DateTimeImmutable('2026-09-08 10:30:00 Europe/Paris'))
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM);
        $arbitration->markResolved();
        $builder = $this->scheduleWithPublishedFixtures($segments, [$published], [$draft], [$arbitration]);

        $items = $builder->build(new \DateTimeImmutable('2026-09-08 Europe/Paris'))['days'][0]['items'];
        $diffusions = array_values(array_filter($items, static fn (array $item): bool => $item['type'] === 'diffusion'));
        $fallbacks = array_values(array_filter($items, static fn (array $item): bool => $item['type'] === 'empty_regular'));

        self::assertCount(3, $items);
        self::assertCount(1, $diffusions);
        self::assertSame($published, $diffusions[0]['diffusion']);
        self::assertFalse($diffusions[0]['isRegular']);
        self::assertCount(2, $fallbacks);
        self::assertSame([null, null], array_column($fallbacks, 'diffusion'));
        self::assertSame(['10:00', '10:00'], array_map(
            static fn (array $item): string => $item['startsAt']->format('H:i'),
            $fallbacks,
        ));
    }

    public function testCarouselKeepsThePublishedEmissionCurrentDespiteTheDrift(): void
    {
        [$builder, $diffusion] = $this->scheduleWithDriftedRegularStart();
        $day = (new PublicDailyScheduleBuilder($builder))->build(
            new \DateTimeImmutable('2026-09-08 00:00:00 Europe/Paris'),
            new \DateTimeImmutable('2026-09-08 10:05:00 Europe/Paris'),
        );

        self::assertSame([
            'currentTypes' => ['diffusion'],
            'activeTitle' => $diffusion->getEmission()->getTitre(),
        ], [
            'currentTypes' => array_column(array_filter(
                $day['items'], static fn (array $item): bool => $item['isCurrent'],
            ), 'type'),
            'activeTitle' => $day['items'][$day['activeIndex']]['emission']?->getTitre(),
        ]);
    }

    public function testPublishedDiffusionsAtTheSameTimeAreBothKept(): void
    {
        $first = $this->publishedDiffusion(101, '10:00');
        $second = $this->publishedDiffusion(102, '10:00');
        $builder = $this->scheduleWithPublishedFixtures($this->emptyWeek(), [$first, $second], []);
        $week = $builder->build(new \DateTimeImmutable('2026-09-08 Europe/Paris'));

        self::assertEqualsCanonicalizing([101, 102], array_map(
            static fn (array $item): ?int => $item['diffusion']?->getId(),
            $week['days'][0]['items'],
        ));
        self::assertSame([false, false], array_column($week['days'][0]['items'], 'isRegular'));
    }

    /** @dataProvider rescheduledDraftTimes */
    public function testRescheduledPublishedRebroadcastDoesNotFillAnotherSlotAtItsDestination(string $draftTime): void
    {
        $slot = new ProgrammationRuleSlot();
        (new \ReflectionProperty($slot, 'id'))->setValue($slot, 31);
        $slot->setBroadcastRank(2);
        $published = $this->publishedDiffusion(103, '10:00')->setNombreDiffusion(2)
            ->setAssignmentGroupKey('regular-group');
        $draft = $this->publishedDraft($slot, $published);
        $draft->setSchedule(new \DateTimeImmutable("2026-09-08 $draftTime Europe/Paris"), 30);
        $otherCategory = (new Categories())->setTitre('Créneau indépendant');
        $rebroadcastCategory = (new Categories())->setTitre('Créneau de la rediffusion');
        $published->getEmission()->setCategorie($rebroadcastCategory);
        $segments = $this->emptyWeek();
        $segments[0] = [
            $this->createRegularSegment('2026-09-08 10:00:00', '2026-09-08 10:30:00', $otherCategory) + ['slotId' => 32],
            $this->createRegularSegment('2026-09-08 12:00:00', '2026-09-08 12:30:00', $rebroadcastCategory)
                + ['slotId' => 31, 'broadcastRank' => 2],
        ];
        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt(new \DateTimeImmutable('2026-09-08 12:00:00 Europe/Paris'))
            ->setOriginalEndsAt(new \DateTimeImmutable('2026-09-08 12:30:00 Europe/Paris'))
            ->setRescheduledStartsAt(new \DateTimeImmutable('2026-09-08 10:00:00 Europe/Paris'))
            ->setRescheduledEndsAt(new \DateTimeImmutable('2026-09-08 10:30:00 Europe/Paris'))
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM);
        $arbitration->markResolved();

        $builder = $this->scheduleWithPublishedFixtures($segments, [$published], [$draft], [$arbitration]);
        $items = $builder->build(new \DateTimeImmutable('2026-09-08 Europe/Paris'))['days'][0]['items'];
        self::assertCount(2, $items);
        self::assertSame(['10:00', '10:00'], array_map(static fn (array $item): string => $item['startsAt']->format('H:i'), $items));
        $byType = array_column($items, null, 'type');
        self::assertSame($published, $byType['diffusion']['diffusion']);
        self::assertTrue($byType['diffusion']['isRegular']);
        self::assertSame('Créneau indépendant', $byType['empty_regular']['category']->getTitre());
    }

    public static function rescheduledDraftTimes(): iterable
    {
        yield 'projected time' => ['10:00'];
        yield 'original time' => ['12:00'];
    }

    /** @dataProvider fallbackTimezones */
    public function testFallbackAndPublishedDiffusionUseTheSameParisInstants(
        string $defaultTimezone,
        string $date,
        string $offset,
    ): void {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set($defaultTimezone);
        try {
            $start = new \DateTimeImmutable("$date 10:00:00 Europe/Paris");
            $published = (new Diffusion())->setEmission(new Emission())->setNombreDiffusion(1)->setSchedule($start, 30);
            // Leave both entries visible without relying on their order.
            $segments = $this->emptyWeek();
            $segments[0][] = $this->createRegularSegment("$date 10:00:00", "$date 10:30:00", new Categories()) + ['slotId' => 31];
            $slot = new ProgrammationRuleSlot();
            (new \ReflectionProperty($slot, 'id'))->setValue($slot, 31);
            (new \ReflectionProperty($published, 'id'))->setValue($published, 101);
            $draft = $this->publishedDraft($slot, $published)->setDeletedAt($start);
            $builder = $this->scheduleWithPublishedFixtures($segments, [$published], [$draft]);

            $items = $builder->build($start)['days'][0]['items'];
            self::assertCount(2, $items);
            $byType = array_column($items, null, 'type');
            $fallback = $byType['empty_regular'];
            $diffusion = $byType['diffusion'];
            self::assertSame("$date 10:00:00 $offset Europe/Paris", $fallback['startsAt']->format('Y-m-d H:i:s P e'));
            self::assertSame("$date 10:30:00 $offset Europe/Paris", $fallback['endsAt']->format('Y-m-d H:i:s P e'));
            self::assertSame($diffusion['startsAt']->getTimestamp(), $fallback['startsAt']->getTimestamp());
            self::assertSame($diffusion['endsAt']->getTimestamp(), $fallback['endsAt']->getTimestamp());
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public static function fallbackTimezones(): iterable
    {
        foreach (['UTC', 'Europe/Paris'] as $timezone) {
            yield "$timezone summer" => [$timezone, '2026-09-08', '+02:00'];
            yield "$timezone winter" => [$timezone, '2026-01-13', '+01:00'];
        }
    }

    public function testAmbiguousHistoricalTimeDoesNotAssignTheDiffusionToEitherSlot(): void
    {
        $segments = $this->emptyWeek();
        $segment = $this->createRegularSegment('2026-09-08 10:00:00', '2026-09-08 10:30:00', null);
        $segments[0] = [$segment + ['slotId' => 31], $segment + ['slotId' => 32]];
        $published = $this->publishedDiffusion(101, '10:00');
        $builder = $this->scheduleWithPublishedFixtures($segments, [$published], []);

        $items = $builder->build(new \DateTimeImmutable('2026-09-08 Europe/Paris'))['days'][0]['items'];
        self::assertEqualsCanonicalizing(['empty_regular', 'empty_regular', 'diffusion'], array_column($items, 'type'));
        $diffusion = array_column($items, null, 'type')['diffusion'];
        self::assertFalse($diffusion['isRegular']);
        self::assertSame($published, $diffusion['diffusion']);
    }

    public function testDeletedDraftCannotPromoteItsDiffusionByHistoricalTime(): void
    {
        $slot = new ProgrammationRuleSlot();
        (new \ReflectionProperty($slot, 'id'))->setValue($slot, 31);
        $published = $this->publishedDiffusion(101, '10:00');
        $draft = $this->publishedDraft($slot, $published)->setDeletedAt(new \DateTimeImmutable('2026-09-08 Europe/Paris'));
        $segments = $this->emptyWeek();
        $segments[0][] = $this->createRegularSegment('2026-09-08 10:00:00', '2026-09-08 10:30:00', null)
            + ['slotId' => 31];
        $builder = $this->scheduleWithPublishedFixtures($segments, [$published], [$draft]);

        $items = $builder->build(new \DateTimeImmutable('2026-09-08 Europe/Paris'))['days'][0]['items'];
        self::assertEqualsCanonicalizing(['empty_regular', 'diffusion'], array_column($items, 'type'));
        $diffusion = array_column($items, null, 'type')['diffusion'];
        self::assertFalse($diffusion['isRegular']);
        self::assertSame($published, $diffusion['diffusion']);
    }

    public function testUnpublishedDraftDoesNotReplaceThePublicFallback(): void
    {
        $slot = new ProgrammationRuleSlot();
        (new \ReflectionProperty($slot, 'id'))->setValue($slot, 31);
        $draft = (new DiffusionDraft())->setSlot($slot)->setEmission((new Emission())->setTitre('Brouillon'))
            ->setSchedule(new \DateTimeImmutable('2026-09-08 10:00:00 Europe/Paris'), 30);
        $category = new Categories();
        $segments = $this->emptyWeek();
        $segments[0][] = $this->createRegularSegment('2026-09-08 10:00:00', '2026-09-08 10:30:00', $category)
            + ['slotId' => 31];

        $builder = $this->scheduleWithPublishedFixtures($segments, [], [$draft]);
        $items = $builder->build(new \DateTimeImmutable('2026-09-08 Europe/Paris'))['days'][0]['items'];

        self::assertSame(['empty_regular'], array_column($items, 'type'));
        self::assertNull($items[0]['emission']);
        self::assertSame($category, $items[0]['category']);
    }

    /** @return array{PublicScheduleBuilder, Diffusion} */
    private function scheduleWithDriftedRegularStart(): array
    {
        $slot = new ProgrammationRuleSlot();
        (new \ReflectionProperty($slot, 'id'))->setValue($slot, 31);
        $slot->setStartTime(new \DateTimeImmutable('2026-09-08 10:00:00 Europe/Paris'));
        $published = $this->publishedDiffusion(101, '10:00');
        $draft = $this->publishedDraft($slot, $published);
        // The direct diffusion edit form updates the start on Diffusion alone.
        $published->setHoraireDiffusion(new \DateTimeImmutable('2026-09-08 09:45:00 Europe/Paris'));
        $segments = $this->emptyWeek();
        $segments[0][] = $this->createRegularSegment(
            '2026-09-08 10:00:00', '2026-09-08 10:30:00', new Categories()
        ) + ['slotId' => 31];

        return [$this->scheduleWithPublishedFixtures($segments, [$published], [$draft]), $published];
    }

    private function publishedDiffusion(int $id, string $time): Diffusion
    {
        $diffusion = (new Diffusion())->setEmission((new Emission())->setTitre('Émission ' . $id))
            ->setNombreDiffusion(1)
            ->setSchedule(new \DateTimeImmutable("2026-09-08 $time Europe/Paris"), 30);
        (new \ReflectionProperty($diffusion, 'id'))->setValue($diffusion, $id);

        return $diffusion;
    }

    private function publishedDraft(ProgrammationRuleSlot $slot, Diffusion $diffusion): DiffusionDraft
    {
        return (new DiffusionDraft())->setSlot($slot)->setEmission($diffusion->getEmission())
            ->setSchedule(\DateTimeImmutable::createFromInterface($diffusion->getHoraireDiffusion()), 30)
            ->setNombreDiffusion($diffusion->getNombreDiffusion())
            ->setAssignmentGroupKey($diffusion->getAssignmentGroupKey())
            ->markAsPublished($diffusion);
    }

    private function scheduleWithPublishedFixtures(array $segments, array $diffusions, array $drafts, array $arbitrations = []): PublicScheduleBuilder
    {
        $this->programmationGridBuilder->method('buildForWeek')->willReturn($segments);
        $this->diffusionRepository->method('findPublishedByWeek')->willReturn($diffusions);
        $arbitrationRepository = $this->createMock(GridSlotArbitrationRepository::class);
        $arbitrationRepository->method('findRelevantForWeek')->willReturn($arbitrations);
        $draftRepository = $this->createMock(DiffusionDraftRepository::class);
        $draftRepository->method('findByPublishedDiffusionIds')->willReturnCallback(
            static fn (array $ids): array => array_values(array_filter($drafts, static fn (DiffusionDraft $draft): bool =>
                in_array($draft->getPublishedDiffusion()?->getId(), $ids, true)
            )),
        );

        return new PublicScheduleBuilder(
            $this->diffusionRepository,
            $this->programmationGridBuilder,
            new GridOccurrenceProjectionService($arbitrationRepository),
            $draftRepository,
        );
    }

    private function configureEmptySchedule(): void
    {
        $emptyWeek = $this->emptyWeek();

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($emptyWeek);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($emptyWeek);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([]);
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function emptyWeek(): array
    {
        return array_fill(0, 7, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function createRegularSegment(
        string $startsAt,
        string $endsAt,
        ?Categories $category
    ): array {
        return [
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'category' => $category,
            'isBlocking' => true,
            'isCancelled' => false,
            'isRescheduledOrigin' => false,
        ];
    }
}
