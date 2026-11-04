<?php

namespace App\Tests\Entity;

use App\Entity\Categories;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use PHPUnit\Framework\TestCase;

class ProgrammationRuleTest extends TestCase
{
    public function testInitialValues(): void
    {
        $before = new \DateTimeImmutable();

        $rule = new ProgrammationRule();

        $after = new \DateTimeImmutable();

        $this->assertNull($rule->getId());
        $this->assertNull($rule->getCategory());
        $this->assertNull($rule->getRuleNumber());
        $this->assertNull($rule->getValidFrom());
        $this->assertNull($rule->getValidUntil());
        $this->assertTrue($rule->isActive());
        $this->assertTrue($rule->getIsActive());
        $this->assertFalse($rule->hasAdditionalRebroadcast());
        $this->assertNull($rule->getDeletedAt());
        $this->assertFalse($rule->isDeleted());
        $this->assertCount(0, $rule->getSlots());
        $this->assertSame(0, $rule->getActiveSlotsCount());

        $this->assertSame(
            'Catégorie règle N° ?',
            $rule->getDisplayName()
        );

        $this->assertSame(
            'Catégorie règle N° ?',
            (string) $rule
        );

        $this->assertGreaterThanOrEqual(
            $before,
            $rule->getCreatedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $rule->getCreatedAt()
        );

        $this->assertSame(
            $rule->getCreatedAt(),
            $rule->getUpdatedAt()
        );
    }

    public function testDisplayNameUsesCategoryAndRuleNumber(): void
    {
        $category = new Categories();
        $category->setTitre('Musique');

        $rule = new ProgrammationRule();

        $rule
            ->setCategory($category)
            ->setRuleNumber(3);

        $this->assertSame(
            'Musique règle N° 3',
            $rule->getDisplayName()
        );

        $this->assertSame(
            'Musique règle N° 3',
            (string) $rule
        );
    }

    public function testBasicGettersAndSetters(): void
    {
        $rule = new ProgrammationRule();
        $category = new Categories();

        $validFrom = new \DateTimeImmutable('2026-01-01');
        $validUntil = new \DateTimeImmutable('2026-12-31');

        $result = $rule
            ->setCategory($category)
            ->setRuleNumber(2)
            ->setValidFrom($validFrom)
            ->setValidUntil($validUntil)
            ->setIsActive(false);

        $this->assertSame($rule, $result);
        $this->assertSame($category, $rule->getCategory());
        $this->assertSame(2, $rule->getRuleNumber());
        $this->assertSame($validFrom, $rule->getValidFrom());
        $this->assertSame($validUntil, $rule->getValidUntil());
        $this->assertFalse($rule->isActive());
        $this->assertFalse($rule->getIsActive());
    }

    public function testNullableFieldsCanBeCleared(): void
    {
        $rule = new ProgrammationRule();

        $rule
            ->setCategory(new Categories())
            ->setValidFrom(new \DateTimeImmutable('2026-01-01'))
            ->setValidUntil(new \DateTimeImmutable('2026-12-31'));

        $rule
            ->setCategory(null)
            ->setValidFrom(null)
            ->setValidUntil(null);

        $this->assertNull($rule->getCategory());
        $this->assertNull($rule->getValidFrom());
        $this->assertNull($rule->getValidUntil());
    }

    public function testSetDeletedAtDisablesRule(): void
    {
        $rule = new ProgrammationRule();

        $deletedAt = new \DateTimeImmutable(
            '2026-09-06 14:00:00'
        );

        $result = $rule->setDeletedAt($deletedAt);

        $this->assertSame($rule, $result);
        $this->assertSame($deletedAt, $rule->getDeletedAt());
        $this->assertTrue($rule->isDeleted());
        $this->assertFalse($rule->isActive());
    }

    public function testClearingDeletedAtDoesNotReactivateRuleAutomatically(): void
    {
        $rule = new ProgrammationRule();

        $rule->setDeletedAt(
            new \DateTimeImmutable('2026-09-06 14:00:00')
        );

        $rule->setDeletedAt(null);

        $this->assertNull($rule->getDeletedAt());
        $this->assertFalse($rule->isDeleted());

        // C'est le comportement actuel :
        // setDeletedAt(null) ne remet pas isActive à true.
        $this->assertFalse($rule->isActive());
    }

    public function testCreatedAtCanBeSetExplicitly(): void
    {
        $rule = new ProgrammationRule();

        $date = new \DateTimeImmutable(
            '2025-01-01 10:00:00'
        );

        $result = $rule->setCreatedAt($date);

        $this->assertSame($rule, $result);
        $this->assertSame($date, $rule->getCreatedAt());
    }

    public function testUpdatedAtCanBeSetExplicitly(): void
    {
        $rule = new ProgrammationRule();

        $date = new \DateTimeImmutable(
            '2025-01-01 10:00:00'
        );

        $result = $rule->setUpdatedAt($date);

        $this->assertSame($rule, $result);
        $this->assertSame($date, $rule->getUpdatedAt());
    }

    public function testMutatingMethodTouchesUpdatedAt(): void
    {
        $rule = new ProgrammationRule();

        $oldDate = new \DateTimeImmutable(
            '2000-01-01 00:00:00'
        );

        $rule->setUpdatedAt($oldDate);

        $rule->setRuleNumber(1);

        $this->assertGreaterThan(
            $oldDate,
            $rule->getUpdatedAt()
        );
    }

    public function testAddSlotMaintainsOwningSide(): void
    {
        $rule = new ProgrammationRule();
        $slot = new ProgrammationRuleSlot();

        $result = $rule->addSlot($slot);

        $this->assertSame($rule, $result);
        $this->assertCount(1, $rule->getSlots());
        $this->assertTrue($rule->getSlots()->contains($slot));
        $this->assertSame($rule, $slot->getRule());
    }

    public function testAddSlotDoesNotDuplicateSlot(): void
    {
        $rule = new ProgrammationRule();
        $slot = new ProgrammationRuleSlot();

        $rule->addSlot($slot);
        $rule->addSlot($slot);

        $this->assertCount(1, $rule->getSlots());
    }

    public function testRemoveSlotClearsOwningSide(): void
    {
        $rule = new ProgrammationRule();
        $slot = new ProgrammationRuleSlot();

        $rule->addSlot($slot);

        $result = $rule->removeSlot($slot);

        $this->assertSame($rule, $result);
        $this->assertCount(0, $rule->getSlots());
        $this->assertNull($slot->getRule());
    }

    public function testRemoveSlotDoesNotClearDifferentRule(): void
    {
        $rule = new ProgrammationRule();
        $otherRule = new ProgrammationRule();

        $slot = new ProgrammationRuleSlot();

        $rule->addSlot($slot);

        $slot->setRule($otherRule);

        $rule->removeSlot($slot);

        $this->assertCount(0, $rule->getSlots());
        $this->assertSame($otherRule, $slot->getRule());
    }

    public function testRemovingUnknownSlotDoesNothing(): void
    {
        $rule = new ProgrammationRule();
        $slot = new ProgrammationRuleSlot();

        $result = $rule->removeSlot($slot);

        $this->assertSame($rule, $result);
        $this->assertCount(0, $rule->getSlots());
    }

    public function testSoftDeleteRule(): void
    {
        $rule = new ProgrammationRule();

        $before = new \DateTimeImmutable();

        $result = $rule->softDelete();

        $after = new \DateTimeImmutable();

        $this->assertSame($rule, $result);
        $this->assertTrue($rule->isDeleted());
        $this->assertFalse($rule->isActive());
        $this->assertNotNull($rule->getDeletedAt());

        $this->assertGreaterThanOrEqual(
            $before,
            $rule->getDeletedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $rule->getDeletedAt()
        );

        $this->assertSame(
            $rule->getDeletedAt(),
            $rule->getUpdatedAt()
        );
    }

    public function testSoftDeleteAlsoDeletesActiveSlots(): void
    {
        $rule = new ProgrammationRule();

        $activeSlot = $this->createMock(ProgrammationRuleSlot::class);
        $alreadyDeletedSlot = $this->createMock(ProgrammationRuleSlot::class);

        $activeSlot
            ->expects($this->once())
            ->method('setRule')
            ->with($rule)
            ->willReturnSelf();

        $alreadyDeletedSlot
            ->expects($this->once())
            ->method('setRule')
            ->with($rule)
            ->willReturnSelf();

        $rule->addSlot($activeSlot);
        $rule->addSlot($alreadyDeletedSlot);

        $activeSlot
            ->expects($this->once())
            ->method('isDeleted')
            ->willReturn(false);

        $activeSlot
            ->expects($this->once())
            ->method('softDelete')
            ->willReturnSelf();

        $alreadyDeletedSlot
            ->expects($this->once())
            ->method('isDeleted')
            ->willReturn(true);

        $alreadyDeletedSlot
            ->expects($this->never())
            ->method('softDelete');

        $rule->softDelete();

        $this->assertTrue($rule->isDeleted());
    }

    public function testRestoreRule(): void
    {
        $rule = new ProgrammationRule();

        $rule->softDelete();

        $this->assertTrue($rule->isDeleted());
        $this->assertFalse($rule->isActive());

        $result = $rule->restore();

        $this->assertSame($rule, $result);
        $this->assertFalse($rule->isDeleted());
        $this->assertNull($rule->getDeletedAt());
        $this->assertTrue($rule->isActive());
    }

    public function testCurrentlyValidWithoutDates(): void
    {
        $rule = new ProgrammationRule();

        $this->assertTrue(
            $rule->isCurrentlyValid(
                new \DateTimeImmutable('2026-09-06')
            )
        );
    }

    public function testInactiveRuleIsNotCurrentlyValid(): void
    {
        $rule = new ProgrammationRule();

        $rule->setIsActive(false);

        $this->assertFalse(
            $rule->isCurrentlyValid(
                new \DateTimeImmutable('2026-09-06')
            )
        );
    }

    public function testDeletedRuleIsNotCurrentlyValid(): void
    {
        $rule = new ProgrammationRule();

        $rule->setDeletedAt(
            new \DateTimeImmutable('2026-09-01')
        );

        $this->assertFalse(
            $rule->isCurrentlyValid(
                new \DateTimeImmutable('2026-09-06')
            )
        );
    }

    public function testDateBeforeValidFromIsNotValid(): void
    {
        $rule = new ProgrammationRule();

        $rule->setValidFrom(
            new \DateTimeImmutable('2026-09-10')
        );

        $this->assertFalse(
            $rule->isCurrentlyValid(
                new \DateTimeImmutable('2026-09-09')
            )
        );
    }

    public function testValidFromDateIsInclusive(): void
    {
        $rule = new ProgrammationRule();

        $date = new \DateTimeImmutable('2026-09-10');

        $rule->setValidFrom($date);

        $this->assertTrue(
            $rule->isCurrentlyValid($date)
        );
    }

    public function testDateAfterValidUntilIsNotValid(): void
    {
        $rule = new ProgrammationRule();

        $rule->setValidUntil(
            new \DateTimeImmutable('2026-09-10')
        );

        $this->assertFalse(
            $rule->isCurrentlyValid(
                new \DateTimeImmutable('2026-09-11')
            )
        );
    }

    public function testValidUntilDateIsInclusive(): void
    {
        $rule = new ProgrammationRule();

        $date = new \DateTimeImmutable('2026-09-10');

        $rule->setValidUntil($date);

        $this->assertTrue(
            $rule->isCurrentlyValid($date)
        );
    }

    public function testDateInsideValidityRangeIsValid(): void
    {
        $rule = new ProgrammationRule();

        $rule
            ->setValidFrom(
                new \DateTimeImmutable('2026-09-01')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2026-09-30')
            );

        $this->assertTrue(
            $rule->isCurrentlyValid(
                new \DateTimeImmutable('2026-09-15')
            )
        );
    }

    public function testActiveSlotsCountIgnoresDeletedSlots(): void
    {
        $rule = new ProgrammationRule();

        $activeSlot = $this->createMock(ProgrammationRuleSlot::class);
        $deletedSlot = $this->createMock(ProgrammationRuleSlot::class);

        $activeSlot
            ->method('setRule')
            ->willReturnSelf();

        $deletedSlot
            ->method('setRule')
            ->willReturnSelf();

        $rule->addSlot($activeSlot);
        $rule->addSlot($deletedSlot);

        $activeSlot
            ->method('getDeletedAt')
            ->willReturn(null);

        $deletedSlot
            ->method('getDeletedAt')
            ->willReturn(
                new \DateTimeImmutable('2026-09-01')
            );

        $this->assertSame(
            1,
            $rule->getActiveSlotsCount()
        );
    }

    public function testAdditionalRebroadcastCanBeEnabledAndDisabled(): void
    {
        $rule = new ProgrammationRule();

        $this->assertFalse($rule->hasAdditionalRebroadcast());

        $oldDate = new \DateTimeImmutable('2000-01-01 00:00:00');
        $rule->setUpdatedAt($oldDate);

        $result = $rule->setHasAdditionalRebroadcast(true);

        $this->assertSame($rule, $result);
        $this->assertTrue($rule->hasAdditionalRebroadcast());
        $this->assertGreaterThan($oldDate, $rule->getUpdatedAt());

        $result = $rule->setHasAdditionalRebroadcast(false);

        $this->assertSame($rule, $result);
        $this->assertFalse($rule->hasAdditionalRebroadcast());
    }
}
