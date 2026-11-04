<?php

namespace App\Tests\Entity;

use App\Entity\Diffusion;
use App\Entity\Emission;
use PHPUnit\Framework\TestCase;

class DiffusionTest extends TestCase
{
    public function testInitialValues(): void
    {
        $before = new \DateTimeImmutable();

        $diffusion = new Diffusion();

        $after = new \DateTimeImmutable();

        $this->assertNull($diffusion->getId());
        $this->assertNull($diffusion->getEmission());
        $this->assertNull($diffusion->getHoraireDiffusion());
        $this->assertNull($diffusion->getDurationMinutes());
        $this->assertNull($diffusion->getEndsAt());
        $this->assertNull($diffusion->getAssignmentGroupKey());
        $this->assertNull($diffusion->getNombreDiffusion());

        $this->assertSame(
            Diffusion::STATUS_PUBLISHED,
            $diffusion->getPublicationStatus()
        );

        $this->assertTrue($diffusion->isPublished());
        $this->assertFalse($diffusion->isUnpublished());

        $this->assertGreaterThanOrEqual(
            $before,
            $diffusion->getCreatedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $diffusion->getCreatedAt()
        );

        $this->assertSame(
            $diffusion->getCreatedAt(),
            $diffusion->getUpdatedAt()
        );
    }

    public function testSetEmission(): void
    {
        $diffusion = new Diffusion();
        $emission = new Emission();

        $result = $diffusion->setEmission($emission);

        $this->assertSame($diffusion, $result);
        $this->assertSame($emission, $diffusion->getEmission());

        $diffusion->setEmission(null);

        $this->assertNull($diffusion->getEmission());
    }

    public function testSetHoraireDiffusion(): void
    {
        $diffusion = new Diffusion();
        $startsAt = new \DateTime('2026-09-06 14:00:00');

        $result = $diffusion->setHoraireDiffusion($startsAt);

        $this->assertSame($diffusion, $result);
        $this->assertSame(
            $startsAt,
            $diffusion->getHoraireDiffusion()
        );
    }

    public function testSetHoraireDiffusionRejectsStartEqualToEndsAt(): void
    {
        $diffusion = new Diffusion();

        $diffusion->setEndsAt(
            new \DateTime('2026-09-06 15:00:00')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'horaireDiffusion doit être avant endsAt.'
        );

        $diffusion->setHoraireDiffusion(
            new \DateTime('2026-09-06 15:00:00')
        );
    }

    public function testSetHoraireDiffusionRejectsStartAfterEndsAt(): void
    {
        $diffusion = new Diffusion();

        $diffusion->setEndsAt(
            new \DateTime('2026-09-06 15:00:00')
        );

        $this->expectException(\InvalidArgumentException::class);

        $diffusion->setHoraireDiffusion(
            new \DateTime('2026-09-06 16:00:00')
        );
    }

    public function testSetNombreDiffusion(): void
    {
        $diffusion = new Diffusion();

        $result = $diffusion->setNombreDiffusion(2);

        $this->assertSame($diffusion, $result);
        $this->assertSame(2, $diffusion->getNombreDiffusion());
    }

    public function testNombreDiffusionCanBeZero(): void
    {
        $diffusion = new Diffusion();

        $diffusion->setNombreDiffusion(0);

        $this->assertSame(0, $diffusion->getNombreDiffusion());
    }

    public function testNegativeNombreDiffusionIsRejected(): void
    {
        $diffusion = new Diffusion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'nombreDiffusion doit être supérieur ou égal à 0.'
        );

        $diffusion->setNombreDiffusion(-1);
    }

    public function testSetDurationMinutes(): void
    {
        $diffusion = new Diffusion();

        $result = $diffusion->setDurationMinutes(60);

        $this->assertSame($diffusion, $result);
        $this->assertSame(60, $diffusion->getDurationMinutes());
    }

    public function testDurationMinutesCanBeNull(): void
    {
        $diffusion = new Diffusion();

        $diffusion->setDurationMinutes(60);
        $diffusion->setDurationMinutes(null);

        $this->assertNull($diffusion->getDurationMinutes());
    }

    public function testDurationMinutesCannotBeZero(): void
    {
        $diffusion = new Diffusion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'durationMinutes doit être supérieur ou égal à 1.'
        );

        $diffusion->setDurationMinutes(0);
    }

    public function testDurationMinutesCannotBeNegative(): void
    {
        $diffusion = new Diffusion();

        $this->expectException(\InvalidArgumentException::class);

        $diffusion->setDurationMinutes(-15);
    }

    public function testSetEndsAt(): void
    {
        $diffusion = new Diffusion();

        $startsAt = new \DateTime('2026-09-06 14:00:00');
        $endsAt = new \DateTime('2026-09-06 15:00:00');

        $diffusion->setHoraireDiffusion($startsAt);

        $result = $diffusion->setEndsAt($endsAt);

        $this->assertSame($diffusion, $result);
        $this->assertSame($endsAt, $diffusion->getEndsAt());
    }

    public function testEndsAtCanBeNull(): void
    {
        $diffusion = new Diffusion();

        $diffusion->setHoraireDiffusion(
            new \DateTime('2026-09-06 14:00:00')
        );

        $diffusion->setEndsAt(
            new \DateTime('2026-09-06 15:00:00')
        );

        $diffusion->setEndsAt(null);

        $this->assertNull($diffusion->getEndsAt());
    }

    public function testEndsAtCannotEqualStart(): void
    {
        $diffusion = new Diffusion();

        $diffusion->setHoraireDiffusion(
            new \DateTime('2026-09-06 14:00:00')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'endsAt doit être après horaireDiffusion.'
        );

        $diffusion->setEndsAt(
            new \DateTime('2026-09-06 14:00:00')
        );
    }

    public function testEndsAtCannotBeBeforeStart(): void
    {
        $diffusion = new Diffusion();

        $diffusion->setHoraireDiffusion(
            new \DateTime('2026-09-06 14:00:00')
        );

        $this->expectException(\InvalidArgumentException::class);

        $diffusion->setEndsAt(
            new \DateTime('2026-09-06 13:59:00')
        );
    }

    public function testSetSchedule(): void
    {
        $diffusion = new Diffusion();

        $startsAt = new \DateTime('2026-09-06 14:00:00');

        $result = $diffusion->setSchedule(
            $startsAt,
            90
        );

        $this->assertSame($diffusion, $result);

        $this->assertInstanceOf(
            \DateTime::class,
            $diffusion->getHoraireDiffusion()
        );

        $this->assertNotSame(
            $startsAt,
            $diffusion->getHoraireDiffusion()
        );

        $this->assertSame(
            '2026-09-06 14:00:00',
            $diffusion->getHoraireDiffusion()->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            90,
            $diffusion->getDurationMinutes()
        );

        $this->assertNotNull($diffusion->getEndsAt());

        $this->assertSame(
            '2026-09-06 15:30:00',
            $diffusion->getEndsAt()->format('Y-m-d H:i:s')
        );
    }

    public function testSetScheduleAcceptsImmutableStart(): void
    {
        $diffusion = new Diffusion();

        $startsAt = new \DateTimeImmutable(
            '2026-09-06 20:00:00'
        );

        $diffusion->setSchedule($startsAt, 45);

        $this->assertInstanceOf(
            \DateTime::class,
            $diffusion->getHoraireDiffusion()
        );

        $this->assertSame(
            '2026-09-06 20:00:00',
            $diffusion->getHoraireDiffusion()->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            45,
            $diffusion->getDurationMinutes()
        );

        $this->assertNotNull($diffusion->getEndsAt());

        $this->assertInstanceOf(
            \DateTime::class,
            $diffusion->getEndsAt()
        );

        $this->assertSame(
            '2026-09-06 20:45:00',
            $diffusion->getEndsAt()->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-09-06 20:00:00',
            $startsAt->format('Y-m-d H:i:s')
        );
    }

    public function testSetScheduleRejectsInvalidDuration(): void
    {
        $diffusion = new Diffusion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'durationMinutes doit être supérieur ou égal à 1.'
        );

        $diffusion->setSchedule(
            new \DateTime('2026-09-06 14:00:00'),
            0
        );
    }

    public function testAssignmentGroupKey(): void
    {
        $diffusion = new Diffusion();

        $result = $diffusion->setAssignmentGroupKey(
            'rule_42_origin_20260906_1400'
        );

        $this->assertSame($diffusion, $result);

        $this->assertSame(
            'rule_42_origin_20260906_1400',
            $diffusion->getAssignmentGroupKey()
        );

        $diffusion->setAssignmentGroupKey(null);

        $this->assertNull($diffusion->getAssignmentGroupKey());
    }

    public function testCreatedAtCanBeSetExplicitly(): void
    {
        $diffusion = new Diffusion();

        $date = new \DateTimeImmutable(
            '2025-01-01 10:00:00'
        );

        $result = $diffusion->setCreatedAt($date);

        $this->assertSame($diffusion, $result);
        $this->assertSame($date, $diffusion->getCreatedAt());
    }

    public function testUpdatedAtCanBeSetExplicitly(): void
    {
        $diffusion = new Diffusion();

        $date = new \DateTimeImmutable(
            '2025-01-01 10:00:00'
        );

        $result = $diffusion->setUpdatedAt($date);

        $this->assertSame($diffusion, $result);
        $this->assertSame($date, $diffusion->getUpdatedAt());
    }

    public function testMutatingMethodsTouchUpdatedAt(): void
    {
        $diffusion = new Diffusion();

        $oldDate = new \DateTimeImmutable(
            '2000-01-01 00:00:00'
        );

        $diffusion->setUpdatedAt($oldDate);

        $diffusion->setDurationMinutes(60);

        $this->assertGreaterThan(
            $oldDate,
            $diffusion->getUpdatedAt()
        );
    }

    public function testAllowedPublicationStatuses(): void
    {
        $this->assertSame(
            [
                Diffusion::STATUS_PUBLISHED,
                Diffusion::STATUS_UNPUBLISHED,
            ],
            Diffusion::ALLOWED_PUBLICATION_STATUSES
        );
    }

    public function testSetPublicationStatusToUnpublished(): void
    {
        $diffusion = new Diffusion();

        $result = $diffusion->setPublicationStatus(
            Diffusion::STATUS_UNPUBLISHED
        );

        $this->assertSame($diffusion, $result);

        $this->assertSame(
            Diffusion::STATUS_UNPUBLISHED,
            $diffusion->getPublicationStatus()
        );

        $this->assertFalse($diffusion->isPublished());
        $this->assertTrue($diffusion->isUnpublished());
    }

    public function testSetPublicationStatusToPublished(): void
    {
        $diffusion = new Diffusion();

        $diffusion->setPublicationStatus(
            Diffusion::STATUS_UNPUBLISHED
        );

        $diffusion->setPublicationStatus(
            Diffusion::STATUS_PUBLISHED
        );

        $this->assertTrue($diffusion->isPublished());
        $this->assertFalse($diffusion->isUnpublished());
    }

    public function testInvalidPublicationStatusIsRejected(): void
    {
        $diffusion = new Diffusion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Statut de publication invalide : invalid'
        );

        $diffusion->setPublicationStatus('invalid');
    }

    public function testMarkAsUnpublished(): void
    {
        $diffusion = new Diffusion();

        $result = $diffusion->markAsUnpublished();

        $this->assertSame($diffusion, $result);

        $this->assertSame(
            Diffusion::STATUS_UNPUBLISHED,
            $diffusion->getPublicationStatus()
        );

        $this->assertTrue($diffusion->isUnpublished());
        $this->assertFalse($diffusion->isPublished());
    }

    public function testMarkAsPublished(): void
    {
        $diffusion = new Diffusion();

        $diffusion->markAsUnpublished();

        $result = $diffusion->markAsPublished();

        $this->assertSame($diffusion, $result);

        $this->assertSame(
            Diffusion::STATUS_PUBLISHED,
            $diffusion->getPublicationStatus()
        );

        $this->assertTrue($diffusion->isPublished());
        $this->assertFalse($diffusion->isUnpublished());
    }
}
