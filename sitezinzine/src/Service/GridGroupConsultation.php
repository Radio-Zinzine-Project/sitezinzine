<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;

final class GridGroupConsultation
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly DiffusionRepository $diffusionRepository,
    ) {}

    public function consult(DiffusionDraft|Diffusion $source): array
    {
        $groupKey = $source->getAssignmentGroupKey();
        if (!$groupKey) {
            $items = [$source instanceof DiffusionDraft
                ? $this->item($source, $source->getPublishedDiffusion())
                : $this->item(null, $source)];
        } else {
            $drafts = $this->draftRepository->findActiveForGroupConsultation($groupKey);
            $diffusions = $this->diffusionRepository->findByAssignmentGroupKey($groupKey);
            $byDiffusionId = [];
            foreach ($diffusions as $diffusion) {
                $byDiffusionId[$diffusion->getId()] = $diffusion;
            }
            $representedDiffusions = [];
            $items = [];
            foreach ($drafts as $draft) {
                $linkedId = $draft->getPublishedDiffusion()?->getId();
                $linked = $byDiffusionId[$linkedId] ?? null;
                if ($linked !== null) {
                    if (isset($representedDiffusions[$linkedId])) {
                        continue;
                    }
                    $representedDiffusions[$linkedId] = true;
                }
                $items[] = $this->item($draft, $linked);
            }
            foreach ($diffusions as $diffusion) {
                if (!isset($representedDiffusions[$diffusion->getId()])) {
                    $items[] = $this->item(null, $diffusion);
                }
            }
        }

        usort($items, static fn(array $a, array $b): int =>
            [$a['startsAt'], $a['draftId'] ?? 0, $a['diffusionId'] ?? 0]
            <=> [$b['startsAt'], $b['draftId'] ?? 0, $b['diffusionId'] ?? 0]
        );

        return ['success' => true, 'assignmentGroupKey' => $groupKey, 'items' => $items];
    }

    private function item(?DiffusionDraft $draft, ?Diffusion $diffusion): array
    {
        // Une publication utilise son horaire effectif. Après dévalidation,
        // le Draft actif redevient la donnée de travail. Sans Draft actif,
        // la Diffusion conserve ses propres informations, quel que soit son statut.
        $displayed = $draft !== null && ($diffusion === null || !$diffusion->isPublished())
            ? $draft : $diffusion;
        if ($draft !== null) {
            $rank = $draft->getNombreDiffusion() ?? 1;
            $label = $rank <= 1 ? '1re diffusion' : sprintf('Rediffusion %d', $rank - 1);
        } else {
            // Un nombre_diffusion historique ne prouve pas le rang d'un slot.
            $label = $diffusion->isPublished() ? 'Diffusion publiée' : 'Diffusion dépubliée';
        }

        return [
            // Compatibilité avec les consommateurs historiques de la route Draft.
            'id' => $draft?->getId() ?? $diffusion->getId(),
            'draftId' => $draft?->getId(),
            'diffusionId' => $diffusion?->getId(),
            'sourceType' => $displayed instanceof DiffusionDraft ? 'draft' : 'diffusion',
            'label' => $label,
            'draftType' => $draft?->getDraftType(),
            'nombreDiffusion' => $displayed->getNombreDiffusion(),
            'publicationStatus' => $displayed->getPublicationStatus(),
            'startsAt' => $displayed->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            'endsAt' => $displayed->getEndsAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
