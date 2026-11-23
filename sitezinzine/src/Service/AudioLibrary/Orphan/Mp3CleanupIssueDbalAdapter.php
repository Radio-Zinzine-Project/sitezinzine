<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Exception\Mp3CleanupActiveIssueCollision;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Uses the writer's connection and transaction; never owns their lifecycle. */
final readonly class Mp3CleanupIssueDbalAdapter
{
    public function __construct(private Connection $connection) {}

    /** @return list<array{id:int, storage:string, relative_path:string, status:string}> */
    public function findActiveIssues(string $storage, string $path): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, storage, obsolete_relative_path AS relative_path, status
             FROM mp3_cleanup_issue
             WHERE storage = ? AND obsolete_relative_path = ? AND status IN (?, ?)
             FOR UPDATE',
            [$storage, $path, 'open', 'investigating'],
        );
    }

    /** @param array{storage:string, relative_path:string, status:string} $data */
    public function createIssue(array $data): int
    {
        try {
            $this->connection->insert('mp3_cleanup_issue', [
                'storage' => $data['storage'],
                'obsolete_relative_path' => $data['relative_path'],
                'status' => 'open',
            ]);
        } catch (UniqueConstraintViolationException $original) {
            $issues = [];
            try {
                if ($this->connection->isConnected() && $this->connection->isTransactionActive()) {
                    $issues = $this->findActiveIssues($data['storage'], $data['relative_path']);
                }
            } catch (\Throwable) {
                // An unsuccessful verification cannot establish a recoverable collision.
                throw $original;
            }

            if (count($issues) === 1
                && $issues[0]['storage'] === $data['storage']
                && $issues[0]['relative_path'] === $data['relative_path']
                && in_array($issues[0]['status'], ['open', 'investigating'], true)) {
                throw new Mp3CleanupActiveIssueCollision($original);
            }

            throw $original;
        }

        return (int) $this->connection->lastInsertId();
    }

    public function appendEvent(int $issueId, Mp3CleanupAnomaly $anomaly): void
    {
        $context = [
            'replacement_relative_path' => $anomaly->context['replacement_relative_path'],
            'commit_confirmed' => $anomaly->context['commit_confirmed'],
            'reference_count' => $anomaly->context['reference_count'],
        ];
        if ($anomaly->historicalIdentity !== null) {
            $context['historical_identity'] = $anomaly->historicalIdentity->toArray();
        }

        $this->connection->insert('mp3_cleanup_event', [
            'issue_id' => $issueId,
            'reason' => $anomaly->reason,
            'emission_id' => $anomaly->emissionId,
            'obsolete_relative_path' => $anomaly->obsoleteRelativePath,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'diagnostic' => json_encode([
                'class' => $anomaly->diagnostic['class'],
                'code' => $anomaly->diagnostic['code'],
                'sql_state' => $anomaly->diagnostic['sql_state'],
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
