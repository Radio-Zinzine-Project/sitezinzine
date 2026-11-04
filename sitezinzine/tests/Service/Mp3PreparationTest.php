<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Categories, Emission};
use App\Repository\CategorieTagImageRepository;
use App\Service\Mp3Processor;
use App\Service\Mp3Replacement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\Mp3TestStorage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Event\{Event, Events};

final class Mp3PreparationTest extends KernelTestCase
{
    private string $base;
    private Mp3Processor $processor;
    private Emission $emission;
    private string $fixture;
    private string $fixtureHash;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->fixture = self::getContainer()->getParameter('kernel.project_dir') . '/tests/Fixtures/mp3/test.mp3';
        $this->fixtureHash = hash_file('sha256', $this->fixture);
        $this->base = Mp3TestStorage::root() . '/prepare-' . bin2hex(random_bytes(16));
        (new Filesystem())->mkdir($this->base . '/TST/2024', 0700);
        self::assertTrue(copy($this->fixture, $this->base . '/TST/2024/original.mp3'));
        $this->emission = (new Emission())->setTitre('Titre')->setDatepub(new \DateTime('2024-06-15'))
            ->setCategorie((new Categories())->setSlug('TST')->setTitre('Album'))
            ->setThumbnailMp3('TST/2024/original.mp3')->setUrl('https://example.test/original.mp3');
        $this->processor = new Mp3Processor(
            $this->createMock(StorageInterface::class), new Filesystem(),
            $this->createMock(CategorieTagImageRepository::class), $this->base,
            'https://example.test/mp3', self::getContainer()->getParameter('kernel.project_dir'),
            $this->base . '/private'
        );
    }

    private function upload(): UploadedFile
    {
        $path = $this->base . '/upload-' . bin2hex(random_bytes(16)) . '.mp3';
        self::assertTrue(copy($this->fixture, $path));
        return new UploadedFile($path, 'new.mp3', 'audio/mpeg', null, true);
    }

    public function testPreparationLeavesOriginalAndEntityUntouched(): void
    {
        $upload = $this->upload();
        $candidate = $this->processor->prepare($this->emission, $upload);
        try {
            self::assertSame('TST/2024/original.mp3', $this->emission->getThumbnailMp3());
            self::assertSame('https://example.test/original.mp3', $this->emission->getUrl());
            self::assertSame($this->fixtureHash, hash_file('sha256', $this->base . '/TST/2024/original.mp3'));
            self::assertSame($this->fixtureHash, hash_file('sha256', $upload->getPathname()));
            self::assertStringStartsWith($this->base . '/private/', $candidate->path);
            self::assertFileExists($candidate->path);
        } finally {
            $this->processor->release($candidate);
        }
        self::assertDirectoryDoesNotExist($candidate->directory);
        self::assertFileExists($this->base . '/TST/2024/original.mp3');
    }

    public function testPublishingNeverOverwritesAnExistingMp3(): void
    {
        $occupied = $this->base . '/TST/2024/TST20240615Titre.mp3';
        self::assertTrue(copy($this->fixture, $occupied));
        $published = [];
        for ($i = 0; $i < 2; ++$i) {
            $candidate = $this->processor->prepare($this->emission, $this->upload());
            try {
                $published[] = $this->processor->publish($candidate);
            } finally {
                $this->processor->release($candidate);
            }
        }
        self::assertSame(['TST/2024/TST20240615Titre-1.mp3', 'TST/2024/TST20240615Titre-2.mp3'], $published);
        self::assertSame($this->fixtureHash, hash_file('sha256', $occupied));
        foreach ($published as $relative) {
            self::assertFileExists($this->base . '/' . $relative);
        }
    }

    public function testInvalidNewFileCannotDamageTheOriginal(): void
    {
        $path = $this->base . '/invalid.mp3';
        file_put_contents($path, 'Not an MP3');
        try {
            $this->processor->prepare($this->emission, new UploadedFile($path, 'bad.mp3', null, null, true));
            self::fail('An invalid MP3 must not be published.');
        } catch (\RuntimeException $error) {
            self::assertSame('MP3 invalide ou illisible.', $error->getMessage());
        }
        self::assertSame($this->fixtureHash, hash_file('sha256', $this->base . '/TST/2024/original.mp3'));
        self::assertSame('TST/2024/original.mp3', $this->emission->getThumbnailMp3());
        self::assertSame([], glob($this->base . '/private/mp3-*'));
    }

    public function testOriginalIsDeletedOnlyAfterCommitConfirmation(): void
    {
        $original = $this->emission->getThumbnailMp3();
        $originalUrl = $this->emission->getUrl();
        $upload = $this->upload();
        $this->emission->setThumbnailFileMp3($upload);
        $connection = $this->createMock(Connection::class);
        $manager = $this->createMock(EntityManagerInterface::class);
        $events = new \Doctrine\Common\EventManager();
        $manager->method('getEventManager')->willReturn($events);
        $manager->method('getConnection')->willReturn($connection);
        $connection->method('getTransactionNestingLevel')->willReturn(0);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('fetchAssociative')->willReturn(['thumbnail_mp3' => $original, 'url' => $originalUrl]);
        $committed = false;
        $manager->expects(self::exactly(2))->method('flush')->willReturnCallback(function () use ($original, $events, $manager): void {
            $events->dispatchEvent(\Doctrine\ORM\Events::onFlush, new \Doctrine\ORM\Event\OnFlushEventArgs($manager));
            self::assertSame($this->fixtureHash, hash_file('sha256', $this->base . '/' . $original));
            self::assertFileExists($this->base . '/' . $this->emission->getThumbnailMp3());
            self::assertNull($this->emission->getThumbnailFileMp3(), 'Vich must not receive the upload during flush.');
        });
        $connection->expects(self::once())->method('commit')->willReturnCallback(function () use (&$committed, $original): void {
            self::assertSame($this->fixtureHash, hash_file('sha256', $this->base . '/' . $original));
            $committed = true;
        });
        $connection->method('fetchOne')->willReturnCallback(function () use (&$committed): int {
            self::assertTrue($committed, 'Obsolete-file cleanup must follow commit confirmation.');
            return 0;
        });
        (new Mp3Replacement($this->processor))->replace($this->emission, $manager, $original, $originalUrl);
        self::assertTrue($committed);
        self::assertFileDoesNotExist($this->base . '/' . $original);
        self::assertFileExists($this->base . '/' . $this->emission->getThumbnailMp3());
        self::assertFileDoesNotExist($upload->getPathname());
    }

    public function testRawVichUploadCannotBypassPrivatePreparation(): void
    {
        $this->emission->setThumbnailFileMp3($this->upload());
        $mapping = new PropertyMapping('thumbnailFileMp3', 'thumbnailMp3');
        $mapping->setMappingName('emissionsMp3');
        try {
            self::getContainer()->get('event_dispatcher')->dispatch(new Event($this->emission, $mapping), Events::PRE_UPLOAD);
            self::fail('Vich must not move an unprepared MP3.');
        } catch (\LogicException $error) {
            self::assertSame('Les MP3 doivent être préparés par le traitement sécurisé avant enregistrement.', $error->getMessage());
        }
        self::assertFalse(self::getContainer()->getParameter('vich_uploader.mappings')['emissionsMp3']['delete_on_update']);
        self::assertSame('TST/2024/original.mp3', $this->emission->getThumbnailMp3());
        self::assertSame($this->fixtureHash, hash_file('sha256', $this->base . '/TST/2024/original.mp3'));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->fixtureHash)) {
                self::assertSame($this->fixtureHash, hash_file('sha256', $this->fixture));
            }
        } finally {
            parent::tearDown();
        }
    }
}
