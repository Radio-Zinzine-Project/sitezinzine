<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Exception\Mp3StorageLockTimeout;
use App\Service\AudioLibrary\RadioExport\RadioExportDestination;
use App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime;
use App\Service\Mp3StoragePath;
use App\Service\RadioWeek;
use Symfony\Component\Filesystem\Path;

final readonly class RadioPreparationLaunch
{
    public function __construct(
        private RadioPreparationCatalog $catalog,
        private RadioPreparationOrchestrator $preparations,
        private RadioExportWeekLifetime $lifetime,
    ) {}

    /** @return string Stable result code for the administrative launch. */
    public function run(string $root, array $protected, \DateTimeImmutable $week, string $requestId, string $destination): string
    {
        if ($week->getTimezone()->getName() !== 'Europe/Paris' || $week != RadioWeek::start($week)
            || preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1) {
            throw new \InvalidArgumentException('Invalid preparation request.');
        }
        if ($this->lifetime->isExpired($week)) {
            return 'expired';
        }
        if (!Path::isAbsolute($root) || !RadioExportDestination::accepts($destination)) {
            return 'not_configured';
        }
        try {
            return RadioPreparationWeekLock::synchronized($root, $protected, $week, function (string $root, array $protected, string $directory) use ($week, $requestId): string {
                if ($this->lifetime->isExpired($week)) {
                    return 'expired';
                }
                $attempt = Mp3StoragePath::resolve($directory, $requestId);
                clearstatcache(true, $attempt);
                if (@lstat($attempt) !== false) {
                    return 'already_requested';
                }

                $listing = $this->catalog->read($root, $week);
                $reason = self::blockedByCatalog($listing);
                if ($reason !== null) {
                    return $reason;
                }
                $reference = $listing['reference'];
                try {
                    $report = $this->preparations->prepare($root, $protected, $week, $requestId, $reference['attemptId'] ?? null, requireReference: $reference !== null);
                } catch (\DomainException) {
                    return $this->lifetime->isExpired($week) ? 'expired' : 'unvalidated';
                }

                return self::publishedOutcome($report, $reference);
            });
        } catch (Mp3StorageLockTimeout) {
            return 'busy';
        }
    }

    public static function blockedByCatalog(array $listing): ?string
    {
        if ($listing['orderUncertain']) {
            return 'ambiguous';
        }
        $reference = $listing['reference'];
        if (($reference !== null && $reference['version'] < 2)
            || ($reference === null && array_intersect(['unusable', 'origin_unavailable'], array_column($listing['rows'], 'status')))) {
            return 'reference_unavailable';
        }

        return null;
    }

    private static function publishedOutcome(array $report, ?array $reference): string
    {
        $plan = $report['differential'] ?? null;
        if ($report['status'] === 'ready') {
            return isset($plan['referenceAttemptId']) ? 'corrective_zip' : 'complete_zip';
        }
        if (isset($plan['referenceAttemptId']) && $report['revision'] === ($reference['revision'] ?? null)
            && $plan['removed'] === [] && $report['sourceIssues'] === []) {
            foreach ($plan['expected'] as $item) {
                if (!in_array($item['changes'], [['unchanged'], ['excluded']], true)) {
                    return 'report_only';
                }
            }

            return 'unchanged';
        }

        return 'report_only';
    }
}
