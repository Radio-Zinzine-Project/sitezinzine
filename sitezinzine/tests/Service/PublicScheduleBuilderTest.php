<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Repository\DiffusionRepository;
use App\Service\GridOccurrenceProjectionService;
use App\Service\ProgrammationGridBuilder;
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
            $this->projectionService
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