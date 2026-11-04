<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Service\GridPlacementConflictService;
use App\Service\RegularRebroadcastPlacementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RegularRebroadcastPlacementServiceTest extends TestCase
{
    /** @var DiffusionDraftRepository&MockObject */
    private DiffusionDraftRepository $draftRepository;

    /** @var DiffusionRepository&MockObject */
    private DiffusionRepository $diffusionRepository;

    /** @var GridPlacementConflictService&MockObject */
    private GridPlacementConflictService $placementConflictService;

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    private RegularRebroadcastPlacementService $service;

    protected function setUp(): void
    {
        $this->draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $this->diffusionRepository = $this->createMock(
            DiffusionRepository::class
        );

        $this->placementConflictService = $this->createMock(
            GridPlacementConflictService::class
        );

        $this->entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $this->service = new RegularRebroadcastPlacementService(
            $this->draftRepository,
            $this->diffusionRepository,
            $this->placementConflictService,
            $this->entityManager
        );
    }

    public function testPlaceRejectsPendingWithoutEmission(): void
    {
        $pending = new PendingRebroadcast();

        $pending->setAssignmentGroupKey('manual_123');

        $this->draftRepository
            ->expects(self::never())
            ->method('findByAssignmentGroupKey');

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findPublishedByAssignmentGroupKey');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Émission introuvable.');

        $this->service->place(
            $pending,
            new \DateTimeImmutable('2026-09-28 10:00:00')
        );
    }

    public function testPlaceRejectsPendingWithoutAssignmentGroup(): void
    {
        $pending = new PendingRebroadcast();

        $pending
            ->setEmission(
                (new Emission())->setDuree(60)
            )
            ->setAssignmentGroupKey('');

        $this->draftRepository
            ->expects(self::never())
            ->method('findByAssignmentGroupKey');

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findPublishedByAssignmentGroupKey');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Cette rediffusion en attente ne possède pas de groupe.'
        );

        $this->service->place(
            $pending,
            new \DateTimeImmutable('2026-09-28 10:00:00')
        );
    }

    public function testPlaceRejectsStartTimeNotAlignedToQuarterHour(): void
    {
        $pending = $this->createPending('manual_123');

        $this->draftRepository
            ->expects(self::never())
            ->method('findByAssignmentGroupKey');

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findPublishedByAssignmentGroupKey');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'L’heure doit être alignée sur un quart d’heure.'
        );

        $this->service->place(
            $pending,
            new \DateTimeImmutable('2026-09-28 10:10:00')
        );
    }

    public function testPlaceRejectsRegularGroupWithoutRegularDraft(): void
    {
        $pending = $this->createPending(
            'rule_12_origin_20260928_1000'
        );

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('rule_12_origin_20260928_1000')
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('rule_12_origin_20260928_1000')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Aucune diffusion régulière trouvée pour ce groupe.'
        );

        $this->service->place(
            $pending,
            new \DateTimeImmutable('2026-09-28 10:00:00')
        );
    }

    public function testPlaceAllowsManualGroupWithoutRegularDraft(): void
    {
        $pending = $this->createPending('manual_123');

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                [
                    'assignmentGroupKey' => 'manual_123',
                ],
                [
                    'horaireDiffusion' => 'ASC',
                    'id' => 'ASC',
                ]
            )
            ->willReturn([]);

        $startsAt = new \DateTimeImmutable(
            '2026-09-28 10:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-28 11:00:00'
        );

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingRegularOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingDraftOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static function (object $draft): bool {
                    return $draft instanceof DiffusionDraft
                        && DiffusionDraft::TYPE_MANUAL_REBROADCAST
                        === $draft->getDraftType()
                        && 'manual_123'
                        === $draft->getAssignmentGroupKey()
                        && 1 === $draft->getNombreDiffusion()
                        && 60 === $draft->getDurationMinutes();
                }
            ));

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $this->entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($pending);

        $draft = $this->service->place(
            $pending,
            $startsAt
        );

        self::assertSame(
            $pending->getEmission(),
            $draft->getEmission()
        );

        self::assertSame(
            'manual_123',
            $draft->getAssignmentGroupKey()
        );

        self::assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $draft->getDraftType()
        );

        self::assertSame(
            1,
            $draft->getNombreDiffusion()
        );

        self::assertSame(
            60,
            $draft->getDurationMinutes()
        );
    }

    public function testPlaceUsesFifteenMinutesWhenEmissionDurationIsInvalid(): void
    {
        $pending = $this->createPending(
            'manual_123',
            0
        );

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                [
                    'assignmentGroupKey' => 'manual_123',
                ],
                [
                    'horaireDiffusion' => 'ASC',
                    'id' => 'ASC',
                ]
            )
            ->willReturn([]);

        $startsAt = new \DateTimeImmutable(
            '2026-09-28 10:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-28 10:15:00'
        );

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingRegularOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingDraftOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static function (object $draft): bool {
                    return $draft instanceof DiffusionDraft
                        && 15 === $draft->getDurationMinutes();
                }
            ));

        $this->entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($pending);

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $draft = $this->service->place(
            $pending,
            $startsAt
        );

        self::assertSame(
            '2026-09-28 10:15:00',
            $draft->getEndsAt()?->format('Y-m-d H:i:s')
        );
    }

    public function testPlaceRejectsRegularRebroadcastBeforeLastRegularEnd(): void
    {
        $pending = $this->createPending(
            'rule_12_origin_20260928_1000'
        );

        $regularDraft = $this->createRegularDraft(
            2,
            '2026-09-28 10:00:00',
            60
        );

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('rule_12_origin_20260928_1000')
            ->willReturn([$regularDraft]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('rule_12_origin_20260928_1000')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Une rediffusion ponctuelle doit être placée après toutes les diffusions régulières du groupe.'
        );

        $this->service->place(
            $pending,
            new \DateTimeImmutable('2026-09-28 10:30:00')
        );
    }

    public function testPlaceRejectsWhenRegularOverlapExists(): void
    {
        $pending = $this->createPending('manual_123');

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([]);

        $startsAt = new \DateTimeImmutable(
            '2026-09-28 12:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-28 13:00:00'
        );

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingRegularOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(true);

        $this->placementConflictService
            ->expects(self::never())
            ->method('hasBlockingDraftOverlap');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Ce créneau chevauche déjà une programmation régulière.'
        );

        $this->service->place(
            $pending,
            $startsAt
        );
    }

    public function testPlaceRejectsWhenDraftOverlapExists(): void
    {
        $pending = $this->createPending('manual_123');

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([]);

        $startsAt = new \DateTimeImmutable(
            '2026-09-28 12:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-28 13:00:00'
        );

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingRegularOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingDraftOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(true);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Ce créneau chevauche déjà une programmation existante.'
        );

        $this->service->place(
            $pending,
            $startsAt
        );
    }

    public function testPlaceUsesPublishedRankForManualGroup(): void
    {
        $pending = $this->createPending('manual_123');

        $publishedFirst = $this->createPublishedDiffusion(1);
        $publishedSecond = $this->createPublishedDiffusion(2);

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([
                $publishedFirst,
                $publishedSecond,
            ]);

        $this->draftRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                [
                    'assignmentGroupKey' => 'manual_123',
                ],
                [
                    'horaireDiffusion' => 'ASC',
                    'id' => 'ASC',
                ]
            )
            ->willReturn([]);

        $startsAt = new \DateTimeImmutable(
            '2026-09-28 12:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-28 13:00:00'
        );

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingRegularOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingDraftOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static function (object $draft): bool {
                    return $draft instanceof DiffusionDraft
                        && DiffusionDraft::TYPE_MANUAL_REBROADCAST
                        === $draft->getDraftType()
                        && 3 === $draft->getNombreDiffusion();
                }
            ));

        $this->entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($pending);

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $draft = $this->service->place(
            $pending,
            $startsAt
        );

        self::assertSame(
            3,
            $draft->getNombreDiffusion()
        );
    }

    public function testPlaceUsesHighestRegularRankAndRenumbersManualRebroadcasts(): void
    {
        $pending = $this->createPending(
            'rule_12_origin_20260928_1000',
            45
        );

        $regularFirst = $this->createRegularDraft(
            1,
            '2026-09-28 08:00:00',
            30
        );

        $regularSecond = $this->createRegularDraft(
            3,
            '2026-09-28 09:00:00',
            60
        );

        $existingManual = $this->createManualRebroadcastDraft(
            99,
            '2026-09-28 11:00:00',
            45,
            'rule_12_origin_20260928_1000'
        );

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('rule_12_origin_20260928_1000')
            ->willReturn([
                $regularFirst,
                $regularSecond,
                $existingManual,
            ]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('rule_12_origin_20260928_1000')
            ->willReturn([]);

        $startsAt = new \DateTimeImmutable(
            '2026-09-28 12:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-28 12:45:00'
        );

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingRegularOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingDraftOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $createdDraft = null;

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static function (object $draft) use (&$createdDraft): bool {
                    if (!$draft instanceof DiffusionDraft) {
                        return false;
                    }

                    $createdDraft = $draft;

                    return DiffusionDraft::TYPE_MANUAL_REBROADCAST
                        === $draft->getDraftType()
                        && 4 === $draft->getNombreDiffusion()
                        && 45 === $draft->getDurationMinutes();
                }
            ));

        /*
     * Après le flush, les deux rediffusions manuelles sont présentes
     * dans le groupe et doivent être renumérotées selon leur horaire.
     */
        $this->draftRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                [
                    'assignmentGroupKey' =>
                    'rule_12_origin_20260928_1000',
                ],
                [
                    'horaireDiffusion' => 'ASC',
                    'id' => 'ASC',
                ]
            )
            ->willReturnCallback(
                static function () use (
                    &$createdDraft,
                    $existingManual
                ): array {
                    self::assertInstanceOf(
                        DiffusionDraft::class,
                        $createdDraft
                    );

                    return [
                        $existingManual,
                        $createdDraft,
                    ];
                }
            );

        $this->entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($pending);

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $draft = $this->service->place(
            $pending,
            $startsAt
        );

        /*
     * La régulière n°3 constitue la base.
     *
     * Existing manual à 11h → n°4
     * Nouvelle rediffusion à 12h → n°5
     */
        self::assertSame(
            5,
            $draft->getNombreDiffusion()
        );

        self::assertSame(
            4,
            $existingManual->getNombreDiffusion()
        );
    }

    public function testPlaceContinuesAfterPublishedManualRebroadcastAndRenumbersDrafts(): void
    {
        $pending = $this->createPending('manual_123');

        $publishedFirst = $this->createPublishedDiffusion(1);
        $publishedRebroadcast = $this->createPublishedDiffusion(2);

        $existingDraft = $this->createManualRebroadcastDraft(
            99,
            '2026-09-28 11:00:00',
            60,
            'manual_123'
        );

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([
                $existingDraft,
            ]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([
                $publishedFirst,
                $publishedRebroadcast,
            ]);

        $startsAt = new \DateTimeImmutable(
            '2026-09-28 12:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-28 13:00:00'
        );

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingRegularOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingDraftOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $createdDraft = null;

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static function (object $draft) use (&$createdDraft): bool {
                    if (!$draft instanceof DiffusionDraft) {
                        return false;
                    }

                    $createdDraft = $draft;

                    return DiffusionDraft::TYPE_MANUAL_REBROADCAST
                        === $draft->getDraftType()
                        && 3 === $draft->getNombreDiffusion();
                }
            ));

        $this->draftRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                [
                    'assignmentGroupKey' => 'manual_123',
                ],
                [
                    'horaireDiffusion' => 'ASC',
                    'id' => 'ASC',
                ]
            )
            ->willReturnCallback(
                static function () use (
                    &$createdDraft,
                    $existingDraft
                ): array {
                    self::assertInstanceOf(
                        DiffusionDraft::class,
                        $createdDraft
                    );

                    return [
                        $existingDraft,
                        $createdDraft,
                    ];
                }
            );

        $this->entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($pending);

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $draft = $this->service->place(
            $pending,
            $startsAt
        );

        /*
     * Les deux diffusions publiées occupent déjà les rangs 1 et 2.
     *
     * Existing draft à 11h → n°3
     * Nouvelle rediffusion à 12h → n°4
     */
        self::assertSame(
            4,
            $draft->getNombreDiffusion()
        );

        self::assertSame(
            3,
            $existingDraft->getNombreDiffusion()
        );
    }

    public function testPlaceDoesNotRemovePendingWhenPlacementFails(): void
    {
        $pending = $this->createPending('manual_123');

        $this->draftRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByAssignmentGroupKey')
            ->with('manual_123')
            ->willReturn([]);

        $startsAt = new \DateTimeImmutable(
            '2026-09-28 12:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-28 13:00:00'
        );

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingRegularOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(false);

        $this->placementConflictService
            ->expects(self::once())
            ->method('hasBlockingDraftOverlap')
            ->with($startsAt, $endsAt)
            ->willReturn(true);

        $this->entityManager
            ->expects(self::never())
            ->method('remove');

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $this->expectException(\DomainException::class);

        $this->service->place(
            $pending,
            $startsAt
        );
    }

    public function testPlaceRejectsDismissedPendingWithoutAccessingRepositoriesOrDatabase(): void
    {
        $pending = $this->createPending('manual_123');
        $pending->dismiss();

        self::assertTrue($pending->isDismissed());

        $this->draftRepository
            ->expects(self::never())
            ->method('findByAssignmentGroupKey');

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findPublishedByAssignmentGroupKey');

        $this->placementConflictService
            ->expects(self::never())
            ->method('hasBlockingRegularOverlap');

        $this->placementConflictService
            ->expects(self::never())
            ->method('hasBlockingDraftOverlap');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::never())
            ->method('remove');

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Cette rediffusion n’est plus disponible dans le parc.'
        );

        $this->service->place(
            $pending,
            new \DateTimeImmutable('2026-09-28 10:00:00')
        );
    }

    public function testPlaceRejectsValidatedWeekWithoutCreatingDraftOrRemovingPending(): void
{
    $pending = $this->createPending('manual_123');

    $startsAt = new \DateTimeImmutable(
        '2026-10-10 14:00:00'
    );

    $weekStart = new \DateTimeImmutable(
        '2026-10-06 00:00:00'
    );

    $weekEnd = new \DateTimeImmutable(
        '2026-10-13 00:00:00'
    );

    $this->diffusionRepository
        ->expects(self::once())
        ->method('hasDiffusionsBetween')
        ->with($weekStart, $weekEnd)
        ->willReturn(true);

    $this->draftRepository
        ->expects(self::never())
        ->method('findByAssignmentGroupKey');

    $this->placementConflictService
        ->expects(self::never())
        ->method('hasBlockingRegularOverlap');

    $this->placementConflictService
        ->expects(self::never())
        ->method('hasBlockingDraftOverlap');

    $this->entityManager
        ->expects(self::never())
        ->method('persist');

    $this->entityManager
        ->expects(self::never())
        ->method('remove');

    $this->entityManager
        ->expects(self::never())
        ->method('flush');

    $this->expectException(\DomainException::class);

    $this->expectExceptionMessage(
        'Impossible de modifier une semaine validée.'
    );

    $this->service->place(
        $pending,
        $startsAt
    );
}

public function testPlaceChecksTargetWeekAndAllowsDraftWeek(): void
{
    $pending = $this->createPending('manual_123');

    $startsAt = new \DateTimeImmutable('2026-10-10 14:00:00');

    $weekStart = new \DateTimeImmutable('2026-10-06 00:00:00');
    $weekEnd = new \DateTimeImmutable('2026-10-13 00:00:00');

    $this->diffusionRepository
        ->expects(self::once())
        ->method('hasDiffusionsBetween')
        ->with($weekStart, $weekEnd)
        ->willReturn(false);

    $this->draftRepository
        ->expects(self::once())
        ->method('findByAssignmentGroupKey')
        ->with('manual_123')
        ->willReturn([]);

    $this->diffusionRepository
        ->method('findPublishedByAssignmentGroupKey')
        ->willReturn([]);

    $this->placementConflictService
        ->method('hasBlockingRegularOverlap')
        ->willReturn(false);

    $this->placementConflictService
        ->method('hasBlockingDraftOverlap')
        ->willReturn(false);

    $this->draftRepository
        ->method('findBy')
        ->willReturn([]);

    $this->entityManager
        ->expects(self::once())
        ->method('persist');

    $this->entityManager
        ->expects(self::once())
        ->method('remove')
        ->with($pending);

    $this->entityManager
        ->expects(self::exactly(2))
        ->method('flush');

    $draft = $this->service->place($pending, $startsAt);

    self::assertSame(
        DiffusionDraft::TYPE_MANUAL_REBROADCAST,
        $draft->getDraftType()
    );

    self::assertSame(
        'manual_123',
        $draft->getAssignmentGroupKey()
    );

    self::assertSame(
        '2026-10-10 14:00:00',
        $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
    );
}

    private function createPending(
        string $assignmentGroupKey = 'manual_123',
        int $duration = 60
    ): PendingRebroadcast {
        $emission = new Emission();

        $emission->setDuree($duration);

        return (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($assignmentGroupKey);
    }

    private function createRegularDraft(
        int $rank,
        string $startsAt,
        int $duration
    ): DiffusionDraft {
        $emission = new Emission();

        $emission->setDuree($duration);

        return (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_REGULAR)
            ->setNombreDiffusion($rank)
            ->setAssignmentGroupKey(
                'rule_12_origin_20260928_1000'
            )
            ->setSchedule(
                new \DateTimeImmutable($startsAt),
                $duration
            );
    }

    private function createManualRebroadcastDraft(
        int $rank,
        string $startsAt,
        int $duration,
        string $assignmentGroupKey
    ): DiffusionDraft {
        $emission = new Emission();

        $emission->setDuree($duration);

        return (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(
                DiffusionDraft::TYPE_MANUAL_REBROADCAST
            )
            ->setNombreDiffusion($rank)
            ->setAssignmentGroupKey($assignmentGroupKey)
            ->setSchedule(
                new \DateTimeImmutable($startsAt),
                $duration
            );
    }

    private function createPublishedDiffusion(
        int $rank
    ): Diffusion {
        $diffusion = $this->createMock(Diffusion::class);

        $diffusion
            ->expects(self::once())
            ->method('getNombreDiffusion')
            ->willReturn($rank);

        return $diffusion;
    }
}
