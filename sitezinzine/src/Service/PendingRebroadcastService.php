<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\DiffusionRepository;
use App\Repository\PendingRebroadcastRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PendingRebroadcastService implements PendingRebroadcastPopulatorInterface
{
    private const MAX_DIFFUSIONS_FOR_POOL = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DiffusionRepository $diffusionRepository,
        private readonly PendingRebroadcastRepository $pendingRebroadcastRepository
    ) {}

    /**
     * Crée une rediffusion en attente pour un groupe.
     *
     * Le flush est volontairement laissé à l'appelant afin que la création
     * du PendingRebroadcast puisse faire partie d'une opération Doctrine
     * plus large et rester atomique.
     */
    public function createForGroup(
        Emission $emission,
        string $assignmentGroupKey
    ): PendingRebroadcast {
        if ('' === trim($assignmentGroupKey)) {
            throw new \InvalidArgumentException(
                'La clé du groupe d’affectation ne peut pas être vide.'
            );
        }

        $pendingRebroadcast = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($assignmentGroupKey);

        $this->entityManager->persist($pendingRebroadcast);

        return $pendingRebroadcast;
    }

    /**
     * Alimente automatiquement le parc à rediff à partir des diffusions
     * concernées par une publication.
     *
     * Une émission est ajoutée au parc uniquement si :
     * - elle appartient au lot de diffusions fourni ;
     * - elle possède moins de 3 diffusions publiées avant la borne fournie ;
     * - elle ne possède pas déjà au moins une occurrence dans le parc.
     *
     * Plusieurs diffusions de la même émission dans le lot ne peuvent créer
     * qu'une seule occurrence automatique.
     *
     * L'assignmentGroupKey de la première diffusion chronologique valide
     * de l'émission dans le lot est conservé.
     *
     * Le flush reste volontairement à la charge de l'appelant.
     *
     * @param Diffusion[] $diffusions
     *
     * @return PendingRebroadcast[] Pending créées par cette synchronisation
     */
public function populateFromPublishedDiffusions(
    array $diffusions,
    \DateTimeInterface $before
): array {
    if ([] === $diffusions) {
        return [];
    }

    /**
     * On trie les diffusions chronologiquement afin que, pour chaque groupe,
     * la première diffusion valide rencontrée soit utilisée comme source.
     */
    usort(
        $diffusions,
        static function (Diffusion $left, Diffusion $right): int {
            $leftStartsAt = $left->getHoraireDiffusion();
            $rightStartsAt = $right->getHoraireDiffusion();

            if (null === $leftStartsAt && null === $rightStartsAt) {
                return 0;
            }

            if (null === $leftStartsAt) {
                return 1;
            }

            if (null === $rightStartsAt) {
                return -1;
            }

            return $leftStartsAt <=> $rightStartsAt;
        }
    );

    /**
     * On construit une source unique par groupe d'affectation.
     *
     * Une même émission peut donc avoir plusieurs groupes indépendants.
     *
     * @var array<string, Diffusion> $sourceByAssignmentGroupKey
     */
    $sourceByAssignmentGroupKey = [];

    foreach ($diffusions as $diffusion) {
        if (!$diffusion instanceof Diffusion) {
            throw new \InvalidArgumentException(
                'Le lot doit contenir uniquement des Diffusion.'
            );
        }

        /*
         * La synchronisation du parc ne doit jamais prendre pour source
         * une Diffusion qui n'est pas publiée.
         */
        if (!$diffusion->isPublished()) {
            continue;
        }

        $emission = $diffusion->getEmission();

        if (!$emission instanceof Emission || null === $emission->getId()) {
            continue;
        }

        $assignmentGroupKey = $diffusion->getAssignmentGroupKey();

        if (
            null === $assignmentGroupKey
            || '' === trim($assignmentGroupKey)
        ) {
            continue;
        }

        /*
         * Une seule source par groupe.
         *
         * Le tri chronologique garantit que la première diffusion
         * du groupe est conservée.
         */
        if (isset($sourceByAssignmentGroupKey[$assignmentGroupKey])) {
            continue;
        }

        $sourceByAssignmentGroupKey[$assignmentGroupKey] = $diffusion;
    }

    if ([] === $sourceByAssignmentGroupKey) {
        return [];
    }

    $assignmentGroupKeys = array_keys(
        $sourceByAssignmentGroupKey
    );

    $publishedCounts =
        $this->diffusionRepository
            ->countPublishedBeforeForAssignmentGroupKeys(
                $assignmentGroupKeys,
                $before
            );

    $created = [];

    foreach (
        $sourceByAssignmentGroupKey
        as $assignmentGroupKey => $sourceDiffusion
    ) {
        $publishedCount =
            $publishedCounts[$assignmentGroupKey] ?? 0;

        /*
         * Trois diffusions du même groupe incluses :
         * le groupe ne doit plus entrer dans le parc.
         */
        if ($publishedCount >= self::MAX_DIFFUSIONS_FOR_POOL) {
            continue;
        }

        /*
         * Un groupe déjà présent dans le parc ne doit pas recevoir
         * automatiquement un deuxième Pending.
         */
        if (
            $this->pendingRebroadcastRepository
                ->existsForAssignmentGroupKey($assignmentGroupKey)
        ) {
            continue;
        }

        $emission = $sourceDiffusion->getEmission();

        if (!$emission instanceof Emission) {
            continue;
        }

        $created[] = $this->createForGroup(
            $emission,
            $assignmentGroupKey
        );
    }

    return $created;
}
}
