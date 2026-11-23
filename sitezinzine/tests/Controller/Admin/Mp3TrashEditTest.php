<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\EmissionController;
use App\Entity\Emission;
use App\Exception\Mp3TrashFailure;
use App\Service\Mp3TrashEdit;
use App\Tests\Support\{FunctionalTestCase, Mp3TestStorage};
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vich\UploaderBundle\Storage\StorageInterface;

final class Mp3TrashEditTest extends FunctionalTestCase
{
    private Connection $db;
    private array $fixtures = [];
    private ?array $restoreRequest = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->db = $this->entityManager->getConnection();
        self::assertSame('symfony_test', $this->db->fetchOne('SELECT DATABASE()'));
        self::assertSame(Mp3TestStorage::library(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(0, $this->db->getTransactionNestingLevel());
        $this->marker = 'trash-edit-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            while (isset($this->db) && $this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            if ($this->restoreRequest !== null) {
                $this->db->delete('mp3_administrative_event', $this->restoreRequest);
            }
            foreach (array_reverse($this->fixtures) as [$table, $id]) {
                if ($table === 'emission') {
                    $this->db->delete('mp3_trash_operation', ['emission_id' => $id]);
                    $this->db->delete('emission_user', ['emission_id' => $id]);
                }
                $this->db->delete($table, ['id' => $id]);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @dataProvider scenarios */
    public function testDeletionAndEditorialSaveHaveDistinctOutcomes(string $scenario, int $status): void
    {
        $editor = $this->createUser($scenario === 'nominal' ? 'ROLE_SUPER_ADMIN' : 'ROLE_ADMIN');
        $this->fixtures[] = ['user', $editor->getId()];
        $submittedUsers = [(string) $editor->getId()];
        if ($scenario === 'nominal') {
            $additionalUser = $this->createUser();
            $this->fixtures[] = ['user', $additionalUser->getId()];
            $submittedUsers[] = (string) $additionalUser->getId();
        }
        $category = $this->createCategory();
        $this->fixtures[] = ['editeur', $category->getEditeur()->getId()];
        $this->fixtures[] = ['categories', $category->getId()];
        $theme = $this->createTheme();
        $this->fixtures[] = ['theme', $theme->getId()];
        $emission = $this->createEmission($this->marker, $editor, $category, $theme);
        $id = $emission->getId();
        $this->fixtures[] = ['emission', $id];
        $path = $this->marker . '.mp3';
        $file = Mp3TestStorage::library() . '/' . $path;
        $url = self::getContainer()->getParameter('app.mp3_public_base_url') . '/' . $path;
        if ($scenario === 'incoherent' || $scenario === 'external') {
            $url = 'https://elsewhere.test/' . $path;
        } elseif ($scenario === 'empty' || $scenario === 'edit_only') {
            $url = null;
        }
        $hasMp3 = !in_array($scenario, ['external', 'empty', 'edit_only'], true);
        if ($hasMp3) {
            file_put_contents($file, 'synthetic');
        }
        $emission->setThumbnailMp3($hasMp3 ? $path : null)->setUrl($url)->setThumbnail('synthetic.jpg');
        $this->entityManager->flush();
        if ($scenario === 'denied') {
            $unrelated = $this->createUser();
            $this->fixtures[] = ['user', $unrelated->getId()];
            $this->client->loginUser($unrelated);
            $this->client->request('GET', "/admin/emission/$id/edit");
            self::assertResponseRedirects('/access-denied');
            $this->client->request('POST', "/admin/emission/$id/edit", ['emission' => ['deleteMp3' => '1']]);
            self::assertResponseRedirects('/access-denied');
            self::assertFileExists($file);
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mp3_trash_operation WHERE emission_id = ?', [$id]));
            return;
        }
        $this->client->loginUser($editor);
        $crawler = $this->client->request('GET', "/admin/emission/$id/edit");
        self::assertResponseIsSuccessful();
        $draft = 'Brouillon ' . $this->marker;
        $fields = [
            'emission[titre]' => $draft, 'emission[keyword]' => 'journal',
            'emission[theme]' => (string) $theme->getId(), 'emission[categorie]' => (string) $category->getId(),
            'emission[duree]' => 60, 'emission[descriptif]' => 'Description modifiée',
            'emission[users]' => $submittedUsers,
        ];
        if ($scenario !== 'edit_only') {
            $fields['emission[deleteMp3]'] = '1';
        }
        $form = $crawler->selectButton('Sauvegarder')->form($fields);
        $removedImage = 0;
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('remove')->willReturnCallback(function () use (&$removedImage, $scenario, $id): void {
            ++$removedImage;
            if ($scenario === 'nominal') {
                self::assertSame('EN_CORBEILLE', $this->db->fetchOne('SELECT phase FROM mp3_trash_operation WHERE emission_id = ?', [$id]));
            }
        });
        $bridge = null;
        $operationId = bin2hex(random_bytes(16));
        if (in_array($scenario, ['pending', 'uncertain'], true)) {
            $bridge = $this->createMock(Mp3TrashEdit::class);
            $bridge->expects(self::once())->method('trash')->willReturnCallback(function () use ($scenario, $operationId, $id, $path, $url, $file, $editor): never {
                $this->db->insert('mp3_trash_operation', [
                    'id' => $operationId, 'emission_id' => $id, 'original_thumbnail_mp3' => $path, 'original_url' => $url,
                    'size_bytes' => filesize($file), 'sha256' => hash_file('sha256', $file), 'author_id' => $editor->getId(),
                    'phase' => 'SUPPRESSION_PREPAREE', 'created_at' => '2026-11-19 00:00:00',
                    'updated_at' => '2026-11-19 00:00:00', 'blocked' => 0, 'restoration_attempts' => '[]',
                ]);
                self::assertTrue(link($file, Mp3TestStorage::root() . '/trash/' . $operationId . '.mp3'));
                throw new Mp3TrashFailure('disassociation', $operationId, $scenario === 'uncertain', new \RuntimeException('private diagnostic'));
            });
        }
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $replaceArguments = function (ControllerArgumentsEvent $event) use ($storage, $bridge, $scenario, $id): void {
            $controller = $event->getController();
            if (!is_array($controller) || !$controller[0] instanceof EmissionController || $controller[1] !== 'edit') {
                return;
            }
            if ($scenario === 'stale') {
                $this->db->update('emission', ['url' => 'https://elsewhere.test/concurrent-' . $this->marker], ['id' => $id]);
            }
            $arguments = $event->getArguments();
            foreach ($arguments as &$argument) {
                if ($argument instanceof StorageInterface) {
                    $argument = $storage;
                } elseif ($bridge !== null && $argument instanceof Mp3TrashEdit) {
                    $argument = $bridge;
                }
            }
            $event->setArguments($arguments);
        };
        $dispatcher->addListener(KernelEvents::CONTROLLER_ARGUMENTS, $replaceArguments);
        $flush = new class($emission, $scenario) {
            public function __construct(private Emission $emission, private string $scenario) {}

            public function onFlush(OnFlushEventArgs $event): void
            {
                foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Emission && $entity->getId() === $this->emission->getId()) {
                        $changes = $event->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
                        Assert::assertArrayNotHasKey('thumbnailMp3', $changes);
                        Assert::assertArrayNotHasKey('url', $changes);
                        if ($this->scenario === 'flush') {
                            throw new \RuntimeException('synthetic editorial flush failure');
                        }
                    }
                }
            }
        };
        if ($scenario === 'nominal' || $scenario === 'flush') {
            $this->entityManager->getEventManager()->addEventListener([Events::onFlush], $flush);
        }
        $holder = null;
        try {
            $values = $form->getPhpValues();
            if ($scenario === 'nominal' || ($status !== 302 && $scenario !== 'flush')) {
                $values['delete_thumbnail'] = '1';
            }
            if ($scenario === 'busy') {
                $lockFile = Mp3TestStorage::root() . '/trash/.storage.lock';
                $holder = fopen($lockFile, 'c+b');
                self::assertTrue(chmod($lockFile, 0600));
                self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
            }
            $files = [];
            if ($scenario === 'upload_wins') {
                $upload = Mp3TestStorage::root() . '/' . $this->marker . '-upload.mp3';
                $excerpt = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/mp3/test.mp3', false, null, 0, 65536);
                self::assertSame(65536, file_put_contents($upload, $excerpt));
                $files = ['emission' => ['thumbnailFileMp3' => new UploadedFile($upload, 'replacement.mp3', 'audio/mpeg', null, true)]];
            }
            $response = $this->client->request($form->getMethod(), $form->getUri(), $values, $files);
        } finally {
            if (is_resource($holder)) {
                fclose($holder);
            }
            $dispatcher->removeListener(KernelEvents::CONTROLLER_ARGUMENTS, $replaceArguments);
            $this->entityManager->getEventManager()->removeEventListener([Events::onFlush], $flush);
        }
        self::assertSame($status, $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        $row = $this->db->fetchAssociative('SELECT titre, thumbnail_mp3, url FROM emission WHERE id = ?', [$id]);
        self::assertSame($status === 302 ? $draft : $this->marker, $row['titre']);
        if (in_array($scenario, ['nominal', 'flush'], true)) {
            self::assertNull($row['thumbnail_mp3']);
            self::assertNull($row['url']);
            $operation = $this->db->fetchAssociative('SELECT id, phase FROM mp3_trash_operation WHERE emission_id = ?', [$id]);
            self::assertSame('EN_CORBEILLE', $operation['phase']);
            self::assertSame('synthetic', file_get_contents(Mp3TestStorage::root() . '/trash/' . $operation['id'] . '.mp3'));
            self::assertFileDoesNotExist($file);
            if ($scenario === 'nominal') {
                self::assertSame(1, $removedImage);
                self::assertEqualsCanonicalizing($submittedUsers, array_map('strval',
                    $this->db->fetchFirstColumn('SELECT user_id FROM emission_user WHERE emission_id = ?', [$id])));
                $this->assertHttpRestoration($operation['id'], $id, $editor->getId(), $path, $url, $file);
            }
        } elseif ($scenario === 'upload_wins') {
            self::assertNotNull($row['thumbnail_mp3']);
            self::assertNotSame($path, $row['thumbnail_mp3']);
            self::assertFileExists(Mp3TestStorage::library() . '/' . $row['thumbnail_mp3']);
            self::assertFileDoesNotExist($file);
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mp3_trash_operation WHERE emission_id = ?', [$id]));
        } else {
            self::assertSame($hasMp3 ? $path : null, $row['thumbnail_mp3']);
            self::assertSame($scenario === 'stale' ? 'https://elsewhere.test/concurrent-' . $this->marker : $url, $row['url']);
            self::assertSame($hasMp3, is_file($file));
            if ($bridge !== null) {
                self::assertSame('SUPPRESSION_PREPAREE', $this->db->fetchOne('SELECT phase FROM mp3_trash_operation WHERE id = ?', [$operationId]));
                self::assertFileExists(Mp3TestStorage::root() . '/trash/' . $operationId . '.mp3');
            } else {
                self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mp3_trash_operation WHERE emission_id = ?', [$id]));
            }
        }
        if ($status !== 302) {
            self::assertSame($draft, $response->selectButton('Sauvegarder')->form()['emission[titre]']->getValue());
            self::assertSame(0, $removedImage);
            $body = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('private diagnostic', $body);
            self::assertStringContainsString(match ($scenario) {
                'flush' => 'déjà en corbeille',
                'pending' => 'reste inachevée',
                'uncertain' => 'n’a pas pu être confirmé',
                default => 'n’a pas été mis en corbeille',
            }, $body);
        }
    }

    private function assertHttpRestoration(string $operationId, int $emissionId, int $actorId,
        string $path, string $url, string $file): void
    {
        $detailPath = '/admin/audio-library/trash/' . $operationId;
        $list = $this->client->request('GET', '/admin/audio-library/trash?phase=EN_CORBEILLE');
        self::assertResponseIsSuccessful();
        $operationLink = $list->selectLink($operationId);
        while ($operationLink->count() === 0 && $list->selectLink('Suivant')->count() === 1) {
            $list = $this->client->click($list->selectLink('Suivant')->link());
            self::assertResponseIsSuccessful();
            $operationLink = $list->selectLink($operationId);
        }
        self::assertCount(1, $operationLink);
        self::assertSame($detailPath, parse_url($operationLink->link()->getUri(), PHP_URL_PATH));
        $cells = $operationLink->ancestors()->filter('tr')->filter('td');
        self::assertStringContainsString('(#' . $emissionId . ')', $cells->eq(1)->text());
        self::assertSame('En corbeille', $cells->eq(3)->text());

        $detail = $this->client->click($operationLink->link());
        self::assertResponseIsSuccessful();
        $restore = $detail->selectLink('Restaurer');
        self::assertCount(1, $restore);
        self::assertSame($detailPath . '/confirm/restore', parse_url($restore->link()->getUri(), PHP_URL_PATH));
        $confirmation = $this->client->click($restore->link());
        self::assertResponseIsSuccessful();
        $form = $confirmation->selectButton('Restaurer le MP3')->form();
        self::assertSame('POST', $form->getMethod());
        self::assertSame($detailPath . '/restore', parse_url($form->getUri(), PHP_URL_PATH));
        $requestId = $form['request_id']->getValue();
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $requestId);
        self::assertNotEmpty($form['_token']->getValue());

        $this->restoreRequest = [
            'request_id' => $requestId, 'operation_id' => $operationId,
            'actor_id' => $actorId, 'action' => 'restore',
        ];
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($detailPath, parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.main-content', "Restaur\u{00E9}e");

        $operation = $this->db->fetchAssociative(
            'SELECT phase, last_attempt_id FROM mp3_trash_operation WHERE id = ? AND emission_id = ?',
            [$operationId, $emissionId],
        );
        self::assertSame('RESTAUREE', $operation['phase']);
        $attemptId = $operation['last_attempt_id'];
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $attemptId);
        self::assertSame(['thumbnail_mp3' => $path, 'url' => $url], $this->db->fetchAssociative(
            'SELECT thumbnail_mp3, url FROM emission WHERE id = ?', [$emissionId],
        ));
        clearstatcache();
        self::assertSame('synthetic', file_get_contents($file));
        self::assertFileDoesNotExist(Mp3TestStorage::root() . '/trash/' . $operationId . '.mp3');

        $events = $this->db->fetchAllAssociative(
            'SELECT operation_id, actor_id, action, attempt_id, event_type, expected_phase, observed_phase
             FROM mp3_administrative_event WHERE request_id = ? ORDER BY id',
            [$requestId],
        );
        self::assertSame(['request', 'transition_confirmed', 'transition_confirmed', 'transition_confirmed'],
            array_column($events, 'event_type'));
        self::assertSame([null, 'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE'],
            array_column($events, 'observed_phase'));
        self::assertSame(array_fill(0, 4, 'EN_CORBEILLE'), array_column($events, 'expected_phase'));
        self::assertSame(array_fill(0, 4, $operationId), array_column($events, 'operation_id'));
        self::assertSame(array_fill(0, 4, $actorId), array_map('intval', array_column($events, 'actor_id')));
        self::assertSame(array_fill(0, 4, 'restore'), array_column($events, 'action'));
        self::assertSame(array_fill(0, 4, $attemptId), array_column($events, 'attempt_id'));
    }

    public static function scenarios(): iterable
    {
        yield 'editorial flush failure' => ['flush', 409];
        yield 'pending' => ['pending', 409];
        yield 'uncertain commit' => ['uncertain', 409];
        yield 'stale audio' => ['stale', 422];
        yield 'nominal' => ['nominal', 302];
        yield 'incoherent' => ['incoherent', 422];
        yield 'external only' => ['external', 302];
        yield 'no MP3' => ['empty', 302];
        yield 'edit without deletion' => ['edit_only', 302];
        yield 'occupied lock' => ['busy', 422];
        yield 'upload wins' => ['upload_wins', 302];
        yield 'unrelated user' => ['denied', 302];
    }
}

