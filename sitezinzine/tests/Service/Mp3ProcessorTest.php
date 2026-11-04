<?php

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\Emission;
use App\Entity\User;
use App\Entity\InviteOldAnimateur;
use App\Service\Mp3Processor;
use App\Entity\CategorieTagImage;
use App\Repository\CategorieTagImageRepository;
use Symfony\Component\Filesystem\Filesystem;
use Vich\UploaderBundle\Storage\StorageInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class Mp3ProcessorTest extends KernelTestCase
{
    private string $fixtureHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureHash = hash_file('sha256', dirname(__DIR__) . '/Fixtures/mp3/test.mp3');
    }

    protected function tearDown(): void
    {
        try {
            self::assertSame($this->fixtureHash, hash_file('sha256', dirname(__DIR__) . '/Fixtures/mp3/test.mp3'));
        } finally {
            parent::tearDown();
        }
    }

    public function testStorageConfigurationIsIsolated(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $root = \App\Tests\Support\Mp3TestStorage::root();
        self::assertSame($root, $container->getParameter('app.mp3_base_dir'));
        $mapping = $container->getParameter('vich_uploader.mappings')['emissionsMp3'] ?? null;
        self::assertNotNull($mapping);
        self::assertSame($root, $mapping['upload_destination']);
        self::assertSame($container->getParameter('app.mp3_public_base_url'), $mapping['uri_prefix']);
        self::assertSame(realpath(sys_get_temp_dir()), dirname($root));
        self::assertMatchesRegularExpression('/^zinzine-mp3-tests-[a-f0-9]{32}$/', basename($root));
        self::assertDirectoryExists($root);
    }

    public function testProcessRejectsCategoryWithoutValidSlug(): void
    {
        self::bootKernel();

        $mp3Processor = self::getContainer()->get(Mp3Processor::class);

        $categorie = new Categories();
        $categorie->setTitre('Catégorie test');

        $emission = new Emission();
        $emission->setTitre('Émission test');
        $emission->setCategorie($categorie);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Categorie.slug manquant/invalide : impossible de ranger le MP3.'
        );

        $mp3Processor->process($emission);
    }

    public function testProcessRejectsEmissionWithoutMp3Filename(): void
    {
        self::bootKernel();

        $mp3Processor = self::getContainer()->get(Mp3Processor::class);

        $categorie = new Categories();
        $categorie->setTitre('Catégorie test');
        $categorie->setSlug('TST');

        $emission = new Emission();
        $emission->setTitre('Émission test');
        $emission->setCategorie($categorie);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "Aucun nom de fichier MP3 n'a été enregistré par Vich."
        );

        $mp3Processor->process($emission);
    }

    public function testProcessRejectsMissingSourceFile(): void
    {
        self::bootKernel();

        $mp3Processor = self::getContainer()->get(Mp3Processor::class);

        $categorie = new Categories();
        $categorie->setTitre('Catégorie test');
        $categorie->setSlug('TST');

        $emission = new Emission();
        $emission->setTitre('Émission test');
        $emission->setCategorie($categorie);
        $emission->setThumbnailMp3('fichier-inexistant.mp3');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fichier source introuvable :');

        $mp3Processor->process($emission);
    }

    public function testProcessMovesMp3AndUpdatesEmission(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $mp3Processor = $container->get(Mp3Processor::class);

        $projectDir = $container->getParameter('kernel.project_dir');

        $fixturePath = $projectDir . '/tests/Fixtures/mp3/test.mp3';
        $mp3BaseDir = \App\Tests\Support\Mp3TestStorage::root();

        $this->assertFileExists($fixturePath);

        if (!is_dir($mp3BaseDir)) {
            mkdir($mp3BaseDir, 0777, true);
        }

        $sourceFilename = 'mp3processor-test-' . uniqid() . '.mp3';
        $sourcePath = $mp3BaseDir . '/' . $sourceFilename;

        $this->assertTrue(
            copy($fixturePath, $sourcePath),
            'Impossible de copier la fixture MP3.'
        );

        $categorie = new Categories();
        $categorie->setTitre('Catégorie test');
        $categorie->setSlug('TST');

        $emission = new Emission();
        $emission->setTitre('Émission service MP3 ' . uniqid());
        $emission->setCategorie($categorie);
        $emission->setThumbnailMp3($sourceFilename);

        $finalPath = null;

        try {
            $mp3Processor->process($emission);

            $this->assertNotNull($emission->getThumbnailMp3());
            $this->assertNotNull($emission->getUrl());

            $this->assertStringStartsWith(
                'TST/' . date('Y') . '/',
                $emission->getThumbnailMp3()
            );

            $this->assertStringEndsWith(
                '.mp3',
                $emission->getThumbnailMp3()
            );

            $this->assertStringContainsString(
                '/uploads/emissionsMp3/' . $emission->getThumbnailMp3(),
                $emission->getUrl()
            );

            $finalPath = $mp3BaseDir . '/' . $emission->getThumbnailMp3();

            $this->assertFileDoesNotExist($sourcePath);
            $this->assertFileExists($finalPath);
        } finally {
            if (is_file($sourcePath)) {
                unlink($sourcePath);
            }

            if ($finalPath !== null && is_file($finalPath)) {
                unlink($finalPath);
            }
        }
    }

    public function testDeleteRemovesMp3FileAndClearsEmission(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $mp3Processor = $container->get(Mp3Processor::class);

        $projectDir = $container->getParameter('kernel.project_dir');

        $fixturePath = $projectDir . '/tests/Fixtures/mp3/test.mp3';
        $mp3BaseDir = \App\Tests\Support\Mp3TestStorage::root();

        $this->assertFileExists($fixturePath);

        if (!is_dir($mp3BaseDir)) {
            mkdir($mp3BaseDir, 0777, true);
        }

        $filename = 'mp3processor-delete-test-' . uniqid() . '.mp3';
        $filePath = $mp3BaseDir . '/' . $filename;

        $this->assertTrue(
            copy($fixturePath, $filePath),
            'Impossible de copier la fixture MP3.'
        );

        $emission = new Emission();
        $emission->setThumbnailMp3($filename);
        $emission->setUrl(
            'https://example.test/uploads/emissionsMp3/' . $filename
        );

        try {
            $this->assertFileExists($filePath);

            $mp3Processor->delete($emission);

            $this->assertFileDoesNotExist($filePath);
            $this->assertNull($emission->getThumbnailMp3());
            $this->assertNull($emission->getUrl());
        } finally {
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
    }

    public function testDeleteDoesNothingWhenEmissionHasNoMp3(): void
    {
        self::bootKernel();

        $mp3Processor = self::getContainer()->get(Mp3Processor::class);

        $emission = new Emission();

        $this->assertNull($emission->getThumbnailMp3());

        $mp3Processor->delete($emission);

        $this->assertNull($emission->getThumbnailMp3());
        $this->assertNull($emission->getUrl());
    }

    public function testProcessWritesUserUsernameAsArtistTag(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $mp3Processor = $container->get(Mp3Processor::class);

        $projectDir = $container->getParameter('kernel.project_dir');

        $fixturePath = $projectDir . '/tests/Fixtures/mp3/test.mp3';
        $mp3BaseDir = \App\Tests\Support\Mp3TestStorage::root();

        $this->assertFileExists($fixturePath);

        if (!is_dir($mp3BaseDir)) {
            mkdir($mp3BaseDir, 0777, true);
        }

        $sourceFilename = 'mp3processor-artist-test-' . uniqid() . '.mp3';
        $sourcePath = $mp3BaseDir . '/' . $sourceFilename;

        $this->assertTrue(
            copy($fixturePath, $sourcePath),
            'Impossible de copier la fixture MP3.'
        );

        $categorie = new Categories();
        $categorie->setTitre('Catégorie test');
        $categorie->setSlug('TST');

        $firstUser = new User();
        $firstUser->setUsername('Animateur Un');

        $secondUser = new User();
        $secondUser->setUsername('Animateur Deux');

        $emission = new Emission();
        $emission->setTitre('Émission artiste test');
        $emission->setCategorie($categorie);
        $emission->setThumbnailMp3($sourceFilename);
        $emission->addUser($firstUser);
        $emission->addUser($secondUser);

        $finalPath = null;

        try {
            $mp3Processor->process($emission);

            $this->assertNotNull($emission->getThumbnailMp3());

            $finalPath = $mp3BaseDir . '/' . $emission->getThumbnailMp3();

            $this->assertFileExists($finalPath);

            $getID3 = new \getID3();
            $fileInfo = $getID3->analyze($finalPath);

            $this->assertArrayHasKey('tags', $fileInfo);
            $this->assertArrayHasKey('id3v2', $fileInfo['tags']);
            $this->assertArrayHasKey('artist', $fileInfo['tags']['id3v2']);

            $this->assertSame(
                'Animateur Un, Animateur Deux',
                $fileInfo['tags']['id3v2']['artist'][0]
            );
        } finally {
            if (is_file($sourcePath)) {
                unlink($sourcePath);
            }

            if ($finalPath !== null && is_file($finalPath)) {
                unlink($finalPath);
            }
        }
    }

    public function testProcessWritesOldAnimatorsAsArtistTag(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $mp3Processor = $container->get(Mp3Processor::class);

        $projectDir = $container->getParameter('kernel.project_dir');

        $fixturePath = $projectDir . '/tests/Fixtures/mp3/test.mp3';
        $mp3BaseDir = \App\Tests\Support\Mp3TestStorage::root();

        $this->assertFileExists($fixturePath);

        if (!is_dir($mp3BaseDir)) {
            mkdir($mp3BaseDir, 0777, true);
        }

        $sourceFilename = 'mp3processor-old-animators-test-' . uniqid() . '.mp3';
        $sourcePath = $mp3BaseDir . '/' . $sourceFilename;

        $this->assertTrue(
            copy($fixturePath, $sourcePath),
            'Impossible de copier la fixture MP3.'
        );

        $categorie = new Categories();
        $categorie->setTitre('Catégorie test');
        $categorie->setSlug('TST');

        $firstAnimator = new InviteOldAnimateur();
        $firstAnimator->setFirstName('Alice');
        $firstAnimator->setLastName('Martin');

        $secondAnimator = new InviteOldAnimateur();
        $secondAnimator->setFirstName('Bob');
        $secondAnimator->setLastName('Durand');

        $emission = new Emission();
        $emission->setTitre('Émission anciens animateurs test');
        $emission->setCategorie($categorie);
        $emission->setThumbnailMp3($sourceFilename);
        $emission->addInviteOldAnimateur($firstAnimator);
        $emission->addInviteOldAnimateur($secondAnimator);

        $finalPath = null;

        try {
            $mp3Processor->process($emission);

            $this->assertNotNull($emission->getThumbnailMp3());

            $finalPath = $mp3BaseDir . '/' . $emission->getThumbnailMp3();

            $this->assertFileExists($finalPath);

            $getID3 = new \getID3();
            $fileInfo = $getID3->analyze($finalPath);

            $this->assertArrayHasKey('tags', $fileInfo);
            $this->assertArrayHasKey('id3v2', $fileInfo['tags']);
            $this->assertArrayHasKey('artist', $fileInfo['tags']['id3v2']);

            $this->assertSame(
                'Alice Martin, Bob Durand',
                $fileInfo['tags']['id3v2']['artist'][0]
            );
        } finally {
            if (is_file($sourcePath)) {
                unlink($sourcePath);
            }

            if ($finalPath !== null && is_file($finalPath)) {
                unlink($finalPath);
            }
        }
    }

    public function testProcessEmbedsCategoryYearCoverAsPng(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $projectDir = $container->getParameter('kernel.project_dir');

        $fixtureMp3Path = $projectDir . '/tests/Fixtures/mp3/test.mp3';
        $coverPath = $projectDir . '/tests/Fixtures/images/test-cover.png';

        $this->assertFileExists($fixtureMp3Path);
        $this->assertFileExists($coverPath);

        $filesystem = new Filesystem();

        $tempDir = \App\Tests\Support\Mp3TestStorage::root() . '/cover-' . bin2hex(random_bytes(16));

        $filesystem->mkdir($tempDir);

        $sourceFilename = 'source.mp3';
        $sourcePath = $tempDir . '/' . $sourceFilename;

        $this->assertTrue(
            copy($fixtureMp3Path, $sourcePath),
            'Impossible de copier la fixture MP3.'
        );

        $categorie = new Categories();
        $categorie->setTitre('Catégorie test');
        $categorie->setSlug('TST');

        $tagImage = new CategorieTagImage();
        $tagImage->setCategorie($categorie);
        $tagImage->setAnnee(2024);
        $tagImage->setImage('test-cover.png');

        $repository = $this->createMock(
            CategorieTagImageRepository::class
        );

        $repository
            ->expects($this->once())
            ->method('findOneByCategorieAndYear')
            ->with($categorie, 2024)
            ->willReturn($tagImage);

        $storage = $this->createMock(StorageInterface::class);

        $storage
            ->expects($this->once())
            ->method('resolvePath')
            ->with($tagImage, 'imageFile')
            ->willReturn($coverPath);

        $mp3Processor = new Mp3Processor(
            $storage,
            $filesystem,
            $repository,
            $tempDir,
            'https://example.test/uploads/emissionsMp3',
            $projectDir
        );

        $emission = new Emission();
        $emission->setTitre('Émission avec pochette');
        $emission->setCategorie($categorie);
        $emission->setDatepub(new \DateTime('2024-06-15'));
        $emission->setThumbnailMp3($sourceFilename);

        try {
            $mp3Processor->process($emission);

            $this->assertNotNull($emission->getThumbnailMp3());

            $finalPath = $tempDir . '/' . $emission->getThumbnailMp3();

            $this->assertFileExists($finalPath);

            $getID3 = new \getID3();
            $fileInfo = $getID3->analyze($finalPath);

            $this->assertArrayHasKey('comments', $fileInfo);
            $this->assertArrayHasKey('picture', $fileInfo['comments']);
            $this->assertNotEmpty($fileInfo['comments']['picture']);

            $picture = $fileInfo['comments']['picture'][0];

            $this->assertSame(
                'image/png',
                $picture['image_mime']
            );
        } finally {
            // The guarded process-owned storage cleanup also removes this directory.
        }
    }
}
