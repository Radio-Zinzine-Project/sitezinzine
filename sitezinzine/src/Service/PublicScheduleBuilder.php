<?php

namespace App\Service;

use App\Repository\DiffusionRepository;

class PublicScheduleBuilder
{
    public function __construct(
        private readonly DiffusionRepository $diffusionRepository,
    ) {
    }

    public function build(\DateTimeImmutable $startOfWeek): array
    {
        $start = $startOfWeek->setTime(0, 0);
        $end = $start->modify('+7 days');

        $diffusions = $this->diffusionRepository->findPublishedByWeek(
            $start,
            $end
        );

        $diffusionsByDay = [];

        foreach ($diffusions as $diffusion) {
            $dayKey = $diffusion
                ->getHoraireDiffusion()
                ->format('Y-m-d');

            $diffusionsByDay[$dayKey][] = $diffusion;
        }

        $days = [];

        for ($i = 0; $i < 7; ++$i) {
            $date = $start->modify(sprintf('+%d days', $i));
            $dayKey = $date->format('Y-m-d');

            $days[] = [
                'date' => $date,
                'key' => $dayKey,
                'diffusions' => $diffusionsByDay[$dayKey] ?? [],
            ];
        }

        return [
            'startOfWeek' => $start,
            'endOfWeek' => $end->modify('-1 day'),
            'days' => $days,
        ];
    }
}