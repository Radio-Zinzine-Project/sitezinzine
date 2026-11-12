<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\ProgrammationRuleController;
use App\Entity\Categories;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Form\ProgrammationRuleType;
use App\Repository\ProgrammationRuleRepository;
use App\Repository\ProgrammationRuleSlotRepository;
use App\Service\ProgrammationRuleConflictChecker;
use App\Service\ProgrammationRuleMutationInterface;
use PHPUnit\Framework\MockObject\MockObject;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class ProgrammationRuleControllerTest extends ControllerActionTestCase
{
    private ProgrammationRuleController $controller;
    private ProgrammationRuleSlotRepository $slotRepository;
    private ProgrammationRuleConflictChecker $conflictChecker;

    /** @var ProgrammationRuleMutationInterface&MockObject */
    private $mutationService;

    /** @var ProgrammationRuleSlot[] */
    private array $structuralCandidates = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new ProgrammationRuleController();
        $this->wire($this->controller);

        $this->structuralCandidates = [];

        $this->slotRepository = $this->createMock(
            ProgrammationRuleSlotRepository::class
        );

        $this->slotRepository
            ->method('findActiveStructuralConflictCandidates')
            ->willReturnCallback(
                fn(): array => $this->structuralCandidates
            );

        $this->conflictChecker = new ProgrammationRuleConflictChecker(
            $this->slotRepository
        );

        $this->mutationService = $this->createMock(
            ProgrammationRuleMutationInterface::class
        );
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            unset($this->controller, $this->slotRepository, $this->conflictChecker, $this->mutationService);
            $this->structuralCandidates = [];
        }
    }

    public function testIndexUsesNonDeletedRules(): void
    {
        $activeRule = new ProgrammationRule();
        $this->identify($activeRule, 1);

        $inactiveRule = (new ProgrammationRule())
            ->setIsActive(false);
        $this->identify($inactiveRule, 2);

        $rules = [
            $activeRule,
            $inactiveRule,
        ];

        $repo = $this->createMock(
            ProgrammationRuleRepository::class
        );

        $repo
            ->expects(self::once())
            ->method('findAllNotDeleted')
            ->willReturn($rules);

        $response = $this->controller->index(
            $repo,
            $this->conflictChecker
        );

        self::assertSame(
            200,
            $response->getStatusCode()
        );

        self::assertSame(
            'admin/programmationRule/index.html.twig',
            $this->template
        );

        self::assertSame(
            $rules,
            $this->parameters['rules']
        );

        self::assertSame(
            [
                2 => false,
            ],
            $this->parameters['conflictStates']
        );
    }

    public function testIndexMarksInactiveRuleWithStructuralConflict(): void
    {
        $inactiveRule = (new ProgrammationRule())
            ->setIsActive(false);
        $this->identify($inactiveRule, 10);

        $inactiveSlot = $this->weeklySlot(
            $inactiveRule,
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $inactiveRule->addSlot($inactiveSlot);

        $activeRule = new ProgrammationRule();
        $this->identify($activeRule, 20);

        $activeSlot = $this->weeklySlot(
            $activeRule,
            dayOfWeek: 2,
            startTime: '20:30',
            duration: 60
        );

        $activeRule->addSlot($activeSlot);

        $this->structuralCandidates = [
            $activeSlot,
        ];

        $repo = $this->createMock(
            ProgrammationRuleRepository::class
        );

        $repo
            ->expects(self::once())
            ->method('findAllNotDeleted')
            ->willReturn([
                $inactiveRule,
                $activeRule,
            ]);

        $this->controller->index(
            $repo,
            $this->conflictChecker
        );

        self::assertSame(
            [
                10 => true,
            ],
            $this->parameters['conflictStates']
        );
    }

    /**
     * @dataProvider ruleNumbers
     */
    public function testCreateAssignsNextNumberOnlyWhenNotProvided(
        ?int $number,
        int $maximum
    ): void {
        $category = (new Categories())
            ->setTitre('Culture');

        $this->form(
            ProgrammationRuleType::class,
            true,
            true,
            prepare: static function (
                ProgrammationRule $rule
            ) use ($category, $number): void {
                $rule->setCategory($category);

                if (null !== $number) {
                    $rule->setRuleNumber($number);
                }
            }
        );

        $repo = $this->createMock(
            ProgrammationRuleRepository::class
        );

        $repo
            ->expects(
                null === $number
                    ? self::once()
                    : self::never()
            )
            ->method('findMaxRuleNumberByCategory')
            ->with($category)
            ->willReturn($maximum);

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::once())
            ->method('persist')
            ->with(
                self::callback(
                    function ($rule) use (
                        $number,
                        $maximum
                    ): bool {
                        return $rule === $this->formData
                            && $rule->getRuleNumber()
                            === ($number ?? $maximum + 1);
                    }
                )
            );

        $em
            ->expects(self::once())
            ->method('flush');

        self::assertTrue(
            $this->controller
                ->create(
                    Request::create('/'),
                    $em,
                    $repo
                )
                ->isRedirect()
        );

        self::assertSame(
            [
                'admin_programmationRule_index',
                [],
            ],
            $this->redirect
        );

        self::assertSame(
            [
                'Règle créée avec succès : '
                    . $this->formData->getDisplayName()
                    . '.',
            ],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }

    public static function ruleNumbers(): iterable
    {
        yield 'première règle' => [
            null,
            0,
        ];

        yield 'règle suivante' => [
            null,
            4,
        ];

        yield 'numéro explicite' => [
            7,
            4,
        ];
    }

    /**
     * @dataProvider rejectedCreates
     */
    public function testCreateDoesNotPersistAnUnsubmittedInvalidOrUncategorizedRule(
        bool $submitted,
        bool $valid
    ): void {
        $this->form(
            ProgrammationRuleType::class,
            $submitted,
            $valid
        );

        $repo = $this->createMock(
            ProgrammationRuleRepository::class
        );

        $repo
            ->expects(self::never())
            ->method('findMaxRuleNumberByCategory');

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('persist');

        $em
            ->expects(self::never())
            ->method('flush');

        $response = $this->controller->create(
            Request::create('/'),
            $em,
            $repo
        );

        self::assertSame(
            $submitted && !$valid ? 422 : 200,
            $response->getStatusCode()
        );

        self::assertSame(
            'admin/programmationRule/create.html.twig',
            $this->template
        );

        self::assertSame(
            $this->formData,
            $this->parameters['rule']
        );

        self::assertSame(
            $valid
                ? [
                    'danger' => [
                        'La catégorie est obligatoire.',
                    ],
                ]
                : [],
            $this->session
                ->getFlashBag()
                ->all()
        );
    }

    public static function rejectedCreates(): iterable
    {
        yield 'affichage' => [
            false,
            false,
        ];

        yield 'invalide' => [
            true,
            false,
        ];

        yield 'catégorie absente' => [
            true,
            true,
        ];
    }

    /**
     * @dataProvider formStates
     */
    public function testEditSynchronizesOnlyValidForm(
        bool $submitted,
        bool $valid
    ): void {
        $rule = new ProgrammationRule();

        $this->form(
            ProgrammationRuleType::class,
            $submitted,
            $valid,
            $rule
        );

        if ($submitted && $valid) {
            $this->mutationService
                ->expects(self::once())
                ->method('synchronizeAfterStructuralChange')
                ->with($rule)
                ->willReturn([
                    'unpublishedWeeks' => [],
                    'synchronization' => [],
                ]);
        } else {
            $this->mutationService
                ->expects(self::never())
                ->method('synchronizeAfterStructuralChange');
        }

        $response = $this->controller->edit(
            Request::create('/'),
            $rule,
            $this->conflictChecker,
            $this->mutationService
        );

        if ($submitted && $valid) {
            self::assertTrue(
                $response->isRedirect()
            );

            self::assertSame(
                [
                    'admin_programmationRule_index',
                    [],
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
                $submitted ? 422 : 200,
                $response->getStatusCode()
            );

            self::assertSame(
                'admin/programmationRule/edit.html.twig',
                $this->template
            );

            self::assertSame(
                $rule,
                $this->parameters['rule']
            );
        }
    }

    public function testEditWithConflictSavesChangesAndDisablesActiveRule(): void
    {
        $rule = new ProgrammationRule();
        $this->identify($rule, 10);

        $slot = $this->weeklySlot(
            $rule,
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $rule->addSlot($slot);

        $otherRule = new ProgrammationRule();
        $this->identify($otherRule, 20);

        $otherSlot = $this->weeklySlot(
            $otherRule,
            dayOfWeek: 2,
            startTime: '20:30',
            duration: 60
        );

        $otherRule->addSlot($otherSlot);

        $this->structuralCandidates = [
            $otherSlot,
        ];

        $this->form(
            ProgrammationRuleType::class,
            true,
            true,
            $rule
        );

        $this->mutationService
            ->expects(self::once())
            ->method('synchronizeAfterStructuralChange')
            ->with($rule)
            ->willReturn([
                'unpublishedWeeks' => [],
                'synchronization' => [],
            ]);

        self::assertTrue(
            $rule->isActive()
        );

        $response = $this->controller->edit(
            Request::create('/'),
            $rule,
            $this->conflictChecker,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $rule->isActive()
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
        $rule = (new ProgrammationRule())
            ->setIsActive(false);

        $this->identify($rule, 10);

        $slot = $this->weeklySlot(
            $rule,
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $rule->addSlot($slot);

        $this->form(
            ProgrammationRuleType::class,
            true,
            true,
            $rule
        );

        $this->mutationService
            ->expects(self::once())
            ->method('synchronizeAfterStructuralChange')
            ->with($rule)
            ->willReturn([
                'unpublishedWeeks' => [],
                'synchronization' => [],
            ]);

        $response = $this->controller->edit(
            Request::create('/'),
            $rule,
            $this->conflictChecker,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $rule->isActive()
        );

        self::assertSame(
            [
                'Règle mise à jour : '
                    . $rule->getDisplayName()
                    . '. Elle ne présente plus de conflit et peut être réactivée.',
            ],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }


    public function testEditRefusesDeletedRule(): void
    {
        $rule = (new ProgrammationRule())
            ->softDelete();

        $this->mutationService
            ->expects(self::never())
            ->method('synchronizeAfterStructuralChange');

        self::assertTrue(
            $this->controller
                ->edit(
                    Request::create('/'),
                    $rule,
                    $this->conflictChecker,
                    $this->mutationService
                )
                ->isRedirect()
        );

        self::assertSame(
            [
                'admin_programmationRule_index',
                [],
            ],
            $this->redirect
        );

        self::assertSame(
            ['Règle supprimée.'],
            $this->session
                ->getFlashBag()
                ->get('danger')
        );
    }

    public function testActivateReactivatesInactiveRuleWithoutConflict(): void
    {
        $rule = (new ProgrammationRule())
            ->setIsActive(false);

        $this->identify($rule, 42);

        $slot = $this->weeklySlot(
            $rule,
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $rule->addSlot($slot);

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::once())
            ->method('flush');

        $response = $this->controller->activate(
            $this->tokenRequest(
                'activate_rule_42',
                true
            ),
            $rule,
            $em,
            $this->conflictChecker
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertTrue(
            $rule->isActive()
        );

        self::assertSame(
            [
                'Règle réactivée : '
                    . $rule->getDisplayName()
                    . '.',
            ],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }

    public function testActivateRefusesRuleWithStructuralConflict(): void
    {
        $rule = (new ProgrammationRule())
            ->setIsActive(false);

        $this->identify($rule, 42);

        $slot = $this->weeklySlot(
            $rule,
            dayOfWeek: 2,
            startTime: '20:00',
            duration: 60
        );

        $rule->addSlot($slot);

        $otherRule = new ProgrammationRule();
        $this->identify($otherRule, 50);

        $otherSlot = $this->weeklySlot(
            $otherRule,
            dayOfWeek: 2,
            startTime: '20:30',
            duration: 60
        );

        $otherRule->addSlot($otherSlot);

        $this->structuralCandidates = [
            $otherSlot,
        ];

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('flush');

        $response = $this->controller->activate(
            $this->tokenRequest(
                'activate_rule_42',
                true
            ),
            $rule,
            $em,
            $this->conflictChecker
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $rule->isActive()
        );

        self::assertSame(
            [
                'Cette règle ne peut pas être réactivée tant qu’elle présente un conflit de programmation.',
            ],
            $this->session
                ->getFlashBag()
                ->get('danger')
        );
    }

    public function testActivateRefusesInvalidCsrf(): void
    {
        $rule = (new ProgrammationRule())
            ->setIsActive(false);

        $this->identify($rule, 42);

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('flush');

        $response = $this->controller->activate(
            $this->tokenRequest(
                'activate_rule_42',
                false
            ),
            $rule,
            $em,
            $this->conflictChecker
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $rule->isActive()
        );

        self::assertSame(
            ['Jeton CSRF invalide.'],
            $this->session
                ->getFlashBag()
                ->get('danger')
        );
    }

    public function testActivateRefusesDeletedRule(): void
    {
        $rule = new ProgrammationRule();
        $this->identify($rule, 42);

        $rule->softDelete();

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('flush');

        $response = $this->controller->activate(
            $this->tokenRequest(
                'activate_rule_42',
                true
            ),
            $rule,
            $em,
            $this->conflictChecker
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $rule->isActive()
        );

        self::assertSame(
            [
                'Une règle supprimée ne peut pas être réactivée.',
            ],
            $this->session
                ->getFlashBag()
                ->get('danger')
        );
    }

    public function testActivateDoesNothingWhenRuleIsAlreadyActive(): void
    {
        $rule = new ProgrammationRule();
        $this->identify($rule, 42);

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('flush');

        $response = $this->controller->activate(
            $this->tokenRequest(
                'activate_rule_42',
                true
            ),
            $rule,
            $em,
            $this->conflictChecker
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertTrue(
            $rule->isActive()
        );

        self::assertSame(
            ['Cette règle est déjà active.'],
            $this->session
                ->getFlashBag()
                ->get('warning')
        );
    }


// =========================================================
// SUPPRESSION D'UNE RÈGLE
// =========================================================

    /**
     * @dataProvider csrfCases
     */
    public function testDeleteSoftDeletesAndInvalidatesOnlyWithValidCsrf(
        bool $valid
    ): void {
        $rule = new ProgrammationRule();
        $this->identify($rule, 42);

        if ($valid) {
            $this->mutationService
                ->expects(self::once())
                ->method('invalidateRule')
                ->with($rule)
                ->willReturn([
                    'unpublishedWeeks' => [],
                    'synchronization' => [],
                ]);
        } else {
            $this->mutationService
                ->expects(self::never())
                ->method('invalidateRule');
        }

        self::assertTrue(
            $this->controller
                ->delete(
                    $this->tokenRequest(
                        'delete_rule_42',
                        $valid
                    ),
                    $rule,
                    $this->mutationService
                )
                ->isRedirect()
        );

        self::assertSame(
            $valid,
            $rule->isDeleted()
        );

        self::assertSame(
            [
                'admin_programmationRule_index',
                [],
            ],
            $this->redirect
        );

        self::assertCount(
            $valid ? 1 : 0,
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }


    public static function csrfCases(): iterable
    {
        yield 'valide' => [true];
        yield 'invalide' => [false];
    }


    private function weeklySlot(
        ProgrammationRule $rule,
        int $dayOfWeek,
        string $startTime,
        int $duration
    ): ProgrammationRuleSlot {
        return (new ProgrammationRuleSlot())
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
    }

    // =========================================================
    // DÉSACTIVATION MANUELLE D'UNE RÈGLE
    // =========================================================

    public function testDeactivateDeactivatesAndInvalidatesActiveRule(): void
    {
        $rule = new ProgrammationRule();
        $this->identify($rule, 42);

        $this->mutationService
            ->expects(self::once())
            ->method('invalidateRule')
            ->with($rule)
            ->willReturn([
                'unpublishedWeeks' => [],
                'synchronization' => [],
            ]);

        self::assertTrue(
            $rule->isActive()
        );

        $response = $this->controller->deactivate(
            $this->tokenRequest(
                'deactivate_rule_42',
                true
            ),
            $rule,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $rule->isActive()
        );

        self::assertSame(
            [
                'admin_programmationRule_index',
                [],
            ],
            $this->redirect
        );

        self::assertSame(
            [
                'Règle désactivée : '
                    . $rule->getDisplayName()
                    . '.',
            ],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }


    public function testDeactivateRefusesInvalidCsrf(): void
    {
        $rule = new ProgrammationRule();
        $this->identify($rule, 42);

        $this->mutationService
            ->expects(self::never())
            ->method('invalidateRule');

        $response = $this->controller->deactivate(
            $this->tokenRequest(
                'deactivate_rule_42',
                false
            ),
            $rule,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertTrue(
            $rule->isActive()
        );

        self::assertSame(
            [
                'admin_programmationRule_index',
                [],
            ],
            $this->redirect
        );

        self::assertSame(
            ['Jeton CSRF invalide.'],
            $this->session
                ->getFlashBag()
                ->get('danger')
        );
    }

    public function testDeactivateDoesNothingWhenRuleIsAlreadyInactive(): void
    {
        $rule = (new ProgrammationRule())
            ->setIsActive(false);

        $this->identify($rule, 42);

        $this->mutationService
            ->expects(self::never())
            ->method('invalidateRule');

        $response = $this->controller->deactivate(
            $this->tokenRequest(
                'deactivate_rule_42',
                true
            ),
            $rule,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $rule->isActive()
        );

        self::assertSame(
            [
                'admin_programmationRule_index',
                [],
            ],
            $this->redirect
        );

        self::assertSame(
            ['Cette règle est déjà inactive.'],
            $this->session
                ->getFlashBag()
                ->get('warning')
        );
    }

    public function testDeactivateRefusesDeletedRule(): void
    {
        $rule = new ProgrammationRule();
        $this->identify($rule, 42);

        $rule->softDelete();

        $this->mutationService
            ->expects(self::never())
            ->method('invalidateRule');

        $response = $this->controller->deactivate(
            $this->tokenRequest(
                'deactivate_rule_42',
                true
            ),
            $rule,
            $this->mutationService
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertFalse(
            $rule->isActive()
        );

        self::assertSame(
            [
                'admin_programmationRule_index',
                [],
            ],
            $this->redirect
        );

        self::assertSame(
            [
                'Une règle supprimée ne peut pas être désactivée.',
            ],
            $this->session
                ->getFlashBag()
                ->get('danger')
        );
    }
}
