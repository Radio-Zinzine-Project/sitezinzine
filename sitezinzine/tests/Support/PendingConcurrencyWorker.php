<?php

declare(strict_types=1);

// Test-only subprocess: independent kernel, PDO connection and real commits.
require __DIR__ . '/../bootstrap.php';

use App\Entity\User;
use App\Service\GridPublicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\KernelEvents;

$config = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$socket = stream_socket_client($config['address'], $errno, $error, 15);
if ($socket === false) {
    throw new RuntimeException('IPC connection failed: ' . $error);
}
stream_set_timeout($socket, 45);
$send = static function (string $event, array $data = []) use ($socket, $config): void {
    fwrite($socket, json_encode(['event' => $event, 'role' => $config['role'], 'token' => $config['token']] + $data, JSON_THROW_ON_ERROR) . "\n");
    fflush($socket);
};
$gate = static function () use ($socket): void {
    if (trim((string) fgets($socket)) !== 'continue') {
        throw new RuntimeException('IPC barrier was not released.');
    }
};
$kernel = null;
try {
    $kernel = new App\Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $em = $container->get(EntityManagerInterface::class);
    $connection = $em->getConnection();
    $database = $connection->fetchOne('SELECT DATABASE()');
    if ($database !== $config['database'] || !preg_match('/_test[0-9]*$/', $database)) {
        throw new RuntimeException('Refusing a database outside the expected test schema.');
    }
    $preloaded = [];
    if (($config['preloadGroupKey'] ?? null) !== null) {
        // Autocommit reads intentionally populate Doctrine's identity map before the contender commits.
        $preloaded = array_merge(
            $em->getRepository(\App\Entity\DiffusionDraft::class)->findBy(['assignmentGroupKey' => $config['preloadGroupKey']]),
            $em->getRepository(\App\Entity\PendingRebroadcast::class)->findBy(['assignmentGroupKey' => $config['preloadGroupKey']])
        );
    }
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 35');
    $connectionId = (int) $connection->fetchOne('SELECT CONNECTION_ID()');
    $announce = static function () use ($send, $gate, $connection, $connectionId, $em, $preloaded): void {
        foreach ($preloaded as $entity) {
            if (!$em->contains($entity)) {
                throw new \RuntimeException('Doctrine preload lost before the action.');
            }
        }
        $send('ready', ['connectionId' => $connectionId, 'level' => $connection->getTransactionNestingLevel(), 'preloaded' => count($preloaded)]);
        $gate();
    };
    $armed = false;
    if ($config['pauseAfterFlush']) {
        $listener = new class($send, $gate, $connection, $armed) {
            private bool $paused = false;
            public function __construct(private Closure $send, private Closure $gate, private $connection, private bool &$armed) {}
            public function postFlush(): void
            {
                if ($this->armed && !$this->paused) {
                    $this->paused = true;
                    ($this->send)('paused', ['level' => $this->connection->getTransactionNestingLevel()]);
                    ($this->gate)();
                }
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postFlush], $listener);
    }
    if ($config['operation'] === 'publish') {
        $announce();
        $result = $container->get(GridPublicationService::class)->publishWeek(new DateTimeImmutable($config['week']));
        $send('done', ['kind' => 'service', 'published' => $result['published'], 'level' => $connection->getTransactionNestingLevel()]);
    } else {
        $client = new KernelBrowser($kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($em->find(User::class, $config['userId']));
        $sent = false;
        $container->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER_ARGUMENTS,
            static function () use (&$sent, &$armed, $announce): void {
                if (!$sent) {
                    $sent = true;
                    $announce();
                    $armed = true;
                }
            });
        if ($config['operation'] === 'place') {
            $client->jsonRequest('POST', '/admin/pending-rebroadcasts/' . $config['pendingId'] . '/place', ['startsAt' => $config['startsAt']]);
        } else {
            $client->jsonRequest('POST', '/admin/grid-drafts/delete', ['draftId' => $config['draftId'], 'deleteMode' => 'single']);
        }
        $send('done', ['kind' => 'http', 'status' => $client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $client->getResponse()->getContent(), true), 'level' => $connection->getTransactionNestingLevel()]);
    }
} catch (Throwable $exception) {
    $send('error', ['class' => $exception::class, 'message' => $exception->getMessage(), 'code' => $exception->getCode()]);
    exit(1);
} finally {
    if ($kernel !== null) {
        $kernel->shutdown();
    }
    fclose($socket);
}
