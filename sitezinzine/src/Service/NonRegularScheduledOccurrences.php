<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;

/** Read-only union of concrete scheduled passages for one emission/group pair. */
final class NonRegularScheduledOccurrences
{
    /**
     * @param DiffusionDraft[] $drafts
     * @param Diffusion[] $diffusions
     * @return array{count:int, last:?\DateTimeImmutable, complete:bool}
     */
    public static function summarize(int $emissionId, string $groupKey, array $drafts, array $diffusions): array
    {
        $published = [];
        $complete = true;
        foreach ($diffusions as $diffusion) {
            if ($diffusion->getEmission()?->getId() !== $emissionId || $diffusion->getAssignmentGroupKey() !== $groupKey) {
                continue;
            }
            if ($diffusion->isPublished()) {
                $published[$diffusion->getId()] = $diffusion;
            }
        }
        $dates = [];
        $represented = [];
        $identified = [];
        foreach ($drafts as $draft) {
            if ($draft->getEmission()?->getId() !== $emissionId || $draft->getAssignmentGroupKey() !== $groupKey) {
                continue;
            }
            $historical = $draft->getPublishedDiffusion();
            $linkedId = null;
            if ($historical !== null && $historical->getEmission()?->getId() === $emissionId
                && $historical->getAssignmentGroupKey() === $groupKey) {
                $linkedId = $historical->getId();
                $identified[$linkedId] = true;
            }
            if ($draft->isDeleted() || $draft->getPublicationStatus() === DiffusionDraft::STATUS_CANCELLED) {
                continue;
            }
            $linked = $published[$linkedId] ?? null;
            $date = $linked?->getHoraireDiffusion() ?? $draft->getHoraireDiffusion();
            if ($date === null) {
                $complete = false;
            }
            // The explicit identity survives unpublication; only the displayed time
            // depends on whether the linked Diffusion is currently published.
            if ($linkedId !== null && isset($represented[$linkedId])) {
                // Preserve the latest relevant date without adding another occurrence.
                $index = $represented[$linkedId];
                if ($date !== null && ($dates[$index] === null || $date > $dates[$index])) {
                    $dates[$index] = $date;
                }
                continue;
            }
            if ($linkedId !== null) {
                $represented[$linkedId] = count($dates);
            }
            $dates[] = $date;
        }
        foreach ($published as $id => $diffusion) {
            if (!isset($represented[$id])) {
                $dates[] = $diffusion->getHoraireDiffusion();
            }
        }
        foreach ($diffusions as $diffusion) {
            if ($diffusion->getEmission()?->getId() === $emissionId && $diffusion->getAssignmentGroupKey() === $groupKey
                && !$diffusion->isPublished() && !isset($identified[$diffusion->getId()])) {
                // An orphaned historical row cannot be assigned to an active or removed passage.
                // Exclude it from the count, but do not invent a visibility trigger.
                $complete = false;
            }
        }
        $last = null;
        foreach ($dates as $date) {
            if ($date === null) {
                $complete = false;
                continue;
            }
            // SQL scheduling values are local wall-clock dates, as in RegularPendingVisibility.
            $local = new \DateTimeImmutable($date->format('Y-m-d H:i:s'), new \DateTimeZone('Europe/Paris'));
            if ($last === null || $local > $last) {
                $last = $local;
            }
        }
        return ['count' => count($dates), 'last' => $last, 'complete' => $complete];
    }
}
