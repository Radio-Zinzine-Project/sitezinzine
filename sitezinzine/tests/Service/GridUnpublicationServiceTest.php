<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Service\GridUnpublicationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GridUnpublicationServiceTest extends TestCase
{
    private DiffusionRepository&MockObject $diffusionRepository;
    private DiffusionDraftRepository&MockObject $draftRepository;
    private EntityManagerInterface&MockObject $entityManager;

    private GridUnpublicationService $service;

    protected function setUp(): void
    {
        $this->diffusionRepository = $this->createMock(
            DiffusionRepository::class
        );

        $this->draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $this->entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $this->service = new GridUnpublicationService(
            $this->diffusionRepository,
            $this->draftRepository,
            $this->entityManager
        );
    }

    public function testPreviewNormalizesRadioWeekAndRejectsEmptyWeek(): void
    {
        $expectedStart = new \DateTimeImmutable(
            '2026-09-08 00:00:00'
        );

        $expectedEnd = new \DateTimeImmutable(
            '2026-09-15 00:00:00'
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with(
                $expectedStart,
                $expectedEnd
            )
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::never())
            ->method('findByPublishedDiffusionIds');

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-10 15:30:00')
        );

        self::assertEquals(
            $expectedStart,
            $result['weekStart']
        );

        self::assertEquals(
            $expectedEnd,
            $result['weekEnd']
        );

        self::assertSame(
            0,
            $result['publishedDiffusionCount']
        );

        self::assertSame(
            0,
            $result['linkedDraftCount']
        );

        self::assertSame(
            0,
            $result['missingDraftCount']
        );

        self::assertFalse(
            $result['canUnpublish']
        );

        self::assertSame(
            'no_published_diffusions',
            $result['reason']
        );

        self::assertSame([], $result['items']);
    }

    public function testPreviewRejectsLegacyWeekWhenDiffusionHasNoLinkedDraft(): void
    {
        $emission = $this->createEmission(
            10,
            'Émission test'
        );

        $diffusion = $this->createDiffusion(
            100,
            '2026-09-09 10:00:00',
            $emission,
            'group_1'
        );

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findByPublishedDiffusionIds')
            ->with([100])
            ->willReturn([]);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            1,
            $result['publishedDiffusionCount']
        );

        self::assertSame(
            0,
            $result['linkedDraftCount']
        );

        self::assertSame(
            1,
            $result['missingDraftCount']
        );

        self::assertFalse(
            $result['canUnpublish']
        );

        self::assertSame(
            'legacy_week_without_drafts',
            $result['reason']
        );

        self::assertCount(1, $result['items']);

        $item = $result['items'][0];

        self::assertSame(100, $item['diffusionId']);

        self::assertSame(
            '2026-09-09 10:00:00',
            $item['startsAt']
        );

        self::assertSame(
            10,
            $item['emissionId']
        );

        self::assertSame(
            'Émission test',
            $item['emissionTitle']
        );

        self::assertSame(
            'group_1',
            $item['assignmentGroupKey']
        );

        self::assertNull(
            $item['draftId']
        );

        self::assertNull(
            $item['draftPublicationStatus']
        );

        self::assertFalse(
            $item['hasLinkedDraft']
        );
    }

    public function testPreviewAllowsWeekWhenEveryDiffusionHasLinkedDraft(): void
    {
        $emission = $this->createEmission(
            10,
            'Émission test'
        );

        $diffusion = $this->createDiffusion(
            100,
            '2026-09-09 10:00:00',
            $emission,
            'group_1'
        );

        $draft = $this->createDraft(
            200,
            $diffusion,
            DiffusionDraft::STATUS_PUBLISHED
        );

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        $this->draftRepository
            ->method('findByPublishedDiffusionIds')
            ->with([100])
            ->willReturn([$draft]);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            1,
            $result['publishedDiffusionCount']
        );

        self::assertSame(
            1,
            $result['linkedDraftCount']
        );

        self::assertSame(
            0,
            $result['missingDraftCount']
        );

        self::assertTrue(
            $result['canUnpublish']
        );

        self::assertNull(
            $result['reason']
        );

        self::assertCount(1, $result['items']);

        $item = $result['items'][0];

        self::assertSame(
            200,
            $item['draftId']
        );

        self::assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $item['draftPublicationStatus']
        );

        self::assertTrue(
            $item['hasLinkedDraft']
        );
    }

    public function testPreviewHandlesSeveralPublishedDiffusions(): void
    {
        $emissionA = $this->createEmission(
            10,
            'Émission A'
        );

        $emissionB = $this->createEmission(
            11,
            'Émission B'
        );

        $firstDiffusion = $this->createDiffusion(
            100,
            '2026-09-09 10:00:00',
            $emissionA
        );

        $secondDiffusion = $this->createDiffusion(
            101,
            '2026-09-10 12:00:00',
            $emissionB
        );

        $firstDraft = $this->createDraft(
            200,
            $firstDiffusion,
            DiffusionDraft::STATUS_PUBLISHED
        );

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([
                $firstDiffusion,
                $secondDiffusion,
            ]);

        $this->draftRepository
            ->method('findByPublishedDiffusionIds')
            ->with([100, 101])
            ->willReturn([$firstDraft]);

        $result = $this->service->previewWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            2,
            $result['publishedDiffusionCount']
        );

        self::assertSame(
            1,
            $result['linkedDraftCount']
        );

        self::assertSame(
            1,
            $result['missingDraftCount']
        );

        self::assertFalse(
            $result['canUnpublish']
        );

        self::assertCount(
            2,
            $result['items']
        );
    }

    public function testUnpublishWeekRejectsWeekThatCannotBeUnpublished(): void
    {
        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([]);

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $this->expectException(
            \DomainException::class
        );

        $this->expectExceptionMessage(
            'Cette semaine ne peut pas être dévalidée automatiquement.'
        );

        $this->service->unpublishWeek(
            new \DateTimeImmutable('2026-09-08')
        );
    }

    public function testUnpublishWeekRestoresDraftAndUnpublishesDiffusion(): void
    {
        $emission = $this->createEmission(
            10,
            'Émission test'
        );

        $previewDiffusion = $this->createDiffusion(
            100,
            '2026-09-09 10:00:00',
            $emission
        );

        $draft = $this->createMock(
            DiffusionDraft::class
        );

        $draft
            ->method('getId')
            ->willReturn(200);

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn($previewDiffusion);

        $draft
            ->method('getPublicationStatus')
            ->willReturn(
                DiffusionDraft::STATUS_PUBLISHED
            );

        $draft
            ->expects(self::once())
            ->method('setPublicationStatus')
            ->with(DiffusionDraft::STATUS_DRAFT)
            ->willReturnSelf();

        $draft
            ->expects(self::once())
            ->method('setPublishedAt')
            ->with(null)
            ->willReturnSelf();

        /*
         * On utilise une vraie Diffusion pour pouvoir vérifier
         * son changement d'état après markAsUnpublished().
         */
        $diffusion = new Diffusion();

        $reflection = new \ReflectionProperty(
            Diffusion::class,
            'id'
        );

        $reflection->setValue(
            $diffusion,
            100
        );

        $diffusion
            ->setEmission($emission)
            ->setHoraireDiffusion(
                new \DateTime(
                    '2026-09-09 10:00:00'
                )
            );

        /*
         * La preview utilise previewDiffusion.
         */
        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$previewDiffusion]);

        /*
         * Premier appel : preview.
         * Second appel : reconstruction pendant unpublishWeek().
         */
        $this->draftRepository
            ->method('findByPublishedDiffusionIds')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn($diffusion);

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $result = $this->service->unpublishWeek(
            new \DateTimeImmutable('2026-09-08')
        );

        self::assertSame(
            1,
            $result['unpublishedDiffusionCount']
        );

        self::assertSame(
            1,
            $result['restoredDraftCount']
        );

        self::assertSame(
            [$diffusion],
            $result['unpublishedDiffusions']
        );

        self::assertSame(
            [$draft],
            $result['restoredDrafts']
        );
    }

    private function createEmission(
        int $id,
        string $title
    ): Emission&MockObject {
        $emission = $this->createMock(
            Emission::class
        );

        $emission
            ->method('getId')
            ->willReturn($id);

        $emission
            ->method('getTitre')
            ->willReturn($title);

        return $emission;
    }

    private function createDiffusion(
        int $id,
        string $startsAt,
        ?Emission $emission = null,
        ?string $assignmentGroupKey = null
    ): Diffusion&MockObject {
        $diffusion = $this->createMock(
            Diffusion::class
        );

        $diffusion
            ->method('getId')
            ->willReturn($id);

        $diffusion
            ->method('getHoraireDiffusion')
            ->willReturn(
                new \DateTime($startsAt)
            );

        $diffusion
            ->method('getEmission')
            ->willReturn($emission);

        $diffusion
            ->method('getAssignmentGroupKey')
            ->willReturn($assignmentGroupKey);

        return $diffusion;
    }

    private function createDraft(
        int $id,
        Diffusion $publishedDiffusion,
        string $publicationStatus
    ): DiffusionDraft&MockObject {
        $draft = $this->createMock(
            DiffusionDraft::class
        );

        $draft
            ->method('getId')
            ->willReturn($id);

        $draft
            ->method('getPublishedDiffusion')
            ->willReturn($publishedDiffusion);

        $draft
            ->method('getPublicationStatus')
            ->willReturn($publicationStatus);

        return $draft;
    }

    public function testUnpublishWeekThrowsWhenDiffusionDisappearsAfterPreview(): void
    {
        $emission = $this->createEmission(
            10,
            'Émission test'
        );

        $diffusion = $this->createDiffusion(
            100,
            '2026-09-09 10:00:00',
            $emission
        );

        $draft = $this->createDraft(
            200,
            $diffusion,
            DiffusionDraft::STATUS_PUBLISHED
        );

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        $this->draftRepository
            ->method('findByPublishedDiffusionIds')
            ->willReturn([$draft]);

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn(null);

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $this->expectException(
            \LogicException::class
        );

        $this->expectExceptionMessage(
            'Diffusion #100 introuvable pendant la dévalidation.'
        );

        $this->service->unpublishWeek(
            new \DateTimeImmutable('2026-09-08')
        );
    }

    public function testUnpublishWeekThrowsWhenLinkedDraftDisappearsAfterPreview(): void
    {
        $emission = $this->createEmission(
            10,
            'Émission test'
        );

        $diffusion = $this->createDiffusion(
            100,
            '2026-09-09 10:00:00',
            $emission
        );

        $previewDraft = $this->createDraft(
            200,
            $diffusion,
            DiffusionDraft::STATUS_PUBLISHED
        );

        $this->diffusionRepository
            ->method('findPublishedByWeek')
            ->willReturn([$diffusion]);

        /*
     * Premier appel : preview => draft présent.
     * Second appel : unpublish => draft disparu.
     */
        $this->draftRepository
            ->method('findByPublishedDiffusionIds')
            ->willReturnOnConsecutiveCalls(
                [$previewDraft],
                []
            );

        $this->diffusionRepository
            ->method('find')
            ->with(100)
            ->willReturn($diffusion);

        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $this->expectException(
            \DomainException::class
        );

        $this->expectExceptionMessage(
            'La Diffusion #100 ne possède plus de draft lié.'
        );

        $this->service->unpublishWeek(
            new \DateTimeImmutable('2026-09-08')
        );
    }
}
