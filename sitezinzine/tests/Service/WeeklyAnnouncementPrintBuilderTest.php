<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Entity\InviteOldAnimateur;
use App\Entity\User;
use App\Repository\DiffusionRepository;
use App\Service\WeeklyAnnouncementPrintBuilder;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WeeklyAnnouncementPrintBuilderTest extends TestCase
{
    private DiffusionRepository&MockObject $diffusionRepository;

    private WeeklyAnnouncementPrintBuilder $builder;

    protected function setUp(): void
    {
        $this->diffusionRepository = $this->createMock(
            DiffusionRepository::class
        );

        $this->builder = new WeeklyAnnouncementPrintBuilder(
            $this->diffusionRepository
        );
    }

    public function testBuildCallsRepositoryWithExpectedWeekBounds(): void
    {
        $start = new \DateTimeImmutable('2026-09-08 00:00:00');
        $end = new \DateTimeImmutable('2026-09-15 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($start, $end)
            ->willReturn([]);

        self::assertSame(
            [],
            $this->builder->build($start, $end)
        );
    }

    public function testBuildIgnoresDiffusionWithoutEmission(): void
    {
        $start = new \DateTimeImmutable('2026-09-08');
        $end = new \DateTimeImmutable('2026-09-15');

        $diffusion = $this->createMock(Diffusion::class);
        $diffusion
            ->method('getEmission')
            ->willReturn(null);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        self::assertSame(
            [],
            $this->builder->build($start, $end)
        );
    }

    public function testBuildIgnoresEmissionWithEmptyDescription(): void
    {
        $emission = $this->createEmission(
            id: 1,
            description: ''
        );

        $diffusion = $this->createDiffusion($emission);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        self::assertSame(
            [],
            $this->builder->build(
                new \DateTimeImmutable('2026-09-08'),
                new \DateTimeImmutable('2026-09-15')
            )
        );
    }

    public function testBuildIgnoresDescriptionContainingOnlyHtmlAndSpaces(): void
    {
        $emission = $this->createEmission(
            id: 1,
            description: '<p>&nbsp;</p>'
        );

        $diffusion = $this->createDiffusion($emission);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        self::assertSame(
            [],
            $this->builder->build(
                new \DateTimeImmutable('2026-09-08'),
                new \DateTimeImmutable('2026-09-15')
            )
        );
    }

    public function testBuildIgnoresPlaceholderDescription(): void
    {
        $emission = $this->createEmission(
            id: 1,
            description: '<p>Description à remplir</p>'
        );

        $diffusion = $this->createDiffusion($emission);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        self::assertSame(
            [],
            $this->builder->build(
                new \DateTimeImmutable('2026-09-08'),
                new \DateTimeImmutable('2026-09-15')
            )
        );
    }

    public function testBuildIgnoresAutomaticallyGeneratedDescription(): void
    {
        $emission = $this->createEmission(
            id: 1,
            description: 'Cette description a été générée automatiquement.'
        );

        $diffusion = $this->createDiffusion($emission);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        self::assertSame(
            [],
            $this->builder->build(
                new \DateTimeImmutable('2026-09-08'),
                new \DateTimeImmutable('2026-09-15')
            )
        );
    }

    public function testBuildIgnoresEmissionWithoutId(): void
    {
        $emission = $this->createEmission(
            id: null,
            description: 'Une vraie description.'
        );

        $diffusion = $this->createDiffusion($emission);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        self::assertSame(
            [],
            $this->builder->build(
                new \DateTimeImmutable('2026-09-08'),
                new \DateTimeImmutable('2026-09-15')
            )
        );
    }

    public function testBuildCreatesOneItemForValidEmission(): void
    {
        $user = $this->createMock(User::class);

        $oldAnimator = $this->createMock(
            InviteOldAnimateur::class
        );

        $oldAnimator
            ->method('isAncienanimateur')
            ->willReturn(true);

        $notOldAnimator = $this->createMock(
            InviteOldAnimateur::class
        );

        $notOldAnimator
            ->method('isAncienanimateur')
            ->willReturn(false);

        $emission = $this->createEmission(
            id: 12,
            description: '<p>Une vraie description.</p>',
            users: [$user],
            oldAnimators: [
                $oldAnimator,
                $notOldAnimator,
            ]
        );

        $diffusion = $this->createDiffusion($emission);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable('2026-09-15')
        );

        self::assertCount(1, $result);

        self::assertSame(
            $emission,
            $result[0]['emission']
        );

        self::assertSame(
            [$user],
            $result[0]['users']
        );

        self::assertSame(
            [$oldAnimator],
            $result[0]['anciensAnimateurs']
        );

        self::assertSame(
            [$diffusion],
            $result[0]['diffusions']
        );
    }

    public function testBuildGroupsSeveralDiffusionsOfSameEmission(): void
    {
        $emission = $this->createEmission(
            id: 42,
            description: 'Description réelle'
        );

        $diffusion1 = $this->createDiffusion($emission);
        $diffusion2 = $this->createDiffusion($emission);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([
                $diffusion1,
                $diffusion2,
            ]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable('2026-09-15')
        );

        self::assertCount(1, $result);

        self::assertSame(
            [
                $diffusion1,
                $diffusion2,
            ],
            $result[0]['diffusions']
        );
    }

    public function testBuildKeepsDifferentEmissionsSeparate(): void
    {
        $emission1 = $this->createEmission(
            id: 1,
            description: 'Première description'
        );

        $emission2 = $this->createEmission(
            id: 2,
            description: 'Deuxième description'
        );

        $diffusion1 = $this->createDiffusion($emission1);
        $diffusion2 = $this->createDiffusion($emission2);

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([
                $diffusion1,
                $diffusion2,
            ]);

        $result = $this->builder->build(
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable('2026-09-15')
        );

        self::assertCount(2, $result);

        self::assertSame(
            $emission1,
            $result[0]['emission']
        );

        self::assertSame(
            $emission2,
            $result[1]['emission']
        );
    }

    private function createDiffusion(
        ?Emission $emission
    ): Diffusion&MockObject {
        $diffusion = $this->createMock(
            Diffusion::class
        );

        $diffusion
            ->method('getEmission')
            ->willReturn($emission);

        return $diffusion;
    }

    private function createEmission(
        ?int $id,
        string $description,
        array $users = [],
        array $oldAnimators = []
    ): Emission&MockObject {
        $emission = $this->createMock(
            Emission::class
        );

        $emission
            ->method('getId')
            ->willReturn($id);

        $emission
            ->method('getDescriptif')
            ->willReturn($description);

        $emission
            ->method('getUsers')
            ->willReturn(
                new ArrayCollection($users)
            );

        $emission
            ->method('getInviteOldAnimateurs')
            ->willReturn(
                new ArrayCollection($oldAnimators)
            );

        return $emission;
    }
}