<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Service\GridPublicationService;
use App\Service\GridUnpublicationService;
use App\Service\GridWeekValidation;
use App\Tests\Support\DoctrineTestTrait;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261123120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GridWeekValidationIntegrationTest extends KernelTestCase
{
    use DoctrineTestTrait;

    public function testManualProofAndRevisionFollowTheExportableGrid(): void
    {
        $this->bootDoctrine();
        $connection = $this->entityManager->getConnection();
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertSame('symfony_test', $connection->fetchOne('SELECT DATABASE()'));
        require_once dirname(__DIR__, 2) . '/migrations/Version20261123120000.php';
        $migration = new Version20261123120000($connection, new NullLogger());
        $migration->up(new Schema());
        $ddl = $migration->getSql()[0]->getStatement();
        // Session-local table, even if the real migration has already been applied.
        $connection->executeStatement(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $ddl));

        try {
            $validation = static::getContainer()->get(GridWeekValidation::class);
            $publication = static::getContainer()->get(GridPublicationService::class);
            $unpublication = static::getContainer()->get(GridUnpublicationService::class);
            $week = new \DateTimeImmutable('2099-01-06', new \DateTimeZone('Europe/Paris'));
            $otherWeek = $week->modify('+7 days');
            $other = $validation->read($otherWeek);
            $emission = (new Emission())->setTitre('Synthetic grid validation')
                ->setKeyword('')->setDatepub(new \DateTime('2099-01-08'))->setRef('PHPUNIT')
                ->setDuree(60)->setDescriptif('Original description');
            $draft = (new DiffusionDraft())->setEmission($emission)
                ->setNombreDiffusion(1)->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
                ->setSchedule($week->modify('+2 days')->setTime(14, 30), 60)
                ->setAssignmentGroupKey('phpunit_manual_week_validation');
            $this->entityManager->persist($emission);
            $this->entityManager->persist($draft);
            $this->entityManager->flush();

            $publication->publishWeek($week);
            self::assertFalse($validation->read($week)['validated']);
            $unpublication->unpublishWeek($week);
            $validation->changeManually($week, true, $publication->publishWeek(...));
            $initial = $validation->read($week);
            self::assertTrue($initial['validated']);
            self::assertNull($emission->getThumbnailMp3());

            $connection->transactional(static function () use ($unpublication, $publication, $week): void {
                $unpublication->unpublishWeek($week);
                $publication->publishWeek($week);
            });
            self::assertSame($initial, $validation->read($week));

            $emission->setDescriptif('Edited description');
            $this->entityManager->flush();
            self::assertSame($initial, $validation->read($week));
            $emission->setTitre('New radio title');
            $this->entityManager->flush();
            $changed = $validation->read($week);
            self::assertTrue($changed['validated']);
            self::assertNotSame($initial['revision'], $changed['revision']);
            self::assertFalse($validation->isCurrent($week, $initial['revision']));

            $validation->changeManually($week, false, $unpublication->unpublishWeek(...));
            self::assertFalse($validation->read($week)['validated']);
            $publication->publishWeek($week);
            self::assertFalse($validation->read($week)['validated']);
            $unpublication->unpublishWeek($week);
            $validation->changeManually($week, true, $publication->publishWeek(...));
            self::assertTrue($validation->read($week)['validated']);
            self::assertFalse($validation->isCurrent($week, $changed['revision']));
            self::assertSame($other, $validation->read($otherWeek));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM grid_week_validation'));

            $beforeFailure = $validation->read($week);
            try {
                $validation->changeManually($week, false, static function () use ($connection, $emission): array {
                    $connection->executeStatement('UPDATE emission SET titre = ? WHERE id = ?', ['Rejected change', $emission->getId()]);
                    throw new \DomainException('Simulated publication failure after a SQL write');
                });
                self::fail('The failing grid mutation must be rolled back.');
            } catch (\DomainException $failure) {
                self::assertSame('Simulated publication failure after a SQL write', $failure->getMessage());
            }
            self::assertSame($beforeFailure, $validation->read($week));
        } finally {
            try {
                $connection->executeStatement('DROP TEMPORARY TABLE grid_week_validation');
            } finally {
                $this->closeEntityManager();
            }
        }
    }
}
