<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\GridSlotArbitrationRepository;
use App\Service\GridOccurrenceProjectionService;
use PHPUnit\Framework\TestCase;

class GridOccurrenceProjectionServiceTest extends TestCase
{
    private GridSlotArbitrationRepository $repository;
    private GridOccurrenceProjectionService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(
            GridSlotArbitrationRepository::class
        );

        $this->service = new GridOccurrenceProjectionService(
            $this->repository
        );
    }

    public function testSegmentWithoutArbitrationIsKeptWithDefaultProjectionFlags(): void
    {
        $startOfWeek = new \DateTimeImmutable('2026-09-01 00:00:00');
        $endOfWeek = new \DateTimeImmutable('2026-09-08 00:00:00');

        $this->repository
            ->expects(self::once())
            ->method('findRelevantForWeek')
            ->with($startOfWeek, $endOfWeek)
            ->willReturn([]);

        $daySegments = array_fill(0, 7, []);

        $daySegments[0][] = [
            'segmentKey' => '42_20260901090000',
            'slotId' => 42,
            'startsAt' => '2026-09-01 09:00:00',
            'endsAt' => '2026-09-01 10:00:00',
            'duration' => 60,
        ];

        $result = $this->service->applyForWeek(
            $daySegments,
            $startOfWeek,
            $endOfWeek
        );

        self::assertCount(1, $result[0]);

        $segment = $result[0][0];

        self::assertSame(
            '2026-09-01 09:00:00',
            $segment['originalStartsAt']
        );

        self::assertFalse($segment['isProjectedOverride']);
        self::assertNull($segment['projectionType']);
        self::assertTrue($segment['canBeRescheduled']);
        self::assertFalse($segment['isCancelled']);
        self::assertFalse($segment['canRestore']);
        self::assertTrue($segment['isBlocking']);
        self::assertFalse($segment['isRescheduledOrigin']);
    }

    public function testSegmentWithoutSlotIdIsKept(): void
    {
        $startOfWeek = new \DateTimeImmutable('2026-09-01 00:00:00');
        $endOfWeek = new \DateTimeImmutable('2026-09-08 00:00:00');

        $this->repository
            ->expects(self::once())
            ->method('findRelevantForWeek')
            ->willReturn([]);

        $daySegments = array_fill(0, 7, []);

        $daySegments[0][] = [
            'segmentKey' => 'manual_segment',
            'slotId' => null,
            'startsAt' => '2026-09-01 11:00:00',
            'endsAt' => '2026-09-01 12:00:00',
        ];

        $result = $this->service->applyForWeek(
            $daySegments,
            $startOfWeek,
            $endOfWeek
        );

        self::assertCount(1, $result[0]);

        $segment = $result[0][0];

        self::assertFalse($segment['isProjectedOverride']);
        self::assertFalse($segment['isCancelled']);
        self::assertTrue($segment['isBlocking']);
        self::assertTrue($segment['canBeRescheduled']);
    }

    public function testCancelArbitrationMarksOriginalSegmentAsCancelledAndNonBlocking(): void
    {
        $startOfWeek = new \DateTimeImmutable('2026-09-01 00:00:00');
        $endOfWeek = new \DateTimeImmutable('2026-09-08 00:00:00');

        $slot = $this->createSlotMock(42);

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt(
                new \DateTimeImmutable('2026-09-02 09:00:00')
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-09-02 10:00:00')
            )
            ->setAction(GridSlotArbitration::ACTION_CANCEL);

        $this->repository
            ->expects(self::once())
            ->method('findRelevantForWeek')
            ->with($startOfWeek, $endOfWeek)
            ->willReturn([$arbitration]);

        $daySegments = array_fill(0, 7, []);

        $daySegments[1][] = [
            'segmentKey' => '42_20260902090000',
            'slotId' => 42,
            'startsAt' => '2026-09-02 09:00:00',
            'endsAt' => '2026-09-02 10:00:00',
            'duration' => 60,
        ];

        $result = $this->service->applyForWeek(
            $daySegments,
            $startOfWeek,
            $endOfWeek
        );

        self::assertCount(1, $result[1]);

        $segment = $result[1][0];

        self::assertTrue($segment['isCancelled']);
        self::assertTrue($segment['canRestore']);
        self::assertFalse($segment['isBlocking']);
        self::assertFalse($segment['canBeRescheduled']);

        self::assertSame(
            GridSlotArbitration::ACTION_CANCEL,
            $segment['projectionType']
        );

        self::assertSame(
            '2026-09-02 09:00:00',
            $segment['originalStartsAt']
        );

        self::assertFalse($segment['isProjectedOverride']);
        self::assertFalse($segment['isRescheduledOrigin']);
    }

    public function testRescheduleKeepsNonBlockingOriginAndCreatesProjectedSegment(): void
    {
        $startOfWeek = new \DateTimeImmutable('2026-09-01 00:00:00');
        $endOfWeek = new \DateTimeImmutable('2026-09-08 00:00:00');

        $slot = $this->createSlotMock(42);

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt(
                new \DateTimeImmutable('2026-09-02 09:00:00')
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-09-02 10:00:00')
            )
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt(
                new \DateTimeImmutable('2026-09-03 10:30:00')
            )
            ->setRescheduledEndsAt(
                new \DateTimeImmutable('2026-09-03 11:30:00')
            );

        $this->repository
            ->expects(self::once())
            ->method('findRelevantForWeek')
            ->with($startOfWeek, $endOfWeek)
            ->willReturn([$arbitration]);

        $daySegments = array_fill(0, 7, []);

        $daySegments[1][] = [
            'segmentKey' => '42_20260902090000',
            'slotId' => 42,
            'startsAt' => '2026-09-02 09:00:00',
            'endsAt' => '2026-09-02 10:00:00',
            'duration' => 60,
            'startIndex' => 36,
        ];

        $result = $this->service->applyForWeek(
            $daySegments,
            $startOfWeek,
            $endOfWeek
        );

        self::assertCount(1, $result[1]);

        $origin = $result[1][0];

        self::assertTrue($origin['isRescheduledOrigin']);
        self::assertTrue($origin['canRestore']);
        self::assertFalse($origin['isBlocking']);
        self::assertFalse($origin['canBeRescheduled']);

        self::assertSame(
            GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM,
            $origin['projectionType']
        );

        self::assertSame(
            '2026-09-02 09:00:00',
            $origin['originalStartsAt']
        );

        /*
     * Le ghost d'origine doit conserver explicitement
     * la destination du déplacement.
     *
     * La sidebar pourra ainsi afficher le nouvel emplacement
     * sans rechercher le segment projeté dans le DOM.
     */
        self::assertSame(
            '2026-09-03 10:30:00',
            $origin['rescheduledStartsAt']
        );

        self::assertSame(
            '2026-09-03 11:30:00',
            $origin['rescheduledEndsAt']
        );

        self::assertCount(1, $result[2]);

        $projected = $result[2][0];

        self::assertSame(
            '42_projected_20260903103000',
            $projected['segmentKey']
        );

        self::assertSame(
            '2026-09-03 10:30:00',
            $projected['startsAt']
        );

        self::assertSame(
            '2026-09-03 11:30:00',
            $projected['endsAt']
        );

        self::assertSame(42, $projected['startIndex']);
        self::assertTrue($projected['isProjectedOverride']);

        self::assertSame(
            GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM,
            $projected['projectionType']
        );

        self::assertSame(
            '2026-09-02 09:00:00',
            $projected['originalStartsAt']
        );

        self::assertTrue($projected['canBeRescheduled']);
        self::assertTrue($projected['isBlocking']);
        self::assertFalse($projected['isCancelled']);
        self::assertFalse($projected['canRestore']);
        self::assertFalse($projected['isRescheduledOrigin']);
    }

    public function testArbitrationForDifferentOccurrenceDoesNotAffectSegment(): void
    {
        $startOfWeek = new \DateTimeImmutable('2026-09-01 00:00:00');
        $endOfWeek = new \DateTimeImmutable('2026-09-08 00:00:00');

        $slot = $this->createSlotMock(42);

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt(
                new \DateTimeImmutable('2026-09-02 14:00:00')
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-09-02 15:00:00')
            )
            ->setAction(GridSlotArbitration::ACTION_CANCEL);

        $this->repository
            ->expects(self::once())
            ->method('findRelevantForWeek')
            ->willReturn([$arbitration]);

        $daySegments = array_fill(0, 7, []);

        $daySegments[1][] = [
            'segmentKey' => '42_20260902090000',
            'slotId' => 42,
            'startsAt' => '2026-09-02 09:00:00',
            'endsAt' => '2026-09-02 10:00:00',
        ];

        $result = $this->service->applyForWeek(
            $daySegments,
            $startOfWeek,
            $endOfWeek
        );

        self::assertCount(1, $result[1]);

        $segment = $result[1][0];

        self::assertFalse($segment['isCancelled']);
        self::assertTrue($segment['isBlocking']);
        self::assertFalse($segment['canRestore']);
        self::assertNull($segment['projectionType']);
    }

    public function testRescheduledOccurrenceIsRebuiltWhenOriginalSegmentIsMissing(): void
    {
        $startOfWeek = new \DateTimeImmutable('2026-09-01 00:00:00');
        $endOfWeek = new \DateTimeImmutable('2026-09-08 00:00:00');

        /*
         * Ici on reproduit précisément le cas du fallback :
         * l'arbitrage existe, mais le segment d'origine ne se trouve
         * pas dans $daySegments.
         */

        $category = $this->createMock(Categories::class);

        $category
            ->method('getTitre')
            ->willReturn('Musique');

        $category
            ->method('getSlug')
            ->willReturn('MUS');

        $rule = $this->createMock(ProgrammationRule::class);

        $rule
            ->method('getId')
            ->willReturn(12);

        $rule
            ->method('getCategory')
            ->willReturn($category);

        $rule
            ->method('getRuleNumber')
            ->willReturn(3);

        $rule
            ->method('getDisplayName')
            ->willReturn('Musique règle N° 3');

        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn(42);

        $slot
            ->method('getRule')
            ->willReturn($rule);

        $slot
            ->method('getBroadcastRank')
            ->willReturn(2);

        $originalStartsAt = new \DateTimeImmutable(
            '2026-08-30 09:00:00'
        );

        $originalEndsAt = new \DateTimeImmutable(
            '2026-08-30 10:00:00'
        );

        $rescheduledStartsAt = new \DateTimeImmutable(
            '2026-09-04 14:15:00'
        );

        $rescheduledEndsAt = new \DateTimeImmutable(
            '2026-09-04 15:15:00'
        );

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt($originalStartsAt)
            ->setOriginalEndsAt($originalEndsAt)
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt($rescheduledStartsAt)
            ->setRescheduledEndsAt($rescheduledEndsAt);

        $this->repository
            ->expects(self::once())
            ->method('findRelevantForWeek')
            ->with($startOfWeek, $endOfWeek)
            ->willReturn([$arbitration]);

        /*
         * Aucun segment d'origine n'est fourni.
         */
        $daySegments = array_fill(0, 7, []);

        $result = $this->service->applyForWeek(
            $daySegments,
            $startOfWeek,
            $endOfWeek
        );

        /*
         * Vendredi = index 3 dans une semaine radio
         * commençant le mardi.
         */
        self::assertCount(1, $result[3]);

        $projected = $result[3][0];

        self::assertSame(
            '42_projected_20260904141500',
            $projected['segmentKey']
        );

        self::assertSame('Musique', $projected['title']);
        self::assertSame('Musique', $projected['displayTitle']);
        self::assertSame('Musique', $projected['categoryTitle']);
        self::assertSame('MUS', $projected['categorySlug']);

        self::assertSame(60, $projected['duration']);

        self::assertSame(12, $projected['ruleId']);
        self::assertSame(3, $projected['ruleNumber']);
        self::assertSame(
            'Musique règle N° 3',
            $projected['ruleDisplayName']
        );

        self::assertSame(42, $projected['slotId']);
        self::assertSame(2, $projected['broadcastRank']);

        self::assertSame(
            '2026-09-04 14:15:00',
            $projected['startsAt']
        );

        self::assertSame(
            '2026-09-04 15:15:00',
            $projected['endsAt']
        );

        /*
         * 14 h 15 :
         * 14 * 4 + 1 = 57.
         */
        self::assertSame(57, $projected['startIndex']);

        self::assertTrue($projected['isProjectedOverride']);

        self::assertSame(
            GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM,
            $projected['projectionType']
        );

        self::assertSame(
            '2026-08-30 09:00:00',
            $projected['originalStartsAt']
        );

        self::assertTrue($projected['canBeRescheduled']);
        self::assertTrue($projected['isBlocking']);

        self::assertFalse($projected['isCancelled']);
        self::assertFalse($projected['canRestore']);
        self::assertFalse($projected['isRescheduledOrigin']);

        self::assertFalse($projected['assigned']);
        self::assertNull($projected['emissionId']);
        self::assertNull($projected['emissionTitle']);
        self::assertFalse(
            $projected['emissionIsAutoGenerated']
        );

        /*
         * Il ne doit apparaître nulle part ailleurs.
         */
        self::assertCount(0, $result[0]);
        self::assertCount(0, $result[1]);
        self::assertCount(0, $result[2]);
        self::assertCount(0, $result[4]);
        self::assertCount(0, $result[5]);
        self::assertCount(0, $result[6]);
    }

    private function createSlotMock(int $id): ProgrammationRuleSlot
    {
        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->method('getId')
            ->willReturn($id);

        return $slot;
    }

    public function testKeepArbitrationKeepsOriginalSegment(): void
    {
        $startOfWeek = new \DateTimeImmutable('2026-09-01 00:00:00');
        $endOfWeek = new \DateTimeImmutable('2026-09-08 00:00:00');

        $slot = $this->createSlotMock(42);

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt(
                new \DateTimeImmutable('2026-09-02 09:00:00')
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-09-02 10:00:00')
            )
            ->setAction(GridSlotArbitration::ACTION_KEEP);

        $this->repository
            ->expects(self::once())
            ->method('findRelevantForWeek')
            ->with($startOfWeek, $endOfWeek)
            ->willReturn([$arbitration]);

        $daySegments = array_fill(0, 7, []);

        $daySegments[1][] = [
            'segmentKey' => '42_20260902090000',
            'slotId' => 42,
            'startsAt' => '2026-09-02 09:00:00',
            'endsAt' => '2026-09-02 10:00:00',
            'duration' => 60,
        ];

        $result = $this->service->applyForWeek(
            $daySegments,
            $startOfWeek,
            $endOfWeek
        );

        self::assertCount(1, $result[1]);

        $segment = $result[1][0];

        self::assertSame(
            '2026-09-02 09:00:00',
            $segment['originalStartsAt']
        );

        self::assertFalse($segment['isProjectedOverride']);
        self::assertNull($segment['projectionType']);
        self::assertFalse($segment['isCancelled']);
        self::assertFalse($segment['isRescheduledOrigin']);
        self::assertTrue($segment['isBlocking']);
    }

    public function testRescheduledOccurrenceOutsideCurrentWeekIsNotInjected(): void
    {
        $startOfWeek = new \DateTimeImmutable('2026-09-01 00:00:00');
        $endOfWeek = new \DateTimeImmutable('2026-09-08 00:00:00');

        $slot = $this->createSlotMock(42);

        $arbitration = (new GridSlotArbitration())
            ->setSlot($slot)
            ->setOriginalStartsAt(
                new \DateTimeImmutable('2026-09-02 09:00:00')
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable('2026-09-02 10:00:00')
            )
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt(
                new \DateTimeImmutable('2026-09-10 09:00:00')
            )
            ->setRescheduledEndsAt(
                new \DateTimeImmutable('2026-09-10 10:00:00')
            );

        $this->repository
            ->expects(self::once())
            ->method('findRelevantForWeek')
            ->with($startOfWeek, $endOfWeek)
            ->willReturn([$arbitration]);

        $daySegments = array_fill(0, 7, []);

        $daySegments[1][] = [
            'segmentKey' => '42_20260902090000',
            'slotId' => 42,
            'startsAt' => '2026-09-02 09:00:00',
            'endsAt' => '2026-09-02 10:00:00',
            'duration' => 60,
        ];

        $result = $this->service->applyForWeek(
            $daySegments,
            $startOfWeek,
            $endOfWeek
        );

        /*
     * L'origine reste visible comme occurrence déplacée.
     */
        self::assertCount(1, $result[1]);

        $origin = $result[1][0];

        self::assertTrue($origin['isRescheduledOrigin']);
        self::assertFalse($origin['isBlocking']);
        self::assertTrue($origin['canRestore']);

        self::assertSame(
            '2026-09-02 09:00:00',
            $origin['originalStartsAt']
        );

        /*
     * Même si la destination se trouve hors de la semaine
     * actuellement affichée, le ghost doit la conserver.
     *
     * Cette information appartient à l'arbitrage et doit
     * rester disponible pour la sidebar.
     */
        self::assertSame(
            '2026-09-10 09:00:00',
            $origin['rescheduledStartsAt']
        );

        self::assertSame(
            '2026-09-10 10:00:00',
            $origin['rescheduledEndsAt']
        );

        /*
     * En revanche, aucune occurrence projetée ne doit être
     * injectée dans la semaine courante.
     */
        $projected = [];

        foreach ($result as $segments) {
            foreach ($segments as $segment) {
                if (($segment['isProjectedOverride'] ?? false) === true) {
                    $projected[] = $segment;
                }
            }
        }

        self::assertCount(0, $projected);
    }
}
