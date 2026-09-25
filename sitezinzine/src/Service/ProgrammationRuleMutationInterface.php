<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProgrammationRule;

interface ProgrammationRuleMutationInterface
{
    /**
     * Synchronise les affectations après une modification structurelle
     * de la règle.
     *
     * @return array{
     *     unpublishedWeeks: \DateTimeImmutable[],
     *     synchronization: array<string, int>
     * }
     */
    public function synchronizeAfterStructuralChange(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array;

    /**
     * Invalide explicitement les groupes futurs encore modifiables
     * d'une règle.
     *
     * @return array{
     *     unpublishedWeeks: \DateTimeImmutable[],
     *     synchronization: array<string, int>
     * }
     */
    public function invalidateRule(
        ProgrammationRule $rule,
        ?\DateTimeImmutable $now = null
    ): array;
}