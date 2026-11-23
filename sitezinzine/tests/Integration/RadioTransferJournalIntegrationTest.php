<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferJournal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261123130000;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RadioTransferJournalIntegrationTest extends KernelTestCase
{
    /** @dataProvider concurrentRequests */
    public function testConcurrentRequests(bool $compatible): void
    {
        $start = hrtime(true);
        self::bootKernel();
        $kernel = hrtime(true);
        $base = self::getContainer()->get(Connection::class);
        $params = $base->getParams();
        unset($params['pdo'], $params['wrapperClass']);
        $db = new class($params, $base->getDriver(), $base->getConfiguration()) extends Connection {
            public \Closure $whileHoldingInsert;

            public function insert(string $table, array $data, array $types = []): int|string
            {
                // Hold the first real INSERT open long enough to observe the second server session waiting.
                return $this->transactional(function () use ($table, $data, $types): int|string {
                    $count = parent::insert($table, $data, $types);
                    ($this->whileHoldingInsert)();

                    return $count;
                });
            }
        };
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertSame('symfony_test', $db->fetchOne('SELECT DATABASE()'));
        self::assertMatchesRegularExpression('/^8\.0\./', $db->fetchOne('SELECT VERSION()'));
        $parentId = (int) $db->fetchOne('SELECT CONNECTION_ID()');
        $db->executeStatement('SET SESSION innodb_lock_wait_timeout = 3');
        $connected = hrtime(true);
        $key = bin2hex(random_bytes(16));
        $request = ['request_id' => $key, 'destination_key' => 'phpunit-' . $key, 'filename' => 'Radio.mp3',
            'sha256' => str_repeat('a', 64), 'week_start' => '2026-11-17', 'preparation_id' => 'A',
            'diffusion_id' => 5, 'actor_id' => 12];
        $other = $request;
        if (!$compatible) {
            $other['sha256'] = str_repeat('b', 64);
        }
        $process = proc_open(['timeout', '--kill-after=1s', '10s', PHP_BINARY,
            dirname(__DIR__) . '/Support/RadioTransferConcurrencyWorker.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 5);
            fwrite($pipes[0], json_encode([$params, $other], JSON_THROW_ON_ERROR) . "\n");
            $childId = self::receive($pipes[1])['connection'];
            self::assertNotSame($parentId, $childId);
            $db->whileHoldingInsert = static function () use ($pipes, $db, $childId): void {
                fwrite($pipes[0], "insert\n");
                $deadline = hrtime(true) + 1_500_000_000;
                do {
                    foreach ($db->fetchAllAssociative('SHOW FULL PROCESSLIST') as $row) {
                        if ((int) $row['Id'] === $childId
                            && stripos((string) $row['Info'], 'INSERT INTO radio_transfer_confirmation') !== false) {
                            return;
                        }
                    }
                    usleep(10_000);
                } while (hrtime(true) < $deadline);
                self::fail('The concurrent INSERT did not reach MySQL while the first insert was uncommitted.');
            };
            $journal = new RadioTransferJournal($db, new \Symfony\Component\Clock\NativeClock());
            $winner = $journal->append($request, 'A', 15);
            $expected = $compatible ? ['id' => (string) $winner['id']] : ['conflict' => true];
            self::assertSame($expected, self::receive($pipes[1]));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM radio_transfer_confirmation WHERE request_id = ?', [$key]));
            self::assertSame($request['sha256'], $journal->replay($request)['sha256']);
        } finally {
            $beforeCleanup = hrtime(true);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process); // The timeout wrapper bounds the wait even if the worker is stuck.
            try {
                $db->delete('radio_transfer_confirmation', ['request_id' => $key, 'destination_key' => $request['destination_key']]);
            } finally {
                $db->close();
                self::reportTimings($start, $kernel, $connected, $beforeCleanup, $compatible ? 'same' : 'conflict');
            }
        }
    }

    public static function concurrentRequests(): iterable
    {
        yield 'identical declaration' => [true];
        yield 'incompatible declaration' => [false];
    }

    private static function receive($stream): array
    {
        $line = fgets($stream);
        self::assertIsString($line, 'Worker response missing or timed out.');
        return json_decode($line, true, 16, JSON_THROW_ON_ERROR);
    }

    private static function reportTimings(int $start, int $kernel, int $connected, int $beforeCleanup, string $case): void
    {
        if (getenv('RADIO_TRANSFER_TEST_TIMINGS') === '1') {
            fwrite(STDOUT, sprintf("\n%s: kernel=%.3fs connection=%.3fs sql-and-sync=%.3fs cleanup=%.3fs\n", $case,
                ($kernel - $start) / 1e9, ($connected - $kernel) / 1e9, ($beforeCleanup - $connected) / 1e9,
                (hrtime(true) - $beforeCleanup) / 1e9));
        }
    }

    public function testAppendOnlyHistoryOnMySql(): void
    {
        $start = hrtime(true);
        self::bootKernel();
        $kernel = hrtime(true);
        $db = self::getContainer()->get(Connection::class);
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertSame('symfony_test', $db->fetchOne('SELECT DATABASE()'));
        self::assertMatchesRegularExpression('/^8\.0\./', $db->fetchOne('SELECT VERSION()'));
        $connected = hrtime(true);
        require_once dirname(__DIR__, 2) . '/migrations/Version20261123130000.php';
        $migration = new Version20261123130000($db, new NullLogger());
        $migration->up(new Schema());
        $db->executeStatement(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $migration->getSql()[0]->getStatement()));
        try {
            $clock = $this->createMock(ClockInterface::class);
            $clock->method('now')->willReturn(new \DateTimeImmutable('2026-11-17T13:00:00.123456+01:00'));
            $journal = new RadioTransferJournal($db, $clock);
            $request = static fn (string $id, string $hash, string $destination = 'alpha'): array => [
                'request_id' => str_repeat($id, 32), 'destination_key' => $destination, 'filename' => 'Radio.mp3',
                'sha256' => str_repeat($hash, 64), 'week_start' => '2026-11-17', 'preparation_id' => 'C',
                'diffusion_id' => 5, 'actor_id' => 12,
            ];
            $a = $journal->append($request('1', 'a'), 'A', 15);
            $b = $journal->append($request('2', 'b'), 'B', 16);
            $aAgain = $journal->append($request('3', 'a'), 'A', 15);
            $other = $journal->append($request('4', 'b', 'beta'), 'B', 16);
            self::assertSame($a, $journal->append($request('1', 'a'), 'A', 15));
            self::assertSame([$aAgain, $b, $a], $journal->history('alpha', 'Radio.mp3'));
            self::assertSame([$b], $journal->history('alpha', 'Radio.mp3', 1, (int) $aAgain['id']));
            self::assertSame([$other], $journal->history('beta', 'Radio.mp3'));
            self::assertSame([$aAgain], $journal->latestForFiles('alpha', ['Radio.mp3', 'Radio.mp3', 'radio.mp3']));
            self::assertSame([$other], $journal->latestForFiles('beta', ['Radio.mp3']));
            self::assertSame([], $journal->latestForFiles('alpha', []));
            self::assertSame([], $journal->history('alpha', 'radio.mp3'));
            self::assertSame('2026-11-17 12:00:00.123456', $aAgain['confirmed_at']);
            try {
                $journal->append($request('1', 'b'), 'B', 16);
                self::fail('A reused key must reject a different declaration.');
            } catch (\DomainException) {
                self::assertSame($a, $journal->replay($request('1', 'a')));
            }
            self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM radio_transfer_confirmation'));
            $active = $request('5', 'a');
            $active['week_start'] = '2026-11-24';
            $future = $request('6', 'a');
            $future['week_start'] = '2026-12-01';
            $journal->append($active, 'A', 15);
            $journal->append($future, 'A', 15);
            self::assertSame(['2026-11-17'], $journal->weeksBefore('2026-11-24'));
            self::assertSame(4, $journal->purgeExpiredWeek('2026-11-17', '2026-11-24'));
            self::assertSame(0, $journal->purgeExpiredWeek('2026-11-17', '2026-11-24'));
            self::assertSame(['2026-11-24', '2026-12-01'], $db->fetchFirstColumn(
                'SELECT week_start FROM radio_transfer_confirmation ORDER BY week_start'
            ));

        } finally {
            $beforeCleanup = hrtime(true);
            $db->executeStatement('DROP TEMPORARY TABLE radio_transfer_confirmation');
            self::reportTimings($start, $kernel, $connected, $beforeCleanup, 'history');
        }
    }
}
