<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\ProgrammationRule;
use App\Form\ProgrammationRuleType;
use App\Tests\Support\FormIntegrationTestCase;

class ProgrammationRuleTypeTest extends FormIntegrationTestCase
{
    public function testCreateSelectsActiveCategoryAndMapsImmutableDates(): void
    {
        $active = $this->category();
        $inactive = $this->category(false);
        $deleted = $this->category(true, true);

        $rule = new ProgrammationRule();

        $form = $this->form(
            ProgrammationRuleType::class,
            $rule
        );

        $values = $this->choiceValues(
            $form->get('category')
        );

        self::assertContains(
            (string) $active->getId(),
            $values
        );

        self::assertNotContains(
            (string) $inactive->getId(),
            $values
        );

        self::assertNotContains(
            (string) $deleted->getId(),
            $values
        );

        self::assertFalse($form->has('isActive'));

        $form->submit([
            'category' => (string) $active->getId(),
            'validFrom' => '2026-10-01',
            'validUntil' => '2026-12-31',
        ]);

        $this->assertValidForm($form);

        self::assertSame(
            $active,
            $rule->getCategory()
        );

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $rule->getValidFrom()
        );

        self::assertSame(
            '2026-10-01',
            $rule->getValidFrom()->format('Y-m-d')
        );

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $rule->getValidUntil()
        );

        self::assertSame(
            '2026-12-31',
            $rule->getValidUntil()->format('Y-m-d')
        );
    }

    public function testEditCannotChangeCategoryAndCanClearDates(): void
    {
        $category = $this->category();
        $other = $this->category();

        $rule = $this->save(
            (new ProgrammationRule())
                ->setCategory($category)
                ->setRuleNumber(1)
        );

        $initialActiveState = $rule->isActive();

        $form = $this->form(
            ProgrammationRuleType::class,
            $rule
        );

        self::assertTrue(
            $form->get('category')->isDisabled()
        );

        self::assertFalse($form->has('isActive'));

        $form->submit([
            'category' => (string) $other->getId(),
            'validFrom' => '',
            'validUntil' => '',
        ]);

        $this->assertValidForm($form);

        self::assertSame(
            $category,
            $rule->getCategory()
        );

        self::assertNull(
            $rule->getValidFrom()
        );

        self::assertNull(
            $rule->getValidUntil()
        );

        self::assertSame(
            $initialActiveState,
            $rule->isActive()
        );
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $form = $this->form(ProgrammationRuleType::class, new ProgrammationRule());
        $form->submit(['category' => '2147483647']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('category')->getErrors()));
    }

    public function testAdditionalRebroadcastCanBeEnabledOnCreate(): void
    {
        $category = $this->category();
        $rule = new ProgrammationRule();

        $form = $this->form(
            ProgrammationRuleType::class,
            $rule
        );

        self::assertTrue($form->has('hasAdditionalRebroadcast'));
        self::assertFalse(
            $form->get('hasAdditionalRebroadcast')->isRequired()
        );

        $form->submit([
            'category' => (string) $category->getId(),
            'validFrom' => '',
            'validUntil' => '',
            'hasAdditionalRebroadcast' => '1',
        ]);

        $this->assertValidForm($form);

        self::assertTrue($rule->hasAdditionalRebroadcast());
    }

    public function testAdditionalRebroadcastCanBeDisabledOnEdit(): void
    {
        $category = $this->category();

        $rule = $this->save(
            (new ProgrammationRule())
                ->setCategory($category)
                ->setRuleNumber(1)
                ->setHasAdditionalRebroadcast(true)
        );

        self::assertTrue($rule->hasAdditionalRebroadcast());

        $form = $this->form(
            ProgrammationRuleType::class,
            $rule
        );

        $form->submit([
            'validFrom' => '',
            'validUntil' => '',
        ]);

        $this->assertValidForm($form);

        self::assertFalse($rule->hasAdditionalRebroadcast());
    }
}
