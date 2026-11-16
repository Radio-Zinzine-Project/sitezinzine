<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Emission;
use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\Service\Mp3Processor;
use App\Service\Mp3Replacement;
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
    private int $removalAttempts = 0;

    protected function setUp(): void
    {
        // An enclosing fixture transaction would deliberately disable post-commit cleanup.
        $this->client = static::createClient(['environment' => 'test', 'debug' => true]);
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->marker = 'mp3cleanup' . bin2hex(random_bytes(8));
    }

    public function testSuccessfulHttpReplacementPersistsCleanupFailureWithoutFailingTheResponse(): void
    {
        $container = self::getContainer();
        self::assertSame('test', self::$kernel->getEnvironment());
        $business = $this->entityManager->getConnection();
        self::assertSame('symfony_test', $business->getParams()['dbname'] ?? null);
        self::assertSame('symfony_test', $business->fetchOne('SELECT DATABASE()'));
        $this->evidence['database'] = 'symfony_test';
        self::assertSame(0, $business->getTransactionNestingLevel());
        self::assertSame(Mp3TestStorage::root(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::root(), $container->getParameter('vich_uploader.mappings')['emissionsMp3']['upload_destination']);
        self::assertSame(['mp3_cleanup_event', 'mp3_cleanup_issue'], $business->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?) ORDER BY TABLE_NAME',
            ['symfony_test', 'mp3_cleanup_issue', 'mp3_cleanup_event'],
        ));
        $this->params = $business->getParams();
        self::assertSame('pdo_mysql', $this->params['driver'] ?? null);
        self::assertArrayNotHasKey('pdo', $this->params);
        self::assertEmpty($this->params['persistent'] ?? false);
        self::assertEmpty($this->params['driverOptions'][\PDO::ATTR_PERSISTENT] ?? false);
        $this->observer = DriverManager::getConnection($this->params);
        self::assertSame('symfony_test', $this->observer->fetchOne('SELECT DATABASE()'));
        self::assertNotSame((int) $business->fetchOne('SELECT CONNECTION_ID()'), (int) $this->observer->fetchOne('SELECT CONNECTION_ID()'));

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
        $emission->setDatepub(new \DateTime('2024-06-15'));
        $this->original = '__test_mp3_cleanup__/' . $this->marker . '/obsolete.mp3';
        $oldDirectory = $this->path(dirname($this->original));
        $newDirectory = $this->path($category->getSlug());
        self::assertDirectoryDoesNotExist($oldDirectory);
        self::assertDirectoryDoesNotExist($newDirectory);
        $this->ownedDirectories = [$oldDirectory, $newDirectory];
        (new Filesystem())->mkdir($oldDirectory, 0700);
        $audio = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/mp3/test.mp3', false, null, 0, 65536);
        self::assertIsString($audio);
        self::assertSame(65536, strlen($audio));
        self::assertSame(65536, file_put_contents($this->path($this->original), $audio));
        $upload = $oldDirectory . '/incoming.mp3';
        self::assertSame(65536, file_put_contents($upload, $audio));
        $originalHash = hash('sha256', $audio);
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
        $filesystem = $this->getMockBuilder(Filesystem::class)->onlyMethods(['remove'])->getMock();
        $failure = new \RuntimeException('Controlled obsolete MP3 cleanup failure', 19);
        $filesystem->method('remove')->willReturnCallback(function (string|iterable $files) use ($business, $id, $failure): void {
            if ($files !== $this->path($this->original)) {
                (new Filesystem())->remove($files);
                return;
            }
            ++$this->removalAttempts;
            // Record outside-transaction SQL visibility BEFORE throwing; assertions stay outside this catch boundary.
            $this->removalObservation = [
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
        self::assertSame([], glob(Mp3TestStorage::root() . '/private/mp3-*'));
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
            'SELECT issue_id, reason, emission_id, obsolete_relative_path, context, diagnostic FROM mp3_cleanup_event WHERE issue_id = ?', [(int) $issues[0]['id']],
        );
        self::assertCount(1, $events);
        self::assertSame((int) $issues[0]['id'], (int) $events[0]['issue_id']);
        self::assertSame('obsolete_cleanup_failed', $events[0]['reason']);
        self::assertSame($id, (int) $events[0]['emission_id']);
        self::assertSame($this->original, $events[0]['obsolete_relative_path']);
        $context = json_decode($events[0]['context'], true, 512, JSON_THROW_ON_ERROR);
        $diagnostic = json_decode($events[0]['diagnostic'], true, 512, JSON_THROW_ON_ERROR);
        ksort($context);
        ksort($diagnostic);
        self::assertSame(['commit_confirmed' => true, 'reference_count' => 0, 'replacement_relative_path' => $stored['thumbnail_mp3']], $context);
        self::assertSame(['class' => $failure::class, 'code' => 19, 'sql_state' => null], $diagnostic);
        self::assertSame(0, $business->getTransactionNestingLevel());
        self::assertFalse($this->dedicated->isConnected());
        $this->evidence += ['database' => 'symfony_test', 'http_status' => 302, 'removal_attempts' => $this->removalAttempts,
            'business_commit_observed_before_removal' => true, 'old_references' => 0, 'issue_id' => (int) $issues[0]['id'],
            'events' => count($events), 'reason' => $events[0]['reason']];
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
        return Mp3TestStorage::root() . '/' . $relative;
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
