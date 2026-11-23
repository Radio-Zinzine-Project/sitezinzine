<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Mp3StorageLock;
use App\Service\Mp3TrashPath;
use PHPUnit\Framework\TestResult;

/** Encloses the fixture transaction, including setup and rollback. */
trait WithMp3StorageLock
{
    public function run(?TestResult $result = null): TestResult
    {
        $paths = new Mp3TrashPath(Mp3TestStorage::root() . '/trash', Mp3TestStorage::library(),
            Mp3TestStorage::work(), dirname(__DIR__, 2) . '/public');
        return (new Mp3StorageLock($paths, 1000))->synchronized(fn () => parent::run($result));
    }
}
