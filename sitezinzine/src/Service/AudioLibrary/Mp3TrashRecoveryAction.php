<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary;

use App\Enum\Mp3TrashConfirmationAction as Action;
use App\Enum\Mp3TrashPhase as Phase;
use App\Service\AudioLibrary\Orphan\Mp3CleanupServiceFactory;
use App\Service\Mp3TrashRecovery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

/** Connects the confirmed HTTP context to an isolated Doctrine unit of work. */
class Mp3TrashRecoveryAction
{
    public function __construct(private readonly EntityManagerInterface $defaultManager, private readonly Mp3TrashRecovery $recovery) {}

    public function execute(Action $action, array $confirmation): void
    {
        $db = Mp3CleanupServiceFactory::createConnection($this->defaultManager->getConnection());
        try {
            $em = new EntityManager($db, $this->defaultManager->getConfiguration());
            $phase = Phase::from($confirmation['expected_phase']);
            match ($action) {
                Action::RESUME_DELETION => $this->recovery->resumeDeletion(
                    em: $em,
                    operationId: $confirmation['operation_id'],
                    actorId: $confirmation['actor_id'],
                    expectedPhase: $phase,
                    requestId: $confirmation['request_id'],
                    expectedBlockVersion: $confirmation['expected_block_version'],
                ),
                Action::RESUME_RESTORATION => $this->recovery->resumeRestoration(
                    em: $em,
                    operationId: $confirmation['operation_id'],
                    actorId: $confirmation['actor_id'],
                    expectedPhase: $phase,
                    expectedAttemptId: $confirmation['expected_attempt_id'],
                    requestId: $confirmation['request_id'],
                    expectedBlockVersion: $confirmation['expected_block_version'],
                ),
                default => throw new \LogicException('Unsupported MP3 recovery action.'),
            };
        } finally {
            $db->close();
        }
    }
}
