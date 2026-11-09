<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PendingRebroadcast;

final class NonRegularPendingVisibility
{
    /** @return PendingRebroadcast[] */
    public function filter(array $pendings, \DateTimeImmutable $weekStart, array $draftsByGroup, array $diffusionsByGroup): array
    {
        return array_values(array_filter($pendings, static function (PendingRebroadcast $pending) use ($weekStart, $draftsByGroup, $diffusionsByGroup): bool {
            $key = $pending->getAssignmentGroupKey();
            if (str_starts_with($key, 'rule_') || $pending->getEmission()?->getId() === null) {
                return true;
            }
            $schedule = NonRegularScheduledOccurrences::summarize(
                $pending->getEmission()->getId(), $key, $draftsByGroup[$key] ?? [], $diffusionsByGroup[$key] ?? []
            );
            // No reliable active trigger: retain legacy visibility without writing anything.
            return !$schedule['complete'] || $schedule['last'] === null
                || $weekStart >= RadioWeek::start($schedule['last'])->modify('+7 days');
        }));
    }
}
