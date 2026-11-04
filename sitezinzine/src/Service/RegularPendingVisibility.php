<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiffusionDraft;
use App\Entity\PendingRebroadcast;
use App\Repository\GridSlotArbitrationRepository;
use App\Repository\ProgrammationRuleRepository;

final class RegularPendingVisibility
{
    public function __construct(
        private readonly ProgrammationRuleRepository $ruleRepository,
        private readonly GridSlotArbitrationRepository $arbitrationRepository,
    ) {}

    /** @return PendingRebroadcast[] */
    public function filter(array $pendings, \DateTimeImmutable $weekStart, array $draftsByGroup, array $diffusionsByGroup): array
    {
        $ruleIds = [];
        foreach ($pendings as $pending) {
            if (preg_match('/^rule_([1-9][0-9]*)_origin_[0-9]{8}_[0-9]{4}$/D', $pending->getAssignmentGroupKey(), $matches)) {
                $ruleIds[$pending->getAssignmentGroupKey()] = (int) $matches[1];
            }
        }
        if ($ruleIds === []) {
            return $pendings;
        }
        $rules = [];
        foreach ($this->ruleRepository->findBy(['id' => array_values(array_unique($ruleIds))]) as $rule) {
            $rules[$rule->getId()] = $rule;
        }
        $occurrences = [];
        foreach ($ruleIds as $key => $ruleId) {
            $rule = $rules[$ruleId] ?? null;
            if ($rule === null || !PendingRebroadcastService::regularRuleAllowsAdditionalRebroadcast($rule)) {
                continue;
            }
            foreach ($draftsByGroup[$key] ?? [] as $draft) {
                if ($draft->getDraftType() === DiffusionDraft::TYPE_REGULAR && !$draft->isDeleted()
                    && $draft->getPublicationStatus() !== DiffusionDraft::STATUS_CANCELLED
                    && $draft->getSlot() !== null && $draft->getHoraireDiffusion() !== null) {
                    $occurrences[] = [$draft->getSlot()->getId(), $draft->getHoraireDiffusion()];
                }
            }
        }
        $arbitrations = [];
        foreach ($this->arbitrationRepository->findActiveForOccurrences($occurrences) as $arbitration) {
            $key = $arbitration->getSlot()->getId() . '|' . $arbitration->getOriginalStartsAt()->format('Y-m-d H:i:s');
            $arbitrations[$key] ??= $arbitration;
        }

        return array_values(array_filter($pendings, function (PendingRebroadcast $pending) use ($weekStart, $ruleIds, $rules, $draftsByGroup, $diffusionsByGroup, $arbitrations): bool {
            if (!$pending->isPending()) {
                return false;
            }
            $key = $pending->getAssignmentGroupKey();
            $rule = $rules[$ruleIds[$key] ?? 0] ?? null;
            if ($rule === null || !PendingRebroadcastService::regularRuleAllowsAdditionalRebroadcast($rule)) {
                return true; // Hors périmètre : comportement existant.
            }
            $emissionId = $pending->getEmission()?->getId();
            $identifiedDiffusions = [];
            $last = null;
            foreach ($draftsByGroup[$key] ?? [] as $draft) {
                if ($draft->getEmission()?->getId() !== $emissionId) {
                    continue;
                }
                $linked = $draft->getPublishedDiffusion();
                if ($linked !== null && $linked->getAssignmentGroupKey() === $key && $linked->getEmission()?->getId() === $emissionId) {
                    // Même supprimé, un Draft identifie le type de sa Diffusion.
                    $identifiedDiffusions[$linked->getId()] = true;
                } else {
                    $linked = null;
                }
                if ($draft->getDraftType() !== DiffusionDraft::TYPE_REGULAR || $draft->isDeleted()
                    || $draft->getPublicationStatus() === DiffusionDraft::STATUS_CANCELLED) {
                    continue;
                }
                $startsAt = $draft->getHoraireDiffusion();
                if ($startsAt === null) {
                    return true; // Calendrier incomplet : aucun rapprochement heuristique.
                }
                $arbitration = $draft->getSlot() !== null
                    ? ($arbitrations[$draft->getSlot()->getId() . '|' . $startsAt->format('Y-m-d H:i:s')] ?? null) : null;
                if ($arbitration?->isCancelAction()) {
                    continue;
                }
                if ($linked?->isPublished()) {
                    $startsAt = $linked->getHoraireDiffusion();
                } elseif ($arbitration?->isRescheduleAction()) {
                    $startsAt = $arbitration->getRescheduledStartsAt();
                }
                if ($startsAt === null) {
                    return true;
                }
                // Les horaires SQL de programmation sont des heures locales sans
                // fuseau stocké. Leur semaine métier se lit en Europe/Paris.
                $effective = new \DateTimeImmutable($startsAt->format('Y-m-d H:i:s'), new \DateTimeZone('Europe/Paris'));
                if ($last === null || $effective > $last) {
                    $last = $effective;
                }
            }
            foreach ($diffusionsByGroup[$key] ?? [] as $diffusion) {
                if ($diffusion->getEmission()?->getId() === $emissionId && !isset($identifiedDiffusions[$diffusion->getId()])) {
                    return true; // Sans Draft, régulière ou supplémentaire reste indécidable.
                }
            }
            return $last === null || $weekStart >= RadioWeek::start($last)->modify('+7 days');
        }));
    }
}
