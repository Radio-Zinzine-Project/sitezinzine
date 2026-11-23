<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Service\Mp3StorageLock;
use App\Service\Mp3StoragePath;
use App\Service\Mp3TrashPath;
use App\Service\RadioWeek;

final class RadioPreparationWeekLock
{
    public static function synchronized(string $root, array $protected, \DateTimeImmutable $week, callable $work): mixed
    {
        if ($week->getTimezone()->getName() !== 'Europe/Paris' || $week != RadioWeek::start($week)) {
            throw new \InvalidArgumentException('A canonical Paris Tuesday is required.');
        }
        [$root, $protected] = RadioPreparationWorkspace::validateRoot($root, $protected);
        $directory = Mp3StoragePath::resolve($root, $week->format('Y-m-d'));
        @mkdir($directory, 0700);
        $lock = new Mp3StorageLock(new Mp3TrashPath($directory, $protected['library'], $protected['work'], $protected['public']), 0);

        return $lock->synchronized(fn () => $work($root, $protected, $directory));
    }
}
