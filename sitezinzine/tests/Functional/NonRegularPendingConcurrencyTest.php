<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\{Categories, DiffusionDraft, Editeur, Emission, PendingRebroadcast, Theme, User};
use App\Tests\Support\FunctionalTestCase;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Process\Process;

/**
 * Separate processes and committed fixtures, never sequential calls inside the fixture transaction.
 * @group concurrency
 */
final class NonRegularPendingConcurrencyTest extends FunctionalTestCase
{
    private array $fixtures = [];
    private array $workers = [];
    private array $channels = [];
    private $server;
    private string $token;
    private int $userId;
    private array $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->entityManager->getConnection();
        self::assertMatchesRegularExpression('/_test[0-9]*$/', $connection->fetchOne('SELECT DATABASE()'));
        $version = $connection->fetchOne('SELECT VERSION()');
        self::assertStringContainsString('8.', $version);
        $this->evidence[] = ['mysqlVersion' => $version];
        $engines = $connection->fetchFirstColumn("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('emission', 'diffusion', 'diffusion_draft', 'pending_rebroadcast')");
        self::assertCount(4, $engines);
        self::assertSame(['InnoDB'], array_values(array_unique($engines)));
        // Fail explicitly if the server cannot expose real lock waits; never replace this with a delay.
        $connection->fetchOne('SELECT COUNT(*) FROM performance_schema.data_lock_waits');
        $this->token = bin2hex(random_bytes(16));
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($this->server, $error);
        $admin = $this->createUser('ROLE_ADMIN');
        $this->userId = $admin->getId();
        $this->client->loginUser($admin);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ($this->channels as $channel) {
                fclose($channel);
            }
            if (is_resource($this->server)) {
                fclose($this->server);
            }
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
            }
            // Fixtures were committed for the subprocesses: explicit, ID-scoped cleanup is mandatory.
            $this->entityManager->clear();
            foreach ($this->fixtures as $fixture) {
                foreach (['diffusion_draft', 'diffusion', 'pending_rebroadcast'] as $table) {
                    $this->entityManager->getConnection()->executeStatement('DELETE FROM ' . $table . ' WHERE assignment_group_key = ?', [$fixture['key']]);
                }
                foreach ($fixture['entities'] as [$class, $id]) {
                    $entity = $this->entityManager->find($class, $id);
                    if ($entity !== null) {
                        $this->entityManager->remove($entity);
                        $this->entityManager->flush();
                    }
                }
            }
            if (isset($this->userId) && ($user = $this->entityManager->find(User::class, $this->userId)) !== null) {
                $this->entityManager->remove($user);
                $this->entityManager->flush();
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function onNotSuccessfulTest(\Throwable $t): void
    {
        try {
            if ($this->hasFailed() && $this->evidence !== []) {
                $diagnostic = json_encode($this->evidence, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                fwrite(STDOUT, "\nCONCURRENCY " . ($diagnostic === false ? '[diagnostic unavailable]' : $diagnostic) . "\n");
            }
        } catch (\Throwable) {
            // Diagnostic encoding or output must never replace the original failure.
        } finally {
            parent::onNotSuccessfulTest($t);
        }
    }

    public function testSamePendingIsConsumedOnlyOnceUnderRealLockContention(): void
    {
        for ($round = 0; $round < 2; ++$round) {
            $group = $this->fixture(false, false, false, $round);
            [$a, $b] = $this->startPair($group, ['operation' => 'place', 'pendingId' => $group['pendingIds'][0], 'startsAt' => (new \DateTimeImmutable('2099-01-20 10:00:00'))->modify('+' . $round . ' days')->format('Y-m-d H:i:s')],
                ['operation' => 'place', 'pendingId' => $group['pendingIds'][0], 'startsAt' => (new \DateTimeImmutable('2099-02-03 10:00:00'))->modify('+' . $round . ' days')->format('Y-m-d H:i:s')]);
            $this->release('b');
            $this->waitForLock($a['connectionId'], $b['connectionId']);
            $this->release('a');
            self::assertSame(200, $this->receive('a', 'done')['status']);
            self::assertSame(400, $this->receive('b', 'done')['status']);
            $state = $this->state($group);
            self::assertCount(2, $state['drafts']);
            self::assertSame(['pending'], array_column($state['pending'], 'status'));
            self::assertNotContains($group['pendingIds'][0], array_map('intval', array_column($state['pending'], 'id')));
        }
    }

    /** @dataProvider duplicatedCalendars */
    public function testTwoManualPendingCanReachOrExceedThree(bool $extra): void
    {
        $group = $this->fixture(true, $extra);
        [$a, $b] = $this->startPair($group, ['operation' => 'place', 'pendingId' => $group['pendingIds'][0], 'startsAt' => '2099-01-20 10:00:00'],
            ['operation' => 'place', 'pendingId' => $group['pendingIds'][1], 'startsAt' => '2099-02-03 10:00:00']);
        $this->release('b');
        $this->waitForLock($a['connectionId'], $b['connectionId']);
        $this->release('a');
        self::assertSame(200, $this->receive('a', 'done')['status']);
        self::assertSame(200, $this->receive('b', 'done')['status']);
        $state = $this->state($group);
        self::assertCount($extra ? 4 : 3, $state['drafts']);
        self::assertCount(0, $state['pending']);
    }

    public static function duplicatedCalendars(): iterable
    {
        yield 'target three' => [false];
        yield 'four remains allowed' => [true];
    }

    /** @dataProvider exclusions */
    public function testPlacementOverlapsRealRemovalAndRestoration(bool $dismissed): void
    {
        $group = $this->fixture(false, true, $dismissed);
        [$a, $b] = $this->startPair($group, ['operation' => 'place', 'pendingId' => $group['pendingIds'][0], 'startsAt' => '2099-01-20 10:00:00'],
            ['operation' => 'restore', 'draftId' => $group['extraId']]);
        self::assertGreaterThanOrEqual(3, $b['preloaded'], 'B keeps its preloaded Doctrine objects while A commits.');
        $this->release('b');
        // Old protocol: B commits during A and reproduces the business RED.
        // Shared protocol: B actually waits on A; never force an impossible early commit.
        $restoration = $this->waitForPublicationOrLock($a['connectionId'], $b['connectionId'], 'restoration');
        $this->release('a');
        self::assertSame(200, $this->receive('a', 'done')['status']);
        self::assertSame(200, ($restoration ?? $this->receive('b', 'done'))['status']);
        $state = $this->state($group);
        self::assertCount(2, $state['drafts']);
        self::assertNotContains($group['extraId'], array_map('intval', array_column($state['drafts'], 'id')));
        self::assertSame($dismissed ? ['dismissed'] : ['pending'], array_column($state['pending'], 'status'),
            'After concurrent restoration, two scheduled occurrences require one proposal unless dismissed.');
        self::assertSame([1, 2], array_map('intval', array_column($state['drafts'], 'nombre_diffusion')), 'The existing chronological renumbering must see both surviving drafts.');
    }

    public static function exclusions(): iterable
    {
        yield 'no exclusion' => [false];
        yield 'dismissed preserved' => [true];
    }

    public function testPlacementOverlapsPublicationWithoutDuplicatePassagesOrPending(): void
    {
        $group = $this->fixture();
        [$a, $b] = $this->startPair($group, ['operation' => 'place', 'pendingId' => $group['pendingIds'][0], 'startsAt' => '2099-01-20 10:00:00'],
            ['operation' => 'publish', 'week' => '2099-01-06']);
        $this->release('b');
        $publication = $this->waitForPublicationOrLock($a['connectionId'], $b['connectionId']);
        $this->release('a');
        self::assertSame(200, $this->receive('a', 'done')['status']);
        self::assertTrue(($publication ?? $this->receive('b', 'done'))['published']);
        $state = $this->state($group);
        self::assertCount(2, $state['drafts']);
        self::assertCount(1, $state['diffusions']);
        self::assertSame('published', $state['diffusions'][0]['publication_status']);
        self::assertSame((int) $state['diffusions'][0]['id'], (int) $state['drafts'][0]['published_diffusion_id']);
        self::assertSame(['pending'], array_column($state['pending'], 'status'));
        $reader = $this->freshConnection();
        try {
            $count = (int) $reader->fetchOne('SELECT COUNT(*) FROM diffusion_draft WHERE assignment_group_key = ? AND deleted_at IS NULL', [$group['key']]);
            $count += (int) $reader->fetchOne("SELECT COUNT(*) FROM diffusion d WHERE d.assignment_group_key = ? AND d.publication_status = 'published' AND NOT EXISTS (SELECT 1 FROM diffusion_draft f WHERE f.published_diffusion_id = d.id AND f.deleted_at IS NULL)", [$group['key']]);
            self::assertSame(2, $count);
        } finally {
            $reader->close();
        }
    }

    private function fixture(bool $duplicate = false, bool $extra = false, bool $dismissed = false, int $dayOffset = 0): array
    {
        // Only fixture construction is wrapped; commit before either worker starts.
        $fixtureConnection = $this->entityManager->getConnection();
        if (!$fixtureConnection->isTransactionActive()) {
            $fixtureConnection->beginTransaction();
        }
        $emission = $this->createEmission($this->marker . ' concurrent');
        $this->client->request('POST', '/admin/grid-drafts/manual', ['emissionId' => $emission->getId(), 'startsAt' => (new \DateTimeImmutable('2099-01-06 10:00:00'))->modify('+' . $dayOffset . ' days')->format('Y-m-d H:i:s'), 'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL, 'durationMinutes' => 60]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $key = 'manual_' . $data['draftId'];
        $pending = $this->entityManager->getRepository(PendingRebroadcast::class)->findOneBy(['assignmentGroupKey' => $key]);
        self::assertNotNull($pending);
        $pendingIds = [$pending->getId()];
        if ($duplicate || $dismissed) {
            $this->client->jsonRequest('POST', '/admin/pending-rebroadcasts/' . $pendingIds[0] . '/duplicate');
            self::assertResponseIsSuccessful();
            $copy = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if ($dismissed) {
                $this->client->request('DELETE', '/admin/pending-rebroadcasts/' . $copy['pendingRebroadcastId']);
                self::assertResponseIsSuccessful();
            } else {
                $pendingIds[] = (int) $copy['pendingRebroadcastId'];
            }
        }
        $extraId = null;
        if ($extra) {
            $draft = (new DiffusionDraft())->setEmission($this->entityManager->find(Emission::class, $emission->getId()))
                ->setAssignmentGroupKey($key)->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)->setNombreDiffusion(2)
                ->setSchedule(new \DateTimeImmutable('2099-01-13 10:00:00'), 60);
            $this->entityManager->persist($draft);
            $this->entityManager->flush();
            $extraId = $draft->getId();
        }
        $emission = $this->entityManager->find(Emission::class, $emission->getId());
        $category = $emission->getCategorie();
        $entities = [[Emission::class, $emission->getId()], [Categories::class, $category->getId()], [Editeur::class, $category->getEditeur()->getId()], [Theme::class, $emission->getTheme()->getId()]];
        $fixture = compact('key', 'pendingIds', 'extraId', 'entities') + ['emissionId' => $emission->getId()];
        $this->fixtures[] = $fixture;
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            self::assertSame(1, $connection->getTransactionNestingLevel());
            $connection->commit();
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
        return $fixture;
    }

    private function startPair(array $group, array $a, array $b): array
    {
        // Every round uses fresh channels; stop/reap all workers from the preceding round.
        foreach ($this->workers as $worker) {
            $worker->wait();
        }
        foreach ($this->channels as $channel) {
            fclose($channel);
        }
        $this->workers = $this->channels = [];
        $this->startWorker('a', $a, true);
        $aReady = $this->receive('a', 'ready');
        $this->startWorker('b', $b, false);
        $bReady = $this->receive('b', 'ready');
        self::assertNotSame($aReady['connectionId'], $bReady['connectionId']);
        $this->evidence[] = ['workerA' => $aReady['connectionId'], 'workerB' => $bReady['connectionId'], 'initialTransactionLevels' => [$aReady['level'], $bReady['level']]];
        self::assertSame(0, $aReady['level']);
        self::assertSame(0, $bReady['level']);
        $this->release('a');
        self::assertSame(1, $this->receive('a', 'paused')['level'], 'A holds a real outer placement transaction, not a test savepoint.');
        return [$aReady, $bReady];
    }

    private function startWorker(string $role, array $operation, bool $pause): void
    {
        $connection = $this->entityManager->getConnection();
        $config = $operation + ['role' => $role, 'token' => $this->token, 'address' => 'tcp://' . stream_socket_get_name($this->server, false),
            'database' => $connection->fetchOne('SELECT DATABASE()'), 'userId' => $this->userId, 'pauseAfterFlush' => $pause, 'preloadGroupKey' => $operation['operation'] === 'restore' ? $this->fixtures[array_key_last($this->fixtures)]['key'] : null];
        $env = ['APP_ENV' => 'test', 'APP_DEBUG' => '0'];
        $env['DATABASE_URL'] = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'];
        $worker = new Process([PHP_BINARY, dirname(__DIR__) . '/Support/PendingConcurrencyWorker.php', json_encode($config, JSON_THROW_ON_ERROR)], dirname(__DIR__, 2), $env);
        $worker->setTimeout(75);
        $worker->start();
        $this->workers[$role] = $worker;
        $channel = stream_socket_accept($this->server, 20);
        self::assertNotFalse($channel, 'Worker did not connect: ' . $worker->getErrorOutput());
        stream_set_timeout($channel, 20);
        $this->channels[$role] = $channel;
    }

    private function release(string $role): void
    {
        fwrite($this->channels[$role], "continue\n");
        fflush($this->channels[$role]);
    }

    private function receive(string $role, string $event): array
    {
        $line = fgets($this->channels[$role]);
        self::assertNotFalse($line, 'No IPC event from ' . $role . ': ' . $this->workers[$role]->getErrorOutput());
        $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->token, $message['token']);
        self::assertSame($role, $message['role']);
        self::assertSame($event, $message['event'], json_encode($message));
        if ($event === 'done') {
            self::assertSame(0, $message['level'], 'Worker completed its own commit/rollback.');
        }
        return $message;
    }

    private function freshConnection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        self::assertNotSame((int) $this->entityManager->getConnection()->fetchOne('SELECT CONNECTION_ID()'), (int) $connection->fetchOne('SELECT CONNECTION_ID()'));
        return $connection;
    }

    private function waitForPublicationOrLock(int $blocking, int $waiting, string $operation = 'publication'): ?array
    {
        $reader = $this->freshConnection();
        try {
            $deadline = microtime(true) + 25;
            do {
                $read = [$this->channels['b']];
                $write = $except = [];
                if (stream_select($read, $write, $except, 0, 0) > 0) {
                    $message = $this->receive('b', 'done');
                    $this->evidence[] = [$operation . 'CommittedWhilePlacementOpen' => true];
                    return $message;
                }
                if ($reader->fetchOne('SELECT 1 FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ? LIMIT 1', [$waiting, $blocking])) {
                    $this->evidence[] = ['blockingConnection' => $blocking, 'waitingConnection' => $waiting, $operation . 'LockWaitObserved' => true];
                    return null;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->evidence[] = ['publicationDiagnostic' => $reader->fetchAllAssociative('SELECT PROCESSLIST_ID, PROCESSLIST_STATE, PROCESSLIST_INFO FROM performance_schema.threads WHERE PROCESSLIST_ID IN (?, ?)', [$blocking, $waiting]), 'waits' => $reader->fetchAllAssociative('SELECT * FROM performance_schema.data_lock_waits')];
            self::fail('Publication neither completed nor exposed a real lock wait.');
        } finally {
            $reader->close();
        }
    }

    private function waitForLock(int $blocking, int $waiting): void
    {
        $reader = $this->freshConnection();
        try {
            $deadline = microtime(true) + 10;
            do {
                $found = (bool) $reader->fetchOne('SELECT 1 FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ? LIMIT 1', [$waiting, $blocking]);
                if ($found) {
                    $this->evidence[] = ['blockingConnection' => $blocking, 'waitingConnection' => $waiting, 'lockWaitObserved' => true];
                    return;
                }
                usleep(10000); // Poll a real server condition, never assume overlap from elapsed time.
            } while (microtime(true) < $deadline);
            self::fail('No real MySQL lock wait observed between the independent transactions.');
        } finally {
            $reader->close();
        }
    }

    private function state(array $group): array
    {
        foreach ($this->workers as $worker) {
            self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
        }
        $reader = $this->freshConnection();
        try {
            $state = [];
            foreach (['drafts' => 'diffusion_draft', 'diffusions' => 'diffusion', 'pending' => 'pending_rebroadcast'] as $name => $table) {
                $state[$name] = $reader->fetchAllAssociative('SELECT * FROM ' . $table . ' WHERE assignment_group_key = ? ORDER BY id', [$group['key']]);
            }
            $this->evidence[] = ['group' => $group['key'], 'isolation' => $reader->fetchOne('SELECT @@transaction_isolation'),
                'drafts' => count($state['drafts']), 'diffusions' => count($state['diffusions']), 'pendingStatuses' => array_column($state['pending'], 'status'),
                'passages' => array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'emission_id', 'horaire_diffusion', 'nombre_diffusion', 'draft_type', 'published_diffusion_id'])), $state['drafts']),
                'pendingRows' => array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'emission_id', 'status'])), $state['pending'])];
            return $state;
        } finally {
            $reader->close();
        }
    }
}
