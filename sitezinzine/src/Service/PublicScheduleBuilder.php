<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Repository\DiffusionRepository;

final class PublicScheduleBuilder
{
    public function __construct(
        private readonly DiffusionRepository $diffusionRepository,
        private readonly ProgrammationGridBuilder $programmationGridBuilder,
        private readonly GridOccurrenceProjectionService $gridOccurrenceProjectionService,
    ) {
    }

    public function build(\DateTimeImmutable $startOfWeek): array
    {
        $start = $startOfWeek->setTime(0, 0);
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

        /*
         * Index chronologique des diffusions.
         *
         * C'est le même principe que dans GridViewBuilder :
         * une diffusion régulière est rattachée à son occurrence par
         * son horaire de diffusion.
         */
        $diffusionIndex = [];

        foreach ($diffusions as $diffusion) {
            if (!$diffusion instanceof Diffusion) {
                continue;
            }

            $startsAt = $diffusion->getHoraireDiffusion();

            if (!$startsAt instanceof \DateTimeInterface) {
                continue;
            }

            $key = $startsAt->format('Y-m-d H:i:s');

            $diffusionIndex[$key] = $diffusion;
        }

        $itemsByDay = array_fill(0, 7, []);

        /*
         * 4. Construction des éléments issus de la programmation régulière.
         */
        foreach ($daySegments as $segments) {
            foreach ($segments as $segment) {
                /*
                 * Une occurrence annulée ne doit jamais apparaître
                 * sur le programme public.
                 *
                 * De même, lorsqu'une occurrence est déplacée, son ancienne
                 * position reste utile à l'administration mais ne doit pas
                 * être visible publiquement.
                 */
                if (
                    ($segment['isBlocking'] ?? true) === false
                    || ($segment['isCancelled'] ?? false) === true
                    || ($segment['isRescheduledOrigin'] ?? false) === true
                ) {
                    continue;
                }

                $startsAt = $this->toImmutableDate(
                    $segment['startsAt'] ?? null
                );

                if (!$startsAt instanceof \DateTimeImmutable) {
                    continue;
                }

                $dayIndex = $this->getDayIndex(
                    $startsAt,
                    $start,
                    $end
                );

                if ($dayIndex === null) {
                    continue;
                }

                $endsAt = $this->toImmutableDate(
                    $segment['endsAt'] ?? null
                );

                $key = $startsAt->format('Y-m-d H:i:s');
                $diffusion = $diffusionIndex[$key] ?? null;

                if ($diffusion instanceof Diffusion) {
                    $emission = $diffusion->getEmission();

                    if ($emission instanceof Emission) {
                        $itemsByDay[$dayIndex][] = [
                            'type' => 'diffusion',
                            'startsAt' => $startsAt,
                            'endsAt' => $endsAt,
                            'emission' => $emission,
                            'category' => $emission->getCategorie(),
                            'diffusion' => $diffusion,
                            'isRegular' => true,
                        ];

                        unset($diffusionIndex[$key]);

                        continue;
                    }
                }

                /*
                 * Aucun contenu publié n'est affecté à ce créneau :
                 * le créneau régulier reste néanmoins visible publiquement.
                 */
                $itemsByDay[$dayIndex][] = [
                    'type' => 'empty_regular',
                    'startsAt' => $startsAt,
                    'endsAt' => $endsAt,
                    'emission' => null,
                    'category' => $segment['category'] ?? null,
                    'diffusion' => null,
                    'isRegular' => true,
                ];
            }
        }

        /*
         * 5. Les diffusions encore présentes dans l'index ne correspondent
         * à aucune occurrence régulière visible.
         *
         * Ce sont notamment les émissions ponctuelles/manuelles publiées.
         */
        foreach ($diffusionIndex as $diffusion) {
            if (!$diffusion instanceof Diffusion) {
                continue;
            }

            $startsAtInterface = $diffusion->getHoraireDiffusion();

            if (!$startsAtInterface instanceof \DateTimeInterface) {
                continue;
            }

            $startsAt = \DateTimeImmutable::createFromInterface(
                $startsAtInterface
            );

            $dayIndex = $this->getDayIndex(
                $startsAt,
                $start,
                $end
            );

            if ($dayIndex === null) {
                continue;
            }

            $emission = $diffusion->getEmission();

            if (!$emission instanceof Emission) {
                continue;
            }

            $endsAtInterface = $diffusion->getEndsAt();

            $endsAt = $endsAtInterface instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($endsAtInterface)
                : $startsAt->modify(
                    sprintf(
                        '+%d minutes',
                        $this->resolveDiffusionDuration($diffusion)
                    )
                );

            $itemsByDay[$dayIndex][] = [
                'type' => 'diffusion',
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
                'emission' => $emission,
                'category' => $emission->getCategorie(),
                'diffusion' => $diffusion,
                'isRegular' => false,
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

        $dayStart = $startsAt->setTime(0, 0, 0);
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
        mixed $value
    ): ?\DateTimeImmutable {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new \DateTimeImmutable($value);
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