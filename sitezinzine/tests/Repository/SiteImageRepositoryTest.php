<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SiteImage;
use App\Repository\SiteImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SiteImageRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private SiteImageRepository $repository;

    /**
     * @var int[]
     */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(
            EntityManagerInterface::class
        );

        $this->repository = self::getContainer()->get(
            SiteImageRepository::class
        );
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            foreach ($this->createdIds as $id) {
                $siteImage = $this->repository->find($id);

                if ($siteImage instanceof SiteImage) {
                    $this->entityManager->remove($siteImage);
                }
            }

            $this->entityManager->flush();
        }

        $this->createdIds = [];

        parent::tearDown();
    }

    public function testFindOneByKeyReturnsMatchingSiteImage(): void
    {
        $uniqueSuffix = bin2hex(random_bytes(6));

        $expected = (new SiteImage())
            ->setKey('test_playlist_day_' . $uniqueSuffix)
            ->setLabel('Playlist de jour test')
            ->setDescription(
                'Image créée uniquement pour le test repository.'
            );

        $other = (new SiteImage())
            ->setKey('test_playlist_night_' . $uniqueSuffix)
            ->setLabel('Playlist de nuit test');

        $this->entityManager->persist($expected);
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        self::assertNotNull($expected->getId());
        self::assertNotNull($other->getId());

        $this->createdIds[] = $expected->getId();
        $this->createdIds[] = $other->getId();

        $this->entityManager->clear();

        $result = $this->repository->findOneByKey(
            'test_playlist_day_' . $uniqueSuffix
        );

        self::assertInstanceOf(
            SiteImage::class,
            $result
        );

        self::assertSame(
            $expected->getId(),
            $result->getId()
        );

        self::assertSame(
            'test_playlist_day_' . $uniqueSuffix,
            $result->getKey()
        );

        self::assertSame(
            'Playlist de jour test',
            $result->getLabel()
        );
    }

    public function testFindOneByKeyReturnsNullForUnknownKey(): void
    {
        $result = $this->repository->findOneByKey(
            'unknown_' . bin2hex(random_bytes(8))
        );

        self::assertNull($result);
    }
}