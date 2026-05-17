<?php

namespace App\Service;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use Doctrine\ORM\EntityManagerInterface;

class GridAssignmentService
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

public function assign(ProgrammationRuleSlot $slot, Emission $emission, \DateTimeImmutable $selectedDate): bool
{
    $rule = $slot->getRule();

    if ($rule === null) {
        throw new \RuntimeException('Règle introuvable.');
    }

    if ($slot->getBroadcastRank() !== 1) {
        throw new \RuntimeException(
            'L’affectation doit se faire sur la première diffusion. Les rediffusions sont générées automatiquement.'
        );
    }

    $originDate = $selectedDate;
    $assignmentGroupKey = $this->buildAssignmentGroupKey($rule->getId(), $originDate);

    foreach ($rule->getSlots() as $relatedSlot) {
        if (!$relatedSlot instanceof ProgrammationRuleSlot) {
            continue;
        }

        if (!$relatedSlot->isActive() || $relatedSlot->isDeleted()) {
            continue;
        }

        $relatedStartsAt = $this->computeStartsAtFromAnchor($originDate, $relatedSlot);

        $this->upsertDraft(
            $relatedSlot,
            $emission,
            $relatedStartsAt,
            $assignmentGroupKey
        );
    }

    $this->em->flush();

    return true;
}

    public function remove(ProgrammationRuleSlot $slot, \DateTimeImmutable $selectedDate): bool
    {
        $draft = $this->draftRepository->findOneRegularBySlotAndHoraire($slot, $selectedDate);

        if (!$draft instanceof DiffusionDraft) {
            return false;
        }

        $assignmentGroupKey = $draft->getAssignmentGroupKey();

        if (!$assignmentGroupKey) {
            $this->em->remove($draft);
            $this->em->flush();

            return false;
        }

        $drafts = $this->draftRepository->findByAssignmentGroupKey($assignmentGroupKey);

        foreach ($drafts as $draftToRemove) {
            if ($draftToRemove instanceof DiffusionDraft) {
                $this->em->remove($draftToRemove);
            }
        }

        $this->em->flush();

        return true;
    }

    private function upsertDraft(
        ProgrammationRuleSlot $slot,
        Emission $emission,
        \DateTimeImmutable $startsAt,
        string $assignmentGroupKey
    ): DiffusionDraft {
        $duration = $this->resolveDurationMinutes($slot, $emission);

        $draft = $this->draftRepository->findOneRegularBySlotAndHoraire($slot, $startsAt);

        if (!$draft instanceof DiffusionDraft) {
            $draft = new DiffusionDraft();
        }

        $draft
            ->setSlot($slot)
            ->setSchedule($startsAt, $duration)
            ->setEmission($emission)
            ->setNombreDiffusion($slot->getBroadcastRank())
            ->setDraftType(DiffusionDraft::TYPE_REGULAR)
            ->setAssignmentGroupKey($assignmentGroupKey);

        $this->em->persist($draft);

        return $draft;
    }

    private function buildAssignmentGroupKey(?int $ruleId, \DateTimeImmutable $originDate): string
    {
        if ($ruleId === null) {
            throw new \RuntimeException('Impossible de générer une clé de groupe sans ID de règle.');
        }

        return sprintf(
            'rule_%d_origin_%s',
            $ruleId,
            $originDate->format('Ymd_Hi')
        );
    }

    private function resolveOriginDate(
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $selectedDate
    ): \DateTimeImmutable {
        if ($slot->getBroadcastRank() === 1) {
            return $selectedDate;
        }

        $weekOffset = $slot->getWeekOffset();

        if (!\is_int($weekOffset)) {
            $weekOffset = 0;
        }

        return $selectedDate->modify(sprintf('-%d days', $weekOffset * 7));
    }

    private function resolveDurationMinutes(ProgrammationRuleSlot $slot, Emission $emission): int
    {
        $slotDuration = $slot->getDurationMinutes();

        if (\is_int($slotDuration) && $slotDuration > 0) {
            return $slotDuration;
        }

        $emissionDuration = $emission->getDuree();

        if (\is_int($emissionDuration) && $emissionDuration > 0) {
            return $emissionDuration;
        }

        return 15;
    }

    private function computeStartsAtFromAnchor(
        \DateTimeImmutable $anchorDate,
        ProgrammationRuleSlot $slot
    ): \DateTimeImmutable {
        $anchorWeekStart = $this->getRadioWeekStart($anchorDate);

        $targetDate = $anchorWeekStart
            ->modify(sprintf('+%d days', $this->radioDayIndexFromDayOfWeek($slot->getDayOfWeek())))
            ->modify(sprintf('+%d days', $slot->getWeekOffset() * 7));

        $startTime = $slot->getStartTime();

        if ($startTime === null) {
            return $targetDate->setTime(0, 0, 0);
        }

        return $targetDate->setTime(
            (int) $startTime->format('H'),
            (int) $startTime->format('i'),
            0
        );
    }

    private function getRadioWeekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $midnight = $date->setTime(0, 0, 0);
        $dayOfWeek = (int) $midnight->format('N');

        return match ($dayOfWeek) {
            2 => $midnight,
            3 => $midnight->modify('-1 day'),
            4 => $midnight->modify('-2 days'),
            5 => $midnight->modify('-3 days'),
            6 => $midnight->modify('-4 days'),
            7 => $midnight->modify('-5 days'),
            1 => $midnight->modify('-6 days'),
            default => $midnight,
        };
    }

    private function radioDayIndexFromDayOfWeek(?int $dayOfWeek): int
    {
        return match ($dayOfWeek) {
            2 => 0,
            3 => 1,
            4 => 2,
            5 => 3,
            6 => 4,
            7 => 5,
            1 => 6,
            default => 0,
        };
    }
}