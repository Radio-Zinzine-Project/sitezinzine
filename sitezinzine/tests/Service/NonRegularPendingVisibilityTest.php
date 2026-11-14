<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Service\NonRegularPendingVisibility;
use App\Service\NonRegularScheduledOccurrences;
use PHPUnit\Framework\TestCase;

final class NonRegularPendingVisibilityTest extends TestCase
{
    public function testOrphanedHistoryDoesNotInvalidateActiveDatesOrIncreaseCount(): void
    {
        $emission = $this->emission();
        $drafts = [
            $this->draft($emission, '2026-11-07 09:30:00'),
            $this->draft($emission, '2026-11-14 17:00:00'),
        ];
        $history = [$this->diffusion($emission, false)];
        $summary = NonRegularScheduledOccurrences::summarize(1, 'manual_test', $drafts, $history);

        self::assertSame(2, $summary['count']);
        self::assertFalse($summary['complete']);
        self::assertTrue($summary['datesComplete']);
        self::assertSame('2026-11-14 17:00:00', $summary['last']->format('Y-m-d H:i:s'));
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey('manual_test');
        self::assertSame([], $this->visible($pending, '2026-11-10', $drafts, $history));
        self::assertSame([$pending], $this->visible($pending, '2026-11-17', $drafts, $history));
    }

    public function testMissingActiveDraftDateRetainsLegacyVisibilityDespiteAnotherKnownDate(): void
    {
        $emission = $this->emission();
        $drafts = [$this->draft($emission, '2026-11-14 17:00:00'), $this->draft($emission)];
        $summary = NonRegularScheduledOccurrences::summarize(1, 'manual_test', $drafts, []);
        self::assertSame(2, $summary['count']);
        self::assertFalse($summary['datesComplete']);
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey('manual_test');
        self::assertSame([$pending], $this->visible($pending, '2026-11-10', $drafts, []));
    }

    public function testMissingPublishedDateRetainsLegacyVisibility(): void
    {
        $emission = $this->emission();
        $history = [$this->diffusion($emission, true)];
        $summary = NonRegularScheduledOccurrences::summarize(1, 'manual_test', [], $history);
        self::assertSame(1, $summary['count']);
        self::assertFalse($summary['datesComplete']);
        self::assertNull($summary['last']);
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey('manual_test');
        self::assertSame([$pending], $this->visible($pending, '2026-11-10', [], $history));
    }

    public function testOrphanedHistoryWithoutActiveOccurrenceRetainsLegacyVisibility(): void
    {
        $emission = $this->emission();
        $history = [$this->diffusion($emission, false)];
        $summary = NonRegularScheduledOccurrences::summarize(1, 'manual_test', [], $history);
        self::assertSame(0, $summary['count']);
        self::assertNull($summary['last']);
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey('manual_test');
        self::assertSame([$pending], $this->visible($pending, '2026-11-10', [], $history));
    }

    public function testRegularGroupIsNotFilteredByNonRegularVisibility(): void
    {
        $emission = $this->emission();
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey('rule_1_origin_20261114_1700');
        $draft = $this->draft($emission, '2026-11-14 17:00:00')->setAssignmentGroupKey($pending->getAssignmentGroupKey());
        self::assertSame([$pending], $this->visible($pending, '2026-11-10', [$draft], []));
    }

    public function testRemovedOrCancelledUndatedDraftDoesNotInvalidateActiveDates(): void
    {
        $emission = $this->emission();
        $active = $this->draft($emission, '2026-11-14 17:00:00');
        $removed = $this->draft($emission)->softDelete();
        $cancelled = $this->draft($emission)->setPublicationStatus(DiffusionDraft::STATUS_CANCELLED);
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey('manual_test');

        foreach ([$removed, $cancelled] as $inactive) {
            $drafts = [$active, $inactive];
            $summary = NonRegularScheduledOccurrences::summarize(1, 'manual_test', $drafts, []);
            self::assertSame(1, $summary['count']);
            self::assertTrue($summary['datesComplete']);
            self::assertTrue($summary['complete']);
            self::assertSame('2026-11-14 17:00:00', $summary['last']->format('Y-m-d H:i:s'));
            self::assertSame([], $this->visible($pending, '2026-11-10', $drafts, []));
            self::assertSame([$pending], $this->visible($pending, '2026-11-17', $drafts, []));
        }
    }

    public function testDatedPublishedDiffusionWithoutDraftDeterminesVisibility(): void
    {
        $emission = $this->emission();
        $date = new \DateTimeImmutable('2026-11-14 17:00:00', new \DateTimeZone('Europe/Paris'));
        $diffusion = $this->diffusion($emission, true);
        $diffusion->method('getHoraireDiffusion')->willReturn($date);
        $history = [$diffusion];
        $summary = NonRegularScheduledOccurrences::summarize(1, 'manual_test', [], $history);

        self::assertSame(1, $summary['count']);
        self::assertTrue($summary['datesComplete']);
        self::assertTrue($summary['complete']);
        self::assertSame('2026-11-14 17:00:00', $summary['last']->format('Y-m-d H:i:s'));
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey('manual_test');
        self::assertSame([], $this->visible($pending, '2026-11-10', [], $history));
        self::assertSame([$pending], $this->visible($pending, '2026-11-17', [], $history));
    }

    public function testDeduplicatedRepresentationsWithMissingDateRetainConservativeVisibility(): void
    {
        $emission = $this->emission();
        $history = $this->diffusion($emission, false);
        $dated = $this->draft($emission, '2026-11-14 17:00:00')->setPublishedDiffusion($history);
        $undated = $this->draft($emission)->setPublishedDiffusion($history);
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey('manual_test');

        // Both orders must preserve uncertainty, even when deduplication recovers a date.
        foreach ([[$undated, $dated], [$dated, $undated]] as $drafts) {
            $summary = NonRegularScheduledOccurrences::summarize(1, 'manual_test', $drafts, [$history]);
            self::assertSame(1, $summary['count']);
            self::assertFalse($summary['datesComplete']);
            self::assertFalse($summary['complete']);
            self::assertSame('2026-11-14 17:00:00', $summary['last']->format('Y-m-d H:i:s'));
            self::assertSame([$pending], $this->visible($pending, '2026-11-10', $drafts, [$history]));
            self::assertSame([$pending], $this->visible($pending, '2026-11-17', $drafts, [$history]));
        }
    }

    private function emission(): Emission
    {
        $emission = $this->createMock(Emission::class);
        $emission->method('getId')->willReturn(1);
        return $emission;
    }

    private function draft(Emission $emission, ?string $date = null): DiffusionDraft
    {
        $draft = (new DiffusionDraft())->setEmission($emission)->setAssignmentGroupKey('manual_test');
        if ($date !== null) {
            $draft->setSchedule(new \DateTimeImmutable($date, new \DateTimeZone('Europe/Paris')), 60);
        }
        return $draft;
    }

    private function diffusion(Emission $emission, bool $published): Diffusion
    {
        $diffusion = $this->createMock(Diffusion::class);
        $diffusion->method('getId')->willReturn(10);
        $diffusion->method('getEmission')->willReturn($emission);
        $diffusion->method('getAssignmentGroupKey')->willReturn('manual_test');
        $diffusion->method('isPublished')->willReturn($published);
        return $diffusion;
    }

    private function visible(PendingRebroadcast $pending, string $week, array $drafts, array $diffusions): array
    {
        $key = $pending->getAssignmentGroupKey();
        return (new NonRegularPendingVisibility())->filter(
            [$pending], new \DateTimeImmutable($week, new \DateTimeZone('Europe/Paris')),
            [$key => $drafts], [$key => $diffusions],
        );
    }
}
