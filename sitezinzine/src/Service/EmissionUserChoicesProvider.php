<?php

namespace App\Service;

use SortDirection;

use App\Entity\Categories;
use App\Entity\Emission;
use App\Entity\User;
use App\Repository\UserRepository;

final class EmissionUserChoicesProvider
{
    public const STATUS_CURRENT = 'current';
    public const STATUS_HISTORICAL = 'historical';
    public const STATUS_OUTSIDE = 'outside';

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Retourne les utilisateurs pouvant être proposés dans le formulaire
     * d'une émission.
     *
     * USER / EDITOR / ADMIN :
     * - utilisateurs actuellement associés à la catégorie ;
     * - utilisateurs historiquement associés à l'émission.
     *
     * SUPER_ADMIN :
     * - tous les utilisateurs.
     *
     * @return User[]
     */
    public function getChoices(
        ?Categories $categorie,
        Emission $emission,
        bool $canManageAllUsers
    ): array {
        $categoryUsers = $this->getCategoryUsers($categorie);
        $historicalUsers = $this->getHistoricalUsers($emission);

        if ($canManageAllUsers) {
            /** @var User[] $choices */
            $choices = $this->userRepository
                ->createQueryBuilder('u')
                ->orderBy(
                    'u.username',
                    SortDirection::Ascending
                )
                ->getQuery()
                ->getResult();
        } else {
            $choices = $this->mergeUniqueUsers(
                $categoryUsers,
                $historicalUsers
            );
        }

        usort(
            $choices,
            fn (User $a, User $b): int => $this->compareUsers(
                $a,
                $b,
                $categoryUsers,
                $historicalUsers
            )
        );

        return $choices;
    }

    /**
     * Retourne le statut d'un utilisateur dans le contexte
     * d'une émission et d'une catégorie.
     */
    public function getStatus(
        User $user,
        ?Categories $categorie,
        Emission $emission
    ): string {
        $categoryUsers = $this->getCategoryUsers($categorie);
        $historicalUsers = $this->getHistoricalUsers($emission);

        return $this->resolveStatus(
            $user,
            $categoryUsers,
            $historicalUsers
        );
    }

    /**
     * Retourne le libellé affiché dans le formulaire.
     */
    public function getLabel(
        User $user,
        ?Categories $categorie,
        Emission $emission
    ): string {
        $identifier = $user->getUserIdentifier();

        return match ($this->getStatus($user, $categorie, $emission)) {
            self::STATUS_CURRENT => $identifier . ' — catégorie actuelle',
            self::STATUS_HISTORICAL => $identifier . ' — ancienne association',
            default => $identifier . ' — hors catégorie',
        };
    }

    /**
     * Retourne le groupe visuel utilisé par EntityType.
     */
    public function getGroupLabel(
        User $user,
        ?Categories $categorie,
        Emission $emission
    ): string {
        return match ($this->getStatus($user, $categorie, $emission)) {
            self::STATUS_CURRENT => 'Rattaché·es actuellement à la catégorie',
            self::STATUS_HISTORICAL => 'Anciennes associations de l’émission',
            default => 'Autres utilisateur·ices',
        };
    }

    /**
     * Permet notamment au contrôleur AJAX de savoir si un utilisateur
     * est actuellement associé à la catégorie.
     */
    public function isCurrentCategoryUser(
        User $user,
        ?Categories $categorie
    ): bool {
        if (!$categorie instanceof Categories) {
            return false;
        }

        return $categorie->getUsers()->contains($user);
    }

    /**
     * @return User[]
     */
    private function getCategoryUsers(?Categories $categorie): array
    {
        if (!$categorie instanceof Categories) {
            return [];
        }

        return array_values(
            array_filter(
                $categorie->getUsers()->toArray(),
                static fn (mixed $user): bool => $user instanceof User
            )
        );
    }

    /**
     * @return User[]
     */
    private function getHistoricalUsers(Emission $emission): array
    {
        return array_values(
            array_filter(
                $emission->getUsers()->toArray(),
                static fn (mixed $user): bool => $user instanceof User
            )
        );
    }

    /**
     * Fusionne les utilisateurs sans doublon.
     *
     * L'id Doctrine est utilisé lorsqu'il existe.
     * spl_object_id() sert uniquement de fallback pour une entité
     * qui n'aurait pas encore été persistée.
     *
     * @param User[] ...$lists
     *
     * @return User[]
     */
    private function mergeUniqueUsers(array ...$lists): array
    {
        $uniqueUsers = [];

        foreach ($lists as $users) {
            foreach ($users as $user) {
                if (!$user instanceof User) {
                    continue;
                }

                $key = $user->getId() !== null
                    ? 'id_' . $user->getId()
                    : 'object_' . spl_object_id($user);

                $uniqueUsers[$key] = $user;
            }
        }

        return array_values($uniqueUsers);
    }

    /**
     * @param User[] $categoryUsers
     * @param User[] $historicalUsers
     */
    private function resolveStatus(
        User $user,
        array $categoryUsers,
        array $historicalUsers
    ): string {
        if ($this->containsUser($categoryUsers, $user)) {
            return self::STATUS_CURRENT;
        }

        if ($this->containsUser($historicalUsers, $user)) {
            return self::STATUS_HISTORICAL;
        }

        return self::STATUS_OUTSIDE;
    }

    /**
     * @param User[] $categoryUsers
     * @param User[] $historicalUsers
     */
    private function compareUsers(
        User $a,
        User $b,
        array $categoryUsers,
        array $historicalUsers
    ): int {
        $rankA = $this->statusRank(
            $this->resolveStatus($a, $categoryUsers, $historicalUsers)
        );

        $rankB = $this->statusRank(
            $this->resolveStatus($b, $categoryUsers, $historicalUsers)
        );

        $rankComparison = $rankA <=> $rankB;

        if ($rankComparison !== 0) {
            return $rankComparison;
        }

        return strcasecmp(
            $a->getUserIdentifier(),
            $b->getUserIdentifier()
        );
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            self::STATUS_CURRENT => 1,
            self::STATUS_HISTORICAL => 2,
            default => 3,
        };
    }

    /**
     * Compare les entités de manière robuste :
     * identité objet d'abord, puis id Doctrine.
     *
     * @param User[] $users
     */
    private function containsUser(array $users, User $searchedUser): bool
    {
        foreach ($users as $user) {
            if ($user === $searchedUser) {
                return true;
            }

            if (
                $user->getId() !== null
                && $searchedUser->getId() !== null
                && $user->getId() === $searchedUser->getId()
            ) {
                return true;
            }
        }

        return false;
    }
}