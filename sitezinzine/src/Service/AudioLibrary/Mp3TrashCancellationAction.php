<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary;

use App\Enum\Mp3TrashConfirmationAction as Action;
use App\Enum\Mp3TrashPhase as Phase;
use App\Service\AudioLibrary\Orphan\Mp3CleanupServiceFactory;
use App\Service\Mp3TrashCancellation;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

class Mp3TrashCancellationAction
{
    public function __construct(private readonly EntityManagerInterface $defaultManager, private readonly Mp3TrashCancellation $cancellation) {}

    public function execute(Action $action, array $confirmation): void
    {
        $db = Mp3CleanupServiceFactory::createConnection($this->defaultManager->getConnection());
        try {
            $em = new EntityManager($db, $this->defaultManager->getConfiguration());
            $phase = Phase::from($confirmation['expected_phase']);
            match ($action) {
                Action::CANCEL_DELETION => $this->cancellation->cancelDeletion(
                    em: $em,
                    operationId: $confirmation['operation_id'],
                    actorId: $confirmation['actor_id'],
                    expectedPhase: $phase,
                    requestId: $confirmation['request_id'],
                    expectedBlockVersion: $confirmation['expected_block_version'],
                ),
                Action::CANCEL_RESTORATION => $this->cancellation->cancelRestoration(
                    em: $em,
                    operationId: $confirmation['operation_id'],
                    actorId: $confirmation['actor_id'],
                    expectedPhase: $phase,
                    requestId: $confirmation['request_id'],
                    expectedAttemptId: $confirmation['expected_attempt_id'],
                    expectedBlockVersion: $confirmation['expected_block_version'],
                ),
                default => throw new \LogicException('Unsupported MP3 cancellation action.'),
            };
        } finally {
            $db->close();
        }
    }
}
