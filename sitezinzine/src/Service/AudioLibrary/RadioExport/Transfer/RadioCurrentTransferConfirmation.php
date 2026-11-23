<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Transfer;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationCatalog;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationCopier;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationWeekLock;
use App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime;
use App\Service\GridWeekValidation;
use App\Service\Mp3StorageLock;

final readonly class RadioCurrentTransferConfirmation
{
    public function __construct(
        private RadioPreparationCatalog $catalog,
        private RadioTransferWorklist $worklist,
        private RadioTransferConfirmation $confirmation,
        private GridWeekValidation $validation,
        private RadioPreparationCopier $copier,
        private Mp3StorageLock $storageLock,
        private RadioExportWeekLifetime $lifetime,
    ) {}

    public static function version(string $filename, string $sha256): string
    {
        return hash('sha256', $filename . "\0" . $sha256);
    }

    public function confirm(
        string $root, array $protected, \DateTimeImmutable $week, string $attemptId, int $diffusionId,
        string $destination, string $version, int $actorId, string $requestId,
    ): array {
        $this->lifetime->requireOpen($week);

        return RadioPreparationWeekLock::synchronized($root, $protected, $week, function (string $root, array $protected) use (
            $week, $attemptId, $diffusionId, $destination, $version, $actorId, $requestId
        ): array {
            $this->lifetime->requireOpen($week);
            $reference = $this->catalog->read($root, $week)['reference'];
            if (($reference['attemptId'] ?? null) !== $attemptId) {
                throw new \DomainException('The selected radio preparation is no longer the reference.');
            }
            $work = $this->worklist->read($root, $week, $attemptId, $destination);
            $line = array_column($work['lines'], null, 'diffusionId')[$diffusionId] ?? null;
            if ($work['status'] !== 'current' || $line === null || $line['zip'] === null
                || !hash_equals(self::version($line['filename'], $line['sha256']), $version)) {
                throw new \DomainException('The displayed radio file is no longer available in the current preparation.');
            }

            return $this->confirmation->confirm($root, $week, $attemptId, $diffusionId, $destination,
                $line['filename'], $line['sha256'], $actorId, $requestId,
                fn (callable $record): array => $this->storageLock->synchronized(function () use ($protected, $week, $work, $line, $record): array {
                    $path = $line['occurrence']['mp3Path'] ?? null;
                    $source = is_string($path) ? $this->copier->probeSource($protected['library'], $path, true) : null;
                    $state = $this->validation->captureForExport($week);
                    if (!is_array($source) || !hash_equals($line['sha256'], $source['sha256'])
                        || !$state['validated'] || !hash_equals($work['revision'], $state['revision'])) {
                        throw new \DomainException('The source or grid changed after this radio preparation.');
                    }

                    return $record();
                })
            );
        });
    }
}
