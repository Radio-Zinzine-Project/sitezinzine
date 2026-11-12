<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\ProgrammationRuleSlotController;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Form\ProgrammationRuleSlotType;
use App\Repository\ProgrammationRuleRepository;
use App\Repository\ProgrammationRuleSlotRepository;
use App\Service\ProgrammationRuleConflictChecker;
use App\Service\ProgrammationRuleMutationInterface;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use PHPUnit\Framework\MockObject\MockObject;

class ProgrammationRuleSlotControllerTest extends ControllerActionTestCase
{
    private ProgrammationRuleSlotController $controller;
    private ProgrammationRule $rule;
    private ProgrammationRuleRepository $repository;
    private ProgrammationRuleConflictChecker $conflictChecker;

    /** @var ProgrammationRuleMutationInterface&MockObject */
    private $mutationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new ProgrammationRuleSlotController();
        $this->wire($this->controller);

        $this->rule = new ProgrammationRule();
        $this->identify($this->rule, 10);

        $this->repository = $this->createMock(
            ProgrammationRuleRepository::class
        );

        $this->conflictChecker = $this->conflictCheckerWith([]);

        $this->mutationService = $this->createMock(
            ProgrammationRuleMutationInterface::class
        );
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            unset($this->controller, $this->rule, $this->repository, $this->conflictChecker, $this->mutationService);
        }
    }

    public function testIndexDisplaysSlotsForRequestedRule(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('find')
            ->with(10)
            ->willReturn($this->rule);

        $slots = [$this->slot()];

        $repo = $this->createMock(
            ProgrammationRuleSlotRepository::class
        );

        $repo
            ->expects(self::once())
            ->method('findNotDeletedByRule')
            ->with($this->rule)
            ->willReturn($slots);

        self::assertSame(
            200,
            $this->controller
                ->index(
                    10,
                    $this->repository,
                    $repo,
                    $this->conflictChecker
                )
                ->getStatusCode()
        );

        self::assertSame(
            'admin/programmationRuleSlot/index.html.twig',
            $this->template
        );

        self::assertSame(
            [
                'rule' => $this->rule,
                'slots' => $slots,
                'conflictsBySlotId' => [],
            ],
            $this->parameters
        );
    }

    /**
     * @dataProvider unavailableRules
     */
    public function testActionsRejectMissingOrDeletedParentRule(
        string $action,
        bool $deleted
    ): void {
        $this->repository
            ->method('find')
            ->with(10)
            ->willReturn(
                $deleted
                    ? $this->rule->softDelete()
                    : null
            );

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('persist');

        if ('delete' === $action) {
            $em
                ->method('getRepository')
                ->with(ProgrammationRule::class)
                ->willReturn($this->repository);
        }

        $slots = $this->createMock(
            ProgrammationRuleSlotRepository::class
        );

        $slots
            ->expects(self::never())
            ->method('findNotDeletedByRule');

        $this->mutationService
            ->expects(self::never())
            ->method('synchronizeAfterStructuralChange');

        $this->expectException(
            NotFoundHttpException::class
        );

        $this->expectExceptionMessage(
            'Règle de programmation introuvable.'
        );

        match ($action) {
            'index' => $this->controller->index(
                10,
                $this->repository,
                $slots,
                $this->conflictChecker
            ),

            'create' => $this->controller->create(
                10,
                Request::create('/'),
                $this->repository,
                $em,
                $this->conflictChecker,
                $this->mutationService
            ),

            'edit' => $this->controller->edit(
                10,
                $this->slot(),
                Request::create('/'),
                $this->repository,
                $this->conflictChecker,
                $this->mutationService
            ),

            'delete' => $this->controller->delete(
                10,
                $this->slot(),
                Request::create('/'),
                $em,
                $this->mutationService
            ),
        };
    }

    public static function unavailableRules(): iterable
    {
        foreach (['index', 'create', 'edit', 'delete'] as $action) {
            yield $action . ' absente' => [
                $action,
                false,
            ];

            yield $action . ' supprimée' => [
                $action,
                true,
            ];
        }
    }

    /**
     * @dataProvider foreignSlots
     */
    public function testEditAndDeleteRejectSlotsFromAnotherRule(
        string $action
    ): void {
        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $otherRule = new ProgrammationRule();
        $this->identify($otherRule, 11);

        $slot = $this->slot()
            ->setRule($otherRule);

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        if ('delete' === $action) {
            $em
                ->method('getRepository')
                ->willReturn($this->repository);
        }

        $this->mutationService
            ->expects(self::never())
            ->method('synchronizeAfterStructuralChange');

        $this->expectException(
            NotFoundHttpException::class
        );

        $this->expectExceptionMessage(
            'Ce créneau n’appartient pas à cette règle.'
        );

        if ('edit' === $action) {
            $this->controller->edit(
                10,
                $slot,
                Request::create('/'),
                $this->repository,
                $this->conflictChecker,
                $this->mutationService
            );
        } else {
            $this->controller->delete(
                10,
                $slot,
                Request::create('/'),
                $em,
                $this->mutationService
            );
        }
    }

    public static function foreignSlots(): iterable
    {
        yield 'édition' => ['edit'];
        yield 'suppression' => ['delete'];
    }

    public function testEditRedirectsForDeletedSlotWithoutWriting(): void
    {
        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $this->mutationService
            ->expects(self::never())
            ->method('synchronizeAfterStructuralChange');

        $response = $this->controller->edit(
            10,
            $this->slot()->softDelete(),
            Request::create('/'),
            $this->repository,
            $this->conflictChecker,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertSame(
            [
                'admin_programmationRuleSlot_index',
                ['ruleId' => 10],
            ],
            $this->redirect
        );

        self::assertSame(
            ['Ce créneau a été supprimé.'],
            $this->session
                ->getFlashBag()
                ->get('danger')
        );
    }

    /**
     * @dataProvider formActions
     */
    public function testCreateAndEditRespectFormState(
        string $action,
        bool $submitted,
        bool $valid
    ): void {
        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $slot = $this->slot();

        $this->form(
            ProgrammationRuleSlotType::class,
            $submitted,
            $valid,
            'edit' === $action ? $slot : null,
            function (ProgrammationRuleSlot $data): void {
                self::assertSame(
                    $this->rule,
                    $data->getRule()
                );

                $data->setDurationMinutes(60);
            }
        );

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(
                $submitted
                    && $valid
                    && 'create' === $action
                    ? self::once()
                    : self::never()
            )
            ->method('persist')
            ->with(
                self::callback(
                    fn($entity): bool =>
                    $entity === $this->formData
                )
            );

        $this->mutationService
            ->expects(
                $submitted && $valid
                    ? self::once()
                    : self::never()
            )
            ->method('synchronizeAfterStructuralChange')
            ->with($this->rule)
            ->willReturn([
                'unpublishedWeeks' => [],
                'synchronization' => [],
            ]);

        $response = 'create' === $action
            ? $this->controller->create(
                10,
                Request::create('/'),
                $this->repository,
                $em,
                $this->conflictChecker,
                $this->mutationService
            )
            : $this->controller->edit(
                10,
                $slot,
                Request::create('/'),
                $this->repository,
                $this->conflictChecker,
                $this->mutationService
            );

        if ($submitted && $valid) {
            self::assertTrue(
                $response->isRedirect()
            );

            self::assertSame(
                [
                    'admin_programmationRuleSlot_index',
                    ['ruleId' => 10],
                ],
                $this->redirect
            );

            self::assertCount(
                1,
                $this->session
                    ->getFlashBag()
                    ->get('success')
            );
        } else {
            self::assertSame(
                200,
                $response->getStatusCode()
            );

            self::assertSame(
                'admin/programmationRuleSlot/'
                    . $action
                    . '.html.twig',
                $this->template
            );

            self::assertSame(
                $this->rule,
                $this->parameters['rule']
            );

            self::assertArrayHasKey(
                'form',
                $this->parameters
            );

            self::assertSame(
                [],
                $this->session
                    ->getFlashBag()
                    ->all()
            );
        }
    }

    public static function formActions(): iterable
    {
        foreach (['create', 'edit'] as $action) {
            foreach (
                self::formStates()
                as $name => $state
            ) {
                yield $action . ' ' . $name => [
                    $action,
                    ...$state,
                ];
            }
        }
    }

    /**
     * @dataProvider invalidSlots
     */
    public function testInvalidSchedulingAddsFieldErrorsAndDoesNotSave(
        string $field,
        mixed $value
    ): void {
        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $this->form(
            ProgrammationRuleSlotType::class,
            true,
            true,
            prepare: static function (
                ProgrammationRuleSlot $slot
            ) use ($field, $value): void {
                $slot->setDurationMinutes(60);

                if (
                    in_array(
                        $field,
                        [
                            'monthlyOccurrence',
                            'monthInterval',
                        ],
                        true
                    )
                ) {
                    $slot
                        ->setRecurrenceType(
                            ProgrammationRuleSlot::RECURRENCE_MONTHLY
                        )
                        ->setMonthlyOccurrence(1);
                }

                $setter = 'set' . ucfirst($field);
                $slot->$setter($value);
            },
            fields: [
                $field => null,
            ]
        );

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('persist');

        $this->mutationService
            ->expects(self::never())
            ->method('synchronizeAfterStructuralChange');

        self::assertSame(
            200,
            $this->controller
                ->create(
                    10,
                    Request::create('/'),
                    $this->repository,
                    $em,
                    $this->conflictChecker,
                    $this->mutationService
                )
                ->getStatusCode()
        );

        self::assertSame(
            [$field],
            array_keys($this->formErrors)
        );

        self::assertNotEmpty(
            $this->formErrors[$field][0]
        );

        self::assertSame(
            'admin/programmationRuleSlot/create.html.twig',
            $this->template
        );
    }

    public static function invalidSlots(): iterable
    {
        yield 'occurrence mensuelle requise' => [
            'monthlyOccurrence',
            null,
        ];

        yield 'décalage excessif' => [
            'weekOffset',
            5,
        ];

        yield 'durée absente' => [
            'durationMinutes',
            null,
        ];
    }

    /**
     * @dataProvider recurrenceTypes
     */
    public function testEditNormalizesFieldsForSelectedRecurrence(
        string $recurrence
    ): void {
        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $slot = $this->slot()
            ->setRecurrenceType($recurrence)
            ->setMonthlyOccurrence(2)
            ->setMonthInterval(3)
            ->setWeekParity(
                ProgrammationRuleSlot::WEEK_PARITY_EVEN
            );

        $this->form(
            ProgrammationRuleSlotType::class,
            true,
            true,
            $slot
        );

        $this->mutationService
            ->expects(self::once())
            ->method('synchronizeAfterStructuralChange')
            ->with($this->rule)
            ->willReturn([
                'unpublishedWeeks' => [],
                'synchronization' => [],
            ]);

        self::assertTrue(
            $this->controller
                ->edit(
                    10,
                    $slot,
                    Request::create('/'),
                    $this->repository,
                    $this->conflictChecker,
                    $this->mutationService
                )
                ->isRedirect()
        );

        $weekly =
            ProgrammationRuleSlot::RECURRENCE_WEEKLY
            === $recurrence;

        self::assertSame(
            $weekly ? null : 2,
            $slot->getMonthlyOccurrence()
        );

        self::assertSame(
            $weekly ? 1 : 3,
            $slot->getMonthInterval()
        );

        self::assertSame(
            $weekly
                ? ProgrammationRuleSlot::WEEK_PARITY_EVEN
                : null,
            $slot->getWeekParity()
        );
    }

    public static function recurrenceTypes(): iterable
    {
        yield 'hebdomadaire' => [
            ProgrammationRuleSlot::RECURRENCE_WEEKLY,
        ];

        yield 'mensuelle' => [
            ProgrammationRuleSlot::RECURRENCE_MONTHLY,
        ];
    }

    public function testCreateWithConflictSavesSlotAndDisablesRule(): void
    {
        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $this->form(
            ProgrammationRuleSlotType::class,
            true,
            true,
            prepare: static function (
                ProgrammationRuleSlot $slot
            ): void {
                $slot
                    ->setRecurrenceType(
                        ProgrammationRuleSlot::RECURRENCE_WEEKLY
                    )
                    ->setDayOfWeek(2)
                    ->setStartTime(
                        new \DateTimeImmutable('20:00')
                    )
                    ->setDurationMinutes(60)
                    ->setBroadcastRank(1)
                    ->setWeekOffset(0)
                    ->setIsActive(true);
            }
        );

        $existing = $this->externalWeeklySlot(
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $conflictChecker = $this->conflictCheckerWith([
            $existing,
        ]);

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::once())
            ->method('persist')
            ->with(
                self::callback(
                    fn($entity): bool =>
                    $entity === $this->formData
                )
            );

        $this->mutationService
            ->expects(self::once())
            ->method('synchronizeAfterStructuralChange')
            ->with($this->rule)
            ->willReturn([
                'unpublishedWeeks' => [],
                'synchronization' => [],
            ]);

        self::assertTrue(
            $this->rule->isActive()
        );

        $response = $this->controller->create(
            10,
            Request::create('/'),
            $this->repository,
            $em,
            $conflictChecker,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $this->rule->isActive()
        );

        self::assertTrue(
            $this->rule
                ->getSlots()
                ->contains($this->formData)
        );

        self::assertCount(
            1,
            $this->session
                ->getFlashBag()
                ->get('warning')
        );

        self::assertSame(
            [],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }

    public function testEditWithConflictSavesChangesAndDisablesRule(): void
    {
        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $slot = $this->slot()
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('20:00')
            )
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $this->rule->addSlot($slot);

        $this->form(
            ProgrammationRuleSlotType::class,
            true,
            true,
            $slot,
            static function (
                ProgrammationRuleSlot $data
            ): void {
                $data->setDurationMinutes(90);
            }
        );

        $existing = $this->externalWeeklySlot(
            dayOfWeek: 2,
            startTime: '20:30',
            duration: 60
        );

        $conflictChecker = $this->conflictCheckerWith([
            $existing,
        ]);

        $this->mutationService
            ->expects(self::once())
            ->method('synchronizeAfterStructuralChange')
            ->with($this->rule)
            ->willReturn([
                'unpublishedWeeks' => [],
                'synchronization' => [],
            ]);

        self::assertTrue(
            $this->rule->isActive()
        );

        $response = $this->controller->edit(
            10,
            $slot,
            Request::create('/'),
            $this->repository,
            $conflictChecker,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $this->rule->isActive()
        );

        self::assertSame(
            90,
            $slot->getDurationMinutes()
        );

        self::assertCount(
            1,
            $this->session
                ->getFlashBag()
                ->get('warning')
        );

        self::assertSame(
            [],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }

    public function testEditWithoutConflictDoesNotReactivateInactiveRule(): void
    {
        $this->rule->setIsActive(false);

        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $slot = $this->slot()
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('20:00')
            )
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $this->rule->addSlot($slot);

        $this->form(
            ProgrammationRuleSlotType::class,
            true,
            true,
            $slot
        );

        $this->mutationService
            ->expects(self::once())
            ->method('synchronizeAfterStructuralChange')
            ->with($this->rule)
            ->willReturn([
                'unpublishedWeeks' => [],
                'synchronization' => [],
            ]);

        $response = $this->controller->edit(
            10,
            $slot,
            Request::create('/'),
            $this->repository,
            $this->conflictChecker,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $this->rule->isActive()
        );

        self::assertSame(
            [
                'Le créneau a bien été modifié. La règle ne présente plus de conflit et peut être réactivée.',
            ],
            $this->session
                ->getFlashBag()
                ->get('success')
        );

        self::assertSame(
            [],
            $this->session
                ->getFlashBag()
                ->get('warning')
        );
    }

    /**
     * @dataProvider deletions
     */
    public function testDeleteChecksCsrfAndAvoidsDeletingTwice(
        bool $valid,
        bool $alreadyDeleted,
        string $flash
    ): void {
        $this->repository
            ->method('find')
            ->willReturn($this->rule);

        $slot = $this->slot();

        if ($alreadyDeleted) {
            $slot->softDelete();
        }

        $oldDeletedAt = $slot->getDeletedAt();

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->method('getRepository')
            ->with(ProgrammationRule::class)
            ->willReturn($this->repository);

        $em
            ->expects(self::never())
            ->method('remove');

        if ($valid && !$alreadyDeleted) {
            $this->mutationService
                ->expects(self::once())
                ->method('synchronizeAfterStructuralChange')
                ->with($this->rule)
                ->willReturn([
                    'unpublishedWeeks' => [],
                    'synchronization' => [],
                ]);
        } else {
            $this->mutationService
                ->expects(self::never())
                ->method('synchronizeAfterStructuralChange');
        }

        self::assertTrue(
            $this->controller
                ->delete(
                    10,
                    $slot,
                    $this->tokenRequest(
                        'delete_slot_42',
                        $valid
                    ),
                    $em,
                    $this->mutationService
                )
                ->isRedirect()
        );

        self::assertSame(
            $alreadyDeleted || $valid,
            $slot->isDeleted()
        );

        if (!$valid || $alreadyDeleted) {
            self::assertSame(
                $oldDeletedAt,
                $slot->getDeletedAt()
            );
        }

        self::assertSame(
            [
                'admin_programmationRuleSlot_index',
                ['ruleId' => 10],
            ],
            $this->redirect
        );

        self::assertCount(
            1,
            $this->session
                ->getFlashBag()
                ->get($flash)
        );
    }

    public static function deletions(): iterable
    {
        yield 'jeton invalide' => [
            false,
            false,
            'danger',
        ];

        yield 'suppression' => [
            true,
            false,
            'success',
        ];

        yield 'déjà supprimé' => [
            true,
            true,
            'warning',
        ];
    }

    /**
     * @param ProgrammationRuleSlot[] $candidates
     */
    private function conflictCheckerWith(
        array $candidates
    ): ProgrammationRuleConflictChecker {
        $repository = $this->createMock(
            ProgrammationRuleSlotRepository::class
        );

        $repository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturn($candidates);

        return new ProgrammationRuleConflictChecker(
            $repository
        );
    }

    private function externalWeeklySlot(
        int $dayOfWeek,
        string $startTime,
        int $duration
    ): ProgrammationRuleSlot {
        $rule = new ProgrammationRule();

        $slot = (new ProgrammationRuleSlot())
            ->setRule($rule)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(
                new \DateTimeImmutable($startTime)
            )
            ->setDurationMinutes($duration)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setIsActive(true);

        $rule->addSlot($slot);

        return $slot;
    }

    private function slot(): ProgrammationRuleSlot
    {
        $slot = (new ProgrammationRuleSlot())
            ->setRule($this->rule)
            ->setDurationMinutes(60);

        $this->identify($slot, 42);

        return $slot;
    }
}
