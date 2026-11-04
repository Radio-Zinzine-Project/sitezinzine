<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\ProgrammationRuleSlot;
use App\Form\ProgrammationRuleSlotType;
use App\Tests\Support\FormIntegrationTestCase;

class ProgrammationRuleSlotTypeTest extends FormIntegrationTestCase
{
    private function payload(): array
    {
        return ['recurrenceType' => 'weekly', 'monthlyOccurrence' => '', 'monthInterval' => '1',
            'dayOfWeek' => '2', 'startTime' => ['hour' => '10', 'minute' => '5'],
            'durationMinutes' => '60', 'broadcastRank' => '1', 'weekOffset' => '0', 'weekParity' => '', 'isActive' => '1'];
    }
    public function testWeeklySubmissionTransformsTimeAndNumericChoices(): void
    {
        $slot = new ProgrammationRuleSlot();
        $form = $this->form(ProgrammationRuleSlotType::class, $slot);
        $form->submit($this->payload());
        $this->assertValidForm($form);
        self::assertSame(2, $slot->getDayOfWeek());
        self::assertSame(0, $slot->getWeekOffset());
        self::assertSame(1, $slot->getBroadcastRank());
        self::assertSame(60, $slot->getDurationMinutes());
        self::assertInstanceOf(\DateTimeImmutable::class, $slot->getStartTime());
        self::assertSame('10:05', $slot->getStartTime()->format('H:i'));
        self::assertNull($slot->getMonthlyOccurrence());
        self::assertNull($slot->getWeekParity());
    }
    public function testMonthlyLastOccurrenceAndInterval(): void
    {
        $slot = new ProgrammationRuleSlot();
        $form = $this->form(ProgrammationRuleSlotType::class, $slot);
        $form->submit(array_replace($this->payload(), ['recurrenceType' => 'monthly', 'monthlyOccurrence' => '-1', 'monthInterval' => '3', 'isActive' => null]));
        $this->assertValidForm($form);
        self::assertSame(-1, $slot->getMonthlyOccurrence());
        self::assertSame(3, $slot->getMonthInterval());
        self::assertFalse($slot->isActive());
    }
    /** @dataProvider invalidValues */
    public function testInvalidChoicesAndTransformationsAreRejected(string $field, mixed $value): void
    {
        $form = $this->form(ProgrammationRuleSlotType::class, new ProgrammationRuleSlot());
        $form->submit(array_replace($this->payload(), [$field => $value]));
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get($field)->getErrors(true)));
    }
    public static function invalidValues(): iterable
    {
        yield 'récurrence' => ['recurrenceType', 'daily'];
        yield 'jour' => ['dayOfWeek', '8'];
        yield 'rang' => ['broadcastRank', '5'];
        yield 'décalage' => ['weekOffset', '5'];
        yield 'parité' => ['weekParity', 'invalid'];
        yield 'occurrence' => ['monthlyOccurrence', '8'];
        yield 'intervalle' => ['monthInterval', '0'];
        yield 'minute hors liste' => ['startTime', ['hour' => '10', 'minute' => '7']];
        yield 'durée absente' => ['durationMinutes', ''];
        yield 'durée non numérique' => ['durationMinutes', 'abc'];
    }
}
