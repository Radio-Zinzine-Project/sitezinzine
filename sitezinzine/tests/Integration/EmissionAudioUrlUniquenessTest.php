<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Emission;
use App\Tests\Support\DoctrineTestTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmissionAudioUrlUniquenessTest extends KernelTestCase
{
    use DoctrineTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDoctrine();

        $connection = $this->entityManager->getConnection();
        self::assertSame('test', static::getContainer()->getParameter('kernel.environment'));
        self::assertSame('symfony_test', $connection->getDatabase());
        self::assertInstanceOf(MySQLPlatform::class, $connection->getDatabasePlatform());
        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame('InnoDB', $connection->fetchOne(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emission'",
        ));
    }

    protected function tearDown(): void
    {
        try {
            // The connection remains accessible even when a failed flush closed the manager.
            $this->closeEntityManager();
        } finally {
            parent::tearDown();
        }
    }

    public function testDuplicateAudioUrlIsRejectedOnFlush(): void
    {
        $url = $this->uniqueAudioUrl();
        $this->persistOwner($url);

        $this->assertUrlRejectedOnFlush($url);
    }

    public function testAudioUrlDifferingOnlyByCaseIsRejectedOnFlush(): void
    {
        $url = $this->uniqueAudioUrl();
        $this->persistOwner($url);

        $this->assertUrlRejectedOnFlush(strtoupper($url));
    }

    public function testSoftDeletedEmissionKeepsExclusiveOwnershipOfAudioUrl(): void
    {
        $url = $this->uniqueAudioUrl();
        $this->persistOwner($url, true);

        $this->assertUrlRejectedOnFlush($url);
    }

    private function uniqueAudioUrl(): string
    {
        return 'https://example.test/sql-unique-' . bin2hex(random_bytes(16)) . '.mp3';
    }

    private function emission(string $url): Emission
    {
        return (new Emission())
            ->setTitre('SQL audio URL uniqueness')
            ->setDatepub(new \DateTime())
            ->setDuree(60)
            ->setDescriptif('SQL constraint test')
            ->setUrl($url);
    }

    private function persistOwner(string $url, bool $deleted = false): void
    {
        $owner = $this->emission($url);
        if ($deleted) {
            $owner->softDelete();
        }

        $this->entityManager->persist($owner);
        // Persist the owner before registering the expected exception.
        $this->entityManager->flush();
    }

    private function assertUrlRejectedOnFlush(string $url): void
    {
        $this->entityManager->persist($this->emission($url));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_emission_url');
        $this->entityManager->flush();
    }
}
