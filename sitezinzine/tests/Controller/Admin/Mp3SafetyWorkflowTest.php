<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\{Categories, Emission, User};
use App\Repository\CategorieTagImageRepository;
use App\Service\Mp3Processor;
use App\Tests\Support\{FunctionalTestCase, Mp3TestStorage, Mp3WorkflowFault};
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Business contracts: these deliberately remain RED until the replacement refactor. */
final class Mp3SafetyWorkflowTest extends FunctionalTestCase
{
    private Emission $emission;
    private Emission $other;
    private User $owner;
    private Categories $category;
    private string $original;
    private string $originalHash;
    private string $otherHash;
    private string $fixtureHash;
    private bool $faultTriggered = false;
    private array $listeners = [];
    private array $restorations = [];
    private ?string $upload = null;
    private Mp3WorkflowFault $observer;
    private array $editorialExpectation = [];
    private array $dispatcherListeners = [];

    protected function setUp(): void
    {
        // Use a freshness-checked container: an old debug=false cache may predate isolation.
        // Keep the transaction/disableReboot infrastructure of FunctionalTestCase.
        $this->client = static::createClient(['debug' => true]);
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->marker = 'mp3red' . bin2hex(random_bytes(8));
        $container = self::getContainer();
        self::assertSame(Mp3TestStorage::root(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::root(), $container->getParameter('vich_uploader.mappings')['emissionsMp3']['upload_destination']);
        self::assertStringContainsString('_test', (string) $this->entityManager->getConnection()->getDatabase());
        $this->entityManager->getConnection()->beginTransaction();
        $this->fixtureHash = hash_file('sha256', $this->fixture());
        $this->owner = $this->createUser('ROLE_SUPER_ADMIN');
        $this->category = $this->createCategory();
        $this->category->setTitre('Album initial');
        $this->emission = $this->createEmission('Titre initial', $this->owner, $this->category);
        $this->emission->setDatepub(new \DateTime('2024-06-15'));
        $this->original = $this->installMp3($this->emission, 'original-' . $this->marker . '.mp3');
        $this->other = $this->createEmission('Autre emission', $this->owner, $this->category);
        $this->installMp3($this->other, 'other-' . $this->marker . '.mp3');
        $this->entityManager->flush();
        $this->originalHash = hash_file('sha256', $this->path($this->original));
        $this->otherHash = hash_file('sha256', $this->path($this->other->getThumbnailMp3()));
        $this->observer = new Mp3WorkflowFault($this->emission, $this->original);
        $this->listen($this->observer);
        $this->client->catchExceptions(false);
        $this->client->loginUser($this->owner);
    }

    /** @dataProvider replacementFailures */
    public function testFailedReplacementPreservesOriginalAndReferences(string $stage): void
    {
        $form = $this->replacementForm();
        $this->uploadCopy($form);
        $probe = $this->armFault($stage);
        $this->submitAllowingFault($form);
        self::assertTrue($stage === 'doctrine' ? $probe->triggered : $this->faultTriggered, 'The intended failure must actually be reached.');
        if ($stage === 'doctrine') {
            self::assertSame('Description du nouveau MP3', $this->tags($probe->preparedRelative)['comment'][0]);
        }
        $this->assertOriginalContract();
    }

    public static function replacementFailures(): iterable
    {
        yield 'metadata preparation' => ['metadata'];
        yield 'final move' => ['move'];
        yield 'Doctrine save after preparation' => ['doctrine'];
    }

    public function testSuccessfulReplacementPublishesTaggedFileWithoutIntermediates(): void
    {
        $before = $this->files();
        $form = $this->replacementForm();
        $upload = $this->uploadCopy($form);
        $this->submitForm($form);
        self::assertResponseRedirects();
        $stored = $this->stored();
        self::assertSame($stored['thumbnail_mp3'], $this->observer->observed->getThumbnailMp3());
        self::assertSame($this->url($stored['thumbnail_mp3']), $stored['url']);
        self::assertSame($stored['url'], $this->observer->observed->getUrl());
        self::assertStringStartsWith($this->category->getSlug() . '/2024/' . $this->category->getSlug() . '20240615TitreInitial', $stored['thumbnail_mp3']);
        $tags = $this->tags($stored['thumbnail_mp3']);
        self::assertSame($this->category->getSlug() . '20240615 Titre initial', $tags['title'][0]);
        self::assertSame($this->owner->getUsername(), $tags['artist'][0]);
        self::assertSame('Album initial', $tags['album'][0]);
        self::assertSame('2024', (string) $tags['year'][0]);
        self::assertSame('Description du nouveau MP3', $tags['comment'][0]);
        // Retaining A as a deliberate backup is allowed; upload/staging leftovers are not.
        $allowed = array_merge($before, [$stored['thumbnail_mp3']]);
        self::assertSame([], array_values(array_diff($this->files(), $allowed)), 'No unused intermediate may remain.');
        self::assertFileDoesNotExist($upload);
        $this->assertOtherUntouched();
    }

    public function testOriginalIsPresentUntilReplacementConfirmation(): void
    {
        $form = $this->replacementForm();
        $this->uploadCopy($form);
        $probe = new Mp3WorkflowFault($this->emission, $this->original);
        $this->listen($probe);
        $beforeUpload = [];
        $this->observe('vich_uploader.pre_upload', function ($event) use (&$beforeUpload): void {
            if ($event->getObject() instanceof Emission && $event->getObject()->getId() === $this->emission->getId()) {
                $beforeUpload[] = is_file($this->path($this->original))
                    && hash_file('sha256', $this->path($this->original)) === $this->originalHash;
            }
        });
        $this->submitForm($form);
        self::assertResponseRedirects();
        self::assertNotEmpty($probe->originalPresentBeforeConfirmation);
        $this->assertOtherUntouched();
        self::assertNotContains(false, array_merge($beforeUpload, $probe->originalPresentBeforeConfirmation), 'A disappeared before persistence of the final replacement reference.');
    }

    /** @dataProvider editorialChanges */
    public function testEditorialChangeRefreshesExistingMp3WithoutUpload(string $field): void
    {
        $form = $this->changedForm($field);
        $this->submitForm($form);
        self::assertResponseRedirects();
        $stored = $this->stored();
        $this->assertOtherUntouched();
        self::assertFileExists($this->path($stored['thumbnail_mp3']));
        self::assertSame($this->url($stored['thumbnail_mp3']), $stored['url']);
        $tags = $this->tags($stored['thumbnail_mp3']);
        $current = $this->observer->observed;
        self::assertNotNull($current);
        $code = $current->getCategorie()->getSlug();
        $date = $current->getDatepub();
        $expectedTitle = $field === 'title' ? 'Titre modifie' : 'Titre initial';
        $expectedArtist = $this->editorialExpectation['artist'] ?? $this->owner->getUsername();
        self::assertSame($this->editorialExpectation['code'], $code);
        self::assertSame($this->editorialExpectation['date'], $date->format('Ymd'));
        $expectedName = $code . $date->format('Ymd') . ($field === 'title' ? 'TitreModifie' : 'TitreInitial');
        // Aggregate assertions show naming, classification AND tag discrepancies together.
        self::assertSame([
            'directory' => $code . '/' . $date->format('Y'),
            'name' => true,
            'title' => $code . $date->format('Ymd') . ' ' . $expectedTitle,
            'artist' => $expectedArtist,
            'album' => $current->getCategorie()->getTitre(),
            'year' => $date->format('Y'),
        ], [
            'directory' => dirname($stored['thumbnail_mp3']),
            'name' => str_starts_with(basename($stored['thumbnail_mp3']), $expectedName),
            'title' => $tags['title'][0] ?? null,
            'artist' => $tags['artist'][0] ?? null,
            'album' => $tags['album'][0] ?? null,
            'year' => isset($tags['year'][0]) ? (string) $tags['year'][0] : null,
        ]);
        $this->assertOtherUntouched();
    }

    public static function editorialChanges(): iterable
    {
        yield 'title' => ['title'];
        yield 'category' => ['category'];
        yield 'publication date' => ['date'];
        yield 'hosts' => ['hosts'];
    }

    /** @dataProvider editorialFailures */
    public function testFailedEditorialReprocessingPreservesOriginal(string $stage): void
    {
        $form = $this->changedForm('title');
        $this->armFault($stage);
        $this->submitAllowingFault($form);
        // Check safety even if current code never attempted the mandatory reprocessing.
        $this->assertOriginalContract();
        self::assertTrue($this->faultTriggered, 'Editorial changes must reach MP3 reprocessing; the armed fault was never triggered.');
    }

    public static function editorialFailures(): iterable
    {
        yield 'metadata preparation' => ['metadata'];
        yield 'final move' => ['move'];
    }

    public function testEditorialChangeWithoutMp3DoesNotProcessFiles(): void
    {
        $this->emission->setThumbnailMp3(null)->setUrl(null);
        $this->entityManager->flush();
        $before = $this->files();
        $form = $this->changedForm('title');
        $this->armFault('metadata');
        $this->replaceProcessorProperty('fs', $this->createMock(Filesystem::class));
        $fs = (new \ReflectionProperty(Mp3Processor::class, 'fs'))->getValue(self::getContainer()->get(Mp3Processor::class));
        foreach (['mkdir', 'rename', 'remove'] as $method) {
            $fs->expects(self::never())->method($method);
        }
        $this->submitForm($form);
        self::assertResponseRedirects();
        self::assertFalse($this->faultTriggered);
        self::assertNull($this->stored()['thumbnail_mp3']);
        self::assertSame($before, $this->files());
        $this->assertOtherUntouched();
    }

    private function fixture(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/mp3/test.mp3';
    }

    private function path(string $relative): string
    {
        if (str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
            throw new \LogicException('Unsafe test MP3 path.');
        }
        return Mp3TestStorage::root() . '/' . $relative;
    }

    private function url(string $relative): string
    {
        return self::getContainer()->getParameter('app.mp3_public_base_url') . '/' . $relative;
    }

    private function installMp3(Emission $emission, string $name): string
    {
        self::assertTrue(copy($this->fixture(), $this->path($name)));
        $emission->setThumbnailMp3($name)->setUrl($this->url($name));
        self::getContainer()->get(Mp3Processor::class)->process($emission);
        return $emission->getThumbnailMp3();
    }

    private function editForm(array $changes = []): Form
    {
        $crawler = $this->client->request('GET', '/admin/emission/' . $this->emission->getId() . '/edit');
        self::assertResponseIsSuccessful();
        return $crawler->selectButton('Sauvegarder')->form($changes);
    }

    private function replacementForm(): Form
    {
        // Distinguish A from the tagged B even when the final filename is reused.
        return $this->editForm(['emission[descriptif]' => 'Description du nouveau MP3']);
    }

    private function changedForm(string $field): Form
    {
        $changes = [];
        $this->editorialExpectation = ['code' => $this->category->getSlug(), 'date' => '20240615'];
        if ($field === 'title') {
            $changes['emission[titre]'] = 'Titre modifie';
        } elseif ($field === 'category') {
            $category = $this->createCategory();
            $category->setTitre('Album modifie');
            $this->entityManager->flush();
            $changes['emission[categorie]'] = (string) $category->getId();
            $this->editorialExpectation['code'] = $category->getSlug();
        } elseif ($field === 'hosts') {
            $host = $this->createUser();
            $changes['emission[users]'] = [(string) $host->getId()];
            $this->editorialExpectation['artist'] = $host->getUsername();
        }
        $form = $this->editForm($changes);
        if ($field === 'date') {
            // No datepub form field exists: exercise the same save path with a managed change.
            $this->observe(\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER_ARGUMENTS, function ($event): void {
                foreach ($event->getArguments() as $argument) {
                    if ($argument instanceof Emission && $argument->getId() === $this->emission->getId()) {
                        $argument->setDatepub(new \DateTime('2025-07-16'));
                    }
                }
            });
            $this->editorialExpectation['date'] = '20250716';
        }
        return $form;
    }

    private function uploadCopy(Form $form): string
    {
        $path = $this->path('upload-' . bin2hex(random_bytes(16)) . '.mp3');
        self::assertTrue(copy($this->fixture(), $path));
        // Send our owned copy directly: DomCrawler::upload() makes an extra shared-/tmp copy.
        $this->upload = $path;
        return $path;
    }

    private function submitForm(Form $form): void
    {
        if ($this->upload === null) {
            $this->client->submit($form);
            return;
        }
        $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), [
            'emission' => ['thumbnailFileMp3' => new UploadedFile($this->upload, 'replacement.mp3', 'audio/mpeg', null, true)],
        ]);
    }

    private function armFault(string $stage): ?Mp3WorkflowFault
    {
        if ($stage === 'doctrine') {
            $probe = new Mp3WorkflowFault($this->emission, $this->original, true);
            $this->listen($probe);
            return $probe;
        }
        $fail = function () use ($stage): never {
            $this->faultTriggered = true;
            throw new \RuntimeException('MP3_TEST_FAULT:' . $stage);
        };
        if ($stage === 'metadata') {
            // Deterministic failure while resolving the ID3 cover metadata, before WriteTags.
            $repository = $this->createMock(CategorieTagImageRepository::class);
            $repository->method('findOneByCategorieAndYear')->willReturnCallback($fail);
            $this->replaceProcessorProperty('categorieTagImageRepository', $repository);
        } else {
            $fs = $this->getMockBuilder(Filesystem::class)->onlyMethods(['rename'])->getMock();
            $fs->method('rename')->willReturnCallback($fail);
            $this->replaceProcessorProperty('fs', $fs);
        }
        return null;
    }

    private function replaceProcessorProperty(string $name, object $value): void
    {
        $processor = self::getContainer()->get(Mp3Processor::class);
        $property = new \ReflectionProperty(Mp3Processor::class, $name);
        $this->restorations[] = [$processor, $property, $property->getValue($processor)];
        $property->setValue($processor, $value);
    }

    private function listen(Mp3WorkflowFault $listener): void
    {
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush], $listener);
        $this->listeners[] = $listener;
    }

    private function observe(string $event, callable $listener): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener($event, $listener);
        $this->dispatcherListeners[] = [$dispatcher, $event, $listener];
    }

    private function submitAllowingFault(Form $form): void
    {
        try {
            $this->submitForm($form);
        } catch (\RuntimeException $error) {
            if (!str_starts_with($error->getMessage(), 'MP3_TEST_FAULT:')) {
                throw $error;
            }
        }
    }

    private function stored(?Emission $emission = null): array
    {
        // Bypass Doctrine's identity map: these values really come from SQL.
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT thumbnail_mp3, url FROM emission WHERE id = ?',
            [($emission ?? $this->emission)->getId()]
        );
        self::assertIsArray($row);
        return $row;
    }

    private function assertOriginalContract(): void
    {
        $this->assertOtherUntouched();
        $stored = $this->stored();
        $exists = is_file($this->path($this->original));
        self::assertSame([
            'original exists' => true,
            'original SHA256' => $this->originalHash,
            'entity reference' => $this->original,
            'entity URL' => $this->url($this->original),
            'SQL reference' => $this->original,
            'SQL URL' => $this->url($this->original),
        ], [
            'original exists' => $exists,
            'original SHA256' => $exists ? hash_file('sha256', $this->path($this->original)) : null,
            'entity reference' => $this->observer->observed?->getThumbnailMp3(),
            'entity URL' => $this->observer->observed?->getUrl(),
            'SQL reference' => $stored['thumbnail_mp3'],
            'SQL URL' => $stored['url'],
        ]);
    }

    private function assertOtherUntouched(): void
    {
        $relative = $this->other->getThumbnailMp3();
        self::assertFileExists($this->path($relative));
        self::assertSame($this->otherHash, hash_file('sha256', $this->path($relative)));
        self::assertSame(['thumbnail_mp3' => $relative, 'url' => $this->url($relative)], $this->stored($this->other));
    }

    private function tags(string $relative): array
    {
        $info = (new \getID3())->analyze($this->path($relative));
        self::assertArrayNotHasKey('error', $info);
        self::assertArrayHasKey('id3v2', $info['tags']);
        return $info['tags']['id3v2'];
    }

    private function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(Mp3TestStorage::root(), \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile()) {
                $files[] = substr($entry->getPathname(), strlen(Mp3TestStorage::root()) + 1);
            }
        }
        sort($files);
        return $files;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->dispatcherListeners as [$dispatcher, $event, $listener]) {
                $dispatcher->removeListener($event, $listener);
            }
            foreach ($this->listeners as $listener) {
                $this->entityManager->getEventManager()->removeEventListener([Events::onFlush], $listener);
            }
            foreach (array_reverse($this->restorations) as [$processor, $property, $value]) {
                $property->setValue($processor, $value);
            }
            if (isset($this->fixtureHash)) {
                self::assertSame($this->fixtureHash, hash_file('sha256', $this->fixture()));
            }
        } finally {
            parent::tearDown();
        }
    }
}
