<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Preparation;

use App\Exception\Mp3StorageLockTimeout;
use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferJournal;
use App\Service\Mp3StoragePath;
use App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Path;

final readonly class RadioPreparationPurge
{
    public function __construct(private RadioTransferJournal $journal, private LoggerInterface $logger = new NullLogger()) {}

    public static function cutoff(\DateTimeImmutable $now): string
    {
        return RadioExportWeekLifetime::cutoff($now);
    }

    /** Purges only completed weeks. An empty week directory retains its stable cooperation lock. */
    public function run(string $root, array $protected, \DateTimeImmutable $now, bool $dryRun = false): array
    {
        if (!Path::isAbsolute($root)) {
            throw new \RuntimeException('Radio export requires an absolute configured root.');
        }
        [$root, $protected] = RadioPreparationWorkspace::validateRoot($root, $protected);
        $cutoff = self::cutoff($now);
        $databaseWeeks = array_fill_keys($this->journal->weeksBefore($cutoff), true);
        $weeks = array_unique([...array_keys($databaseWeeks), ...self::names($root)]);
        sort($weeks, SORT_STRING);
        $results = [];
        foreach ($weeks as $key) {
            $week = self::expiredWeek($key, $cutoff);
            if ($week === null) {
                if (isset($databaseWeeks[$key])) {
                    $results[$key] = ['status' => 'failed', 'reason' => 'invalid_week'];
                }
                continue;
            }
            $stage = 'disk';
            try {
                $directory = Mp3StoragePath::resolve($root, $key);
                if (!isset($databaseWeeks[$key]) && array_diff(self::names($directory), ['.storage.lock']) === []) {
                    continue;
                }
                $results[$key] = $dryRun
                    ? ['status' => 'planned', 'files' => self::countFiles(self::inventory($directory))]
                    : RadioPreparationWeekLock::synchronized($root, $protected, $week, function (
                        string $root, array $protected, string $directory
                    ) use ($key, $cutoff, &$stage): array {
                        $attempts = self::inventory($directory);
                        self::removeAttempts($attempts);
                        if (self::names($directory) !== ['.storage.lock']) {
                            throw new \RuntimeException('Unexpected entries remain in the radio week.');
                        }
                        $stage = 'sql';
                        $deleted = $this->journal->purgeExpiredWeek($key, $cutoff);

                        return ['status' => 'purged', 'files' => self::countFiles($attempts), 'confirmations' => $deleted];
                    });
            } catch (Mp3StorageLockTimeout) {
                $results[$key] = ['status' => 'busy'];
            } catch (\RuntimeException|\Doctrine\DBAL\Exception) {
                $this->logger->error('Radio export purge failed.', ['week' => $key, 'stage' => $stage]);
                $results[$key] = ['status' => 'failed', 'reason' => $stage];
            }
        }

        return $results;
    }

    private static function expiredWeek(string $value, string $cutoff): ?\DateTimeImmutable
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1 || $value >= $cutoff) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Paris'));

        return $date !== false && $date->format('Y-m-d') === $value && $date->format('N') === '2' ? $date : null;
    }

    private static function names(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return [];
        }
        if (($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Expected a private radio export directory.');
        }
        $names = @scandir($path);
        if ($names === false) {
            throw new \RuntimeException('Cannot list the radio export directory.');
        }

        return array_values(array_diff($names, ['.', '..']));
    }

    private static function inventory(string $week): array
    {
        $attempts = [];
        foreach (array_diff(self::names($week), ['.storage.lock']) as $name) {
            if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $name) !== 1) {
                throw new \RuntimeException('Unknown radio export entry.');
            }
            $attempt = Mp3StoragePath::resolve($week, $name);
            $identity = self::directoryIdentity($attempt);
            $files = $groups = [];
            foreach (self::names($attempt) as $filename) {
                if (preg_match("/\A(?:[a-zA-Z0-9][a-zA-Z0-9'-]*\.mp3|audio\.zip|(?:result|failure)\.json|\.(?:result|failure)\.pending|\.audio\.pending(?:\.[a-zA-Z0-9]+)?|\.source-[1-9][0-9]*\.part)\z/", $filename) !== 1) {
                    throw new \RuntimeException('Unknown radio export artefact.');
                }
                $path = $attempt . '/' . $filename;
                $stat = self::regularFile($path);
                $files[$path] = $stat;
                $key = $stat['dev'] . ':' . $stat['ino'];
                $groups[$key] = ($groups[$key] ?? 0) + 1;
            }
            foreach ($files as $stat) {
                if ($groups[$stat['dev'] . ':' . $stat['ino']] !== $stat['nlink']) {
                    throw new \RuntimeException('A radio artefact has links outside its attempt.');
                }
            }
            $marker = $attempt . '/result.json';
            self::requireSameDirectory($attempt, $identity);
            $attempts[$attempt] = ['identity' => $identity,
                'files' => array_intersect_key($files, [$marker => true]) + $files];
        }

        return $attempts;
    }

    private static function regularFile(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Cannot establish radio artefact ownership.');
        }

        return $stat;
    }

    private static function removeAttempts(array $attempts): void
    {
        foreach ($attempts as $attempt => $snapshot) {
            foreach ($snapshot['files'] as $path => $before) {
                self::requireSameDirectory($attempt, $snapshot['identity']);
                $stat = self::regularFile($path);
                if ($before['dev'] !== $stat['dev'] || $before['ino'] !== $stat['ino'] || !@unlink($path)) {
                    throw new \RuntimeException('Cannot remove a radio artefact.');
                }
            }
            self::requireSameDirectory($attempt, $snapshot['identity']);
            if (!@rmdir($attempt)) {
                throw new \RuntimeException('Cannot remove the radio attempt directory.');
            }
        }
    }

    private static function directoryIdentity(string $path): array
    {
        $path = Mp3StoragePath::resolve(dirname($path), basename($path));
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Cannot establish radio attempt ownership.');
        }

        return [$stat['dev'], $stat['ino']];
    }

    private static function requireSameDirectory(string $path, array $identity): void
    {
        if (self::directoryIdentity($path) !== $identity) {
            throw new \RuntimeException('The radio attempt directory changed during purge.');
        }
    }

    private static function countFiles(array $attempts): int
    {
        return array_sum(array_map(static fn (array $attempt): int => count($attempt['files']), $attempts));
    }
}
