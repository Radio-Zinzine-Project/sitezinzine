<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;

/** Grouping policy only; persistence must provide atomicity and concurrent uniqueness. */
final readonly class Mp3CleanupIssueGrouper
{
    /**
     * @param \Closure(string, string):list<array{id:int, storage:string, relative_path:string, status:string}> $findActiveIssues
     * @param \Closure(array{storage:string, relative_path:string, status:string}):int $createIssue
     * @param \Closure(int, Mp3CleanupAnomaly):void $appendEvent
     */
    public function __construct(
        private \Closure $findActiveIssues,
        private \Closure $createIssue,
        private \Closure $appendEvent,
    ) {}

    public function record(string $storage, Mp3CleanupAnomaly $anomaly): int
    {
        $activeIssues = ($this->findActiveIssues)($storage, $anomaly->obsoleteRelativePath);

        if (count($activeIssues) > 1) {
            throw new \DomainException('Multiple active MP3 cleanup issues exist for the same file.');
        }

        $id = $activeIssues === []
            ? ($this->createIssue)([
                'storage' => $storage,
                'relative_path' => $anomaly->obsoleteRelativePath,
                'status' => 'open',
            ])
            : $activeIssues[0]['id'];

        ($this->appendEvent)($id, $anomaly);

        return $id;
    }
}
