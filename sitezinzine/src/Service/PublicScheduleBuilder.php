<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;

final class PublicScheduleBuilder
{
    public function __construct(
        private readonly DiffusionRepository $diffusionRepository,
        private readonly ProgrammationGridBuilder $programmationGridBuilder,
        private readonly GridOccurrenceProjectionService $gridOccurrenceProjectionService,
        private readonly DiffusionDraftRepository $draftRepository,
    ) {
    }

    public function build(\DateTimeImmutable $startOfWeek): array
    {
        $radioTimezone = new \DateTimeZone('Europe/Paris');
        $start = $startOfWeek->setTimezone($radioTimezone)->setTime(0, 0);
        $end = $start->modify('+7 days');

        /*
         * 1. Construction des occurrences régulières théoriques.
         */
        $daySegments = $this->programmationGridBuilder->buildForWeek(
            $start,
            $end
        );

        /*
         * 2. Application des annulations et déplacements.
         *
         * Le programme public doit refléter la grille réellement projetée,
         * et non uniquement la programmation théorique.
         */
        $daySegments = $this->gridOccurrenceProjectionService->applyForWeek(
            $daySegments,
            $start,
            $end
        );

        /*
         * 3. Seules les Diffusion actuellement publiées peuvent apparaître
         * sur le programme public.
         */
        $diffusions = $this->diffusionRepository->findPublishedByWeek(
            $start,
            $end
        );

        $published = [];
        $publishedIndexById = [];
        foreach ($diffusions as $diffusion) {
            if (!$diffusion instanceof Diffusion || !$diffusion->getEmission() instanceof Emission) {
                continue;
            }

            $startsAt = $this->toImmutableDate($diffusion->getHoraireDiffusion(), $radioTimezone);
            $dayIndex = null === $startsAt ? null : $this->getDayIndex($startsAt, $start, $end);
            if (null === $dayIndex) {
                continue;
            }

            $id = $diffusion->getId();
            if (null !== $id) {
                if (isset($publishedIndexById[$id])) {
                    continue;
                }
                $publishedIndexById[$id] = \count($published);
            }

            $published[] = [
                'day' => $dayIndex,
                'startsAt' => $startsAt,
                'diffusion' => $diffusion,
            ];
        }

        $occurrences = [];
        $occurrencesByIdentity = [];
        foreach ($daySegments as $segments) {
            foreach ($segments as $segment) {
                if (
                    ($segment['isBlocking'] ?? true) === false
                    || ($segment['isCancelled'] ?? false) === true
                    || ($segment['isRescheduledOrigin'] ?? false) === true
                ) {
                    continue;
                }

                $startsAt = $this->toImmutableDate($segment['startsAt'] ?? null, $radioTimezone);
                $dayIndex = null === $startsAt ? null : $this->getDayIndex($startsAt, $start, $end);
                if (null === $dayIndex) {
                    continue;
                }

                $slotId = (int) ($segment['slotId'] ?? 0);
                if ($slotId > 0) {
                    $index = \count($occurrences);
                    $effective = $startsAt->format('Y-m-d H:i:s');
                    $original = $this->toImmutableDate($segment['originalStartsAt'] ?? null, $radioTimezone)?->format('Y-m-d H:i:s') ?? $effective;
                    foreach (array_unique([$effective, $original]) as $time) {
                        $key = $slotId . '|' . $time;
                        $occurrencesByIdentity[$key][] = $index;
                    }
                }

                $occurrences[] = [
                    'day' => $dayIndex,
                    'startsAt' => $startsAt,
                    'endsAt' => $this->toImmutableDate($segment['endsAt'] ?? null, $radioTimezone),
                    'category' => $segment['category'] ?? null,
                ];
            }
        }

        $matches = [];
        $consumed = [];
        $withDraftLink = [];
        $claims = [];
        $claimedIdentities = [];
        $drafts = [] === $publishedIndexById ? [] : $this->draftRepository->findByPublishedDiffusionIds(array_keys($publishedIndexById));
        foreach ($drafts as $draft) {
            $id = $draft->getPublishedDiffusion()?->getId();
            if (null === $id || !isset($publishedIndexById[$id])) {
                continue;
            }

            $diffusionIndex = $publishedIndexById[$id];
            // Even an obsolete or manual link prevents matching by time to another slot.
            $withDraftLink[$diffusionIndex] = ($withDraftLink[$diffusionIndex] ?? 0) + 1;

            $diffusion = $published[$diffusionIndex]['diffusion'];
            $slotId = $draft->getSlot()?->getId();
            $startsAt = $draft->getHoraireDiffusion();
            if (!$draft->isPublished() || $draft->isDeleted() || !$draft->isRegular()
                || $draft->getEmission() !== $diffusion->getEmission()
                || null === $slotId || null === $startsAt) {
                continue;
            }

            $key = $slotId . '|' . $startsAt->format('Y-m-d H:i:s');
            $claimedIdentities[$key] = true;
            $candidates = $occurrencesByIdentity[$key] ?? [];
            if (1 === \count($candidates)) {
                $claims[$diffusionIndex] = $candidates[0];
            }
        }

        $claimsByOccurrence = [];
        foreach ($claims as $diffusionIndex => $occurrenceIndex) {
            if (1 === $withDraftLink[$diffusionIndex]) {
                $claimsByOccurrence[$occurrenceIndex][] = $diffusionIndex;
            }
        }
        foreach ($claimsByOccurrence as $occurrenceIndex => $candidates) {
            if (1 === \count($candidates)) {
                $matches[$occurrenceIndex] = $candidates[0];
                $consumed[$candidates[0]] = $occurrenceIndex;
            }
        }

        // Legacy rows without a draft link can only fill an unambiguous remaining slot.
        $reservedOccurrences = [];
        foreach ($claimedIdentities as $key => $_) {
            foreach ($occurrencesByIdentity[$key] ?? [] as $index) {
                $reservedOccurrences[$index] = true;
            }
        }
        $historicalByTime = [];
        foreach ($published as $index => $item) {
            if (!isset($withDraftLink[$index])) {
                $historicalByTime[$item['startsAt']->format('Y-m-d H:i:s')][] = $index;
            }
        }
        $unmatchedByTime = [];
        foreach ($occurrences as $index => $occurrence) {
            if (!isset($matches[$index]) && !isset($reservedOccurrences[$index])) {
                $unmatchedByTime[$occurrence['startsAt']->format('Y-m-d H:i:s')][] = $index;
            }
        }
        foreach ($unmatchedByTime as $time => $indices) {
            $candidates = $historicalByTime[$time] ?? [];
            if (1 === \count($indices) && 1 === \count($candidates)) {
                $matches[$indices[0]] = $candidates[0];
                $consumed[$candidates[0]] = $indices[0];
            }
        }

        $itemsByDay = array_fill(0, 7, []);
        foreach ($occurrences as $index => $occurrence) {
            if (isset($matches[$index])) {
                continue;
            }

            $itemsByDay[$occurrence['day']][] = [
                'type' => 'empty_regular',
                'startsAt' => $occurrence['startsAt'],
                'endsAt' => $occurrence['endsAt'],
                'emission' => null,
                'category' => $occurrence['category'],
                'diffusion' => null,
                'isRegular' => true,
            ];
        }
        foreach ($published as $index => $item) {
            $diffusion = $item['diffusion'];
            $emission = $diffusion->getEmission();
            $endsAt = $this->toImmutableDate($diffusion->getEndsAt(), $radioTimezone);
            if (null === $endsAt && null === $diffusion->getDurationMinutes()
                && isset($consumed[$index]) && !isset($withDraftLink[$index])) {
                // Preserve the slot end for historical rows without their own schedule length.
                $endsAt = $occurrences[$consumed[$index]]['endsAt'];
            }
            $endsAt ??= $item['startsAt']->modify(sprintf('+%d minutes', $this->resolveDiffusionDuration($diffusion)));
            $itemsByDay[$item['day']][] = [
                'type' => 'diffusion',
                'startsAt' => $item['startsAt'],
                'endsAt' => $endsAt,
                'emission' => $emission,
                'category' => $emission->getCategorie(),
                'diffusion' => $diffusion,
                'isRegular' => isset($consumed[$index]),
            ];
        }

        /*
         * 6. Le programme public est toujours présenté chronologiquement,
         * quelle que soit l'origine de l'élément.
         */
        foreach ($itemsByDay as &$items) {
            usort(
                $items,
                static fn(array $a, array $b): int =>
                    $a['startsAt'] <=> $b['startsAt']
            );
        }
        unset($items);

        /*
         * 7. Construction des sept journées de la semaine radio.
         */
        $days = [];

        for ($i = 0; $i < 7; ++$i) {
            $date = $start->modify(
                sprintf('+%d days', $i)
            );

            $days[] = [
                'date' => $date,
                'key' => $date->format('Y-m-d'),
                'items' => $itemsByDay[$i],
            ];
        }

        return [
            'startOfWeek' => $start,
            'endOfWeek' => $end->modify('-1 day'),
            'days' => $days,
        ];
    }

    private function getDayIndex(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): ?int {
        if ($startsAt < $start || $startsAt >= $end) {
            return null;
        }

        $dayStart = $startsAt->setTimezone($start->getTimezone())->setTime(0, 0, 0);
        $diffDays = $start->diff($dayStart)->days;

        if (
            $diffDays === false
            || $diffDays < 0
            || $diffDays > 6
        ) {
            return null;
        }

        return $diffDays;
    }

    private function toImmutableDate(
        mixed $value,
        \DateTimeZone $radioTimezone,
    ): ?\DateTimeImmutable {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new \DateTimeImmutable($value, $radioTimezone);
        }

        return null;
    }

    private function resolveDiffusionDuration(
        Diffusion $diffusion
    ): int {
        $duration = $diffusion->getDurationMinutes()
            ?? $diffusion->getEmission()?->getDuree()
            ?? 15;

        return $duration > 0 ? $duration : 15;
    }
}
