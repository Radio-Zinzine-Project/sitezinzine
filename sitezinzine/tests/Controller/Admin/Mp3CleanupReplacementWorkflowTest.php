<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Emission;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanup;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupAction;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupAttemptJournal;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupResult;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueResolutionService;
use App\Service\Mp3DiskIo;
use App\Service\Mp3Processor;
use App\Service\Mp3Replacement;
use App\Service\Mp3StorageLock;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Real HTTP replacement and anomaly persistence, with a fault only at obsolete-file removal. */
final class Mp3CleanupReplacementWorkflowTest extends FunctionalTestCase
{
    private ?Connection $observer = null;
    private ?Connection $dedicated = null;
    private ?Mp3Processor $processor = null;
    private ?Filesystem $originalFilesystem = null;
    private ?string $original = null;
    private array $params = [];
    private array $rows = [];
    private array $ownedDirectories = [];
    private array $removalObservation = [];
    private array $evidence = [];
    private array $requestIds = [];
    private array $unlinkObservation = [];
    private array $previousPrefixEnvironment = [];
    private const PREFIX_ENV = 'MP3_DEFERRED_CLEANUP_LOCAL_URL_PREFIXES';
    private int $removalAttempts = 0;

    protected function setUp(): void
    {
        $this->previousPrefixEnvironment = [$_ENV[self::PREFIX_ENV] ?? null, $_SERVER[self::PREFIX_ENV] ?? null];
        $_ENV[self::PREFIX_ENV] = $_SERVER[self::PREFIX_ENV] = '["https://example.test/uploads/emissionsMp3","https://legacy.example.test/audio"]';

        // An enclosing fixture transaction would deliberately disable post-commit cleanup.
        $this->client = static::createClient(['environment' => 'test', 'debug' => true]);
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->marker = 'mp3cleanup' . bin2hex(random_bytes(8));
    }

    public function testReplacementIncidentRemovalAndClosureThroughHttp(): void
    {
        $container = self::getContainer();
        self::assertSame('test', self::$kernel->getEnvironment());
        $business = $this->entityManager->getConnection();
        self::assertSame('symfony_test', $business->getParams()['dbname'] ?? null);
        self::assertSame('symfony_test', $business->fetchOne('SELECT DATABASE()'));
        $this->evidence['database'] = 'symfony_test';
        self::assertSame(0, $business->getTransactionNestingLevel());
        self::assertSame(Mp3TestStorage::library(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::library(), $container->getParameter('vich_uploader.mappings')['emissionsMp3']['upload_destination']);
        self::assertSame(Mp3TestStorage::work(), $container->getParameter('app.mp3_work_dir'));
        self::assertSame(Mp3TestStorage::root() . '/trash', $container->getParameter('app.mp3_trash_dir'));
        self::assertSame(['mp3_cleanup_event', 'mp3_cleanup_issue', 'mp3_cleanup_issue_closure',
            'mp3_deferred_cleanup_attempt', 'mp3_trash_operation'], $business->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?, ?, ?, ?) ORDER BY TABLE_NAME',
            ['symfony_test', 'mp3_cleanup_event', 'mp3_cleanup_issue', 'mp3_cleanup_issue_closure',
                'mp3_deferred_cleanup_attempt', 'mp3_trash_operation'],
        ));
        $this->params = $business->getParams();
        self::assertSame('pdo_mysql', $this->params['driver'] ?? null);
        self::assertArrayNotHasKey('pdo', $this->params);
        self::assertEmpty($this->params['persistent'] ?? false);
        self::assertEmpty($this->params['driverOptions'][\PDO::ATTR_PERSISTENT] ?? false);
        $this->observer = DriverManager::getConnection($this->params);
        self::assertSame('symfony_test', $this->observer->fetchOne('SELECT DATABASE()'));
        self::assertNotSame((int) $business->fetchOne('SELECT CONNECTION_ID()'), (int) $this->observer->fetchOne('SELECT CONNECTION_ID()'));

        $observeUnlink = function (string $path) use ($container): void {
            if ($this->original === null || $path !== $this->path($this->original)) {
                return;
            }
            $requestId = $this->requestIds[array_key_last($this->requestIds)];
            $this->unlinkObservation = [
                'request' => $this->observer->fetchAssociative(
                    'SELECT issue_id, expected_event_id, actor_id, state, result_code FROM mp3_deferred_cleanup_attempt WHERE request_id = ?',
                    [$requestId],
                ),
                'locked' => $container->get(Mp3StorageLock::class)->isHeldByCurrentProcess(),
                'present' => is_file($path),
            ];
        };
        // Observe the durable request from an independent MySQL session before the real syscall.
        $container->set(Mp3DiskIo::class, new class($observeUnlink) extends Mp3DiskIo {
            public function __construct(private \Closure $observe) {}

            public function unlink(string $path): bool
            {
                ($this->observe)($path);

                return parent::unlink($path);
            }
        });

        // Setup alone has a short transaction; it is committed before either HTTP request.
        $business->beginTransaction();
        $owner = $this->createUser('ROLE_SUPER_ADMIN');
        $this->registerRow($owner, 'username', $owner->getUsername());
        $category = $this->createCategory();
        $this->registerRow($category->getEditeur(), 'name', $category->getEditeur()->getName());
        $this->registerRow($category, 'titre', $category->getTitre());
        $theme = $this->createTheme();
        $this->registerRow($theme, 'name', $theme->getName());
        $emission = $this->createEmission('Titre ' . $this->marker, $owner, $category, $theme);
        $this->registerRow($emission, 'ref', $emission->getRef());
        $reference = $this->createEmission('Synthetic reference ' . $this->marker, null, $category, $theme);
        $this->registerRow($reference, 'ref', $reference->getRef());
        $emission->setDatepub(new \DateTime('2024-06-15'));
        $this->original = '__test_mp3_cleanup__/' . $this->marker . '/obsolete.mp3';
        $oldDirectory = $this->path(dirname($this->original));
        $newDirectory = $this->path($category->getSlug());
        self::assertDirectoryDoesNotExist($oldDirectory);
        self::assertDirectoryDoesNotExist($newDirectory);
        $this->ownedDirectories = [$oldDirectory, $newDirectory];
        (new Filesystem())->mkdir($oldDirectory, 0700);
        $audio = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/mp3/test-short.mp3', false, null, 0, 65536);
        self::assertIsString($audio);
        self::assertSame(65536, strlen($audio));
        self::assertSame(65536, file_put_contents($this->path($this->original), $audio));
        $upload = $oldDirectory . '/incoming.mp3';
        self::assertSame(65536, file_put_contents($upload, $audio));
        $originalHash = hash('sha256', $audio);
        $originalStat = lstat($this->path($this->original));
        self::assertIsArray($originalStat);
        unset($audio);
        $baseUrl = $container->getParameter('app.mp3_public_base_url');
        $emission->setThumbnailMp3($this->original)->setUrl($baseUrl . '/' . $this->original);
        $this->entityManager->flush();
        $business->commit();
        self::assertSame(0, $business->getTransactionNestingLevel());
        $id = $emission->getId();
        $this->evidence['emission_id'] = $id;
        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/admin/emission/' . $id . '/edit');
        self::assertResponseIsSuccessful();
        $description = 'Remplacement confirmé malgré un nettoyage différé';
        $form = $crawler->selectButton('Sauvegarder')->form(['emission[descriptif]' => $description]);

        $replacement = $container->get(Mp3Replacement::class);
        $reporter = (new \ReflectionProperty(Mp3Replacement::class, 'cleanupReporter'))->getValue($replacement);
        self::assertInstanceOf(Mp3CleanupReporter::class, $reporter);
        $this->dedicated = $container->get('app.mp3_cleanup.connection');
        self::assertSame('symfony_test', $this->dedicated->getParams()['dbname'] ?? null);
        $this->processor = $container->get(Mp3Processor::class);
        $property = new \ReflectionProperty(Mp3Processor::class, 'fs');
        $this->originalFilesystem = $property->getValue($this->processor);
        $filesystem = $this->getMockBuilder(Filesystem::class)->onlyMethods(['remove', 'rename'])->getMock();
        $preparedPath = null;
        $filesystem->expects(self::once())->method('rename')->willReturnCallback(
            function (string $source, string $target, bool $overwrite) use (&$preparedPath): void {
                $this->originalFilesystem->rename($source, $target, $overwrite);
                $preparedPath = $target;
            }
        );
        $failure = new \RuntimeException('Controlled obsolete MP3 cleanup failure', 19);
        $storageLock = $container->get(Mp3StorageLock::class);
        $filesystem->method('remove')->willReturnCallback(function (string|iterable $files) use ($business, $id, $failure, $storageLock): void {
            if ($files !== $this->path($this->original)) {
                (new Filesystem())->remove($files);
                return;
            }
            ++$this->removalAttempts;
            // Record outside-transaction SQL visibility BEFORE throwing; assertions stay outside this catch boundary.
            $this->removalObservation = [
                'storage_locked' => $storageLock->isHeldByCurrentProcess(),
                'business_level' => $business->getTransactionNestingLevel(),
                'native_transaction' => $business->getNativeConnection()->inTransaction(),
                'emission' => $this->observer->fetchAssociative('SELECT thumbnail_mp3, url, descriptif FROM emission WHERE id = ?', [$id]),
                'old_references' => (int) $this->observer->fetchOne('SELECT COUNT(*) FROM emission WHERE thumbnail_mp3 = ?', [$this->original]),
            ];
            $this->evidence['removal_observed_after_commit'] = $this->removalObservation['business_level'] === 0
                && !$this->removalObservation['native_transaction'] && $this->removalObservation['old_references'] === 0;
            throw $failure;
        });
        $property->setValue($this->processor, $filesystem);

        $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), [
            'emission' => ['thumbnailFileMp3' => new UploadedFile($upload, 'replacement.mp3', 'audio/mpeg', null, true)],
        ]);
        $this->evidence['http_status'] = $this->client->getResponse()->getStatusCode();
        self::assertResponseRedirects();
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $session = $this->client->getSession();
        self::assertNotNull($session);
        self::assertContains('L\'émission a bien été modifiée.', $session->getFlashBag()->peek('success'));
        $session->save();
        self::assertSame(1, $this->removalAttempts, 'The precise obsolete-file deletion failure must have been reached.');
        self::assertSame(0, $this->removalObservation['business_level']);
        self::assertTrue($this->removalObservation['storage_locked']);
        self::assertFalse($storageLock->isHeldByCurrentProcess());
        self::assertFalse($this->removalObservation['native_transaction']);
        self::assertSame(0, $this->removalObservation['old_references']);
        $stored = $this->observer->fetchAssociative('SELECT thumbnail_mp3, url, descriptif FROM emission WHERE id = ?', [$id]);
        self::assertIsArray($stored);
        self::assertSame($stored, $this->removalObservation['emission'], 'The replacement was committed before deletion was attempted.');
        self::assertNotSame($this->original, $stored['thumbnail_mp3']);
        self::assertSame($baseUrl . '/' . $stored['thumbnail_mp3'], $stored['url']);
        self::assertSame($description, $stored['descriptif']);
        self::assertFileExists($this->path($stored['thumbnail_mp3']));
        self::assertFileExists($this->path($this->original));
        self::assertSame($originalHash, hash_file('sha256', $this->path($this->original)));
        self::assertFileDoesNotExist($upload);
        self::assertIsString($preparedPath);
        self::assertStringStartsWith(Mp3TestStorage::work() . DIRECTORY_SEPARATOR, $preparedPath);
        self::assertSame([], glob(Mp3TestStorage::work() . '/mp3-*'));
        self::assertSame($reporter, (new \ReflectionProperty(Mp3Replacement::class, 'cleanupReporter'))->getValue($replacement));

        $issues = $this->observer->fetchAllAssociative(
            'SELECT id, storage, obsolete_relative_path, status, active_marker FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?',
            ['emissionsMp3', $this->original],
        );
        self::assertCount(1, $issues);
        self::assertSame('emissionsMp3', $issues[0]['storage']);
        self::assertSame($this->original, $issues[0]['obsolete_relative_path']);
        self::assertSame('open', $issues[0]['status']);
        self::assertSame(1, (int) $issues[0]['active_marker']);
        $events = $this->observer->fetchAllAssociative(
            'SELECT id, issue_id, reason, emission_id, obsolete_relative_path, context, diagnostic FROM mp3_cleanup_event WHERE issue_id = ?', [(int) $issues[0]['id']],
        );
        self::assertCount(1, $events);
        self::assertSame((int) $issues[0]['id'], (int) $events[0]['issue_id']);
        self::assertSame('obsolete_cleanup_failed', $events[0]['reason']);
        self::assertSame($id, (int) $events[0]['emission_id']);
        self::assertSame($this->original, $events[0]['obsolete_relative_path']);
        $context = json_decode($events[0]['context'], true, 512, JSON_THROW_ON_ERROR);
        $diagnostic = json_decode($events[0]['diagnostic'], true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals([
            'state' => 'complete',
            'storage' => 'emissionsMp3',
            'relative_path' => $this->original,
            'original_url' => $baseUrl . '/' . $this->original,
            'identity' => array_intersect_key($originalStat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))
                + ['sha256' => $originalHash],
        ], $context['historical_identity']);
        unset($context['historical_identity']);
        ksort($context);
        ksort($diagnostic);
        self::assertSame(['commit_confirmed' => true, 'reference_count' => 0, 'replacement_relative_path' => $stored['thumbnail_mp3']], $context);
        self::assertSame(['class' => $failure::class, 'code' => 19, 'sql_state' => null], $diagnostic);
        self::assertSame(0, $business->getTransactionNestingLevel());
        self::assertFalse($this->dedicated->isConnected());
        $this->evidence += ['database' => 'symfony_test', 'http_status' => 302, 'removal_attempts' => $this->removalAttempts,
            'business_commit_observed_before_removal' => true, 'old_references' => 0, 'issue_id' => (int) $issues[0]['id'],
            'events' => count($events), 'reason' => $events[0]['reason']];

        $issueId = (int) $issues[0]['id'];
        $actorId = $owner->getId();
        $eventId = (int) $events[0]['id'];
        $uri = '/admin/audio-library/cleanup-issues/' . $issueId;
        $newFile = $this->path($stored['thumbnail_mp3']);
        $newHash = hash_file('sha256', $newFile);
        $this->assertSecurityGuards($uri, $issueId, $eventId, $actorId, $reference, $originalStat, $originalHash, $newFile, $newHash);
        $page = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        $formNode = $page->filter('form[action$="/cleanup"]');
        self::assertCount(1, $formNode, 'An authorized synthetic library must offer deferred cleanup.');
        $values = $formNode->form()->getValues();
        $requestId = $values['request_id'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $requestId);
        $this->requestIds[] = $requestId;
        $this->client->request('POST', $uri . '/cleanup', ['confirmed' => '1'] + $values);
        self::assertResponseRedirects($uri, 303);
        $attempt = $this->observer->fetchAssociative(
            'SELECT issue_id, expected_event_id, actor_id, state, result_code, requested_at, finished_at
             FROM mp3_deferred_cleanup_attempt WHERE request_id = ?', [$requestId],
        );
        self::assertIsArray($attempt);
        self::assertTrue($this->unlinkObservation['locked']);
        self::assertTrue($this->unlinkObservation['present']);
        $pending = $this->unlinkObservation['request'];
        self::assertIsArray($pending);
        self::assertSame([$issueId, $eventId, $actorId, 'pending', null],
            [(int) $pending['issue_id'], (int) $pending['expected_event_id'], (int) $pending['actor_id'], $pending['state'], $pending['result_code']]);
        self::assertSame([$issueId, $eventId, $actorId],
            [(int) $attempt['issue_id'], (int) $attempt['expected_event_id'], (int) $attempt['actor_id']]);
        self::assertSame(['finished', 'removed'], [$attempt['state'], $attempt['result_code']]);
        self::assertNotNull($attempt['finished_at']);
        self::assertLessThanOrEqual($attempt['finished_at'], $attempt['requested_at']);
        self::assertFileDoesNotExist($this->path($this->original));
        self::assertSame($newHash, hash_file('sha256', $newFile));

        // Replay the same authenticated submission before fetching a new confirmation.
        $observation = $this->unlinkObservation;
        $this->unlinkObservation = [];
        $this->client->request('POST', $uri . '/cleanup', ['confirmed' => '1'] + $values);
        self::assertResponseRedirects($uri, 303);
        self::assertSame([], $this->unlinkObservation);
        self::assertSame(1, (int) $this->observer->fetchOne(
            'SELECT COUNT(*) FROM mp3_deferred_cleanup_attempt WHERE request_id = ?', [$requestId],
        ));
        self::assertSame($attempt['finished_at'], $this->observer->fetchOne(
            'SELECT finished_at FROM mp3_deferred_cleanup_attempt WHERE request_id = ?', [$requestId],
        ));
        self::assertNotEmpty($observation);

        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Cette demande existe');
        $form = $page->filter('form[action$="/close"]')->form();
        $this->client->request('POST', $form->getUri(), [
            'confirmed' => '1', 'reason' => 'Synthetic replacement cleanup verified',
        ] + $form->getValues());
        self::assertResponseRedirects($uri, 303);
        $trace = $this->observer->fetchAssociative(
            'SELECT issue_id, actor_id, expected_event_id, proof_type, request_id, reason, closed_at
             FROM mp3_cleanup_issue_closure WHERE issue_id = ?', [$issueId],
        );
        self::assertIsArray($trace);
        self::assertSame([$issueId, $actorId, $eventId], [(int) $trace['issue_id'], (int) $trace['actor_id'], (int) $trace['expected_event_id']]);
        self::assertSame(['confirmed_removal', $requestId, 'Synthetic replacement cleanup verified'],
            [$trace['proof_type'], $trace['request_id'], $trace['reason']]);
        $resolved = $this->observer->fetchAssociative('SELECT status, resolved_at FROM mp3_cleanup_issue WHERE id = ?', [$issueId]);
        self::assertSame(['status' => 'resolved', 'resolved_at' => $trace['closed_at']], $resolved);
        self::assertSame($events, $this->observer->fetchAllAssociative(
            'SELECT id, issue_id, reason, emission_id, obsolete_relative_path, context, diagnostic FROM mp3_cleanup_event WHERE issue_id = ?', [$issueId],
        ));
        self::assertSame($attempt, $this->observer->fetchAssociative(
            'SELECT issue_id, expected_event_id, actor_id, state, result_code, requested_at, finished_at
             FROM mp3_deferred_cleanup_attempt WHERE request_id = ?', [$requestId],
        ));
        self::assertSame($stored, $this->observer->fetchAssociative('SELECT thumbnail_mp3, url, descriptif FROM emission WHERE id = ?', [$id]));
        self::assertSame($newHash, hash_file('sha256', $newFile));
    }

    private function assertSecurityGuards(string $uri, int $issueId, int $eventId, int $actorId, Emission $reference,
        array $originalStat, string $originalHash, string $newFile, string $newHash): void
    {
        $container = self::getContainer();
        $journal = $container->get(Mp3DeferredCleanupAttemptJournal::class);
        foreach (['[]', 'not-json', '["https://example.test:8080/audio"]'] as $prefixes) {
            $action = new Mp3DeferredCleanupAction($this->observer, $journal, $container->get(Mp3DeferredCleanup::class),
                $container->get(Mp3CleanupIssueResolutionService::class), $prefixes,
                $container->getParameter('app.mp3_public_base_url'));
            self::assertFalse($action->read($issueId, null)['configured']);
            $id = bin2hex(random_bytes(16));
            $this->requestIds[] = $id;
            self::assertSame(Mp3DeferredCleanupResult::RequestNotRecorded,
                $action->remove($issueId, $eventId, $id, $actorId));
            self::assertNull($journal->find($this->observer, $id));
        }

        $close = $this->confirmation($uri, 'close');
        $this->client->request('POST', $uri . '/close', ['confirmed' => '1', 'reason' => 'Synthetic unsafe closure'] + $close);
        self::assertResponseRedirects($uri, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'un fichier occupe encore le chemin');

        $form = $this->confirmation($uri, 'cleanup');
        $session = $this->client->getSession();
        $confirmation = $session->get('mp3_deferred_cleanup_confirmation');
        $confirmation[4] = time() - 1;
        $session->set('mp3_deferred_cleanup_confirmation', $confirmation);
        $session->save();
        $this->requestIds[] = $form['request_id'];
        $this->client->request('POST', $uri . '/cleanup', ['confirmed' => '1'] + $form);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($journal->find($this->observer, $form['request_id']));

        // An independent historical URL points to the old path with no thumbnail_mp3.
        $criteria = ['id' => $reference->getId(), 'ref' => $reference->getRef()];
        self::assertSame(1, (int) $this->observer->update('emission', [
            'url' => 'https://legacy.example.test/audio/' . $this->original, 'thumbnail_mp3' => null,
        ], $criteria));
        try {
            $this->assertCleanupRefused($uri, $this->confirmation($uri, 'cleanup'), 'reference_active');
        } finally {
            $this->observer->update('emission', ['url' => null], $criteria);
        }

        $reservation = ['id' => bin2hex(random_bytes(16)), 'emission_id' => $reference->getId(), 'original_thumbnail_mp3' => $this->original];
        $this->rows[] = ['table' => 'mp3_trash_operation', 'criteria' => $reservation];
        $at = gmdate('Y-m-d H:i:s');
        $this->observer->insert('mp3_trash_operation', $reservation + [
            'size_bytes' => $originalStat['size'], 'sha256' => $originalHash, 'author_id' => $actorId,
            'phase' => 'SUPPRESSION_PREPAREE', 'created_at' => $at, 'updated_at' => $at, 'blocked' => 0, 'restoration_attempts' => '[]',
        ]);
        try {
            $this->assertCleanupRefused($uri, $this->confirmation($uri, 'cleanup'), 'reservation_active');
        } finally {
            $this->observer->delete('mp3_trash_operation', $reservation);
        }

        // Move the parent away and back to preserve the historical file's inode and metadata.
        $old = $this->path($this->original);
        $directory = dirname($old);
        $saved = $directory . '-saved';
        self::assertDirectoryDoesNotExist($saved);
        $this->ownedDirectories[] = $saved;
        self::assertTrue(rename($directory, $saved));
        try {
            self::assertTrue(mkdir($directory, 0700));
            self::assertTrue(copy($saved . '/' . basename($old), $old));
            $this->assertCleanupRefused($uri, $this->confirmation($uri, 'cleanup'), 'different');
        } finally {
            (new Filesystem())->remove($directory);
            self::assertTrue(rename($saved, $directory));
        }
        self::assertSame($originalHash, hash_file('sha256', $old));

        $form = $this->confirmation($uri, 'cleanup');
        $newEvent = $this->observer->fetchAssociative(
            'SELECT issue_id, reason, emission_id, obsolete_relative_path, context, diagnostic FROM mp3_cleanup_event WHERE id = ? AND issue_id = ?',
            [$eventId, $issueId],
        );
        self::assertIsArray($newEvent);
        $this->observer->insert('mp3_cleanup_event', $newEvent);
        $newEventId = (int) $this->observer->lastInsertId();
        try {
            $this->assertCleanupRefused($uri, $form, 'stale_view');
        } finally {
            $this->observer->delete('mp3_cleanup_event', ['id' => $newEventId, 'issue_id' => $issueId]);
        }

        $form = $this->confirmation($uri, 'cleanup');
        $blockingRequest = bin2hex(random_bytes(16));
        $this->requestIds[] = $blockingRequest;
        $journal->reserve($this->observer, $blockingRequest, $issueId, $eventId, $actorId, new \DateTimeImmutable());
        try {
            $this->assertBlockedSubmission($uri, $form, $blockingRequest, 'pending');
            $journal->finish($this->observer, $blockingRequest, Mp3DeferredCleanupResult::Indeterminate, new \DateTimeImmutable());
            $this->assertBlockedSubmission($uri, $form, $blockingRequest, 'finished');
            $page = $this->client->request('GET', $uri . '?attempt=' . $blockingRequest);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('bloque toute nouvelle demande', $page->text());
            self::assertCount(0, $page->filter('form[action$="/cleanup"], form[action$="/close"]'));
            self::assertSame('indeterminate', $journal->find($this->observer, $blockingRequest)['result_code']);
        } finally {
            $this->observer->delete('mp3_deferred_cleanup_attempt', ['issue_id' => $issueId, 'request_id' => $blockingRequest]);
        }
        self::assertFalse($this->observer->fetchOne('SELECT issue_id FROM mp3_cleanup_issue_closure WHERE issue_id = ?', [$issueId]));
        self::assertSame([], $this->unlinkObservation);
        self::assertSame($originalHash, hash_file('sha256', $old));
        self::assertSame($newHash, hash_file('sha256', $newFile));
    }

    private function confirmation(string $uri, string $action): array
    {
        $page = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $page->filter('form[action$="/' . $action . '"]')->form()->getValues();
    }

    private function assertCleanupRefused(string $uri, array $form, string $expected): void
    {
        $this->requestIds[] = $form['request_id'];
        $this->client->request('POST', $uri . '/cleanup', ['confirmed' => '1'] + $form);
        self::assertResponseRedirects($uri, 303);
        self::assertSame($expected, $this->observer->fetchOne(
            'SELECT result_code FROM mp3_deferred_cleanup_attempt WHERE request_id = ?', [$form['request_id']],
        ));
        self::assertFileExists($this->path($this->original));
    }

    private function assertBlockedSubmission(string $uri, array $form, string $blockingRequest, string $expectedState): void
    {
        $this->requestIds[] = $form['request_id'];
        $this->client->request('POST', $uri . '/cleanup', ['confirmed' => '1'] + $form);
        self::assertResponseRedirects($uri, 303);
        self::assertFalse($this->observer->fetchOne('SELECT request_id FROM mp3_deferred_cleanup_attempt WHERE request_id = ?', [$form['request_id']]));
        self::assertSame($expectedState, $this->observer->fetchOne(
            'SELECT state FROM mp3_deferred_cleanup_attempt WHERE request_id = ?', [$blockingRequest],
        ));
        self::assertFileExists($this->path($this->original));
    }

    private function registerRow(object $entity, string $column, string $value): void
    {
        $this->rows[] = ['table' => $this->entityManager->getClassMetadata($entity::class)->getTableName(),
            'criteria' => ['id' => $entity->getId(), $column => $value]];
    }

    private function path(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
            throw new \LogicException('Unsafe isolated MP3 path.');
        }
        return Mp3TestStorage::library() . '/' . $relative;
    }

    protected function tearDown(): void
    {
        $failures = [];
        $cleanup = null;
        try {
            if ($this->processor !== null && $this->originalFilesystem !== null) {
                (new \ReflectionProperty(Mp3Processor::class, 'fs'))->setValue($this->processor, $this->originalFilesystem);
            }
            if (isset($this->entityManager)) {
                $business = $this->entityManager->getConnection();
                while ($business->isConnected() && ($level = $business->getTransactionNestingLevel()) > 0) {
                    $business->rollBack();
                    if ($business->getTransactionNestingLevel() >= $level) { throw new \LogicException('Fixture rollback made no progress.'); }
                }
                $this->entityManager->clear();
            }
        } catch (\Throwable $error) {
            $failures[] = $error;
            if (isset($business)) { $business->close(); }
        }
        try {
            if (($this->params['dbname'] ?? null) === 'symfony_test' && ($this->rows !== [] || $this->original !== null)) {
                $cleanup = DriverManager::getConnection($this->params);
                self::assertSame('symfony_test', $cleanup->fetchOne('SELECT DATABASE()'));
                $cleanup->beginTransaction();
                if ($this->original !== null) {
                    $ids = $cleanup->fetchFirstColumn('SELECT id FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ? FOR UPDATE', ['emissionsMp3', $this->original]);
                    foreach ($ids as $id) {
                        $cleanup->delete('mp3_cleanup_issue_closure', ['issue_id' => (int) $id]);
                        foreach (array_unique($this->requestIds) as $requestId) {
                            $cleanup->delete('mp3_deferred_cleanup_attempt', ['issue_id' => (int) $id, 'request_id' => $requestId]);
                        }
                        $cleanup->delete('mp3_cleanup_event', ['issue_id' => (int) $id]);
                        $cleanup->delete('mp3_cleanup_issue', ['id' => (int) $id, 'storage' => 'emissionsMp3', 'obsolete_relative_path' => $this->original]);
                    }
                }
                foreach (array_reverse($this->rows) as $row) {
                    if ($row['table'] === 'emission' && $cleanup->fetchOne('SELECT id FROM emission WHERE id = ? AND ref = ?', array_values($row['criteria'])) !== false) {
                        $cleanup->delete('emission_user', ['emission_id' => $row['criteria']['id']]);
                        $cleanup->delete('emission_invite_old_animateur', ['emission_id' => $row['criteria']['id']]);
                    }
                    $cleanup->delete($row['table'], $row['criteria']);
                }
                $cleanup->commit();
                foreach ($this->rows as $row) {
                    self::assertSame(0, (int) $cleanup->fetchOne('SELECT COUNT(*) FROM ' . $row['table'] . ' WHERE id = ?', [$row['criteria']['id']]));
                }
                if ($this->original !== null) {
                    self::assertSame(0, (int) $cleanup->fetchOne('SELECT COUNT(*) FROM mp3_cleanup_issue WHERE storage = ? AND obsolete_relative_path = ?', ['emissionsMp3', $this->original]));
                    self::assertSame(0, (int) $cleanup->fetchOne('SELECT COUNT(*) FROM mp3_cleanup_event WHERE obsolete_relative_path = ?', [$this->original]));
                }
                foreach (array_unique($this->requestIds) as $requestId) {
                    self::assertFalse($cleanup->fetchOne('SELECT request_id FROM mp3_deferred_cleanup_attempt WHERE request_id = ?', [$requestId]));
                }
                $this->evidence['targeted_sql_cleanup_verified'] = true;
            }
        } catch (\Throwable $error) {
            $failures[] = $error;
        } finally {
            foreach ([$cleanup, $this->observer, $this->dedicated] as $connection) {
                if ($connection === null) { continue; }
                try {
                    if ($connection->getTransactionNestingLevel() > 0) { $connection->rollBack(); }
                } catch (\Throwable $error) { $failures[] = $error; }
                finally { $connection->close(); }
            }
            try {
                (new Filesystem())->remove($this->ownedDirectories);
                foreach ($this->ownedDirectories as $directory) { self::assertDirectoryDoesNotExist($directory); }
                $this->evidence['targeted_file_cleanup_verified'] = true;
            } catch (\Throwable $error) { $failures[] = $error; }
            try { parent::tearDown(); } catch (\Throwable $error) { $failures[] = $error; }
            $this->processor = $this->originalFilesystem = $this->observer = $this->dedicated = null;
            $this->params = $this->rows = $this->ownedDirectories = [];
            [$oldEnv, $oldServer] = $this->previousPrefixEnvironment;
            if ($oldEnv === null) {
                unset($_ENV[self::PREFIX_ENV]);
            } else {
                $_ENV[self::PREFIX_ENV] = $oldEnv;
            }
            if ($oldServer === null) {
                unset($_SERVER[self::PREFIX_ENV]);
            } else {
                $_SERVER[self::PREFIX_ENV] = $oldServer;
            }
        }
        $this->evidence['cleanup_errors'] = array_map(static fn (\Throwable $error): string => $error::class, $failures);
        if (($report = getenv('MP3_CLEANUP_HTTP_EVIDENCE')) !== false && $report !== '') {
            try {
                if (file_put_contents($report, json_encode($this->evidence, JSON_THROW_ON_ERROR)) === false) { throw new \RuntimeException('Cannot save workflow evidence.'); }
            } catch (\Throwable $error) { $failures[] = $error; }
        }
        if ($failures !== []) {
            foreach ($failures as $error) { fwrite(STDERR, 'MP3 cleanup HTTP fixture cleanup failed: ' . $error::class . PHP_EOL); }
            if (!$this->hasFailed()) { throw $failures[0]; }
        }
    }
}
