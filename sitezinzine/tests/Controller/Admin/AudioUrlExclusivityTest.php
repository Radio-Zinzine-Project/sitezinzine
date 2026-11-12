<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\{Categories, Emission, User};
use App\Tests\Support\{FunctionalTestCase, Mp3TestStorage};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** RED contracts for audio ownership; all writes are rolled back in the test database. */
final class AudioUrlExclusivityTest extends FunctionalTestCase
{
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
        self::assertSame(Mp3TestStorage::root(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::root(), $container->getParameter('vich_uploader.mappings')['emissionsMp3']['upload_destination']);
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
    public function testCreationRejectsAnotherEmissionsUrl(bool $local): void
    {
        $this->prepareAudio($local);
        $before = $this->state($this->first);
        $title = $this->marker . ' duplicate';
        $crawler = $this->client->request('GET', '/admin/emission/create');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Sauvegarder')->form([
            'emission[titre]' => $title,
            'emission[duree]' => '60',
            'emission[categorie]' => (string) $this->category->getId(),
            'emission[users]' => [(string) $this->owner->getId()],
            'emission[url]' => $this->state($this->first)['url'],
        ]));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM emission WHERE titre = ?', [$title]
        ), 'A duplicate URL must not create an emission.');
        self::assertSame($before, $this->state($this->first));
        $this->assertRejectedForm();
    }

    /** @dataProvider audioKinds */
    public function testModificationRejectsAnotherEmissionsUrl(bool $local): void
    {
        $this->prepareAudio($local);
        $this->client->submit($this->editForm($this->second, $this->state($this->first)['url']));
        $this->assertRejectedForm();
    }

    /** @dataProvider audioKinds */
    public function testRejectedModificationPreservesBothPersistedAssociations(bool $local): void
    {
        $this->prepareAudio($local);
        $before = [$this->state($this->first), $this->state($this->second)];
        $this->client->submit($this->editForm($this->second, $this->state($this->first)['url']));
        // Read SQL directly: a bound form may have changed Doctrine's managed object.
        self::assertSame($before, [$this->state($this->first), $this->state($this->second)],
            'Neither the original owner nor the edited emission may lose its association.');
    }

    /** @dataProvider audioKinds */
    public function testModificationMayKeepItsOwnUrl(bool $local): void
    {
        $this->prepareAudio($local);
        $before = $this->state($this->first);
        $form = $this->editForm($this->first, $this->state($this->first)['url']);
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
        $hash = hash_file('sha256', Mp3TestStorage::root() . '/' . $first['thumbnail_mp3']);
        $second = $this->upload($this->second);
        self::assertNotSame($first['url'], $second['url']);
        self::assertNotSame($first['thumbnail_mp3'], $second['thumbnail_mp3']);
        self::assertSame($first, $this->state($this->first));
        self::assertSame($hash, hash_file('sha256', Mp3TestStorage::root() . '/' . $first['thumbnail_mp3']));
    }

    private function prepareAudio(bool $local): void
    {
        if ($local) {
            $this->upload($this->first);
            $this->upload($this->second);
        }
    }

    private function editForm(Emission $emission, ?string $url): Form
    {
        $crawler = $this->client->request('GET', '/admin/emission/' . $emission->getId() . '/edit');
        self::assertResponseIsSuccessful();
        return $crawler->selectButton('Sauvegarder')->form(['emission[url]' => $url ?? '']);
    }

    private function upload(Emission $emission): array
    {
        $form = $this->editForm($emission, $this->state($emission)['url']);
        $path = Mp3TestStorage::root() . '/input-' . bin2hex(random_bytes(12)) . '.mp3';
        self::assertTrue(copy(dirname(__DIR__, 2) . '/Fixtures/mp3/test.mp3', $path));
        $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), [
            'emission' => ['thumbnailFileMp3' => new UploadedFile($path, 'audio.mp3', 'audio/mpeg', null, true)],
        ]);
        self::assertResponseRedirects();
        $state = $this->state($emission);
        self::assertNotEmpty($state['thumbnail_mp3']);
        self::assertFileExists(Mp3TestStorage::root() . '/' . $state['thumbnail_mp3']);
        self::assertSame(self::getContainer()->getParameter('app.mp3_public_base_url') . '/' . $state['thumbnail_mp3'], $state['url']);
        return $state;
    }

    private function assertRejectedForm(): void
    {
        self::assertFalse($this->client->getResponse()->isRedirect(), 'A conflicting URL must be refused.');
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 422]);
        self::assertSelectorExists('form .invalid-feedback, form .form-error-message', 'The refusal must display a validation error.');
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
