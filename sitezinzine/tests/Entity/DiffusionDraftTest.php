<?php

namespace App\Tests\Entity;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use PHPUnit\Framework\TestCase;

class DiffusionDraftTest extends TestCase
{
    public function testInitialValues(): void
    {
        $before = new \DateTimeImmutable();

        $draft = new DiffusionDraft();

        $after = new \DateTimeImmutable();

        $this->assertNull($draft->getId());
        $this->assertNull($draft->getEmission());
        $this->assertNull($draft->getSlot());
        $this->assertFalse($draft->hasSlot());
        $this->assertNull($draft->getHoraireDiffusion());
        $this->assertNull($draft->getNombreDiffusion());
        $this->assertNull($draft->getDurationMinutes());
        $this->assertNull($draft->getEndsAt());
        $this->assertNull($draft->getAssignmentGroupKey());
        $this->assertNull($draft->getPublishedAt());
        $this->assertNull($draft->getPublishedDiffusion());
        $this->assertNull($draft->getDeletedAt());

        $this->assertSame(
            DiffusionDraft::TYPE_REGULAR,
            $draft->getDraftType()
        );

        $this->assertTrue($draft->isRegular());
        $this->assertFalse($draft->isManual());

        $this->assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $draft->getPublicationStatus()
        );

        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->isPublished());
        $this->assertFalse($draft->isCancelled());
        $this->assertFalse($draft->isDeleted());

        $this->assertGreaterThanOrEqual(
            $before,
            $draft->getCreatedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $draft->getCreatedAt()
        );

        $this->assertSame(
            $draft->getCreatedAt(),
            $draft->getUpdatedAt()
        );
    }

    public function testSetEmission(): void
    {
        $draft = new DiffusionDraft();
        $emission = new Emission();

        $result = $draft->setEmission($emission);

        $this->assertSame($draft, $result);
        $this->assertSame($emission, $draft->getEmission());

        $draft->setEmission(null);

        $this->assertNull($draft->getEmission());
    }

    public function testSetSlotAndHasSlot(): void
    {
        $draft = new DiffusionDraft();
        $slot = new ProgrammationRuleSlot();

        $result = $draft->setSlot($slot);

        $this->assertSame($draft, $result);
        $this->assertSame($slot, $draft->getSlot());
        $this->assertTrue($draft->hasSlot());

        $draft->setSlot(null);

        $this->assertNull($draft->getSlot());
        $this->assertFalse($draft->hasSlot());
    }

    public function testSetHoraireDiffusion(): void
    {
        $draft = new DiffusionDraft();

        $startsAt = new \DateTimeImmutable(
            '2026-09-06 14:00:00'
        );

        $result = $draft->setHoraireDiffusion($startsAt);

        $this->assertSame($draft, $result);
        $this->assertSame(
            $startsAt,
            $draft->getHoraireDiffusion()
        );
    }

    public function testHoraireDiffusionCannotEqualEndsAt(): void
    {
        $draft = new DiffusionDraft();

        $draft->setEndsAt(
            new \DateTimeImmutable('2026-09-06 15:00:00')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'horaireDiffusion doit être avant endsAt.'
        );

        $draft->setHoraireDiffusion(
            new \DateTimeImmutable('2026-09-06 15:00:00')
        );
    }

    public function testHoraireDiffusionCannotBeAfterEndsAt(): void
    {
        $draft = new DiffusionDraft();

        $draft->setEndsAt(
            new \DateTimeImmutable('2026-09-06 15:00:00')
        );

        $this->expectException(\InvalidArgumentException::class);

        $draft->setHoraireDiffusion(
            new \DateTimeImmutable('2026-09-06 16:00:00')
        );
    }

    public function testSetNombreDiffusion(): void
    {
        $draft = new DiffusionDraft();

        $result = $draft->setNombreDiffusion(2);

        $this->assertSame($draft, $result);
        $this->assertSame(2, $draft->getNombreDiffusion());
        $this->assertSame(2, $draft->getBroadcastRank());
    }

    public function testNombreDiffusionCannotBeZero(): void
    {
        $draft = new DiffusionDraft();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'nombreDiffusion doit être supérieur ou égal à 1.'
        );

        $draft->setNombreDiffusion(0);
    }

    public function testNombreDiffusionCannotBeNegative(): void
    {
        $draft = new DiffusionDraft();

        $this->expectException(\InvalidArgumentException::class);

        $draft->setNombreDiffusion(-1);
    }

    public function testAllowedDraftTypes(): void
    {
        $this->assertSame(
            [
                DiffusionDraft::TYPE_REGULAR,
                DiffusionDraft::TYPE_MANUAL_REBROADCAST,
                DiffusionDraft::TYPE_MANUAL_SPECIAL,
                DiffusionDraft::TYPE_MANUAL_LIVE,
            ],
            DiffusionDraft::ALLOWED_TYPES
        );
    }

    public function testRegularDraftType(): void
    {
        $draft = new DiffusionDraft();

        $draft->setDraftType(DiffusionDraft::TYPE_REGULAR);

        $this->assertTrue($draft->isRegular());
        $this->assertFalse($draft->isManual());
        $this->assertFalse($draft->isManualRebroadcast());
        $this->assertFalse($draft->isManualSpecial());
        $this->assertFalse($draft->isManualLive());
    }

    public function testManualRebroadcastDraftType(): void
    {
        $draft = new DiffusionDraft();

        $draft->setDraftType(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
        );

        $this->assertFalse($draft->isRegular());
        $this->assertTrue($draft->isManual());
        $this->assertTrue($draft->isManualRebroadcast());
        $this->assertFalse($draft->isManualSpecial());
        $this->assertFalse($draft->isManualLive());
    }

    public function testManualSpecialDraftType(): void
    {
        $draft = new DiffusionDraft();

        $draft->setDraftType(
            DiffusionDraft::TYPE_MANUAL_SPECIAL
        );

        $this->assertFalse($draft->isRegular());
        $this->assertTrue($draft->isManual());
        $this->assertFalse($draft->isManualRebroadcast());
        $this->assertTrue($draft->isManualSpecial());
        $this->assertFalse($draft->isManualLive());
    }

    public function testManualLiveDraftType(): void
    {
        $draft = new DiffusionDraft();

        $draft->setDraftType(
            DiffusionDraft::TYPE_MANUAL_LIVE
        );

        $this->assertFalse($draft->isRegular());
        $this->assertTrue($draft->isManual());
        $this->assertFalse($draft->isManualRebroadcast());
        $this->assertFalse($draft->isManualSpecial());
        $this->assertTrue($draft->isManualLive());
    }

    public function testInvalidDraftTypeIsRejected(): void
    {
        $draft = new DiffusionDraft();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Type de draft invalide : invalid'
        );

        $draft->setDraftType('invalid');
    }

    public function testSetDurationMinutes(): void
    {
        $draft = new DiffusionDraft();

        $result = $draft->setDurationMinutes(60);

        $this->assertSame($draft, $result);
        $this->assertSame(60, $draft->getDurationMinutes());
    }

    public function testDurationMinutesCanBeNull(): void
    {
        $draft = new DiffusionDraft();

        $draft->setDurationMinutes(60);
        $draft->setDurationMinutes(null);

        $this->assertNull($draft->getDurationMinutes());
    }

    public function testDurationMinutesCannotBeZero(): void
    {
        $draft = new DiffusionDraft();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'durationMinutes doit être supérieur ou égal à 1.'
        );

        $draft->setDurationMinutes(0);
    }

    public function testEffectiveDurationUsesDraftDurationFirst(): void
    {
        $draft = new DiffusionDraft();
        $emission = new Emission();

        $emission->setDuree(60);

        $draft
            ->setEmission($emission)
            ->setDurationMinutes(90);

        $this->assertSame(
            90,
            $draft->getEffectiveDurationMinutes()
        );
    }

    public function testEffectiveDurationFallsBackToEmissionDuration(): void
    {
        $draft = new DiffusionDraft();
        $emission = new Emission();

        $emission->setDuree(60);

        $draft->setEmission($emission);

        $this->assertSame(
            60,
            $draft->getEffectiveDurationMinutes()
        );
    }

    public function testEffectiveDurationIsNullWithoutDurationOrEmission(): void
    {
        $draft = new DiffusionDraft();

        $this->assertNull(
            $draft->getEffectiveDurationMinutes()
        );
    }

    public function testSetEndsAt(): void
    {
        $draft = new DiffusionDraft();

        $startsAt = new \DateTimeImmutable(
            '2026-09-06 14:00:00'
        );

        $endsAt = new \DateTimeImmutable(
            '2026-09-06 15:00:00'
        );

        $draft->setHoraireDiffusion($startsAt);

        $result = $draft->setEndsAt($endsAt);

        $this->assertSame($draft, $result);
        $this->assertSame($endsAt, $draft->getEndsAt());
    }

    public function testEndsAtCannotEqualStart(): void
    {
        $draft = new DiffusionDraft();

        $startsAt = new \DateTimeImmutable(
            '2026-09-06 14:00:00'
        );

        $draft->setHoraireDiffusion($startsAt);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'endsAt doit être après horaireDiffusion.'
        );

        $draft->setEndsAt($startsAt);
    }

    public function testEndsAtCannotBeBeforeStart(): void
    {
        $draft = new DiffusionDraft();

        $draft->setHoraireDiffusion(
            new \DateTimeImmutable('2026-09-06 14:00:00')
        );

        $this->expectException(\InvalidArgumentException::class);

        $draft->setEndsAt(
            new \DateTimeImmutable('2026-09-06 13:00:00')
        );
    }

    public function testComputeEndsAtFromDuration(): void
    {
        $draft = new DiffusionDraft();

        $draft->setHoraireDiffusion(
            new \DateTimeImmutable('2026-09-06 14:00:00')
        );

        $draft->computeEndsAtFromDuration(90);

        $this->assertSame(
            '2026-09-06 15:30:00',
            $draft->getEndsAt()->format('Y-m-d H:i:s')
        );
    }

    public function testComputeEndsAtRejectsInvalidDuration(): void
    {
        $draft = new DiffusionDraft();

        $draft->setHoraireDiffusion(
            new \DateTimeImmutable('2026-09-06 14:00:00')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'durationMinutes doit être supérieur ou égal à 1.'
        );

        $draft->computeEndsAtFromDuration(0);
    }

    public function testComputeEndsAtRequiresStart(): void
    {
        $draft = new DiffusionDraft();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'horaireDiffusion doit être défini avant de calculer endsAt.'
        );

        $draft->computeEndsAtFromDuration(60);
    }

    public function testSetSchedule(): void
    {
        $draft = new DiffusionDraft();

        $startsAt = new \DateTimeImmutable(
            '2026-09-06 14:00:00'
        );

        $result = $draft->setSchedule($startsAt, 90);

        $this->assertSame($draft, $result);
        $this->assertSame(
            $startsAt,
            $draft->getHoraireDiffusion()
        );
        $this->assertSame(90, $draft->getDurationMinutes());

        $this->assertSame(
            '2026-09-06 15:30:00',
            $draft->getEndsAt()->format('Y-m-d H:i:s')
        );
    }

    public function testSetScheduleRejectsInvalidDuration(): void
    {
        $draft = new DiffusionDraft();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'durationMinutes doit être supérieur ou égal à 1.'
        );

        $draft->setSchedule(
            new \DateTimeImmutable('2026-09-06 14:00:00'),
            0
        );
    }

    public function testGetRuleWithoutSlotReturnsNull(): void
    {
        $draft = new DiffusionDraft();

        $this->assertNull($draft->getRule());
    }

    public function testGetRuleReturnsSlotRule(): void
    {
        $draft = new DiffusionDraft();

        $rule = $this->createMock(ProgrammationRule::class);
        $slot = $this->createMock(ProgrammationRuleSlot::class);

        $slot
            ->expects($this->once())
            ->method('getRule')
            ->willReturn($rule);

        $draft->setSlot($slot);

        $this->assertSame($rule, $draft->getRule());
    }

    public function testAssignmentGroupKey(): void
    {
        $draft = new DiffusionDraft();

        $result = $draft->setAssignmentGroupKey(
            'rule_42_origin_20260906_1400'
        );

        $this->assertSame($draft, $result);

        $this->assertSame(
            'rule_42_origin_20260906_1400',
            $draft->getAssignmentGroupKey()
        );

        $draft->setAssignmentGroupKey(null);

        $this->assertNull($draft->getAssignmentGroupKey());
    }

    public function testCreatedAtCanBeSetExplicitly(): void
    {
        $draft = new DiffusionDraft();

        $date = new \DateTimeImmutable(
            '2025-01-01 10:00:00'
        );

        $result = $draft->setCreatedAt($date);

        $this->assertSame($draft, $result);
        $this->assertSame($date, $draft->getCreatedAt());
    }

    public function testUpdatedAtCanBeSetExplicitly(): void
    {
        $draft = new DiffusionDraft();

        $date = new \DateTimeImmutable(
            '2025-01-01 10:00:00'
        );

        $result = $draft->setUpdatedAt($date);

        $this->assertSame($draft, $result);
        $this->assertSame($date, $draft->getUpdatedAt());
    }

    public function testMutatingMethodTouchesUpdatedAt(): void
    {
        $draft = new DiffusionDraft();

        $oldDate = new \DateTimeImmutable(
            '2000-01-01 00:00:00'
        );

        $draft->setUpdatedAt($oldDate);

        $draft->setDurationMinutes(60);

        $this->assertGreaterThan(
            $oldDate,
            $draft->getUpdatedAt()
        );
    }

    public function testAllowedPublicationStatuses(): void
    {
        $this->assertSame(
            [
                DiffusionDraft::STATUS_DRAFT,
                DiffusionDraft::STATUS_PUBLISHED,
                DiffusionDraft::STATUS_CANCELLED,
            ],
            DiffusionDraft::ALLOWED_PUBLICATION_STATUSES
        );
    }

    public function testPublicationStatuses(): void
    {
        $draft = new DiffusionDraft();

        $draft->setPublicationStatus(
            DiffusionDraft::STATUS_PUBLISHED
        );

        $this->assertTrue($draft->isPublished());
        $this->assertFalse($draft->isDraft());
        $this->assertFalse($draft->isCancelled());

        $draft->setPublicationStatus(
            DiffusionDraft::STATUS_CANCELLED
        );

        $this->assertFalse($draft->isPublished());
        $this->assertFalse($draft->isDraft());
        $this->assertTrue($draft->isCancelled());

        $draft->setPublicationStatus(
            DiffusionDraft::STATUS_DRAFT
        );

        $this->assertFalse($draft->isPublished());
        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->isCancelled());
    }

    public function testInvalidPublicationStatusIsRejected(): void
    {
        $draft = new DiffusionDraft();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Statut de publication invalide : invalid'
        );

        $draft->setPublicationStatus('invalid');
    }

    public function testPublishedAtCanBeSetAndCleared(): void
    {
        $draft = new DiffusionDraft();

        $date = new \DateTimeImmutable(
            '2026-09-06 15:00:00'
        );

        $result = $draft->setPublishedAt($date);

        $this->assertSame($draft, $result);
        $this->assertSame($date, $draft->getPublishedAt());

        $draft->setPublishedAt(null);

        $this->assertNull($draft->getPublishedAt());
    }

    public function testPublishedDiffusionCanBeSetAndCleared(): void
    {
        $draft = new DiffusionDraft();
        $diffusion = new Diffusion();

        $result = $draft->setPublishedDiffusion($diffusion);

        $this->assertSame($draft, $result);
        $this->assertSame(
            $diffusion,
            $draft->getPublishedDiffusion()
        );

        $draft->setPublishedDiffusion(null);

        $this->assertNull($draft->getPublishedDiffusion());
    }

    public function testMarkAsPublishedWithExplicitDate(): void
    {
        $draft = new DiffusionDraft();
        $diffusion = new Diffusion();

        $publishedAt = new \DateTimeImmutable(
            '2026-09-06 16:00:00'
        );

        $result = $draft->markAsPublished(
            $diffusion,
            $publishedAt
        );

        $this->assertSame($draft, $result);

        $this->assertSame(
            DiffusionDraft::STATUS_PUBLISHED,
            $draft->getPublicationStatus()
        );

        $this->assertTrue($draft->isPublished());

        $this->assertSame(
            $diffusion,
            $draft->getPublishedDiffusion()
        );

        $this->assertSame(
            $publishedAt,
            $draft->getPublishedAt()
        );
    }

    public function testMarkAsPublishedGeneratesPublicationDate(): void
    {
        $draft = new DiffusionDraft();
        $diffusion = new Diffusion();

        $before = new \DateTimeImmutable();

        $draft->markAsPublished($diffusion);

        $after = new \DateTimeImmutable();

        $this->assertNotNull($draft->getPublishedAt());

        $this->assertGreaterThanOrEqual(
            $before,
            $draft->getPublishedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $draft->getPublishedAt()
        );

        $this->assertSame(
            $diffusion,
            $draft->getPublishedDiffusion()
        );

        $this->assertTrue($draft->isPublished());
    }

    public function testSetDeletedAtAndClearIt(): void
    {
        $draft = new DiffusionDraft();

        $deletedAt = new \DateTimeImmutable(
            '2026-09-06 17:00:00'
        );

        $result = $draft->setDeletedAt($deletedAt);

        $this->assertSame($draft, $result);
        $this->assertSame(
            $deletedAt,
            $draft->getDeletedAt()
        );
        $this->assertTrue($draft->isDeleted());

        $draft->setDeletedAt(null);

        $this->assertNull($draft->getDeletedAt());
        $this->assertFalse($draft->isDeleted());
    }

    public function testSoftDelete(): void
    {
        $draft = new DiffusionDraft();

        $before = new \DateTimeImmutable();

        $result = $draft->softDelete();

        $after = new \DateTimeImmutable();

        $this->assertSame($draft, $result);
        $this->assertTrue($draft->isDeleted());
        $this->assertNotNull($draft->getDeletedAt());

        $this->assertGreaterThanOrEqual(
            $before,
            $draft->getDeletedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $draft->getDeletedAt()
        );
    }

    public function testRestore(): void
    {
        $draft = new DiffusionDraft();

        $draft->softDelete();

        $this->assertTrue($draft->isDeleted());

        $result = $draft->restore();

        $this->assertSame($draft, $result);
        $this->assertFalse($draft->isDeleted());
        $this->assertNull($draft->getDeletedAt());
    }
}