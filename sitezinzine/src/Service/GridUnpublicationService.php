<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class GridUnpublicationService
{
    public function __construct(
        private readonly DiffusionRepository $diffusionRepository,
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function previewWeek(
        \DateTimeImmutable $weekStart
    ): array {
        $weekStart = $this->getRadioWeekStart($weekStart);
        $weekEnd = $weekStart->modify('+7 days');

        $publishedDiffusions = $this->diffusionRepository
            ->findPublishedByWeek(
                $weekStart,
                $weekEnd
            );

        if ([] === $publishedDiffusions) {
            return [
                'weekStart' => $weekStart,
                'weekEnd' => $weekEnd,
                'publishedDiffusionCount' => 0,
                'linkedDraftCount' => 0,
                'missingDraftCount' => 0,
                'canUnpublish' => false,
                'reason' => 'no_published_diffusions',
                'items' => [],
            ];
        }

        $diffusionIds = [];

        foreach ($publishedDiffusions as $diffusion) {
            if (
                $diffusion instanceof Diffusion
                && null !== $diffusion->getId()
            ) {
                $diffusionIds[] = $diffusion->getId();
            }
        }

        $linkedDrafts = $this->draftRepository
            ->findByPublishedDiffusionIds($diffusionIds);

        /*
         * Index :
         * diffusion_id => DiffusionDraft
         */
        $draftByDiffusionId = [];

        foreach ($linkedDrafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            $publishedDiffusionId = $draft
                ->getPublishedDiffusion()
                ?->getId();

            if (null === $publishedDiffusionId) {
                continue;
            }

            $draftByDiffusionId[$publishedDiffusionId] = $draft;
        }

        $items = [];
        $missingDraftCount = 0;

        foreach ($publishedDiffusions as $diffusion) {
            if (!$diffusion instanceof Diffusion) {
                continue;
            }

            $diffusionId = $diffusion->getId();

            if (null === $diffusionId) {
                continue;
            }

            $draft = $draftByDiffusionId[$diffusionId] ?? null;

            if (!$draft instanceof DiffusionDraft) {
                $missingDraftCount++;
            }

            $items[] = [
                'diffusionId' => $diffusionId,
                'startsAt' => $diffusion
                    ->getHoraireDiffusion()
                    ?->format('Y-m-d H:i:s'),

                'emissionId' => $diffusion
                    ->getEmission()
                    ?->getId(),

                'emissionTitle' => $diffusion
                    ->getEmission()
                    ?->getTitre(),

                'assignmentGroupKey' => $diffusion
                    ->getAssignmentGroupKey(),

                'draftId' => $draft?->getId(),

                'draftPublicationStatus' => $draft
                    ?->getPublicationStatus(),

                'hasLinkedDraft' => $draft instanceof DiffusionDraft,
            ];
        }

        $publishedDiffusionCount = count($publishedDiffusions);
        $linkedDraftCount = $publishedDiffusionCount - $missingDraftCount;

        /*
         * Pour le moment :
         * toute Diffusion publiée doit avoir son Draft lié.
         */
        $canUnpublish =
            $publishedDiffusionCount > 0
            && 0 === $missingDraftCount;

        return [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,

            'publishedDiffusionCount' => $publishedDiffusionCount,
            'linkedDraftCount' => $linkedDraftCount,
            'missingDraftCount' => $missingDraftCount,

            'canUnpublish' => $canUnpublish,

            'reason' => $canUnpublish
                ? null
                : 'legacy_week_without_drafts',

            'items' => $items,
        ];
    }

    private function getRadioWeekStart(
        \DateTimeImmutable $date
    ): \DateTimeImmutable {
        $date = $date->setTime(0, 0, 0);

        $dayOfWeek = (int) $date->format('N');

        if ($dayOfWeek >= 2) {
            return $date->modify(
                sprintf('-%d days', $dayOfWeek - 2)
            );
        }

        return $date->modify('-6 days');
    }

    public function unpublishWeek(
        \DateTimeImmutable $weekStart
    ): array {
        return $this->entityManager->wrapInTransaction(
            function () use ($weekStart): array {
                $preview = $this->previewWeek($weekStart);

                if (!$preview['canUnpublish']) {
                    throw new \DomainException(
                        'Cette semaine ne peut pas être dévalidée automatiquement.'
                    );
                }

                $diffusionIds = array_map(
                    static fn(array $item): int => (int) $item['diffusionId'],
                    $preview['items']
                );

                $drafts = $this->draftRepository
                    ->findByPublishedDiffusionIds($diffusionIds);

                $draftByDiffusionId = [];

                foreach ($drafts as $draft) {
                    if (!$draft instanceof DiffusionDraft) {
                        continue;
                    }

                    $diffusionId = $draft->getPublishedDiffusion()?->getId();

                    if (null !== $diffusionId) {
                        $draftByDiffusionId[$diffusionId] = $draft;
                    }
                }

                $unpublishedDiffusions = [];
                $restoredDrafts = [];

                foreach ($preview['items'] as $item) {
                    $diffusionId = (int) ($item['diffusionId'] ?? 0);

                    if ($diffusionId <= 0) {
                        throw new \LogicException(
                            'Une Diffusion de la preview ne possède pas d’identifiant valide.'
                        );
                    }

                    $diffusion = $this->diffusionRepository->find($diffusionId);

                    if (!$diffusion instanceof Diffusion) {
                        throw new \LogicException(
                            sprintf(
                                'Diffusion #%d introuvable pendant la dévalidation.',
                                $diffusionId
                            )
                        );
                    }

                    $draft = $draftByDiffusionId[$diffusionId] ?? null;

                    if (!$draft instanceof DiffusionDraft) {
                        throw new \DomainException(
                            sprintf(
                                'La Diffusion #%d ne possède plus de draft lié.',
                                $diffusionId
                            )
                        );
                    }

                    /*
                 * La Diffusion reste en base.
                 * Elle est simplement ignorée par l'affichage validé.
                 */
                    $diffusion->markAsUnpublished();

                    /*
                 * Le draft redevient éditable.
                 *
                 * IMPORTANT :
                 * on conserve publishedDiffusion.
                 * C'est ce lien qui permettra à la prochaine validation
                 * de mettre à jour cette même Diffusion au lieu d'en créer
                 * une nouvelle.
                 */
                    $draft
                        ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)
                        ->setPublishedAt(null);

                    $unpublishedDiffusions[] = $diffusion;
                    $restoredDrafts[] = $draft;
                }

                $this->entityManager->flush();

                return [
                    'weekStart' => $preview['weekStart'],
                    'weekEnd' => $preview['weekEnd'],

                    'unpublishedDiffusionCount' => count($unpublishedDiffusions),
                    'restoredDraftCount' => count($restoredDrafts),

                    'unpublishedDiffusions' => $unpublishedDiffusions,
                    'restoredDrafts' => $restoredDrafts,
                ];
            }
        );
    }
}
