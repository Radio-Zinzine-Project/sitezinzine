<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\DiffusionRepository;
use App\Repository\PendingRebroadcastRepository;
use App\Repository\DiffusionDraftRepository;
use App\Service\PendingRebroadcastService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PendingRebroadcastServiceTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    /** @var DiffusionRepository&MockObject */
    private DiffusionRepository $diffusionRepository;

    /** @var PendingRebroadcastRepository&MockObject */
    private PendingRebroadcastRepository $pendingRebroadcastRepository;

    private PendingRebroadcastService $service;

    /** @var DiffusionDraftRepository&MockObject */
    private DiffusionDraftRepository $draftRepository;

    protected function setUp(): void
    {
        $this->entityManager =
            $this->createMock(EntityManagerInterface::class);

        $this->diffusionRepository =
            $this->createMock(DiffusionRepository::class);

        $this->pendingRebroadcastRepository =
            $this->createMock(PendingRebroadcastRepository::class);

        $this->draftRepository =
            $this->createMock(DiffusionDraftRepository::class);

        $this->draftRepository
            ->method('findByAssignmentGroupKey')
            ->willReturn([]);

        $this->service = new PendingRebroadcastService(
            $this->entityManager,
            $this->diffusionRepository,
            $this->pendingRebroadcastRepository,
            $this->draftRepository
        );
    }

    public function testCreateForGroupPersistsPendingWithoutFlushing(): void
    {
        $emission = $this->createEmission();

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static function (object $entity) use ($emission): bool {
                    return $entity instanceof PendingRebroadcast
                        && $entity->getEmission() === $emission
                        && 'group_1' === $entity->getAssignmentGroupKey();
                }
            ));

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $pending = $this->service->createForGroup(
            $emission,
            'group_1'
        );

        self::assertSame($emission, $pending->getEmission());
        self::assertSame(
            'group_1',
            $pending->getAssignmentGroupKey()
        );
    }

    public function testCreateForGroupRejectsEmptyAssignmentGroupKey(): void
    {
        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createForGroup(
            $this->createEmission(),
            '   '
        );
    }

    public function testPopulateCreatesPendingWhenGroupHasOnlyTwoPublishedDiffusions(): void
    {
        $emission = $this->createEmission(10);

        $diffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_10'], $before)
            ->willReturn([
                10 => ['group_10' => 2],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('group_10')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            $before
        );

        self::assertCount(1, $created);
        self::assertSame($emission, $created[0]->getEmission());
        self::assertSame('group_10', $created[0]->getAssignmentGroupKey());
    }

    public function testPopulateDoesNotCreatePendingAtThirdPublishedDiffusion(): void
    {
        $emission = $this->createEmission(10);

        $diffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_10'], $before)
            ->willReturn([
                10 => ['group_10' => 3],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::never())
            ->method('findByAssignmentGroupKey');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            $before
        );

        self::assertSame([], $created);
    }

    public function testPopulateDoesNotCreatePendingAtFourthPublishedDiffusion(): void
    {
        $emission = $this->createEmission(10);

        $diffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_10'], $before)
            ->willReturn([
                10 => ['group_10' => 4],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::never())
            ->method('findByAssignmentGroupKey');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            $before
        );

        self::assertSame([], $created);
    }

    public function testPopulateDoesNotCreatePendingWhenGroupIsAlreadyInPool(): void
    {
        $emission = $this->createEmission(10);

        $diffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $existing = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('group_10');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_10'], $before)
            ->willReturn([
                10 => ['group_10' => 2],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('group_10')
            ->willReturn([$existing]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            $before
        );

        self::assertSame([], $created);
        self::assertTrue($existing->isPending());
    }

    public function testPopulateCreatesOnlyOnePendingForSeveralDiffusionsOfSameGroup(): void
    {
        $emission = $this->createEmission(10);

        $laterDiffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-24 10:00:00'
        );

        $firstDiffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_10'], $before)
            ->willReturn([
                10 => ['group_10' => 2],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('group_10')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emission
                    && $entity->getAssignmentGroupKey() === 'group_10'
                    && $entity->isPending()
            ));

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $created = $this->service->populateFromPublishedDiffusions(
            [$laterDiffusion, $firstDiffusion],
            $before
        );

        self::assertCount(1, $created);
        self::assertSame($emission, $created[0]->getEmission());
        self::assertSame('group_10', $created[0]->getAssignmentGroupKey());
    }

    public function testPopulateIgnoresUnpublishedDiffusions(): void
    {
        $emission = $this->createEmission(10);

        $diffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $diffusion->markAsUnpublished();

        $this->diffusionRepository
            ->expects(self::never())
            ->method('countPublishedBeforeForAssignmentGroupKeys');

        $this->pendingRebroadcastRepository
            ->expects(self::never())
            ->method('existsForAssignmentGroupKey');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            new \DateTimeImmutable('2026-09-29 00:00:00')
        );

        self::assertSame([], $created);
    }


    public function testPopulateIgnoresDiffusionWithoutAssignmentGroupKey(): void
    {
        $emission = $this->createEmission(10);

        $diffusion = $this->createDiffusion(
            $emission,
            null,
            '2026-09-22 10:00:00'
        );

        $this->diffusionRepository
            ->expects(self::never())
            ->method('countPublishedBeforeForAssignmentGroupKeys');

        $this->pendingRebroadcastRepository
            ->expects(self::never())
            ->method('existsForAssignmentGroupKey');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            new \DateTimeImmutable('2026-09-29 00:00:00')
        );

        self::assertSame([], $created);
    }

    public function testPopulateDoesNotFlush(): void
    {
        $emission = $this->createEmission(10);

        $diffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_10'], $before)
            ->willReturn([
                10 => ['group_10' => 1],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('group_10')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emission
                    && $entity->getAssignmentGroupKey() === 'group_10'
                    && $entity->isPending()
            ));

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            $before
        );

        self::assertCount(1, $created);
    }

    public function testPopulateEvaluatesEachGroupIndependently(): void
    {
        $emission = $this->createEmission(10);

        $groupA = $this->createDiffusion(
            $emission,
            'group_A',
            '2026-09-22 10:00:00'
        );

        $groupB = $this->createDiffusion(
            $emission,
            'group_B',
            '2026-09-22 11:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_A', 'group_B'], $before)
            ->willReturn([
                10 => [
                    'group_A' => 3,
                    'group_B' => 2,
                ],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('group_B')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emission
                    && $entity->getAssignmentGroupKey() === 'group_B'
                    && $entity->isPending()
            ));

        $created = $this->service->populateFromPublishedDiffusions(
            [$groupA, $groupB],
            $before
        );

        self::assertCount(1, $created);
        self::assertSame($emission, $created[0]->getEmission());
        self::assertSame('group_B', $created[0]->getAssignmentGroupKey());
    }

    public function testPopulateEvaluatesDifferentEmissionsIndependently(): void
    {
        $eligibleEmission = $this->createEmission(10);
        $ineligibleEmission = $this->createEmission(20);

        $eligibleDiffusion = $this->createDiffusion(
            $eligibleEmission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $ineligibleDiffusion = $this->createDiffusion(
            $ineligibleEmission,
            'group_20',
            '2026-09-22 11:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_10', 'group_20'], $before)
            ->willReturn([
                10 => ['group_10' => 2],
                20 => ['group_20' => 3],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('group_10')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $eligibleEmission
                    && $entity->getAssignmentGroupKey() === 'group_10'
                    && $entity->isPending()
            ));

        $created = $this->service->populateFromPublishedDiffusions(
            [$eligibleDiffusion, $ineligibleDiffusion],
            $before
        );

        self::assertCount(1, $created);
        self::assertSame($eligibleEmission, $created[0]->getEmission());
        self::assertSame('group_10', $created[0]->getAssignmentGroupKey());
    }

    public function testPopulateDoesNotCreatePendingWhenCurrentPublicationHasThreePublishedDiffusions(): void
    {
        $emission = $this->createEmission(10);

        $firstDiffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-22 10:00:00'
        );

        $secondDiffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-23 10:00:00'
        );

        $thirdDiffusion = $this->createDiffusion(
            $emission,
            'group_10',
            '2026-09-24 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        /*
     * GridPublicationService effectue un flush avant
     * l'alimentation du parc.
     *
     * Le repository voit donc les trois Diffusion publiées
     * du groupe concerné par la validation courante.
     */
        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['group_10'], $before)
            ->willReturn([
                10 => ['group_10' => 3],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::never())
            ->method('findByAssignmentGroupKey');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $created = $this->service->populateFromPublishedDiffusions(
            [
                $firstDiffusion,
                $secondDiffusion,
                $thirdDiffusion,
            ],
            $before
        );

        self::assertSame([], $created);
    }

    public function testPopulateDoesNotRecreateDismissedPending(): void
    {
        $emission = $this->createEmission(10);

        $diffusion = $this->createDiffusion(
            $emission,
            'manual_10',
            '2026-09-22 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $dismissed = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('manual_10')
            ->dismiss();

        self::assertTrue($dismissed->isDismissed());

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['manual_10'], $before)
            ->willReturn([
                10 => ['manual_10' => 1],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('manual_10')
            ->willReturn([$dismissed]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            $before
        );

        self::assertSame([], $created);
        self::assertTrue($dismissed->isDismissed());
    }

    public function testPopulateKeepsDismissalScopedToAssignmentGroup(): void
    {
        $emission = $this->createEmission(10);

        $dismissedGroup = $this->createDiffusion(
            $emission,
            'manual_10',
            '2026-09-22 10:00:00'
        );

        $eligibleGroup = $this->createDiffusion(
            $emission,
            'manual_20',
            '2026-09-23 10:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $dismissed = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('manual_10')
            ->dismiss();

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with(['manual_10', 'manual_20'], $before)
            ->willReturn([
                10 => [
                    'manual_10' => 1,
                    'manual_20' => 1,
                ],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::exactly(2))
            ->method('findByAssignmentGroupKey')
            ->willReturnCallback(
                static function (string $groupKey) use ($dismissed): array {
                    return match ($groupKey) {
                        'manual_10' => [$dismissed],
                        'manual_20' => [],
                        default => throw new \LogicException(
                            'Groupe inattendu : ' . $groupKey
                        ),
                    };
                }
            );

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emission
                    && $entity->getAssignmentGroupKey() === 'manual_20'
                    && $entity->isPending()
            ));

        $created = $this->service->populateFromPublishedDiffusions(
            [$dismissedGroup, $eligibleGroup],
            $before
        );

        self::assertCount(1, $created);
        self::assertSame($emission, $created[0]->getEmission());
        self::assertSame('manual_20', $created[0]->getAssignmentGroupKey());

        self::assertTrue($dismissed->isDismissed());
    }


    public function testRestoreToPoolCreatesPendingWhenGroupDoesNotExist(): void
    {
        $emission = $this->createEmission(10);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('manual_100')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emission
                    && $entity->getAssignmentGroupKey() === 'manual_100'
                    && $entity->isPending()
            ));

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $pending = $this->service->restoreToPoolIfEligible(
            $emission,
            'manual_100'
        );

        self::assertInstanceOf(PendingRebroadcast::class, $pending);
        self::assertTrue($pending->isPending());
    }

    public function testRestoreToPoolReusesExistingActivePending(): void
    {
        $emission = $this->createEmission(10);

        $existing = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('manual_101');

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('manual_101')
            ->willReturn([$existing]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $result = $this->service->restoreToPoolIfEligible(
            $emission,
            'manual_101'
        );

        self::assertSame($existing, $result);
        self::assertTrue($existing->isPending());
    }

    public function testRestoreToPoolDoesNotRecreateDismissedPending(): void
    {
        $emission = $this->createEmission(10);

        $dismissed = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('manual_102')
            ->dismiss();

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with('manual_102')
            ->willReturn([$dismissed]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $result = $this->service->restoreToPoolIfEligible(
            $emission,
            'manual_102'
        );

        self::assertNull($result);
        self::assertTrue($dismissed->isDismissed());
    }

    public function testRestoreToPoolRejectsEmptyAssignmentGroupKey(): void
    {
        $this->pendingRebroadcastRepository
            ->expects(self::never())
            ->method('findByAssignmentGroupKey');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->restoreToPoolIfEligible(
            $this->createEmission(10),
            '   '
        );
    }

    public function testRestoreToPoolCreatesPendingForAnotherEmissionDespiteDismissal(): void
    {
        $emissionA = $this->createEmission(10);
        $emissionB = $this->createEmission(20);
        $groupKey = 'rule_100_origin_20990106_2000';

        $rule = (new \App\Entity\ProgrammationRule())->setHasAdditionalRebroadcast(true);
        $this->entityManager->expects(self::once())
            ->method('find')
            ->with(\App\Entity\ProgrammationRule::class, 100)
            ->willReturn($rule);

        $dismissedA = (new PendingRebroadcast())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey)
            ->dismiss();

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with($groupKey)
            ->willReturn([$dismissedA]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emissionB
                    && $entity->getAssignmentGroupKey() === $groupKey
                    && $entity->isPending()
            ));

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $result = $this->service->restoreToPoolIfEligible(
            $emissionB,
            $groupKey
        );

        self::assertInstanceOf(PendingRebroadcast::class, $result);
        self::assertSame($emissionB, $result->getEmission());
        self::assertTrue($result->isPending());

        self::assertTrue($dismissedA->isDismissed());
        self::assertSame($emissionA, $dismissedA->getEmission());
        self::assertSame($groupKey, $dismissedA->getAssignmentGroupKey());
    }

    public function testRestoreToPoolReusesOtherEmissionActivePendingDespiteDismissal(): void
    {
        $emissionA = $this->createEmission(10);
        $emissionB = $this->createEmission(20);
        $groupKey = 'rule_101_origin_20990106_2000';

        $rule = (new \App\Entity\ProgrammationRule())->setHasAdditionalRebroadcast(true);
        $this->entityManager->expects(self::once())
            ->method('find')
            ->with(\App\Entity\ProgrammationRule::class, 101)
            ->willReturn($rule);

        $dismissedA = (new PendingRebroadcast())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey)
            ->dismiss();

        $activeB = (new PendingRebroadcast())
            ->setEmission($emissionB)
            ->setAssignmentGroupKey($groupKey);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with($groupKey)
            ->willReturn([$dismissedA, $activeB]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $result = $this->service->restoreToPoolIfEligible(
            $emissionB,
            $groupKey
        );

        self::assertSame($activeB, $result);
        self::assertTrue($activeB->isPending());
        self::assertSame($emissionB, $activeB->getEmission());

        self::assertTrue($dismissedA->isDismissed());
        self::assertSame($emissionA, $dismissedA->getEmission());
    }

    public function testRestoreToPoolDoesNotCreatePendingWhenTargetEmissionIsDismissed(): void
    {
        $emissionA = $this->createEmission(10);
        $emissionB = $this->createEmission(20);
        $groupKey = 'rule_102_origin_20990106_2000';

        $dismissedA = (new PendingRebroadcast())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey)
            ->dismiss();

        $dismissedB = (new PendingRebroadcast())
            ->setEmission($emissionB)
            ->setAssignmentGroupKey($groupKey)
            ->dismiss();

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with($groupKey)
            ->willReturn([$dismissedA, $dismissedB]);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $result = $this->service->restoreToPoolIfEligible(
            $emissionB,
            $groupKey
        );

        self::assertNull($result);

        self::assertTrue($dismissedA->isDismissed());
        self::assertTrue($dismissedB->isDismissed());
        self::assertSame($emissionA, $dismissedA->getEmission());
        self::assertSame($emissionB, $dismissedB->getEmission());
    }

    public function testPopulateCreatesPendingForAnotherEmissionSharingSameGroup(): void
    {
        $emissionA = $this->createEmission(10);
        $emissionB = $this->createEmission(20);

        $groupKey = 'manual_shared_group';

        $diffusionB = $this->createDiffusion(
            $emissionB,
            $groupKey,
            '2026-09-22 11:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $existingA = (new PendingRebroadcast())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with([$groupKey], $before)
            ->willReturn([
                20 => [$groupKey => 1],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with($groupKey)
            ->willReturn([$existingA]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emissionB
                    && $entity->getAssignmentGroupKey() === $groupKey
                    && $entity->isPending()
            ));

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusionB],
            $before
        );

        self::assertCount(1, $created);
        self::assertSame($emissionB, $created[0]->getEmission());
        self::assertSame($groupKey, $created[0]->getAssignmentGroupKey());

        self::assertSame($emissionA, $existingA->getEmission());
        self::assertTrue($existingA->isPending());
    }

    public function testPopulateCreatesOnePendingPerEmissionWhenGroupIsShared(): void
    {
        $emissionA = $this->createEmission(10);
        $emissionB = $this->createEmission(20);

        $groupKey = 'manual_shared_group';

        $diffusionA = $this->createDiffusion(
            $emissionA,
            $groupKey,
            '2026-09-22 10:00:00'
        );

        $diffusionB = $this->createDiffusion(
            $emissionB,
            $groupKey,
            '2026-09-22 11:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with([$groupKey], $before)
            ->willReturn([
                10 => [$groupKey => 1],
                20 => [$groupKey => 1],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with($groupKey)
            ->willReturn([]);

        $persisted = [];

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(
                static function (object $entity) use (&$persisted): void {
                    self::assertInstanceOf(PendingRebroadcast::class, $entity);
                    $persisted[] = $entity;
                }
            );

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusionA, $diffusionB],
            $before
        );

        self::assertCount(2, $created);
        self::assertSame($created, $persisted);

        $emissions = array_map(
            static fn(PendingRebroadcast $pending): ?Emission =>
            $pending->getEmission(),
            $created
        );

        self::assertContains($emissionA, $emissions);
        self::assertContains($emissionB, $emissions);

        foreach ($created as $pending) {
            self::assertSame($groupKey, $pending->getAssignmentGroupKey());
            self::assertTrue($pending->isPending());
        }
    }

    public function testPopulateCountsPublishedDiffusionsPerEmissionWhenGroupIsShared(): void
    {
        $emissionA = $this->createEmission(10);
        $emissionB = $this->createEmission(20);

        $groupKey = 'manual_shared_group';

        $diffusionA = $this->createDiffusion(
            $emissionA,
            $groupKey,
            '2026-09-22 10:00:00'
        );

        $diffusionB = $this->createDiffusion(
            $emissionB,
            $groupKey,
            '2026-09-22 11:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        /*
     * A possède trois diffusions publiées.
     * B n'en possède qu'une.
     *
     * Seule B reste éligible au parc.
     */
        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with([$groupKey], $before)
            ->willReturn([
                10 => [$groupKey => 3],
                20 => [$groupKey => 1],
            ]);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with($groupKey)
            ->willReturn([]);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emissionB
                    && $entity->getAssignmentGroupKey() === $groupKey
                    && $entity->isPending()
            ));

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusionA, $diffusionB],
            $before
        );

        self::assertCount(1, $created);
        self::assertSame($emissionB, $created[0]->getEmission());
        self::assertSame($groupKey, $created[0]->getAssignmentGroupKey());
    }

    public function testPopulateIgnoresPendingOfAnotherEmissionSharingSameGroup(): void
    {
        $emissionA = $this->createEmission(10);
        $emissionB = $this->createEmission(20);

        $groupKey = 'manual_shared_group';

        $diffusionB = $this->createDiffusion(
            $emissionB,
            $groupKey,
            '2026-09-22 11:00:00'
        );

        $before = new \DateTimeImmutable('2026-09-29 00:00:00');

        $this->diffusionRepository
            ->expects(self::once())
            ->method('countPublishedBeforeByEmissionAndGroup')
            ->with([$groupKey], $before)
            ->willReturn([
                20 => [
                    $groupKey => 1,
                ],
            ]);

        // Un Pending existe pour A, mais pas pour B.
        $existingA = (new PendingRebroadcast())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey);

        $this->pendingRebroadcastRepository
            ->expects(self::once())
            ->method('findByAssignmentGroupKey')
            ->with($groupKey)
            ->willReturn([$existingA]);

        // Le Pending de B doit être créé.
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entity): bool =>
                $entity instanceof PendingRebroadcast
                    && $entity->getEmission() === $emissionB
                    && $entity->getAssignmentGroupKey() === $groupKey
                    && $entity->isPending()
            ));

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusionB],
            $before
        );

        self::assertCount(1, $created);
        self::assertSame($emissionB, $created[0]->getEmission());
        self::assertSame($groupKey, $created[0]->getAssignmentGroupKey());

        // Le Pending de A reste inchangé.
        self::assertSame($emissionA, $existingA->getEmission());
        self::assertTrue($existingA->isPending());
    }

public function testRestoreToPoolReusesPendingWhenEmissionInstancesHaveSameId(): void
{
    // Deux instances PHP différentes pour une même émission en base.
    $emission = $this->createEmission(10);
    $sameEmission = $this->createEmission(10);

    self::assertNotSame($emission, $sameEmission);
    self::assertSame($emission->getId(), $sameEmission->getId());

    $existing = (new PendingRebroadcast())
        ->setEmission($emission)
        ->setAssignmentGroupKey('manual_same_emission');

    $this->pendingRebroadcastRepository
        ->expects(self::once())
        ->method('findByAssignmentGroupKey')
        ->with('manual_same_emission')
        ->willReturn([$existing]);

    // Il ne faut surtout pas créer un deuxième Pending.
    $this->entityManager
        ->expects(self::never())
        ->method('persist');

    $this->entityManager
        ->expects(self::never())
        ->method('flush');

    $result = $this->service->restoreToPoolIfEligible(
        $sameEmission,
        'manual_same_emission'
    );

    self::assertSame($existing, $result);
    self::assertTrue($existing->isPending());
}

    private function createEmission(?int $id = null): Emission
    {
        $emission = new Emission();

        if (null !== $id) {
            $reflection = new \ReflectionProperty(
                Emission::class,
                'id'
            );

            $reflection->setValue($emission, $id);
        }

        return $emission;
    }

    private function createDiffusion(
        Emission $emission,
        ?string $assignmentGroupKey,
        string $startsAt
    ): Diffusion {
        return (new Diffusion())
            ->setEmission($emission)
            ->setHoraireDiffusion(new \DateTime($startsAt))
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($assignmentGroupKey)
            ->markAsPublished();
    }
}
