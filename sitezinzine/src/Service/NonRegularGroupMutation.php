<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DiffusionDraft;
use App\Entity\PendingRebroadcast;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/** Shared entry protocol for non-regular placement and removal/restoration. */
final class NonRegularGroupMutation
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * Request entry points call this before group reads/writes. The emission lookup
     * used only to route the request must stay outside this newly opened transaction.
     * Refreshing objects cannot reset a snapshot in an unrelated, already-open transaction.
     */
    public function run(int $emissionId, ?string $groupKey, \Closure $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        return $connection->transactional(function () use ($connection, $emissionId, $groupKey, $operation): mixed {
            // Must precede the first consistent group read: under REPEATABLE-READ,
            // a later lock would not renew an already established snapshot.
            if (!$connection->fetchOne('SELECT id FROM emission WHERE id = ? FOR UPDATE', [$emissionId])) {
                throw new \DomainException('Émission introuvable.');
            }
            if ($groupKey !== null) {
                // Fresh SQL is not enough when Doctrine already holds managed objects.
                // Refresh the concrete group and its links, without clearing unrelated entities.
                $this->entityManager->createQuery(
                    'SELECT d, published FROM ' . DiffusionDraft::class . ' d LEFT JOIN d.publishedDiffusion published WHERE d.assignmentGroupKey = :groupKey'
                )->setParameter('groupKey', $groupKey)->setHint(Query::HINT_REFRESH, true)->getResult();
                $this->entityManager->createQuery(
                    'SELECT pending FROM ' . PendingRebroadcast::class . ' pending WHERE pending.assignmentGroupKey = :groupKey'
                )->setParameter('groupKey', $groupKey)->setHint(Query::HINT_REFRESH, true)->getResult();
            }
            return $operation();
        });
    }
}
