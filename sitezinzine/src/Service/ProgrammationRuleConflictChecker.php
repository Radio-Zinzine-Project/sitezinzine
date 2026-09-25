<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\ProgrammationRuleSlotRepository;

final class ProgrammationRuleConflictChecker
{
    public function __construct(
        private readonly ProgrammationRuleSlotRepository $slotRepository
    ) {}

    /**
     * Recherche les conflits structurels d'un créneau.
     *
     * Par défaut, une règle inactive n'est pas considérée comme candidate.
     * $ignoreCandidateRuleState permet de tester une règle inactive comme
     * si elle devait être réactivée.
     *
     * @return ProgrammationRuleSlot[]
     */
    public function findStructuralConflicts(
        ProgrammationRuleSlot $candidate,
        bool $ignoreCandidateRuleState = false
    ): array {
        if ($candidate->isDeleted() || !$candidate->isActive()) {
            return [];
        }

        $candidateRule = $candidate->getRule();

        if (!$candidateRule instanceof ProgrammationRule) {
            return [];
        }

        if ($candidateRule->isDeleted()) {
            return [];
        }

        if (!$ignoreCandidateRuleState && !$candidateRule->isActive()) {
            return [];
        }

        $conflicts = [];

        foreach (
            $this->slotRepository->findActiveStructuralConflictCandidates()
            as $existing
        ) {
            if (!$existing instanceof ProgrammationRuleSlot) {
                continue;
            }

            if ($existing->isDeleted() || !$existing->isActive()) {
                continue;
            }

            if ($this->isSamePersistedSlot($candidate, $existing)) {
                continue;
            }

            $existingRule = $existing->getRule();

            if (!$existingRule instanceof ProgrammationRule) {
                continue;
            }

            if ($existingRule->isDeleted() || !$existingRule->isActive()) {
                continue;
            }

            /*
             * Lorsque nous vérifions une règle inactive en vue de sa
             * réactivation, ses propres créneaux ne sont pas présents dans
             * la requête des règles actives.
             *
             * Les conflits internes à la règle sont vérifiés séparément
             * par findRuleStructuralConflicts().
             */
            if (
                $ignoreCandidateRuleState
                && $candidateRule === $existingRule
            ) {
                continue;
            }

            if (!$this->rulesCanCoexist($candidateRule, $existingRule)) {
                continue;
            }

            if (!$this->slotsCanOccurOnSameCycle($candidate, $existing)) {
                continue;
            }

            if (!$this->positionsCanStructurallyCoincide($candidate, $existing)) {
                continue;
            }

            if (!$this->timesOverlap($candidate, $existing)) {
                continue;
            }

            $conflicts[] = $existing;
        }

        return $conflicts;
    }

    /**
     * Vérifie une règle entière, même si elle est actuellement inactive.
     *
     * Cette méthode sert notamment :
     * - après création/modification d'un créneau ;
     * - pour savoir si une règle inactive est réactivable ;
     * - avant toute tentative de réactivation.
     *
     * Elle vérifie :
     * - les conflits internes entre les créneaux de la règle ;
     * - les conflits avec les autres règles actuellement actives.
     *
     * @return array<int, array{
     *     candidate: ProgrammationRuleSlot,
     *     conflicting: ProgrammationRuleSlot
     * }>
     */
    public function findRuleStructuralConflicts(
        ProgrammationRule $rule
    ): array {
        if ($rule->isDeleted()) {
            return [];
        }

        $slots = [];

        foreach ($rule->getSlots() as $slot) {
            if (!$slot instanceof ProgrammationRuleSlot) {
                continue;
            }

            if ($slot->isDeleted() || !$slot->isActive()) {
                continue;
            }

            $slots[] = $slot;
        }

        $conflicts = [];

        /*
         * 1. Conflits internes à la règle.
         *
         * Ils doivent être vérifiés même lorsque la règle est inactive,
         * puisque deux créneaux d'une même règle peuvent eux-mêmes se
         * chevaucher structurellement.
         */
        $slotCount = count($slots);

        for ($i = 0; $i < $slotCount; ++$i) {
            for ($j = $i + 1; $j < $slotCount; ++$j) {
                $candidate = $slots[$i];
                $existing = $slots[$j];

                if (!$this->rulesCanCoexist($rule, $rule)) {
                    continue;
                }

                if (!$this->slotsCanOccurOnSameCycle($candidate, $existing)) {
                    continue;
                }

                if (!$this->positionsCanStructurallyCoincide($candidate, $existing)) {
                    continue;
                }

                if (!$this->timesOverlap($candidate, $existing)) {
                    continue;
                }

                $conflicts[] = [
                    'candidate' => $candidate,
                    'conflicting' => $existing,
                ];
            }
        }

        /*
         * 2. Conflits avec les autres règles actives.
         */
        foreach ($slots as $candidate) {
            foreach (
                $this->findStructuralConflicts(
                    $candidate,
                    ignoreCandidateRuleState: true
                )
                as $existing
            ) {
                $conflicts[] = [
                    'candidate' => $candidate,
                    'conflicting' => $existing,
                ];
            }
        }

        return $this->deduplicateRuleConflicts($conflicts);
    }

    /**
     * Indique si une règle possède actuellement au moins un conflit
     * structurel.
     */
    public function hasRuleStructuralConflicts(
        ProgrammationRule $rule
    ): bool {
        return [] !== $this->findRuleStructuralConflicts($rule);
    }

    /**
     * Indique si une règle inactive peut être réactivée.
     *
     * Une règle supprimée n'est jamais réactivable.
     * Une règle déjà active n'a pas besoin d'être "réactivée".
     */
    public function canActivateRule(
        ProgrammationRule $rule
    ): bool {
        if ($rule->isDeleted() || $rule->isActive()) {
            return false;
        }

        return !$this->hasRuleStructuralConflicts($rule);
    }

    private function isSamePersistedSlot(
        ProgrammationRuleSlot $candidate,
        ProgrammationRuleSlot $existing
    ): bool {
        if ($candidate === $existing) {
            return true;
        }

        $candidateId = $candidate->getId();
        $existingId = $existing->getId();

        return null !== $candidateId
            && null !== $existingId
            && $candidateId === $existingId;
    }

    private function rulesCanCoexist(
        ProgrammationRule $candidateRule,
        ProgrammationRule $existingRule
    ): bool {
        if ($candidateRule->isDeleted() || $existingRule->isDeleted()) {
            return false;
        }

        $candidateFrom = $candidateRule->getValidFrom();
        $candidateUntil = $candidateRule->getValidUntil();

        $existingFrom = $existingRule->getValidFrom();
        $existingUntil = $existingRule->getValidUntil();

        if (
            null !== $candidateUntil
            && null !== $existingFrom
            && $candidateUntil < $existingFrom
        ) {
            return false;
        }

        if (
            null !== $existingUntil
            && null !== $candidateFrom
            && $existingUntil < $candidateFrom
        ) {
            return false;
        }

        return true;
    }

    private function slotsCanOccurOnSameCycle(
        ProgrammationRuleSlot $candidate,
        ProgrammationRuleSlot $existing
    ): bool {
        /*
     * Deux créneaux mensuels conservent une position explicite dans leur
     * cycle mensuel, y compris lorsqu'un des deux est une rediffusion.
     */
        if ($candidate->isMonthly() && $existing->isMonthly()) {
            return $this->monthlyCyclesCanCoincide(
                $candidate,
                $existing
            );
        }

        /*
     * Deux rediffusions peuvent être techniquement enregistrées comme
     * hebdomadaires alors que leur cycle réel dépend de la première
     * diffusion de leur règle.
     *
     * Si leurs deux premières diffusions sont mensuelles, on compare donc
     * les cycles mensuels d'origine avant de comparer la position des
     * rediffusions elles-mêmes.
     */
        if (
            $this->isRebroadcast($candidate)
            && $this->isRebroadcast($existing)
        ) {
            $candidateOrigin = $this->findOriginSlot($candidate);
            $existingOrigin = $this->findOriginSlot($existing);

            if (
                $candidateOrigin !== null
                && $existingOrigin !== null
                && $candidateOrigin->isMonthly()
                && $existingOrigin->isMonthly()
            ) {
                return $this->monthlyCyclesCanCoincide(
                    $candidateOrigin,
                    $existingOrigin
                );
            }

            return true;
        }

        /*
     * Lorsqu'un seul des deux créneaux est une rediffusion, sa position
     * dépend de la première diffusion de sa règle. On conserve ici le
     * comportement structurel existant.
     */
        if (
            $this->isRebroadcast($candidate)
            || $this->isRebroadcast($existing)
        ) {
            return true;
        }

        if ($candidate->isWeekly() && $existing->isWeekly()) {
            return $this->weeklyCyclesCanCoincide(
                $candidate,
                $existing
            );
        }

        /*
     * Hebdomadaire + mensuel :
     * la collision dépend d'une date concrète du calendrier.
     * Elle reste donc gérée par les arbitrages de grille.
     */
        return false;
    }

    private function weeklyCyclesCanCoincide(
        ProgrammationRuleSlot $candidate,
        ProgrammationRuleSlot $existing
    ): bool {
        $candidateParity = $candidate->getWeekParity();
        $existingParity = $existing->getWeekParity();

        if (null === $candidateParity || null === $existingParity) {
            return true;
        }

        return $candidateParity === $existingParity;
    }

    private function monthlyCyclesCanCoincide(
        ProgrammationRuleSlot $candidate,
        ProgrammationRuleSlot $existing
    ): bool {
        if (
            $candidate->getMonthlyOccurrence()
            !== $existing->getMonthlyOccurrence()
        ) {
            return false;
        }

        $candidateInterval = max(
            1,
            $candidate->getMonthInterval()
        );

        $existingInterval = max(
            1,
            $existing->getMonthInterval()
        );

        if ($candidateInterval !== $existingInterval) {
            /*
             * Deux périodicités différentes peuvent se rencontrer
             * ponctuellement. Ce n'est pas un conflit structurel bloquant.
             */
            return false;
        }

        if (1 === $candidateInterval) {
            return true;
        }

        $candidateRule = $candidate->getRule();
        $existingRule = $existing->getRule();

        if (
            !$candidateRule instanceof ProgrammationRule
            || !$existingRule instanceof ProgrammationRule
        ) {
            return false;
        }

        $candidateAnchor = $candidateRule->getValidFrom();
        $existingAnchor = $existingRule->getValidFrom();

        /*
         * Sans deux ancres explicites, on ne prétend pas établir une
         * phase structurelle identique pour un intervalle > 1.
         */
        if (null === $candidateAnchor || null === $existingAnchor) {
            return false;
        }

        return $this->sameMonthlyPhase(
            $candidateAnchor,
            $existingAnchor,
            $candidateInterval
        );
    }

    private function sameMonthlyPhase(
        \DateTimeInterface $first,
        \DateTimeInterface $second,
        int $interval
    ): bool {
        $firstMonth = ((int) $first->format('Y') * 12)
            + (int) $first->format('n');

        $secondMonth = ((int) $second->format('Y') * 12)
            + (int) $second->format('n');

        return abs($firstMonth - $secondMonth) % $interval === 0;
    }

    private function positionsCanStructurallyCoincide(
        ProgrammationRuleSlot $candidate,
        ProgrammationRuleSlot $existing
    ): bool {
        if (
            !$this->isRebroadcast($candidate)
            && !$this->isRebroadcast($existing)
        ) {
            if ($candidate->isWeekly() && $existing->isWeekly()) {
                return $candidate->getDayOfWeek()
                    === $existing->getDayOfWeek();
            }

            if ($candidate->isMonthly() && $existing->isMonthly()) {
                return $candidate->getDayOfWeek()
                    === $existing->getDayOfWeek();
            }

            return false;
        }

        return $this->structuralDayPosition($candidate)
            === $this->structuralDayPosition($existing);
    }

    private function structuralDayPosition(
        ProgrammationRuleSlot $slot
    ): int {
        $dayOfWeek = (int) $slot->getDayOfWeek();

        if (!$this->isRebroadcast($slot)) {
            return $dayOfWeek;
        }

        $weekOffset = (int) ($slot->getWeekOffset() ?? 0);

        return ($weekOffset * 7) + $dayOfWeek;
    }

    private function timesOverlap(
        ProgrammationRuleSlot $candidate,
        ProgrammationRuleSlot $existing
    ): bool {
        $candidateStart = $this->minutesSinceMidnight(
            $candidate->getStartTime()
        );

        $existingStart = $this->minutesSinceMidnight(
            $existing->getStartTime()
        );

        if (null === $candidateStart || null === $existingStart) {
            return false;
        }

        $candidateDuration = $candidate->getDurationMinutes();
        $existingDuration = $existing->getDurationMinutes();

        if (
            null === $candidateDuration
            || $candidateDuration <= 0
            || null === $existingDuration
            || $existingDuration <= 0
        ) {
            return false;
        }

        $candidateEnd = $candidateStart + $candidateDuration;
        $existingEnd = $existingStart + $existingDuration;

        return $candidateStart < $existingEnd
            && $candidateEnd > $existingStart;
    }

    private function minutesSinceMidnight(
        ?\DateTimeInterface $time
    ): ?int {
        if (null === $time) {
            return null;
        }

        return ((int) $time->format('H') * 60)
            + (int) $time->format('i');
    }

    private function findOriginSlot(
        ProgrammationRuleSlot $slot
    ): ?ProgrammationRuleSlot {
        $rule = $slot->getRule();

        if ($rule === null) {
            return null;
        }

        foreach ($rule->getSlots() as $ruleSlot) {
            if (
                !$ruleSlot->isDeleted()
                && $ruleSlot->isActive()
                && $ruleSlot->getBroadcastRank() === 1
            ) {
                return $ruleSlot;
            }
        }

        return null;
    }

    private function isRebroadcast(
        ProgrammationRuleSlot $slot
    ): bool {
        return (int) ($slot->getBroadcastRank() ?? 1) > 1;
    }

    /**
     * @param array<int, array{
     *     candidate: ProgrammationRuleSlot,
     *     conflicting: ProgrammationRuleSlot
     * }> $conflicts
     *
     * @return array<int, array{
     *     candidate: ProgrammationRuleSlot,
     *     conflicting: ProgrammationRuleSlot
     * }>
     */
    private function deduplicateRuleConflicts(
        array $conflicts
    ): array {
        $unique = [];

        foreach ($conflicts as $conflict) {
            $candidate = $conflict['candidate'];
            $conflicting = $conflict['conflicting'];

            $candidateKey = $candidate->getId() !== null
                ? 'id_' . $candidate->getId()
                : 'obj_' . spl_object_id($candidate);

            $conflictingKey = $conflicting->getId() !== null
                ? 'id_' . $conflicting->getId()
                : 'obj_' . spl_object_id($conflicting);

            $pair = [$candidateKey, $conflictingKey];
            sort($pair);

            $unique[implode('__', $pair)] = $conflict;
        }

        return array_values($unique);
    }
}
