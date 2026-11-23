<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\{Categories, Emission, User};
use App\Tests\Support\{FunctionalTestCase, Mp3TestStorage};
use App\Tests\Support\WithMp3StorageLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Form URL locking and internal upload ownership; fixture writes are rolled back. */
final class AudioUrlExclusivityTest extends FunctionalTestCase
{
    use WithMp3StorageLock;

    private User $owner;
    private Categories $category;
    private Emission $first;
    private Emission $second;

    protected function setUp(): void
    {
        $this->client = static::createClient(['debug' => true]);
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        // Check isolation before connecting or creating fixtures.
        self::assertSame('test', $container->getParameter('kernel.environment'));
        self::assertStringEndsWith('_test', (string) $this->entityManager->getConnection()->getDatabase());
        self::assertSame(Mp3TestStorage::library(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::library(), $container->getParameter('vich_uploader.mappings')['emissionsMp3']['upload_destination']);
        $this->entityManager->getConnection()->beginTransaction();
        $this->marker = 'exclusive' . bin2hex(random_bytes(8));
        $this->owner = $this->createUser('ROLE_SUPER_ADMIN');
        $this->category = $this->createCategory();
        $this->first = $this->createEmission($this->marker . ' A', $this->owner, $this->category);
        $this->first->setUrl('https://example.test/' . $this->marker . '-a.mp3');
        $this->entityManager->flush();
        $this->second = $this->createEmission($this->marker . ' B', $this->owner, $this->category);
        $this->second->setUrl('https://example.test/' . $this->marker . '-b.mp3');
        $this->entityManager->flush();
        $this->client->loginUser($this->owner);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            unset($this->owner, $this->category, $this->first, $this->second);
        }
    }

    public static function audioKinds(): iterable
    {
        yield 'local MP3' => [true];
        yield 'external URL' => [false];
    }

    /** @dataProvider audioKinds */
    public function testCreationIgnoresAnotherEmissionsForgedUrl(bool $local): void
    {
        $this->prepareAudio($local);
        $before = $this->state($this->first);
        $title = $this->marker . ' duplicate';
        $crawler = $this->client->request('GET', '/admin/emission/create');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[name="emission[url]"]');
        self::assertSelectorNotExists('[name="emission[thumbnailFileMp3]"]');
        $form = $crawler->selectButton('Sauvegarder')->form([
            'emission[titre]' => $title,
            'emission[duree]' => '60',
            'emission[categorie]' => (string) $this->category->getId(),
            'emission[theme]' => (string) $this->second->getTheme()->getId(),
            'emission[users]' => [(string) $this->owner->getId()],
        ]);
        $values = $form->getPhpValues();
        $values['emission']['url'] = $before['url'];
        $this->client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM emission WHERE titre = ?', [$title]
        ));
        self::assertSame(['url' => null, 'thumbnail_mp3' => null], $this->entityManager->getConnection()->fetchAssociative(
            'SELECT url, thumbnail_mp3 FROM emission WHERE titre = ?', [$title]
        ));
        self::assertSame($before, $this->state($this->first));
    }

    /** @dataProvider audioKinds */
    public function testModificationIgnoresAnotherEmissionsForgedUrl(bool $local): void
    {
        $this->prepareAudio($local);
        $before = $this->state($this->second);
        $this->submitForgedEdit($this->second, $this->state($this->first)['url']);
        self::assertResponseRedirects();
        self::assertSame($before, $this->state($this->second));
    }

    /** @dataProvider audioKinds */
    public function testForgedModificationPreservesBothPersistedAssociations(bool $local): void
    {
        $this->prepareAudio($local);
        $before = [$this->state($this->first), $this->state($this->second)];
        $this->submitForgedEdit($this->second, $this->state($this->first)['url']);
        self::assertResponseRedirects();
        // Read SQL directly: a bound form may have changed Doctrine's managed object.
        self::assertSame($before, [$this->state($this->first), $this->state($this->second)],
            'Neither the original owner nor the edited emission may lose its association.');
    }

    public function testModificationIgnoresAnotherEmissionsUrlWithDifferentCase(): void
    {
        $before = [$this->state($this->first), $this->state($this->second)];
        $url = strtoupper($before[0]['url']);
        self::assertNotSame($before[0]['url'], $url);

        $this->submitForgedEdit($this->second, $url);

        self::assertResponseRedirects();
        self::assertSame($before, [$this->state($this->first), $this->state($this->second)],
            'A case-only conflict must preserve both persisted audio associations.');
    }

    public function testModificationIgnoresSoftDeletedEmissionsForgedUrl(): void
    {
        $this->first->softDelete();
        $this->entityManager->flush();
        self::assertNotNull($this->entityManager->getConnection()->fetchOne(
            'SELECT deleted_at FROM emission WHERE id = ?', [$this->first->getId()]
        ));
        $before = [$this->state($this->first), $this->state($this->second)];

        $this->submitForgedEdit($this->second, $before[0]['url']);

        self::assertResponseRedirects();
        self::assertSame($before, [$this->state($this->first), $this->state($this->second)],
            'A soft-deleted owner must keep its URL and the edited association must remain unchanged.');
    }

    /** @dataProvider audioKinds */
    public function testModificationMayKeepItsOwnUrl(bool $local): void
    {
        $this->prepareAudio($local);
        $before = $this->state($this->first);
        $form = $this->editForm($this->first);
        $form['emission[keyword]'] = 'editorial-change';
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame($before, $this->state($this->first));
        self::assertSame('editorial-change', $this->entityManager->getConnection()->fetchOne(
            'SELECT keyword FROM emission WHERE id = ?', [$this->first->getId()]
        ));
    }

    public function testTwoDistinctUploadsKeepDistinctAudioAssociations(): void
    {
        // Identical naming inputs exercise the existing safe filename collision handling.
        foreach ([$this->first, $this->second] as $emission) {
            $emission->setTitre($this->marker)->setDatepub(new \DateTime('2024-06-15'))->setUrl(null);
        }
        $this->entityManager->flush();
        $first = $this->upload($this->first);
        $hash = hash_file('sha256', Mp3TestStorage::library() . '/' . $first['thumbnail_mp3']);
        $second = $this->upload($this->second);
        self::assertNotSame($first['url'], $second['url']);
        self::assertNotSame($first['thumbnail_mp3'], $second['thumbnail_mp3']);
        self::assertSame($first, $this->state($this->first));
        self::assertSame($hash, hash_file('sha256', Mp3TestStorage::library() . '/' . $first['thumbnail_mp3']));
    }

    private function prepareAudio(bool $local): void
    {
        if ($local) {
            $this->upload($this->first);
            $this->upload($this->second);
        }
    }

    public function testShowStillLinksToTheStoredAudioUrl(): void
    {
        $crawler = $this->client->request('GET', '/admin/emission/' . $this->first->getId());
        self::assertResponseIsSuccessful();
        $listen = $crawler->filter('a')->reduce(static fn ($link) => str_contains($link->text(), 'Écouter'));
        self::assertGreaterThan(0, $listen->count());
        self::assertSame($this->first->getUrl(), $listen->first()->attr('href'));
    }

    private function editForm(Emission $emission): Form
    {
        $crawler = $this->client->request('GET', '/admin/emission/' . $emission->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#emission_url[disabled]');
        self::assertSame($this->state($emission)['url'] ?? '', $crawler->filter('#emission_url')->attr('value') ?? '');
        return $crawler->selectButton('Sauvegarder')->form();
    }

    private function submitForgedEdit(Emission $emission, ?string $url): void
    {
        $form = $this->editForm($emission);
        $values = $form->getPhpValues();
        $values['emission']['url'] = $url;
        $this->client->request($form->getMethod(), $form->getUri(), $values);
    }

    private function upload(Emission $emission): array
    {
        $form = $this->editForm($emission);
        $path = Mp3TestStorage::root() . '/input-' . bin2hex(random_bytes(12)) . '.mp3';
        // A short real MP3 excerpt exercises upload/tagging without copying the full recording.
        $audio = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/mp3/test.mp3', false, null, 0, 65536);
        self::assertNotFalse($audio);
        self::assertSame(65536, file_put_contents($path, $audio));
        unset($audio);
        $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), [
            'emission' => ['thumbnailFileMp3' => new UploadedFile($path, 'audio.mp3', 'audio/mpeg', null, true)],
        ]);
        self::assertResponseRedirects();
        $state = $this->state($emission);
        self::assertNotEmpty($state['thumbnail_mp3']);
        self::assertFileExists(Mp3TestStorage::library() . '/' . $state['thumbnail_mp3']);
        self::assertSame(self::getContainer()->getParameter('app.mp3_public_base_url') . '/' . $state['thumbnail_mp3'], $state['url']);
        return $state;
    }

    private function state(Emission $emission): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT url, thumbnail_mp3 FROM emission WHERE id = ?', [$emission->getId()]
        );
        self::assertIsArray($row);
        return $row;
    }
}
