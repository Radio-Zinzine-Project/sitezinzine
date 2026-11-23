<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Transfer;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationPublisher;
use App\Service\RadioWeek;
use App\Service\AudioLibrary\RadioExport\RadioExportDestination;
use App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime;

final readonly class RadioTransferConfirmation
{
    public function __construct(
        private RadioPreparationPublisher $publisher,
        private RadioTransferJournal $journal,
        private RadioExportWeekLifetime $lifetime,
    ) {}

    /** Records an administrator's declaration. Actor and destination are supplied by the trusted caller. */
    public function confirm(
        string $root,
        \DateTimeImmutable $week,
        string $attemptId,
        int $diffusionId,
        string $destination,
        string $filename,
        string $sha256,
        int $actorId,
        string $requestId,
        ?callable $recordGuard = null,
    ): array {
        if ($week->getTimezone()->getName() !== 'Europe/Paris' || $week != RadioWeek::start($week)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/', $attemptId) !== 1
            || !RadioExportDestination::accepts($destination)
            || strlen($filename) > 255 || preg_match("/\A[A-Za-z0-9][A-Za-z0-9'-]*\.mp3\z/", $filename) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1 || preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1
            || $actorId < 1 || $diffusionId < 1) {
            throw new \InvalidArgumentException('Invalid radio transfer confirmation parameters.');
        }

        $this->lifetime->requireOpen($week);
        $request = [
            'request_id' => $requestId, 'destination_key' => $destination, 'filename' => $filename, 'sha256' => $sha256,
            'week_start' => $week->format('Y-m-d'), 'preparation_id' => $attemptId, 'diffusion_id' => $diffusionId, 'actor_id' => $actorId,
        ];
        $existing = $this->journal->replay($request);
        if ($existing !== null) {
            return $existing;
        }

        $report = $this->publisher->read($root, $week, $attemptId);
        $originId = $attemptId;
        $artifact = self::archivedArtifact($report, $diffusionId);
        if ($artifact === null && ($report['version'] ?? null) === 3) {
            $rows = array_column($report['differential']['expected'], null, 'diffusionId');
            $origin = $rows[$diffusionId]['origin'] ?? null;
            if ($origin !== null) {
                $originId = $origin['attemptId'];
                $physical = $this->publisher->read($root, $week, $originId);
                $verified = self::archivedArtifact($physical, $diffusionId);
                if ($verified !== null && $verified === $origin['artifact']) {
                    $artifact = $verified;
                }
            }
        }

        if ($artifact === null || $artifact['filename'] !== $filename || !hash_equals($artifact['sha256'], $sha256)) {
            throw new \DomainException('The requested radio file is not available in a verified archive.');
        }

        $record = function () use ($week, $request, $originId, $artifact): array {
            $this->lifetime->requireOpen($week);

            return $this->journal->append($request, $originId, $artifact['size']);
        };

        return $recordGuard === null ? $record() : $recordGuard($record);
    }

    private static function archivedArtifact(?array $report, int $diffusionId): ?array
    {
        if (($report['status'] ?? null) !== 'ready') {
            return null;
        }

        return array_column($report['artifacts'], null, 'diffusionId')[$diffusionId] ?? null;
    }
}
