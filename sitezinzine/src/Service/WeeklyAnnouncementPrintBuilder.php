<?php

namespace App\Service;

use App\Entity\Emission;
use App\Repository\DiffusionRepository;

class WeeklyAnnouncementPrintBuilder
{
    public function __construct(
        private readonly DiffusionRepository $diffusionRepository,
    ) {}

    public function build(
        \DateTimeImmutable $startOfWeek,
        \DateTimeImmutable $endOfWeek
    ): array {
        $diffusions = $this->diffusionRepository->findPublishedByWeek(
            $startOfWeek,
            $endOfWeek
        );

        $items = [];

        foreach ($diffusions as $diffusion) {
            $emission = $diffusion->getEmission();

            if (!$emission instanceof Emission) {
                continue;
            }

            if (!$this->hasRealDescription($emission)) {
                continue;
            }

            $emissionId = $emission->getId();

            if ($emissionId === null) {
                continue;
            }

            if (!isset($items[$emissionId])) {
                $anciensAnimateurs = [];

                foreach ($emission->getInviteOldAnimateurs() as $person) {
                    if (!$person->isAncienanimateur()) {
                        continue;
                    }

                    $anciensAnimateurs[] = $person;
                }

                $items[$emissionId] = [
                    'emission' => $emission,
                    'users' => $emission->getUsers()->toArray(),
                    'anciensAnimateurs' => $anciensAnimateurs,
                    'diffusions' => [],
                ];
            }

            $items[$emissionId]['diffusions'][] = $diffusion;
        }

        return array_values($items);
    }

    private function hasRealDescription(Emission $emission): bool
    {
        $text = html_entity_decode(
            strip_tags($emission->getDescriptif()),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = str_replace("\u{00A0}", ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        if (mb_strtolower($text) === 'description à remplir') {
            return false;
        }

        return true;
    }
}
