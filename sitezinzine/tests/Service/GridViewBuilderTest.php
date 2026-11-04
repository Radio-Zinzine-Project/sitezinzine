<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\CategoriesRepository;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Service\GridConflictDetector;
use App\Service\GridOccurrenceProjectionService;
use App\Service\GridViewBuilder;
use App\Service\ProgrammationGridBuilder;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GridViewBuilderTest extends TestCase
{
    private ProgrammationGridBuilder&MockObject $programmationGridBuilder;
    private DiffusionDraftRepository&MockObject $draftRepository;
    private DiffusionRepository&MockObject $diffusionRepository;
    private GridOccurrenceProjectionService&MockObject $projectionService;
    private GridConflictDetector&MockObject $conflictDetector;
    private CategoriesRepository&MockObject $categoriesRepository;

    private GridViewBuilder $service;

    protected function setUp(): void
    {
        $this->programmationGridBuilder = $this->createMock(
            ProgrammationGridBuilder::class
        );

        $this->draftRepository = $this->createMock(
            DiffusionDraftRepository::class
        );

        $this->diffusionRepository = $this->createMock(
            DiffusionRepository::class
        );

        $this->projectionService = $this->createMock(
            GridOccurrenceProjectionService::class
        );

        $this->conflictDetector = $this->createMock(
            GridConflictDetector::class
        );

        $this->categoriesRepository = $this->createMock(
            CategoriesRepository::class
        );

        $this->service = new GridViewBuilder(
            $this->programmationGridBuilder,
            $this->draftRepository,
            $this->diffusionRepository,
            $this->projectionService,
            $this->conflictDetector,
            $this->categoriesRepository
        );
    }

    public function testBuildUsesDraftModeAndReturnsSpecialCategories(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = $this->emptyWeek();

        $specialCategory = $this->createMock(
            Categories::class
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('hasDiffusionsBetween')
            ->with($weekStart, $weekEnd)
            ->willReturn(false);

        $this->programmationGridBuilder
            ->expects(self::once())
            ->method('buildForWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn($segments);

        $this->projectionService
            ->expects(self::once())
            ->method('applyForWeek')
            ->with($segments, $weekStart, $weekEnd)
            ->willReturn($segments);

        $this->conflictDetector
            ->expects(self::once())
            ->method('detectForWeek')
            ->with($segments)
            ->willReturn($segments);

        $this->draftRepository
            ->expects(self::once())
            ->method('findByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([
            $specialCategory,
        ]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertFalse(
            $result['hasValidatedWeek']
        );

        self::assertSame(
            'draft',
            $result['gridMode']
        );

        self::assertSame(
            $segments,
            $result['daySegments']
        );

        self::assertSame(
            $this->emptyWeek(),
            $result['manualDraftsByDay']
        );

        self::assertSame(
            [$specialCategory],
            $result['specialCategories']
        );
    }

    public function testBuildUsesDiffusionModeForValidatedWeek(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = $this->emptyWeek();

        $this->prepareBuild(
            true,
            $segments
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([]);

        /*
     * findByWeek() reste destiné notamment au mécanisme
     * de publication/revalidation, mais ne doit plus être utilisé
     * pour hydrater l'affichage d'une grille validée.
     */
        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->draftRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertTrue(
            $result['hasValidatedWeek']
        );

        self::assertSame(
            'diffusion',
            $result['gridMode']
        );

        self::assertSame(
            $this->emptyWeek(),
            $result['manualDraftsByDay']
        );
    }

    public function testDraftModeHydratesRegularDraft(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [[[
            'slotId' => 10,
            'startsAt' => '2026-09-09 10:00:00',
            'originalStartsAt' => '2026-09-09 10:00:00',
            'title' => 'Créneau régulier',
            'categoryTitle' => 'Catégorie originale',
            'categorySlug' => 'ORI',
        ]]];

        $slot = $this->createSlot(10);

        $category = $this->createMock(
            Categories::class
        );

        $category
            ->method('getTitre')
            ->willReturn('Musique');

        $category
            ->method('getSlug')
            ->willReturn('MUS');

        $emission = $this->createEmission(
            20,
            'Mon émission',
            $category,
            true
        );

        $draft = $this->createRegularDraft(
            30,
            $slot,
            new \DateTimeImmutable('2026-09-09 10:00:00'),
            $emission
        );

        $this->prepareBuild(
            false,
            $segments
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([$draft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $segment = $result['daySegments'][0][0];

        self::assertTrue(
            $segment['assigned']
        );

        self::assertSame(
            30,
            $segment['draftId']
        );

        self::assertSame(
            20,
            $segment['emissionId']
        );

        self::assertSame(
            'Mon émission',
            $segment['emissionTitle']
        );

        self::assertSame(
            'Mon émission',
            $segment['displayTitle']
        );

        self::assertTrue(
            $segment['emissionIsAutoGenerated']
        );

        self::assertSame(
            'Musique',
            $segment['categoryTitle']
        );

        self::assertSame(
            'MUS',
            $segment['categorySlug']
        );
    }

    public function testDraftModeLoadsRescheduledDraftUsingOriginalOccurrence(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [[[
            'slotId' => 10,
            'startsAt' => '2026-09-11 15:00:00',
            'originalStartsAt' => '2026-09-10 14:00:00',
            'title' => 'Créneau déplacé',
            'isProjectedOverride' => true,
        ]]];

        $slot = $this->createSlot(10);

        $emission = $this->createEmission(
            20,
            'Émission déplacée'
        );

        /*
         * Le draft reste attaché à son occurrence d'origine.
         */
        $draft = $this->createRegularDraft(
            30,
            $slot,
            new \DateTimeImmutable('2026-09-10 14:00:00'),
            $emission
        );

        $this->prepareBuild(
            false,
            $segments
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([]);

        $this->draftRepository
            ->expects(self::once())
            ->method(
                'findRegularDraftsBySlotAndStartsAtPairs'
            )
            ->with(
                self::callback(
                    static function (array $pairs): bool {
                        if (1 !== count($pairs)) {
                            return false;
                        }

                        $pair = $pairs[0];

                        return 10 === $pair['slotId']
                            && $pair['startsAt']
                            instanceof \DateTimeImmutable
                            && '2026-09-10 14:00:00'
                            === $pair['startsAt']->format(
                                'Y-m-d H:i:s'
                            );
                    }
                )
            )
            ->willReturn([$draft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $segment = $result['daySegments'][0][0];

        self::assertTrue(
            $segment['assigned']
        );

        self::assertSame(
            30,
            $segment['draftId']
        );

        self::assertSame(
            'Émission déplacée',
            $segment['displayTitle']
        );
    }

    public function testDraftModeAddsManualDraftToCorrectDay(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $emission = $this->createEmission(
            20,
            'Émission spéciale'
        );

        $draft = $this->createManualDraft(
            30,
            new \DateTimeImmutable(
                '2026-09-10 14:30:00'
            ),
            new \DateTimeImmutable(
                '2026-09-10 15:15:00'
            ),
            $emission,
            45
        );

        $this->prepareBuild(
            false,
            $this->emptyWeek()
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([$draft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        /*
         * Mardi 0
         * Mercredi 1
         * Jeudi 2
         */
        self::assertCount(
            1,
            $result['manualDraftsByDay'][2]
        );

        $manual =
            $result['manualDraftsByDay'][2][0];

        self::assertSame(
            30,
            $manual['id']
        );

        self::assertSame(
            58,
            $manual['startIndex']
        );

        self::assertSame(
            45,
            $manual['duration']
        );

        self::assertSame(
            '2026-09-10 14:30:00',
            $manual['startsAt']
        );

        self::assertSame(
            '2026-09-10 15:15:00',
            $manual['endsAt']
        );

        self::assertSame(
            'Émission spéciale',
            $manual['title']
        );

        self::assertTrue(
            $manual['assigned']
        );

        self::assertTrue(
            $manual['isManualDraft']
        );

        self::assertSame(
            'manual_special',
            $manual['draftType']
        );

        self::assertSame(
            'manual_group',
            $manual['assignmentGroupKey']
        );
    }

    public function testDraftModeUsesFifteenMinutesForInvalidManualDuration(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $emission = $this->createEmission(
            20,
            'Émission spéciale'
        );

        $draft = $this->createManualDraft(
            30,
            new \DateTimeImmutable(
                '2026-09-08 00:00:00'
            ),
            new \DateTimeImmutable(
                '2026-09-08 00:15:00'
            ),
            $emission,
            0
        );

        $this->prepareBuild(
            false,
            $this->emptyWeek()
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([$draft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $manual =
            $result['manualDraftsByDay'][0][0];

        self::assertSame(
            15,
            $manual['duration']
        );

        self::assertSame(
            0,
            $manual['startIndex']
        );
    }

    public function testDraftModeIgnoresManualDraftOutsideWeek(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $draft = $this->createManualDraft(
            30,
            new \DateTimeImmutable(
                '2026-09-15 10:00:00'
            ),
            new \DateTimeImmutable(
                '2026-09-15 11:00:00'
            ),
            $this->createEmission(
                20,
                'Hors semaine'
            ),
            60
        );

        $this->prepareBuild(
            false,
            $this->emptyWeek()
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([$draft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertSame(
            $this->emptyWeek(),
            $result['manualDraftsByDay']
        );
    }

    public function testDraftModeSortsManualDraftsByStartTime(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $emission = $this->createEmission(
            20,
            'Manuelle'
        );

        $later = $this->createManualDraft(
            31,
            new \DateTimeImmutable(
                '2026-09-10 18:00:00'
            ),
            new \DateTimeImmutable(
                '2026-09-10 19:00:00'
            ),
            $emission,
            60
        );

        $earlier = $this->createManualDraft(
            30,
            new \DateTimeImmutable(
                '2026-09-10 09:00:00'
            ),
            new \DateTimeImmutable(
                '2026-09-10 10:00:00'
            ),
            $emission,
            60
        );

        $this->prepareBuild(
            false,
            $this->emptyWeek()
        );

        /*
         * Ordre volontairement inverse.
         */
        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([
                $later,
                $earlier,
            ]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertCount(
            2,
            $result['manualDraftsByDay'][2]
        );

        self::assertSame(
            30,
            $result['manualDraftsByDay'][2][0]['id']
        );

        self::assertSame(
            31,
            $result['manualDraftsByDay'][2][1]['id']
        );
    }

    public function testDraftModeDoesNotAssignCancelledSegment(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [[[
            'slotId' => 10,
            'startsAt' => '2026-09-09 10:00:00',
            'originalStartsAt' => '2026-09-09 10:00:00',
            'title' => 'Créneau',
            'isCancelled' => true,
        ]]];

        $slot = $this->createSlot(10);

        $draft = $this->createRegularDraft(
            30,
            $slot,
            new \DateTimeImmutable(
                '2026-09-09 10:00:00'
            ),
            $this->createEmission(
                20,
                'Émission'
            )
        );

        $this->prepareBuild(
            false,
            $segments
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([$draft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $segment = $result['daySegments'][0][0];

        self::assertFalse(
            $segment['assigned']
        );

        self::assertNull(
            $segment['draftId']
        );

        self::assertNull(
            $segment['emissionId']
        );
    }

    public function testManualDraftHidesOverlappingCancelledGhost(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [
            [],
            [],
            [
                [
                    'slotId' => 10,
                    'startsAt' => '2026-09-10 14:00:00',
                    'originalStartsAt' => '2026-09-10 14:00:00',
                    'endsAt' => '2026-09-10 15:00:00',
                    'title' => 'Fantôme',
                    'isCancelled' => true,
                ],
            ],
            [],
            [],
            [],
            [],
        ];

        $manualDraft = $this->createManualDraft(
            30,
            new \DateTimeImmutable(
                '2026-09-10 14:30:00'
            ),
            new \DateTimeImmutable(
                '2026-09-10 15:30:00'
            ),
            $this->createEmission(
                20,
                'Remplacement'
            ),
            60
        );

        $this->prepareBuild(
            false,
            $segments
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([$manualDraft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertSame(
            [],
            $result['daySegments'][2]
        );

        self::assertCount(
            1,
            $result['manualDraftsByDay'][2]
        );
    }

    public function testGhostRemainsWhenManualDraftOnlyTouchesBoundary(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [
            [],
            [],
            [
                [
                    'slotId' => 10,
                    'startsAt' => '2026-09-10 14:00:00',
                    'originalStartsAt' => '2026-09-10 14:00:00',
                    'endsAt' => '2026-09-10 15:00:00',
                    'title' => 'Fantôme',
                    'isCancelled' => true,
                ],
            ],
            [],
            [],
            [],
            [],
        ];

        /*
         * Commence exactement lorsque le fantôme finit.
         * Il n'y a donc pas chevauchement.
         */
        $manualDraft = $this->createManualDraft(
            30,
            new \DateTimeImmutable(
                '2026-09-10 15:00:00'
            ),
            new \DateTimeImmutable(
                '2026-09-10 16:00:00'
            ),
            $this->createEmission(
                20,
                'Après'
            ),
            60
        );

        $this->prepareBuild(
            false,
            $segments
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([$manualDraft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertCount(
            1,
            $result['daySegments'][2]
        );
    }

    public function testNormalSegmentRemainsEvenWhenManualDraftOverlaps(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [
            [],
            [],
            [
                [
                    'slotId' => 10,
                    'startsAt' => '2026-09-10 14:00:00',
                    'originalStartsAt' => '2026-09-10 14:00:00',
                    'endsAt' => '2026-09-10 15:00:00',
                    'title' => 'Créneau normal',
                    'isCancelled' => false,
                    'isRescheduledOrigin' => false,
                ],
            ],
            [],
            [],
            [],
            [],
        ];

        $manualDraft = $this->createManualDraft(
            30,
            new \DateTimeImmutable(
                '2026-09-10 14:30:00'
            ),
            new \DateTimeImmutable(
                '2026-09-10 15:30:00'
            ),
            $this->createEmission(
                20,
                'Manuelle'
            ),
            60
        );

        $this->prepareBuild(
            false,
            $segments
        );

        $this->draftRepository
            ->method('findByWeek')
            ->willReturn([$manualDraft]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertCount(
            1,
            $result['daySegments'][2]
        );

        self::assertSame(
            'Créneau normal',
            $result['daySegments'][2][0]['displayTitle']
        );
    }

    public function testDiffusionModeMarksEmptyRegularAixSegmentAsAix(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [[[
            'slotId' => 10,
            'startsAt' => '2026-09-09 10:00:00',
            'originalStartsAt' => '2026-09-09 10:00:00',
            'title' => 'Créneau Aix',
            'categoryTitle' => 'Infos d\'Aix',
            'categorySlug' => 'IFA',
            'categoryEditorId' => 2,
        ]]];

        $this->prepareBuild(
            true,
            $segments
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $segment = $result['daySegments'][0][0];

        self::assertFalse(
            $segment['assigned']
        );

        self::assertTrue(
            $segment['isAix']
        );

        self::assertSame(
            2,
            $segment['categoryEditorId']
        );

        self::assertSame(
            'empty',
            $segment['sourceType']
        );
    }

    public function testDiffusionModeHydratesRegularValidatedSegment(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [[[
            'slotId' => 10,
            'startsAt' => '2026-09-09 10:00:00',
            'originalStartsAt' => '2026-09-09 10:00:00',
            'title' => 'Créneau',
            'categoryTitle' => 'Catégorie',
            'categorySlug' => 'CAT',
        ]]];

        $emission = $this->createEmission(
            20,
            'Émission validée'
        );

        $diffusion = $this->createDiffusion(
            40,
            new \DateTimeImmutable(
                '2026-09-09 10:00:00'
            ),
            $emission,
            60,
            2,
            'group_1'
        );

        $this->prepareBuild(
            true,
            $segments
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $segment = $result['daySegments'][0][0];

        self::assertTrue(
            $segment['assigned']
        );

        self::assertSame(
            40,
            $segment['diffusionId']
        );

        self::assertNull(
            $segment['draftId']
        );

        self::assertSame(
            20,
            $segment['emissionId']
        );

        self::assertSame(
            'Émission validée',
            $segment['emissionTitle']
        );

        self::assertSame(
            2,
            $segment['broadcastRank']
        );

        self::assertSame(
            2,
            $segment['nombreDiffusion']
        );

        self::assertSame(
            'group_1',
            $segment['assignmentGroupKey']
        );

        self::assertTrue(
            $segment['isValidatedDiffusion']
        );

        self::assertTrue(
            $segment['isReadonly']
        );

        self::assertSame(
            'diffusion',
            $segment['sourceType']
        );
    }

    public function testDiffusionModeAssociatesCoveredRescheduledGhostWithEffectiveSegment(): void
    {
        $weekStart = new \DateTimeImmutable('2026-10-06 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-13 00:00:00');

        /*
     * Mercredi 07/10 à 17h :
     *
     * - slot 100 = ancien emplacement de "Sortir ce mois-ci",
     *   déplacé au 30/09 à 17h ;
     *
     * - slot 57 = créneau réellement occupé par
     *   "Le nucléaire et son monde".
     */
        $segments = [
            [],
            [
                [
                    'segmentKey' => '100_20261007170000',
                    'slotId' => 100,
                    'startsAt' => '2026-10-07 17:00:00',
                    'endsAt' => '2026-10-07 18:00:00',
                    'originalStartsAt' => '2026-10-07 17:00:00',
                    'rescheduledStartsAt' => '2026-09-30 17:00:00',
                    'rescheduledEndsAt' => '2026-09-30 18:00:00',
                    'duration' => 60,
                    'startIndex' => 68,
                    'title' => 'Sortir ce mois-ci',
                    'categoryTitle' => 'Sortir ce mois-ci',
                    'categorySlug' => 'SOR',
                    'isRescheduledOrigin' => true,
                    'isCancelled' => false,
                    'isBlocking' => false,
                    'canRestore' => true,
                ],
                [
                    'segmentKey' => '57_20261007170000',
                    'slotId' => 57,
                    'startsAt' => '2026-10-07 17:00:00',
                    'endsAt' => '2026-10-07 18:00:00',
                    'originalStartsAt' => '2026-10-07 17:00:00',
                    'duration' => 60,
                    'startIndex' => 68,
                    'title' => 'Le nucléaire et son monde',
                    'categoryTitle' => 'Nucléaire',
                    'categorySlug' => 'NUC',
                    'isRescheduledOrigin' => false,
                    'isCancelled' => false,
                    'isBlocking' => true,
                ],
            ],
            [],
            [],
            [],
            [],
            [],
        ];

        $category = $this->createMock(Categories::class);

        $category
            ->method('getTitre')
            ->willReturn('Nucléaire');

        $category
            ->method('getSlug')
            ->willReturn('NUC');

        $emission = $this->createEmission(
            9811,
            'Le nucléaire et son monde',
            $category
        );

        $diffusion = $this->createDiffusion(
            1867219,
            new \DateTimeImmutable('2026-10-07 17:00:00'),
            $emission,
            60,
            1,
            'rule_25_origin_20261002_1230',
            new \DateTimeImmutable('2026-10-07 18:00:00')
        );

        $this->prepareBuild(
            true,
            $segments
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        /*
     * Le contenu principal du créneau doit être l'émission
     * réellement diffusée.
     *
     * Le ghost ne doit plus rester un second segment concurrent
     * au même emplacement.
     */
        self::assertCount(
            1,
            $result['daySegments'][1]
        );

        $segment = $result['daySegments'][1][0];

        self::assertTrue(
            $segment['assigned']
        );

        self::assertSame(
            1867219,
            $segment['diffusionId']
        );

        self::assertSame(
            9811,
            $segment['emissionId']
        );

        self::assertSame(
            'Le nucléaire et son monde',
            $segment['emissionTitle']
        );

        self::assertSame(
            1,
            $segment['broadcastRank']
        );

        /*
     * Mais l'information administrative concernant
     * l'occurrence déplacée doit être conservée sur
     * le segment effectif.
     */
        self::assertTrue(
            $segment['hasCoveredGhost']
        );

        self::assertSame(
            'Sortir ce mois-ci',
            $segment['coveredGhost']['title']
        );

        self::assertSame(
            100,
            $segment['coveredGhost']['slotId']
        );

        self::assertTrue(
            $segment['coveredGhost']['isRescheduledOrigin']
        );

        self::assertSame(
            '2026-10-07 17:00:00',
            $segment['coveredGhost']['originalStartsAt']
        );

        self::assertSame(
            '2026-09-30 17:00:00',
            $segment['coveredGhost']['rescheduledStartsAt']
        );

        self::assertSame(
            '2026-09-30 18:00:00',
            $segment['coveredGhost']['rescheduledEndsAt']
        );

        self::assertTrue(
            $segment['coveredGhost']['canRestore']
        );

        self::assertFalse(
            $segment['coveredGhost']['isBlocking']
        );
    }

    public function testDiffusionModeDoesNotHydrateCancelledSegment(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [[[
            'slotId' => 10,
            'startsAt' => '2026-09-09 10:00:00',
            'originalStartsAt' => '2026-09-09 10:00:00',
            'title' => 'Créneau',
            'isCancelled' => true,
        ]]];

        $diffusion = $this->createDiffusion(
            40,
            new \DateTimeImmutable(
                '2026-09-09 10:00:00'
            ),
            $this->createEmission(
                20,
                'Émission validée'
            ),
            60,
            1,
            'group_1'
        );

        $this->prepareBuild(
            true,
            $segments
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $segment = $result['daySegments'][0][0];

        self::assertFalse(
            $segment['assigned']
        );

        self::assertFalse(
            $segment['isValidatedDiffusion']
        );

        self::assertFalse(
            $segment['isReadonly']
        );

        self::assertSame(
            'empty',
            $segment['sourceType']
        );

        /*
     * La diffusion publiée n'a pas été consommée par
     * le segment annulé : elle ressort donc comme
     * diffusion manuelle validée.
     */
        self::assertCount(
            1,
            $result['manualDraftsByDay'][1]
        );
    }

    public function testDiffusionModeAddsUnmatchedDiffusionAsReadonlyManualItem(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $diffusion = $this->createDiffusion(
            40,
            new \DateTimeImmutable(
                '2026-09-11 18:00:00'
            ),
            $this->createEmission(
                20,
                'Ponctuelle validée'
            ),
            60,
            1,
            'group_manual',
            new \DateTimeImmutable(
                '2026-09-11 19:00:00'
            )
        );

        $this->prepareBuild(
            true,
            $this->emptyWeek()
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        /*
     * Vendredi = index 3.
     */
        self::assertCount(
            1,
            $result['manualDraftsByDay'][3]
        );

        $manual =
            $result['manualDraftsByDay'][3][0];

        self::assertSame(
            40,
            $manual['id']
        );

        self::assertSame(
            40,
            $manual['diffusionId']
        );

        self::assertNull(
            $manual['draftId']
        );

        self::assertSame(
            72,
            $manual['startIndex']
        );

        self::assertSame(
            60,
            $manual['duration']
        );

        self::assertSame(
            'validated_diffusion',
            $manual['draftType']
        );

        self::assertFalse(
            $manual['isManualDraft']
        );

        self::assertTrue(
            $manual['isValidatedDiffusion']
        );

        self::assertTrue(
            $manual['isReadonly']
        );

        self::assertSame(
            'diffusion',
            $manual['sourceType']
        );

        self::assertSame(
            'group_manual',
            $manual['assignmentGroupKey']
        );
    }

    public function testDiffusionModeMarksUnmatchedAixDiffusionAsAix(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $editor = $this->createMock(\App\Entity\Editeur::class);

        $editor
            ->method('getId')
            ->willReturn(2);

        $category = $this->createMock(Categories::class);

        $category
            ->method('getTitre')
            ->willReturn('Peuple d’Orion (le) - Aix');

        $category
            ->method('getSlug')
            ->willReturn('PPO');

        $category
            ->method('getEditeur')
            ->willReturn($editor);

        $emission = $this->createEmission(
            20,
            'Le peuple d\'Orion',
            $category
        );

        $diffusion = $this->createDiffusion(
            40,
            new \DateTimeImmutable('2026-09-11 18:00:00'),
            $emission,
            60,
            1,
            null
        );

        $this->prepareBuild(
            true,
            $this->emptyWeek()
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $manual = $result['manualDraftsByDay'][3][0];

        self::assertSame(
            40,
            $manual['diffusionId']
        );

        self::assertSame(
            'Le peuple d\'Orion',
            $manual['title']
        );

        self::assertTrue(
            $manual['isAix']
        );
    }

    public function testDiffusionModeUsesFifteenMinutesForInvalidDuration(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $diffusion = $this->createDiffusion(
            40,
            new \DateTimeImmutable(
                '2026-09-08 23:45:00'
            ),
            $this->createEmission(
                20,
                'Ponctuelle'
            ),
            0,
            1,
            null
        );

        $this->prepareBuild(
            true,
            $this->emptyWeek()
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $manual =
            $result['manualDraftsByDay'][0][0];

        self::assertSame(
            15,
            $manual['duration']
        );

        self::assertSame(
            95,
            $manual['startIndex']
        );
    }

    public function testDiffusionModeSortsManualDiffusionsByStartTime(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $emission = $this->createEmission(
            20,
            'Ponctuelle'
        );

        $later = $this->createDiffusion(
            41,
            new \DateTimeImmutable(
                '2026-09-11 18:00:00'
            ),
            $emission,
            60
        );

        $earlier = $this->createDiffusion(
            40,
            new \DateTimeImmutable(
                '2026-09-11 09:00:00'
            ),
            $emission,
            60
        );

        $this->prepareBuild(
            true,
            $this->emptyWeek()
        );

        /*
     * Ordre volontairement inverse afin de vérifier
     * le tri effectué par GridViewBuilder.
     */
        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([
                $later,
                $earlier,
            ]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertCount(
            2,
            $result['manualDraftsByDay'][3]
        );

        self::assertSame(
            40,
            $result['manualDraftsByDay'][3][0]['diffusionId']
        );

        self::assertSame(
            41,
            $result['manualDraftsByDay'][3][1]['diffusionId']
        );
    }

    public function testDiffusionOutsideWeekIsIgnoredAsManualItem(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $diffusion = $this->createDiffusion(
            40,
            new \DateTimeImmutable(
                '2026-09-15 10:00:00'
            ),
            $this->createEmission(
                20,
                'Hors semaine'
            ),
            60
        );

        $this->prepareBuild(
            true,
            $this->emptyWeek()
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->diffusionRepository
            ->expects(self::never())
            ->method('findByWeek');

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertSame(
            $this->emptyWeek(),
            $result['manualDraftsByDay']
        );
    }

    public function testDiffusionModeDoesNotHydrateUnpublishedDiffusion(): void
    {
        $weekStart = new \DateTimeImmutable('2026-09-08');
        $weekEnd = new \DateTimeImmutable('2026-09-15');

        $segments = [[[
            'slotId' => 10,
            'startsAt' => '2026-09-09 10:00:00',
            'originalStartsAt' => '2026-09-09 10:00:00',
            'title' => 'Créneau',
            'categoryTitle' => 'Catégorie',
            'categorySlug' => 'CAT',
        ]]];

        $publishedEmission = $this->createEmission(
            20,
            'Émission publiée'
        );

        $unpublishedEmission = $this->createEmission(
            21,
            'Ancienne émission dévalidée'
        );

        /*
     * Cette diffusion publiée suffit à placer la semaine
     * en mode "diffusion".
     *
     * Elle est volontairement placée à un autre horaire.
     */
        $publishedDiffusion = $this->createDiffusion(
            40,
            new \DateTimeImmutable('2026-09-10 12:00:00'),
            $publishedEmission,
            60,
            1,
            'published_group'
        );

        $publishedDiffusion
            ->method('getPublicationStatus')
            ->willReturn(Diffusion::STATUS_PUBLISHED);

        /*
     * Cette ancienne diffusion appartient à une version
     * dévalidée de la grille.
     *
     * Elle doit rester en base afin de pouvoir être réutilisée
     * lors d'une future revalidation, mais elle ne doit plus
     * hydrater la grille actuellement validée.
     */
        $unpublishedDiffusion = $this->createDiffusion(
            41,
            new \DateTimeImmutable('2026-09-09 10:00:00'),
            $unpublishedEmission,
            60,
            1,
            'unpublished_group'
        );

        $unpublishedDiffusion
            ->method('getPublicationStatus')
            ->willReturn(Diffusion::STATUS_UNPUBLISHED);

        $this->prepareBuild(
            true,
            $segments
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([
                $publishedDiffusion,
            ]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertTrue(
            $result['hasValidatedWeek']
        );

        self::assertSame(
            'diffusion',
            $result['gridMode']
        );

        $segment = $result['daySegments'][0][0];

        self::assertFalse(
            $segment['assigned']
        );

        self::assertNull(
            $segment['diffusionId']
        );

        self::assertNull(
            $segment['emissionId']
        );

        self::assertFalse(
            $segment['isValidatedDiffusion']
        );

        self::assertFalse(
            $segment['isReadonly']
        );

        self::assertSame(
            'empty',
            $segment['sourceType']
        );

        /*
     * Aucune diffusion unpublished ne doit non plus ressortir
     * dans les diffusions manuelles affichées.
     *
     * Seule la diffusion publiée située le 10/09 à 12h
     * peut apparaître dans cette collection.
     */
        $manualDiffusions = array_merge(
            ...$result['manualDraftsByDay']
        );

        self::assertCount(
            1,
            $manualDiffusions
        );

        self::assertSame(
            40,
            $manualDiffusions[0]['diffusionId']
        );
    }

    public function testDiffusionModeKeepsRescheduledGhostWhenEffectiveSegmentDoesNotOverlapIt(): void
    {
        $weekStart = new \DateTimeImmutable('2026-10-06 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-13 00:00:00');

        $segments = [
            [],
            [
                [
                    'segmentKey' => '100_20261007170000',
                    'slotId' => 100,
                    'startsAt' => '2026-10-07 17:00:00',
                    'endsAt' => '2026-10-07 18:00:00',
                    'originalStartsAt' => '2026-10-07 17:00:00',
                    'rescheduledStartsAt' => '2026-09-30 17:00:00',
                    'rescheduledEndsAt' => '2026-09-30 18:00:00',
                    'duration' => 60,
                    'startIndex' => 68,
                    'title' => 'Sortir ce mois-ci',
                    'categoryTitle' => 'Sortir ce mois-ci',
                    'categorySlug' => 'SOR',
                    'isRescheduledOrigin' => true,
                    'isCancelled' => false,
                    'isBlocking' => false,
                    'canRestore' => true,
                ],
                [
                    'segmentKey' => '57_20261007190000',
                    'slotId' => 57,
                    'startsAt' => '2026-10-07 19:00:00',
                    'endsAt' => '2026-10-07 20:00:00',
                    'originalStartsAt' => '2026-10-07 19:00:00',
                    'duration' => 60,
                    'startIndex' => 76,
                    'title' => 'Autre émission',
                    'categoryTitle' => 'Autre catégorie',
                    'categorySlug' => 'AUT',
                    'isRescheduledOrigin' => false,
                    'isCancelled' => false,
                    'isBlocking' => true,
                ],
            ],
            [],
            [],
            [],
            [],
            [],
        ];

        $category = $this->createMock(
            Categories::class
        );

        $category
            ->method('getTitre')
            ->willReturn('Autre catégorie');

        $category
            ->method('getSlug')
            ->willReturn('AUT');

        $emission = $this->createEmission(
            9811,
            'Autre émission',
            $category
        );

        $diffusion = $this->createDiffusion(
            1867219,
            new \DateTimeImmutable(
                '2026-10-07 19:00:00'
            ),
            $emission,
            60,
            1,
            'rule_25_origin_20261007_1900',
            new \DateTimeImmutable(
                '2026-10-07 20:00:00'
            )
        );

        $this->prepareBuild(
            true,
            $segments
        );

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        self::assertCount(
            2,
            $result['daySegments'][1]
        );

        $ghost = $result['daySegments'][1][0];
        $effectiveSegment = $result['daySegments'][1][1];

        self::assertTrue(
            $ghost['isRescheduledOrigin']
        );

        self::assertFalse(
            $ghost['assigned']
        );

        self::assertSame(
            'Sortir ce mois-ci',
            $ghost['title']
        );

        self::assertTrue(
            $effectiveSegment['assigned']
        );

        self::assertSame(
            1867219,
            $effectiveSegment['diffusionId']
        );

        self::assertSame(
            'Autre émission',
            $effectiveSegment['emissionTitle']
        );

        self::assertFalse(
            $effectiveSegment['hasCoveredGhost']
        );

        self::assertNull(
            $effectiveSegment['coveredGhost']
        );
    }


    public function testDiffusionModeHydratesRescheduledSegmentAtEffectiveTime(): void
    {
        $weekStart = new \DateTimeImmutable('2026-10-06 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-13 00:00:00');

        $segments = [
            [],
            [
                [
                    'slotId' => 10,
                    'startsAt' => '2026-10-07 14:00:00',
                    'originalStartsAt' => '2026-10-07 10:00:00',
                    'endsAt' => '2026-10-07 15:00:00',
                    'title' => 'Créneau déplacé',
                    'isProjectedOverride' => true,
                    'isBlocking' => true,
                ],
            ],
            [],
            [],
            [],
            [],
            [],
        ];

        $emissionA = $this->createEmission(
            20,
            'Émission A déplacée'
        );

        $emissionB = $this->createEmission(
            21,
            'Émission B à l’origine'
        );

        $diffusionA = $this->createDiffusion(
            40,
            new \DateTimeImmutable('2026-10-07 14:00:00'),
            $emissionA,
            60,
            1,
            'group_A'
        );

        $diffusionB = $this->createDiffusion(
            41,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            $emissionB,
            60,
            1,
            'group_B'
        );

        $this->prepareBuild(true, $segments);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusionA, $diffusionB]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $segment = $result['daySegments'][1][0];

        self::assertTrue($segment['assigned']);

        self::assertSame(40, $segment['diffusionId']);
        self::assertSame(20, $segment['emissionId']);
        self::assertSame(
            'Émission A déplacée',
            $segment['displayTitle']
        );

        self::assertSame(
            'group_A',
            $segment['assignmentGroupKey']
        );

        // A doit être rattachée au segment déplacé,
        // et ne doit pas apparaître une deuxième fois.
        $manualDiffusions = $result['manualDraftsByDay'][1];

        self::assertCount(1, $manualDiffusions);

        // B reste indépendante du segment déplacé.
        self::assertSame(41, $manualDiffusions[0]['diffusionId']);
        self::assertSame(21, $manualDiffusions[0]['emissionId']);
    }


    public function testDiffusionModeHydratesRescheduledSegmentFromPreviousWeek(): void
    {
        // Semaine radio de destination : mardi 13 → lundi 19 octobre.
        $weekStart = new \DateTimeImmutable('2026-10-13 00:00:00');
        $weekEnd = new \DateTimeImmutable('2026-10-20 00:00:00');

        $segments = [
            [],
            [
                [
                    'slotId' => 10,
                    'startsAt' => '2026-10-14 14:00:00',
                    'originalStartsAt' => '2026-10-07 10:00:00',
                    'endsAt' => '2026-10-14 15:00:00',
                    'title' => 'Créneau déplacé depuis la semaine précédente',
                    'isProjectedOverride' => true,
                    'isBlocking' => true,
                ],
            ],
            [],
            [],
            [],
            [],
            [],
        ];

        $emission = $this->createEmission(
            20,
            'Émission déplacée entre semaines'
        );

        $diffusion = $this->createDiffusion(
            40,
            new \DateTimeImmutable('2026-10-14 14:00:00'),
            $emission,
            60,
            1,
            'group_interweek'
        );

        $this->prepareBuild(true, $segments);

        $this->diffusionRepository
            ->expects(self::once())
            ->method('findPublishedByWeek')
            ->with($weekStart, $weekEnd)
            ->willReturn([$diffusion]);

        $this->prepareSpecialCategories([]);

        $result = $this->service->build(
            $weekStart,
            $weekEnd
        );

        $segment = $result['daySegments'][1][0];

        self::assertTrue($segment['assigned']);
        self::assertSame(40, $segment['diffusionId']);
        self::assertSame(20, $segment['emissionId']);
        self::assertSame(
            'Émission déplacée entre semaines',
            $segment['displayTitle']
        );
        self::assertSame(
            'group_interweek',
            $segment['assignmentGroupKey']
        );

        // La Diffusion doit être représentée une seule fois.
        self::assertSame(
            [],
            $result['manualDraftsByDay'][1]
        );
    }

    private function prepareBuild(
        bool $validated,
        array $segments
    ): void {
        $this->diffusionRepository
            ->method('hasDiffusionsBetween')
            ->willReturn($validated);

        $this->programmationGridBuilder
            ->method('buildForWeek')
            ->willReturn($segments);

        $this->projectionService
            ->method('applyForWeek')
            ->willReturn($segments);

        $this->conflictDetector
            ->method('detectForWeek')
            ->willReturn($segments);
    }

    private function prepareSpecialCategories(
        array $categories
    ): void {
        $query = $this->createMock(
            Query::class
        );

        $query
            ->method('getResult')
            ->willReturn($categories);

        $queryBuilder = $this->createMock(
            QueryBuilder::class
        );

        $queryBuilder
            ->method('andWhere')
            ->willReturnSelf();

        $queryBuilder
            ->method('setParameter')
            ->willReturnSelf();

        $queryBuilder
            ->method('orderBy')
            ->willReturnSelf();

        $queryBuilder
            ->method('getQuery')
            ->willReturn($query);

        $this->categoriesRepository
            ->method('createQueryBuilder')
            ->with('c')
            ->willReturn($queryBuilder);
    }

    private function createSlot(
        int $id
    ): ProgrammationRuleSlot&MockObject {
        $slot = $this->createMock(
            ProgrammationRuleSlot::class
        );

        $slot
            ->method('getId')
            ->willReturn($id);

        return $slot;
    }

    private function createEmission(
        int $id,
        string $title,
        ?Categories $category = null,
        bool $autoGenerated = false
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

        $emission
            ->method('getCategorie')
            ->willReturn($category);

        $emission
            ->method('isAutoGenerated')
            ->willReturn($autoGenerated);

        return $emission;
    }

    private function createRegularDraft(
        int $id,
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $startsAt,
        Emission $emission
    ): DiffusionDraft&MockObject {
        $draft = $this->createMock(
            DiffusionDraft::class
        );

        $draft
            ->method('getId')
            ->willReturn($id);

        $draft
            ->method('getSlot')
            ->willReturn($slot);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn($startsAt);

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        return $draft;
    }

    private function createManualDraft(
        int $id,
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        Emission $emission,
        ?int $duration
    ): DiffusionDraft&MockObject {
        $draft = $this->createMock(
            DiffusionDraft::class
        );

        $draft
            ->method('getId')
            ->willReturn($id);

        $draft
            ->method('getSlot')
            ->willReturn(null);

        $draft
            ->method('getHoraireDiffusion')
            ->willReturn($startsAt);

        $draft
            ->method('getEndsAt')
            ->willReturn($endsAt);

        $draft
            ->method('getEmission')
            ->willReturn($emission);

        $draft
            ->method('getDurationMinutes')
            ->willReturn($duration);

        $draft
            ->method('getDraftType')
            ->willReturn('manual_special');

        $draft
            ->method('getNombreDiffusion')
            ->willReturn(1);

        $draft
            ->method('getAssignmentGroupKey')
            ->willReturn('manual_group');

        return $draft;
    }

    private function createDiffusion(
        int $id,
        \DateTimeImmutable $startsAt,
        Emission $emission,
        ?int $duration = null,
        int $nombreDiffusion = 1,
        ?string $assignmentGroupKey = null,
        ?\DateTimeImmutable $endsAt = null
    ): Diffusion&MockObject {
        $diffusion = $this->createMock(
            Diffusion::class
        );

        $diffusion
            ->method('getId')
            ->willReturn($id);

        $diffusion
            ->method('getHoraireDiffusion')
            ->willReturn($startsAt);

        $diffusion
            ->method('getEmission')
            ->willReturn($emission);

        $diffusion
            ->method('getDurationMinutes')
            ->willReturn($duration);

        $diffusion
            ->method('getEndsAt')
            ->willReturn($endsAt);

        $diffusion
            ->method('getNombreDiffusion')
            ->willReturn($nombreDiffusion);

        $diffusion
            ->method('getAssignmentGroupKey')
            ->willReturn($assignmentGroupKey);

        return $diffusion;
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function emptyWeek(): array
    {
        return array_fill(
            0,
            7,
            []
        );
    }
}
