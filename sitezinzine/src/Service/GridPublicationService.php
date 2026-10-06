<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\GridSlotArbitration;
use App\Entity\ProgrammationRuleSlot;
use App\Repository\DiffusionDraftRepository;
use App\Repository\DiffusionRepository;
use App\Repository\GridSlotArbitrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class GridPublicationService
{
    public function __construct(
        private readonly DiffusionDraftRepository $draftRepository,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly GridSlotArbitrationRepository $arbitrationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PendingRebroadcastPopulatorInterface $pendingRebroadcastService,
        private readonly ClockInterface $clock,
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
     * - Une occurrence annulée par arbitrage n'est pas publiée.
     * - Une occurrence déplacée est évaluée à son horaire effectif.
     * - Une occurrence déplacée vers une autre semaine appartient à sa
     *   semaine de destination pour la publication.
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

        /*
     * Drafts dont l'horaire brut appartient à la semaine.
     *
     * Cette liste n'est pas encore la liste réellement publiable :
     * les arbitrages peuvent annuler une occurrence, la déplacer hors
     * de la semaine, ou faire entrer dans la semaine une occurrence
     * dont le Draft source appartient à une autre semaine.
     */
        $drafts = $this->draftRepository->findPublishableByWeek(
            $weekStart,
            $weekEnd
        );

        /*
     * Résolution de la réalité métier après arbitrages.
     *
     * Chaque entrée conserve le DiffusionDraft source, mais possède
     * l'horaire auquel cette occurrence doit réellement être publiée.
     */
        $effectiveDrafts = $this->resolveEffectivePublishableDrafts(
            $drafts,
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
     * Une Diffusion déjà liée à un Draft effectivement publiable est
     * réservée à ce Draft.
     *
     * Important :
     * un Draft annulé ou déplacé hors de cette semaine ne réserve plus
     * sa publishedDiffusion pour cette validation.
     *
     * Structure :
     * diffusionId => draftId
     */
        $reservedDiffusionIds = [];

        foreach ($effectiveDrafts as $effectiveDraft) {
            $draft = $effectiveDraft['draft'];

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

        foreach ($effectiveDrafts as $effectiveDraft) {
            $draft = $effectiveDraft['draft'];
            $startsAt = $effectiveDraft['startsAt'];

            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            /*
         * On conserve le comportement historique pour un Draft invalide.
         *
         * resolveEffectivePublishableDrafts() laisse volontairement passer
         * ces Drafts avec startsAt = null afin que la preview puisse signaler
         * explicitement le problème.
         */
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

            $startsAt = \DateTimeImmutable::createFromInterface(
                $startsAt
            );

            /*
         * Toutes les recherches de Diffusion se font désormais avec
         * l'horaire EFFECTIF et non avec l'horaire brut du Draft.
         */
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
         *
         * Si l'occurrence a été déplacée, cette Diffusion pourra actuellement
         * se trouver à l'ancien horaire. Cela ne change pas son appartenance
         * au Draft : elle reste sa cible de mise à jour.
         */
            if ($publishedDiffusion instanceof Diffusion) {
                $targetDiffusion = $publishedDiffusion;
                $action = 'update';

                $targetDiffusionId = $publishedDiffusion->getId();

                /*
             * On vérifie uniquement qu'aucune AUTRE Diffusion déjà
             * réservée à un autre Draft actif ne revendique exactement
             * le même horaire effectif.
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
                 * effectivement publiable de la semaine.
                 *
                 * Elle appartient donc potentiellement à l'ancienne
                 * version dévalidée et peut être réutilisée.
                 */
                    $reusableDiffusions[] = $existing;
                }

                /*
             * Si une Diffusion au même horaire effectif est déjà réservée
             * à un autre Draft actif, on ne peut pas la voler.
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
                 * horaire effectif : on la réutilise.
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
                 * existent au même horaire, on ne choisit pas
                 * arbitrairement laquelle réutiliser : on crée une
                 * nouvelle ligne propre.
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
                'startsAt' => $startsAt,
                'hasConflict' => [] !== $itemConflicts,
                'conflicts' => $itemConflicts,
            ];

            /*
         * Seuls les groupes réellement présents dans la semaine après
         * arbitrage participent à la recherche des rediffusions futures.
         */
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

            /*
         * Ce compteur représente désormais les occurrences réellement
         * publiables dans cette semaine et non les Drafts bruts dont
         * l'horaire source appartient à la semaine.
         */
            'publishableDraftCount' => count($effectiveDrafts),

            'createCount' => $createCount,
            'updateCount' => $updateCount,
            'conflictCount' => count($conflicts),
            'futureDraftCount' => count($futureDraftsLeft),

            'hasBlockingConflicts' => [] !== $conflicts,
            'canPublish' => [] !== $effectiveDrafts && [] === $conflicts,
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
             *
             * IMPORTANT :
             * startsAt correspond à l'horaire EFFECTIF déterminé
             * par la preview.
             *
             * Il peut donc différer de DiffusionDraft::horaireDiffusion
             * lorsqu'une occurrence a été déplacée par arbitrage.
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
                $publishedDiffusions = [];

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

                    /*
                 * L'horaire de publication vient impérativement
                 * de l'occurrence EFFECTIVE calculée par la preview.
                 *
                 * Le Draft conserve volontairement son horaire d'origine
                 * lorsqu'un GridSlotArbitration déplace l'occurrence.
                 */
                    $startsAt = $item['startsAt'] ?? null;

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
                                'Le draft #%d ne possède pas d’horaire effectif valide.',
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

                    $mutableStartsAt = \DateTime::createFromInterface(
                        $startsAt
                    );

                    /*
                 * La fin doit rester cohérente avec l'horaire EFFECTIF.
                 *
                 * Si une durée est connue, setSchedule() recalculera
                 * automatiquement endsAt depuis startsAt + durée.
                 *
                 * On ne réutilise donc pas le endsAt original du Draft
                 * pour une occurrence éventuellement déplacée.
                 */
                    $diffusion
                        ->setEmission($emission)
                        ->setNombreDiffusion($nombreDiffusion)
                        ->setAssignmentGroupKey(
                            $draft->getAssignmentGroupKey()
                        )
                        ->markAsPublished();

                    if (null !== $durationMinutes) {
                        $diffusion->setSchedule(
                            $mutableStartsAt,
                            $durationMinutes
                        );
                    } else {
                        /*
                     * Sans durée exploitable, on conserve le comportement
                     * historique : début publié, durée et fin nulles.
                     */
                        $diffusion
                            ->setEndsAt(null)
                            ->setHoraireDiffusion($mutableStartsAt)
                            ->setDurationMinutes(null);
                    }

                    /*
                 * markAsPublished() rattache également le Draft
                 * à la Diffusion réellement utilisée.
                 *
                 * Le Draft conserve son horaire d'origine :
                 * l'arbitrage reste la source de vérité du déplacement.
                 */
                    $draft->markAsPublished(
                        $diffusion,
                        new \DateTimeImmutable()
                    );

                    $publishedDrafts[] = $draft;
                    $publishedDiffusions[] = $diffusion;
                }

                /*
             * Alimentation automatique du parc à rediff.
             *
             * La borne correspond à la fin de la semaine radio contenant
             * le présent lorsque la semaine publiée est passée ou présente.
             *
             * Une semaine future ne doit jamais étendre cette borne.
             */
                $poolBefore = $this->resolvePendingRebroadcastUpperBound();

                /*
             * Les Diffusion doivent être synchronisées en base avant
             * l'alimentation du parc.
             *
             * PendingRebroadcastService compte les Diffusion publiées via une
             * requête SQL. Sans ce flush intermédiaire, les nouvelles Diffusion
             * de la semaine courante ne sont pas encore visibles par cette requête.
             *
             * Ce flush reste dans la même transaction Doctrine :
             * il ne valide donc pas définitivement la publication si la suite
             * de l'opération échoue.
             */
                $this->entityManager->flush();

                $this->pendingRebroadcastService
                    ->populateFromPublishedDiffusions(
                        $publishedDiffusions,
                        $poolBefore
                    );

                /*
             * Flush des éventuels PendingRebroadcast créés par la synchronisation.
             */
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

    /**
     * Détermine la borne temporelle exclusive utilisée pour alimenter
     * automatiquement le parc à rediff.
     *
     * Le présent constitue la limite métier : on tient compte des Diffusion
     * publiées jusqu'à la fin de la semaine radio contenant aujourd'hui,
     * jamais au-delà.
     */
    private function resolvePendingRebroadcastUpperBound(): \DateTimeImmutable
    {
        $now = \DateTimeImmutable::createFromInterface(
            $this->clock->now()
        );

        [, $currentWeekEnd] = $this->resolveRadioWeekBounds($now);

        return $currentWeekEnd;
    }

    /**
     * Résout les Drafts effectivement publiables dans la semaine demandée.
     *
     * Le DiffusionDraft conserve toujours son horaire d'origine.
     * Les arbitrages déterminent uniquement l'horaire effectif de publication.
     *
     * @return array<int, array{
     *     draft: DiffusionDraft,
     *     startsAt: \DateTimeImmutable
     * }>
     */
    private function resolveEffectivePublishableDrafts(
        array $drafts,
        \DateTimeImmutable $weekStart,
        \DateTimeImmutable $weekEnd
    ): array {
        $arbitrations = $this->arbitrationRepository->findRelevantForWeek(
            $weekStart,
            $weekEnd
        );

        /** @var array<string, GridSlotArbitration> $arbitrationsByOccurrence */
        $arbitrationsByOccurrence = [];

        foreach ($arbitrations as $arbitration) {
            if (!$arbitration instanceof GridSlotArbitration) {
                continue;
            }

            $slot = $arbitration->getSlot();
            $originalStartsAt = $arbitration->getOriginalStartsAt();

            if (
                !$slot instanceof ProgrammationRuleSlot
                || null === $slot->getId()
                || !$originalStartsAt instanceof \DateTimeInterface
            ) {
                continue;
            }

            $key = $this->buildOccurrenceKey(
                (int) $slot->getId(),
                $originalStartsAt
            );

            $arbitrationsByOccurrence[$key] = $arbitration;
        }

        $effectiveDrafts = [];

        /*
     * Première passe :
     *
     * on traite les Drafts dont l'horaire d'origine appartient déjà
     * à la semaine.
     */
        foreach ($drafts as $draft) {
            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            $startsAt = $draft->getHoraireDiffusion();

            /*
         * On conserve volontairement les Drafts invalides.
         *
         * previewWeekPublication() doit continuer à produire son conflit
         * "invalid_draft" historique.
         */
            if (!$startsAt instanceof \DateTimeInterface) {
                $effectiveDrafts[] = [
                    'draft' => $draft,
                    'startsAt' => null,
                ];

                continue;
            }

            $effectiveStartsAt = \DateTimeImmutable::createFromInterface(
                $startsAt
            );

            $slot = $draft->getSlot();

            if (
                !$slot instanceof ProgrammationRuleSlot
                || null === $slot->getId()
            ) {
                $effectiveDrafts[] = [
                    'draft' => $draft,
                    'startsAt' => $effectiveStartsAt,
                ];

                continue;
            }

            $occurrenceKey = $this->buildOccurrenceKey(
                (int) $slot->getId(),
                $startsAt
            );

            $arbitration = $arbitrationsByOccurrence[$occurrenceKey] ?? null;

            if (!$arbitration instanceof GridSlotArbitration) {
                $effectiveDrafts[] = [
                    'draft' => $draft,
                    'startsAt' => $effectiveStartsAt,
                ];

                continue;
            }

            /*
         * Une occurrence annulée n'est plus publiable.
         */
            if ($arbitration->isCancelAction()) {
                continue;
            }

            if (!$arbitration->isRescheduleAction()) {
                $effectiveDrafts[] = [
                    'draft' => $draft,
                    'startsAt' => $effectiveStartsAt,
                ];

                continue;
            }

            $rescheduledStartsAt = $arbitration->getRescheduledStartsAt();

            if (!$rescheduledStartsAt instanceof \DateTimeInterface) {
                continue;
            }

            $rescheduledStartsAt = \DateTimeImmutable::createFromInterface(
                $rescheduledStartsAt
            );

            /*
         * L'origine appartient à cette semaine mais sa destination
         * appartient à une autre semaine.
         *
         * Le Draft reste en base à son horaire d'origine, mais il ne doit
         * pas être publié dans cette semaine.
         */
            if (
                $rescheduledStartsAt < $weekStart
                || $rescheduledStartsAt >= $weekEnd
            ) {
                continue;
            }

            /*
         * Déplacement à l'intérieur de la même semaine.
         */
            $effectiveDrafts[] = [
                'draft' => $draft,
                'startsAt' => $rescheduledStartsAt,
            ];
        }

        /*
     * Deuxième passe :
     *
     * recherche des occurrences dont l'origine est hors semaine mais dont
     * la destination entre dans la semaine.
     */
        foreach ($arbitrations as $arbitration) {
            if (
                !$arbitration instanceof GridSlotArbitration
                || !$arbitration->isRescheduleAction()
            ) {
                continue;
            }

            $rescheduledStartsAt = $arbitration->getRescheduledStartsAt();

            if (!$rescheduledStartsAt instanceof \DateTimeInterface) {
                continue;
            }

            $rescheduledStartsAt = \DateTimeImmutable::createFromInterface(
                $rescheduledStartsAt
            );

            if (
                $rescheduledStartsAt < $weekStart
                || $rescheduledStartsAt >= $weekEnd
            ) {
                continue;
            }

            $originalStartsAt = $arbitration->getOriginalStartsAt();
            $slot = $arbitration->getSlot();

            if (
                !$originalStartsAt instanceof \DateTimeInterface
                || !$slot instanceof ProgrammationRuleSlot
            ) {
                continue;
            }

            $originalStartsAt = \DateTimeImmutable::createFromInterface(
                $originalStartsAt
            );

            /*
         * Si l'origine appartient elle-même à la semaine, elle a déjà été
         * traitée pendant la première passe.
         */
            if (
                $originalStartsAt >= $weekStart
                && $originalStartsAt < $weekEnd
            ) {
                continue;
            }

            $draft = $this->draftRepository
                ->findOneActiveDraftBySlotAndHoraire(
                    $slot,
                    $originalStartsAt
                );

            if (!$draft instanceof DiffusionDraft) {
                continue;
            }

            /*
         * Sécurité contre un éventuel doublon.
         */
            $alreadyPresent = false;

            foreach ($effectiveDrafts as $effectiveDraft) {
                if ($effectiveDraft['draft'] === $draft) {
                    $alreadyPresent = true;
                    break;
                }
            }

            if ($alreadyPresent) {
                continue;
            }

            $effectiveDrafts[] = [
                'draft' => $draft,
                'startsAt' => $rescheduledStartsAt,
            ];
        }

        return $effectiveDrafts;
    }

    /**
     * Construit la clé métier identifiant une occurrence régulière.
     */
    private function buildOccurrenceKey(
        int $slotId,
        \DateTimeInterface $startsAt
    ): string {
        return sprintf(
            '%d|%s',
            $slotId,
            $startsAt->format('Y-m-d H:i:s')
        );
    }
}
