<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIdentityVerifier;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueResolutionService;
use App\Service\AudioLibrary\Orphan\Mp3CleanupResolutionDecision;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupPreconditions;
use App\Service\Mp3StorageLock;
use App\Service\Mp3TrashPath;
use Doctrine\DBAL\Connection;

final class Mp3CleanupClosureFixture
{
    public static function service(string $path): Mp3CleanupIssueResolutionService
    {
        if (!preg_match('~\A__test_mp3_cleanup__/[a-f0-9]{32}/obsolete\.mp3\z~', $path)) {
            throw new \InvalidArgumentException('Expected a synthetic closure fixture.');
        }
        Mp3TestStorage::initialize();
        $library = Mp3TestStorage::library();
        $parent = dirname($library . '/' . $path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
            throw new \RuntimeException('Cannot create the synthetic closure fixture directory.');
        }
        $lock = new Mp3StorageLock(new Mp3TrashPath(Mp3TestStorage::root() . '/trash', $library,
            Mp3TestStorage::work(), dirname(__DIR__, 2) . '/public'), 1000);

        return new Mp3CleanupIssueResolutionService($lock,
            new Mp3DeferredCleanupPreconditions($lock, new Mp3CleanupResolutionDecision(), $library),
            new Mp3CleanupIdentityVerifier($library));
    }

    public static function resolve(Connection $db, string $path, int $id, array $token, \DateTimeImmutable $at): string
    {
        // Read only this connection's controlled references.
        $db->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS emission (thumbnail_mp3 VARCHAR(255), url VARCHAR(255)) ENGINE=InnoDB');

        return self::service($path)->resolve($db, $id, $token, self::urls(), 19, 'Synthetic closure', $at);
    }

    public static function urls(): AudioLocalUrlResolver
    {
        return new AudioLocalUrlResolver(['https://example.test/mp3']);
    }
}
