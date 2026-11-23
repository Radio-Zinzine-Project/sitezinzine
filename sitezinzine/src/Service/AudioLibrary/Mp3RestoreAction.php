<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary;

use App\Enum\Mp3TrashPhase;
use App\Exception\Mp3RestoreFailure;
use App\Service\AudioLibrary\Orphan\Mp3CleanupServiceFactory;
use App\Service\Mp3RestoreService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

class Mp3RestoreAction
{
    public function __construct(private readonly EntityManagerInterface $defaultManager, private readonly Mp3RestoreService $restore) {}

    /** Returns false only when the journal entry is confirmed missing after a reservation conflict. */
    public function execute(array $confirmation): bool
    {
        $db = Mp3CleanupServiceFactory::createConnection($this->defaultManager->getConnection());
        try {
            $this->restore->restore(
                em: new EntityManager($db, $this->defaultManager->getConfiguration()),
                operationId: $confirmation['operation_id'],
                actorId: $confirmation['actor_id'],
                expectedPhase: Mp3TrashPhase::from($confirmation['expected_phase']),
                expectedLastAttemptId: $confirmation['expected_last_attempt_id'],
                requestId: $confirmation['request_id'],
            );

            return true;
        } catch (Mp3RestoreFailure $failure) {
            if ($failure->reason === 'conflict' && $failure->step === 'reservation'
                && !$failure->commitUncertain && $this->isMissing($db, $confirmation['operation_id'])) {
                return false;
            }
            throw $failure;
        } finally {
            $db->close();
        }
    }

    private function isMissing(Connection $db, string $operationId): bool
    {
        try {
            return !$db->isTransactionActive()
                && $db->fetchOne('SELECT id FROM mp3_trash_operation WHERE id = ?', [$operationId]) === false;
        } catch (Exception) {
            return false;
        }
    }
}
