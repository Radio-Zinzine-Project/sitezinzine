<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class GridPublicationService
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Prépare la validation d’une semaine sans effectuer aucune écriture.
     *
     * @return array{
     *     weekStart: \DateTimeImmutable,
     *     weekEnd: \DateTimeImmutable,
     *     items: array<int, array<string, mixed>>,
     *     conflicts: array<int, array<string, mixed>>,
     *     futureDraftsLeft: DiffusionDraft[],
     *     publishableDraftCount: int,
     *     createCount: int,
     *     updateCount: int,
     *     conflictCount: int,
     *     futureDraftCount: int,
     *     hasBlockingConflicts: bool,
     *     canPublish: bool
     * }
     */
    public function previewWeekPublication(\DateTimeImmutable $weekStart): array
    {
        [$weekStart, $weekEnd] = $this->resolveRadioWeekBounds($weekStart);

        $drafts = $this->draftRepository->findPublishableByWeek(
            $weekStart,
            $weekEnd
        );

        /*
         * Les Diffusion présentes dans la semaine sont chargées une seule fois.
         * On les indexe ensuite par horaire pour éviter une requête par draft.
         */
        $existingDiffusions = $this->diffusionRepository->findByWeek(
            $weekStart,
            $weekEnd
        );

        $diffusionsByHoraire = $this->indexDiffusionsByHoraire(
            $existingDiffusions
        );

        $items = [];
        $conflicts = [];
        $assignmentGroupKeys = [];

        $createCount = 0;
        $updateCount = 0;

        foreach ($drafts as $draft) {
            $startsAt = $draft->getHoraireDiffusion();

            if (!$startsAt instanceof \DateTimeInterface) {
                $conflict = [
                    'type' => 'invalid_draft',
                    'message' => 'Le draft ne possède pas d’horaire valide.',
                    'draft' => $draft,
                    'existingDiffusions' => [],
                ];

                $conflicts[] = $conflict;

                $items[] = [
                    'draft' => $draft,
                    'action' => null,
                    'targetDiffusion' => null,
                    'startsAt' => null,
                    'hasConflict' => true,
                    'conflicts' => [$conflict],
                ];

                continue;
            }

            $publishedDiffusion = $draft->getPublishedDiffusion();

            /*
             * Un draft dévalidé conserve publishedDiffusion :
             * sa prochaine validation fera donc un UPDATE.
             *
             * Un draft jamais publié n’a pas de Diffusion liée :
             * sa validation fera un CREATE.
             */
            $action = $publishedDiffusion instanceof Diffusion
                ? 'update'
                : 'create';

            if ('update' === $action) {
                $updateCount++;
            } else {
                $createCount++;
            }

            $horaireKey = $this->buildHoraireKey($startsAt);

            $diffusionsAtSameTime = $diffusionsByHoraire[$horaireKey] ?? [];

            /*
             * La Diffusion déjà liée au draft n’est pas un conflit :
             * elle constitue précisément la ligne que l’on mettra à jour.
             *
             * Toute autre Diffusion au même horaire est bloquante.
             */
            $foreignDiffusions = array_values(array_filter(
                $diffusionsAtSameTime,
                static function (Diffusion $existing) use ($publishedDiffusion): bool {
                    if (!$publishedDiffusion instanceof Diffusion) {
                        return true;
                    }

                    return $existing->getId() !== $publishedDiffusion->getId();
                }
            ));

            $itemConflicts = [];

            if ([] !== $foreignDiffusions) {
                $itemConflicts[] = [
                    'type' => 'existing_diffusion_at_same_time',
                    'message' => sprintf(
                        'Une autre diffusion existe déjà le %s.',
                        $startsAt->format('d/m/Y à H:i')
                    ),
                    'draft' => $draft,
                    'existingDiffusions' => $foreignDiffusions,
                ];
            }

            foreach ($itemConflicts as $conflict) {
                $conflicts[] = $conflict;
            }

            $items[] = [
                'draft' => $draft,
                'action' => $action,
                'targetDiffusion' => $publishedDiffusion,
                'startsAt' => \DateTimeImmutable::createFromInterface($startsAt),
                'hasConflict' => [] !== $itemConflicts,
                'conflicts' => $itemConflicts,
            ];

            $assignmentGroupKey = $draft->getAssignmentGroupKey();

            if (\is_string($assignmentGroupKey) && '' !== trim($assignmentGroupKey)) {
                $assignmentGroupKeys[] = $assignmentGroupKey;
            }
        }

        $futureDraftsLeft = $this->draftRepository
            ->findFutureDraftsByAssignmentGroupKeys(
                $assignmentGroupKeys,
                $weekEnd
            );

        return [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,

            'items' => $items,
            'conflicts' => $conflicts,
            'futureDraftsLeft' => $futureDraftsLeft,

            'publishableDraftCount' => count($drafts),
            'createCount' => $createCount,
            'updateCount' => $updateCount,
            'conflictCount' => count($conflicts),
            'futureDraftCount' => count($futureDraftsLeft),

            'hasBlockingConflicts' => [] !== $conflicts,
            'canPublish' => [] !== $drafts && [] === $conflicts,
        ];
    }

    /**
     * Normalise n’importe quelle date vers la semaine radio mardi → lundi.
     *
     * La borne de fin est exclusive :
     * [mardi 00:00 ; mardi suivant 00:00[
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveRadioWeekBounds(
        \DateTimeImmutable $date
    ): array {
        $date = $date->setTime(0, 0, 0);
        $dayOfWeek = (int) $date->format('N');

        $daysSinceTuesday = match ($dayOfWeek) {
            2 => 0,
            3 => 1,
            4 => 2,
            5 => 3,
            6 => 4,
            7 => 5,
            1 => 6,
            default => 0,
        };

        $weekStart = 0 === $daysSinceTuesday
            ? $date
            : $date->modify(sprintf('-%d days', $daysSinceTuesday));

        return [
            $weekStart,
            $weekStart->modify('+7 days'),
        ];
    }

    /**
     * @param Diffusion[] $diffusions
     *
     * @return array<string, Diffusion[]>
     */
    private function indexDiffusionsByHoraire(array $diffusions): array
    {
        $index = [];

        foreach ($diffusions as $diffusion) {
            if (!$diffusion instanceof Diffusion) {
                continue;
            }

            $horaire = $diffusion->getHoraireDiffusion();

            if (!$horaire instanceof \DateTimeInterface) {
                continue;
            }

            $key = $this->buildHoraireKey($horaire);
            $index[$key][] = $diffusion;
        }

        return $index;
    }

    private function buildHoraireKey(\DateTimeInterface $horaire): string
    {
        return $horaire->format('Y-m-d H:i:s');
    }

    /**
     * Valide intégralement une semaine radio.
     *
     * La preview est recalculée dans la transaction afin d’éviter de publier
     * des données qui auraient changé entre l’affichage et la confirmation.
     *
     * @return array<string, mixed>
     */
    public function publishWeek(\DateTimeImmutable $weekStart): array
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($weekStart): array {
                $preview = $this->previewWeekPublication($weekStart);

                if ($preview['hasBlockingConflicts']) {
                    throw new \DomainException(
                        'La validation est bloquée par un ou plusieurs conflits.'
                    );
                }

                if (!$preview['canPublish']) {
                    throw new \DomainException(
                        'Aucun draft publiable n’a été trouvé pour cette semaine.'
                    );
                }

                /*
             * La preview fournit déjà les éléments dans l’ordre chronologique
             * puisque findPublishableByWeek() trie par horaire.
             *
             * On trie néanmoins explicitement ici pour sécuriser la règle
             * de numérotation.
             */
                $items = $preview['items'];

                usort(
                    $items,
                    static function (array $a, array $b): int {
                        $startsAtA = $a['startsAt'] ?? null;
                        $startsAtB = $b['startsAt'] ?? null;

                        if (
                            !$startsAtA instanceof \DateTimeInterface
                            || !$startsAtB instanceof \DateTimeInterface
                        ) {
                            return 0;
                        }

                        $comparison = $startsAtA <=> $startsAtB;

                        if (0 !== $comparison) {
                            return $comparison;
                        }

                        $draftIdA = $a['draft'] instanceof DiffusionDraft
                            ? ($a['draft']->getId() ?? 0)
                            : 0;

                        $draftIdB = $b['draft'] instanceof DiffusionDraft
                            ? ($b['draft']->getId() ?? 0)
                            : 0;

                        return $draftIdA <=> $draftIdB;
                    }
                );

                $emissionIds = [];

                foreach ($items as $item) {
                    $draft = $item['draft'] ?? null;
                    $emissionId = $draft instanceof DiffusionDraft
                        ? $draft->getEmission()?->getId()
                        : null;

                    if (\is_int($emissionId) && $emissionId > 0) {
                        $emissionIds[] = $emissionId;
                    }
                }

                $numberCounters = $this->diffusionRepository
                    ->findMaxNumbersBeforeForEmissionIds(
                        $emissionIds,
                        $preview['weekStart']
                    );

                $created = [];
                $updated = [];
                $publishedDrafts = [];

                foreach ($items as $item) {
                    $draft = $item['draft'] ?? null;

                    if (!$draft instanceof DiffusionDraft) {
                        throw new \LogicException(
                            'Un élément de publication ne contient pas de DiffusionDraft valide.'
                        );
                    }

                    if ($item['hasConflict'] ?? false) {
                        throw new \DomainException(
                            sprintf(
                                'Le draft #%d possède un conflit bloquant.',
                                $draft->getId() ?? 0
                            )
                        );
                    }

                    $emission = $draft->getEmission();
                    $startsAt = $draft->getHoraireDiffusion();

                    if (null === $emission || null === $emission->getId()) {
                        throw new \LogicException(
                            sprintf(
                                'Le draft #%d ne possède pas d’émission valide.',
                                $draft->getId() ?? 0
                            )
                        );
                    }

                    if (!$startsAt instanceof \DateTimeInterface) {
                        throw new \LogicException(
                            sprintf(
                                'Le draft #%d ne possède pas d’horaire valide.',
                                $draft->getId() ?? 0
                            )
                        );
                    }

                    $emissionId = $emission->getId();

                    $numberCounters[$emissionId] =
                        ($numberCounters[$emissionId] ?? 0) + 1;

                    $diffusion = $draft->getPublishedDiffusion();

                    if ($diffusion instanceof Diffusion) {
                        $updated[] = $diffusion;
                    } else {
                        $diffusion = new Diffusion();
                        $this->entityManager->persist($diffusion);
                        $created[] = $diffusion;
                    }

                    $durationMinutes = $draft->getEffectiveDurationMinutes();

                    if (null !== $durationMinutes && $durationMinutes < 1) {
                        $durationMinutes = null;
                    }

                    $endsAt = $draft->getEndsAt();

                    if (
                        null === $endsAt
                        && null !== $durationMinutes
                    ) {
                        $endsAt = \DateTimeImmutable::createFromInterface($startsAt)
                            ->modify(sprintf('+%d minutes', $durationMinutes));
                    }

                    $mutableStartsAt = \DateTime::createFromInterface($startsAt);

                    $mutableEndsAt = null;

                    if ($endsAt instanceof \DateTimeInterface) {
                        $mutableEndsAt = \DateTime::createFromInterface($endsAt);
                    }

                    $diffusion
                        ->setEmission($emission)
                        ->setHoraireDiffusion($mutableStartsAt)
                        ->setNombreDiffusion($numberCounters[$emissionId])
                        ->setDurationMinutes($durationMinutes)
                        ->setEndsAt($mutableEndsAt)
                        ->setAssignmentGroupKey($draft->getAssignmentGroupKey())
                        ->markAsPublished();

                    $draft->markAsPublished(
                        $diffusion,
                        new \DateTimeImmutable()
                    );

                    $publishedDrafts[] = $draft;
                }

                /*
             * wrapInTransaction() effectuera également un flush à la fin,
             * mais le flush explicite rend l’intention claire et permet de
             * détecter une erreur SQL avant de construire le résultat.
             */
                $this->entityManager->flush();

                return [
                    'published' => true,
                    'weekStart' => $preview['weekStart'],
                    'weekEnd' => $preview['weekEnd'],
                    'createdCount' => count($created),
                    'updatedCount' => count($updated),
                    'publishedDraftCount' => count($publishedDrafts),
                    'created' => $created,
                    'updated' => $updated,
                    'publishedDrafts' => $publishedDrafts,
                    'futureDraftsLeft' => $preview['futureDraftsLeft'],
                    'futureDraftCount' => $preview['futureDraftCount'],
                ];
            }
        );
    }
}
