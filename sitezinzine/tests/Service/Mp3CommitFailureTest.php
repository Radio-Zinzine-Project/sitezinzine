<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Categories, Emission};
use App\Enum\Mp3FailureStage;
use App\Exception\Mp3OperationFailure;
use App\Repository\CategorieTagImageRepository;
use App\Service\{Mp3Processor, Mp3Replacement};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;

final class Mp3CommitFailureTest extends KernelTestCase
{
    public function testThrownCommitRetainsBothVersionsAndReportsAnUncertainOutcome(): void
    {
        self::bootKernel();
        $project = self::getContainer()->getParameter('kernel.project_dir');
        $fixture = $project . '/tests/Fixtures/mp3/test.mp3';
        $fixtureHash = hash_file('sha256', $fixture);
        $base = Mp3TestStorage::root() . '/commit-' . bin2hex(random_bytes(16));
        $fs = new Filesystem();
        $fs->mkdir($base . '/TST/2024', 0700);
        $original = 'TST/2024/original.mp3';
        self::assertTrue(copy($fixture, $base . '/' . $original));
        self::assertTrue(copy($fixture, $base . '/upload.mp3'));
        $processor = new Mp3Processor($this->createMock(StorageInterface::class), $fs,
            $this->createMock(CategorieTagImageRepository::class), $base, 'https://example.test/mp3', $project, $base . '/private');
        $emission = (new Emission())->setTitre('Titre')->setDatepub(new \DateTime('2024-06-15'))
            ->setCategorie((new Categories())->setSlug('TST')->setTitre('Album'))
            ->setThumbnailMp3($original)->setUrl('https://example.test/original.mp3')
            ->setThumbnailFileMp3(new UploadedFile($base . '/upload.mp3', 'new.mp3', 'audio/mpeg', null, true));
        (new \ReflectionProperty(Emission::class, 'id'))->setValue($emission, 42);
        $manager = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $events = new EventManager();
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getEventManager')->willReturn($events);
        $level = 0;
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$level): void { $level = 1; });
        $connection->method('fetchAssociative')->willReturn(['thumbnail_mp3' => $original, 'url' => $emission->getUrl()]);
        $manager->method('flush')->willReturnCallback(static function () use ($events, $manager): void {
            $events->dispatchEvent(Events::onFlush, new OnFlushEventArgs($manager));
        });
        $origin = new \RuntimeException('commit transport failure');
        $connection->expects(self::once())->method('commit')->willThrowException($origin);
        $connection->expects(self::once())->method('rollBack')->willReturnCallback(static function () use (&$level): void { $level = 0; });
        $connection->expects(self::never())->method('fetchOne');
        try {
            (new Mp3Replacement($processor))->replace($emission, $manager, $original, $emission->getUrl());
            self::fail('A thrown commit cannot be reported as success.');
        } catch (Mp3OperationFailure $failure) {
            self::assertSame(Mp3FailureStage::COMMIT, $failure->stage);
            self::assertFalse($failure->rollbackConfirmed, 'A successful local rollback does not establish what a failed commit did.');
            self::assertTrue($failure->originalPreserved);
            self::assertSame($origin, $failure->getPrevious());
        } finally {
            self::assertSame($fixtureHash, hash_file('sha256', $fixture));
        }
        self::assertSame($fixtureHash, hash_file('sha256', $base . '/' . $original));
        self::assertNotSame($original, $emission->getThumbnailMp3());
        self::assertFileExists($base . '/' . $emission->getThumbnailMp3());
        self::assertFileDoesNotExist($base . '/upload.mp3');
    }
}
