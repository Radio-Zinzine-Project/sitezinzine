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
     * Après une dévalidation, DiffusionDraft est la source de vérité.
     *
     * - Un Draft ayant encore une publishedDiffusion met à jour cette Diffusion.
     * - Un Draft sans publishedDiffusion peut réutiliser une ancienne Diffusion
     *   non revendiquée située au même horaire.
     * - S'il n'existe aucune Diffusion réutilisable, une nouvelle sera créée.
     * - Une ancienne Diffusion non utilisée ne bloque pas la validation :
     *   elle reste simplement non publiée.
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
    public function previewWeekPublication(
        \DateTimeImmutable $weekStart
    ): array {
        [$weekStart, $weekEnd] = $this->resolveRadioWeekBounds(
            $weekStart
        );

        $drafts = $this->draftRepository->findPublishableByWeek(
            $weekStart,
            $weekEnd
        );

        /*
         * Toutes les Diffusion encore présentes dans la semaine sont chargées.
         *
         * Après dévalidation, certaines représentent l'ancienne version
         * validée de la grille. Elles ne doivent donc pas être considérées
         * automatiquement comme des conflits.
         */
        $existingDiffusions = $this->diffusionRepository->findByWeek(
            $weekStart,
            $weekEnd
        );

        $diffusionsByHoraire = $this->indexDiffusionsByHoraire(
            $existingDiffusions
        );

        /*
         * Une Diffusion déjà liée à un Draft publiable est réservée à ce Draft.
         *
         * Cela évite qu'un autre Draft sans publishedDiffusion récupère
         * accidentellement cette même Diffusion.
         *
         * Structure :
         * diffusionId => draftId
         */
        $reservedDiffusionIds = [];

        foreach ($drafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            $publishedDiffusion = $draft->getPublishedDiffusion();

            if (!$publishedDiffusion instanceof Diffusion) {
                continue;
            }

            $diffusionId = $publishedDiffusion->getId();

            if (null === $diffusionId) {
                continue;
            }

            $reservedDiffusionIds[$diffusionId] = $draft->getId();
        }

        $items = [];
        $conflicts = [];
        $assignmentGroupKeys = [];

        $createCount = 0;
        $updateCount = 0;

        foreach ($drafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

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

            $horaireKey = $this->buildHoraireKey($startsAt);

            $diffusionsAtSameTime = $diffusionsByHoraire[$horaireKey] ?? [];

            $publishedDiffusion = $draft->getPublishedDiffusion();

            $targetDiffusion = null;
            $action = null;
            $itemConflicts = [];

            /*
             * CAS 1
             * -----
             * Le Draft possède encore la Diffusion à laquelle il était lié
             * avant la dévalidation.
             *
             * On conserve cette même Diffusion et on la mettra à jour.
             */
            if ($publishedDiffusion instanceof Diffusion) {
                $targetDiffusion = $publishedDiffusion;
                $action = 'update';

                $targetDiffusionId = $publishedDiffusion->getId();

                /*
                 * On vérifie uniquement qu'aucune AUTRE Diffusion déjà
                 * réservée à un autre Draft actif ne revendique exactement
                 * le même horaire.
                 *
                 * Les anciennes Diffusion non revendiquées sont ignorées :
                 * elles appartiennent à une ancienne version de la semaine
                 * et restent non publiées.
                 */
                foreach ($diffusionsAtSameTime as $existing) {
                    if (!$existing instanceof Diffusion) {
                        continue;
                    }

                    $existingId = $existing->getId();

                    if (null === $existingId) {
                        continue;
                    }

                    if (
                        null !== $targetDiffusionId
                        && $existingId === $targetDiffusionId
                    ) {
                        continue;
                    }

                    if (!isset($reservedDiffusionIds[$existingId])) {
                        continue;
                    }

                    $ownerDraftId = $reservedDiffusionIds[$existingId];

                    if ($ownerDraftId === $draft->getId()) {
                        continue;
                    }

                    $itemConflicts[] = [
                        'type' => 'diffusion_reserved_by_other_draft',
                        'message' => sprintf(
                            'Une autre émission du brouillon utilise déjà le créneau du %s.',
                            $startsAt->format('d/m/Y à H:i')
                        ),
                        'draft' => $draft,
                        'existingDiffusions' => [$existing],
                    ];
                }
            } else {
                /*
                 * CAS 2
                 * -----
                 * Draft sans publishedDiffusion.
                 *
                 * Cela peut arriver :
                 * - pour un nouveau créneau ajouté après dévalidation ;
                 * - pour un Draft dont l'ancien lien a disparu ;
                 * - pour une nouvelle série générée dans la semaine brouillon.
                 *
                 * DiffusionDraft reste la source de vérité.
                 */

                $diffusionsReservedByOtherDraft = [];
                $reusableDiffusions = [];

                foreach ($diffusionsAtSameTime as $existing) {
                    if (!$existing instanceof Diffusion) {
                        continue;
                    }

                    $existingId = $existing->getId();

                    if (null === $existingId) {
                        continue;
                    }

                    if (isset($reservedDiffusionIds[$existingId])) {
                        $diffusionsReservedByOtherDraft[] = $existing;

                        continue;
                    }

                    /*
                     * Cette Diffusion n'est utilisée par aucun autre Draft
                     * publiable de la semaine.
                     *
                     * Elle appartient donc potentiellement à l'ancienne
                     * version dévalidée et peut être réutilisée.
                     */
                    $reusableDiffusions[] = $existing;
                }

                /*
                 * Si une Diffusion au même horaire est déjà réservée à
                 * un autre Draft actif, on ne peut pas la voler.
                 *
                 * C'est un vrai conflit de brouillon.
                 */
                if ([] !== $diffusionsReservedByOtherDraft) {
                    $itemConflicts[] = [
                        'type' => 'diffusion_reserved_by_other_draft',
                        'message' => sprintf(
                            'Une autre émission du brouillon utilise déjà le créneau du %s.',
                            $startsAt->format('d/m/Y à H:i')
                        ),
                        'draft' => $draft,
                        'existingDiffusions' => $diffusionsReservedByOtherDraft,
                    ];
                } elseif (1 === count($reusableDiffusions)) {
                    /*
                     * Une seule ancienne Diffusion libre existe au même
                     * horaire : on la réutilise.
                     *
                     * Exemple :
                     *
                     * ancienne Diffusion :
                     * 06/09 19h -> émission 11931
                     *
                     * nouveau Draft :
                     * 06/09 19h -> émission 11945
                     *
                     * => UPDATE de l'ancienne Diffusion.
                     */
                    $targetDiffusion = $reusableDiffusions[0];
                    $action = 'update';

                    $targetId = $targetDiffusion->getId();

                    if (null !== $targetId) {
                        $reservedDiffusionIds[$targetId] = $draft->getId();
                    }
                } else {
                    /*
                     * Aucune ancienne Diffusion réutilisable.
                     *
                     * C'est donc un véritable nouveau créneau.
                     *
                     * Si plusieurs anciennes Diffusion non revendiquées
                     * existent au même horaire, on ne choisit pas arbitrairement
                     * laquelle réutiliser : on crée une nouvelle ligne propre.
                     * Les anciennes restent non publiées.
                     */
                    $action = 'create';
                }
            }

            if ('update' === $action) {
                $updateCount++;
            } elseif ('create' === $action) {
                $createCount++;
            }

            foreach ($itemConflicts as $conflict) {
                $conflicts[] = $conflict;
            }

            $items[] = [
                'draft' => $draft,
                'action' => $action,
                'targetDiffusion' => $targetDiffusion,
                'startsAt' => \DateTimeImmutable::createFromInterface(
                    $startsAt
                ),
                'hasConflict' => [] !== $itemConflicts,
                'conflicts' => $itemConflicts,
            ];

            $assignmentGroupKey = $draft->getAssignmentGroupKey();

            if (
                \is_string($assignmentGroupKey)
                && '' !== trim($assignmentGroupKey)
            ) {
                $assignmentGroupKeys[] = $assignmentGroupKey;
            }
        }

        $assignmentGroupKeys = array_values(
            array_unique($assignmentGroupKeys)
        );

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
            : $date->modify(
                sprintf('-%d days', $daysSinceTuesday)
            );

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
    private function indexDiffusionsByHoraire(
        array $diffusions
    ): array {
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

    private function buildHoraireKey(
        \DateTimeInterface $horaire
    ): string {
        return $horaire->format('Y-m-d H:i:s');
    }

    /**
     * Valide intégralement une semaine radio.
     *
     * La preview est recalculée dans la transaction afin d’éviter
     * de publier des données qui auraient changé entre l’affichage
     * et la confirmation.
     *
     * DiffusionDraft constitue la source de vérité de la semaine.
     *
     * @return array<string, mixed>
     */
    public function publishWeek(
        \DateTimeImmutable $weekStart
    ): array {
        return $this->entityManager->wrapInTransaction(
            function () use ($weekStart): array {
                $preview = $this->previewWeekPublication(
                    $weekStart
                );

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
                 * On conserve un ordre de traitement stable.
                 */
                $items = $preview['items'];

                usort(
                    $items,
                    static function (
                        array $a,
                        array $b
                    ): int {
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

                        $draftIdA = $a['draft']
                            instanceof DiffusionDraft
                            ? ($a['draft']->getId() ?? 0)
                            : 0;

                        $draftIdB = $b['draft']
                            instanceof DiffusionDraft
                            ? ($b['draft']->getId() ?? 0)
                            : 0;

                        return $draftIdA <=> $draftIdB;
                    }
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

                    if (
                        null === $emission
                        || null === $emission->getId()
                    ) {
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

                    /*
                     * Le rang est déjà déterminé dans DiffusionDraft.
                     * On ne le recalcule pas depuis l'historique.
                     */
                    $nombreDiffusion = $draft->getNombreDiffusion();

                    if (
                        null === $nombreDiffusion
                        || $nombreDiffusion < 1
                    ) {
                        throw new \LogicException(
                            sprintf(
                                'Le draft #%d possède un nombreDiffusion invalide.',
                                $draft->getId() ?? 0
                            )
                        );
                    }

                    /*
                     * IMPORTANT :
                     *
                     * On utilise la targetDiffusion déterminée pendant
                     * la preview.
                     *
                     * Elle peut être :
                     * - la publishedDiffusion historique du Draft ;
                     * - une ancienne Diffusion réutilisée au même horaire ;
                     * - null pour un nouveau créneau.
                     */
                    $diffusion = $item['targetDiffusion'] ?? null;

                    if ($diffusion instanceof Diffusion) {
                        $updated[] = $diffusion;
                    } else {
                        $diffusion = new Diffusion();

                        $this->entityManager->persist($diffusion);

                        $created[] = $diffusion;
                    }

                    $durationMinutes = $draft
                        ->getEffectiveDurationMinutes();

                    if (
                        null !== $durationMinutes
                        && $durationMinutes < 1
                    ) {
                        $durationMinutes = null;
                    }

                    $endsAt = $draft->getEndsAt();

                    if (
                        null === $endsAt
                        && null !== $durationMinutes
                    ) {
                        $endsAt = \DateTimeImmutable
                            ::createFromInterface($startsAt)
                            ->modify(
                                sprintf(
                                    '+%d minutes',
                                    $durationMinutes
                                )
                            );
                    }

                    $mutableStartsAt = \DateTime::createFromInterface(
                        $startsAt
                    );

                    $mutableEndsAt = null;

                    if ($endsAt instanceof \DateTimeInterface) {
                        $mutableEndsAt = \DateTime::createFromInterface(
                            $endsAt
                        );
                    }

                    /*
                     * Le Draft est la source de vérité :
                     * toutes les valeurs publiées proviennent de lui.
                     */
                    $diffusion
                        ->setEmission($emission)
                        ->setHoraireDiffusion($mutableStartsAt)
                        ->setNombreDiffusion($nombreDiffusion)
                        ->setDurationMinutes($durationMinutes)
                        ->setEndsAt($mutableEndsAt)
                        ->setAssignmentGroupKey(
                            $draft->getAssignmentGroupKey()
                        )
                        ->markAsPublished();

                    /*
                     * markAsPublished() rattache également le Draft
                     * à la Diffusion réellement utilisée.
                     *
                     * C'est particulièrement important lorsqu'on vient
                     * de récupérer une ancienne Diffusion qui n'était
                     * plus liée au Draft.
                     */
                    $draft->markAsPublished(
                        $diffusion,
                        new \DateTimeImmutable()
                    );

                    $publishedDrafts[] = $draft;
                }

                $this->entityManager->flush();

                return [
                    'published' => true,
                    'weekStart' => $preview['weekStart'],
                    'weekEnd' => $preview['weekEnd'],

                    'createdCount' => count($created),
                    'updatedCount' => count($updated),
                    'publishedDraftCount' => count(
                        $publishedDrafts
                    ),

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
