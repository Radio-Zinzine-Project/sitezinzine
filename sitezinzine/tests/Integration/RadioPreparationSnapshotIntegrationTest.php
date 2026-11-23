<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Service\AudioLibrary\RadioExport\Selection\WeeklyAudioSelection;
use App\Service\GridWeekValidation;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RadioPreparationSnapshotIntegrationTest extends KernelTestCase
{
    public function testCaptureAgreesWithItsRevisionAcrossAConcurrentCommit(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertSame('symfony_test', $db->fetchOne('SELECT DATABASE()'));
        self::assertFalse($db->isTransactionActive());
        self::assertMatchesRegularExpression('/^8\.0\./', $db->fetchOne('SELECT VERSION()'));

        $validation = self::getContainer()->get(GridWeekValidation::class);
        $selection = new WeeklyAudioSelection($em->getRepository(Diffusion::class), 'https://example.test/uploads/emissionsMp3', '[]');
        $week = new \DateTimeImmutable('2197-04-11', new \DateTimeZone('Europe/Paris'));
        $week = \App\Service\RadioWeek::start($week);
        $key = $week->format('Y-m-d');
        self::assertFalse($db->fetchOne('SELECT week_start FROM grid_week_validation WHERE week_start = ?', [$key]));
        self::assertSame([], $validation->captureForExport($week)['rows']);
        $writer = DriverManager::getConnection($db->getParams());
        $emission = (new Emission())->setTitre('Original snapshot title')->setKeyword('')
            ->setDatepub(\DateTime::createFromImmutable($week))->setRef('PHPUNIT')->setDuree(60)->setDescriptif('Synthetic');
        $diffusion = (new Diffusion())->setEmission($emission)->setNombreDiffusion(1)
            ->setSchedule(\DateTime::createFromImmutable($week->setTime(10, 0)), 60);
        $ownsProof = false;
        try {
            self::assertSame('symfony_test', $writer->fetchOne('SELECT DATABASE()'));
            self::assertNotSame($db->fetchOne('SELECT CONNECTION_ID()'), $writer->fetchOne('SELECT CONNECTION_ID()'));
            $em->persist($emission);
            $em->persist($diffusion);
            $em->flush();
            self::assertFalse($validation->captureForExport($week)['validated']);
            $db->insert('grid_week_validation', ['week_start' => $key, 'validated' => 1, 'manual_revision' => 1]);
            $ownsProof = true;
            $before = $validation->read($week);

            $writer->beginTransaction();
            $writer->update('emission', ['titre' => 'Changed snapshot title'], ['id' => $emission->getId()]);
            $captured = $validation->captureForExport($week);
            $writer->commit();

            self::assertTrue($captured['validated']);
            self::assertSame($before['revision'], $captured['revision']);
            self::assertSame('Original snapshot title', $selection->fromSnapshot($week, $captured['rows'])['occurrences'][0]['emissionTitle']);
            $after = $validation->captureForExport($week);
            self::assertTrue($after['validated']);
            self::assertNotSame($captured['revision'], $after['revision']);
            self::assertSame($validation->read($week)['revision'], $after['revision']);
            self::assertSame('Changed snapshot title', $selection->fromSnapshot($week, $after['rows'])['occurrences'][0]['emissionTitle']);
            self::assertSame('Original snapshot title', $emission->getTitre());
            self::assertFalse($db->isTransactionActive());
        } finally {
            $writer->close();
            $db->transactional(static function () use ($db, $diffusion, $emission, $ownsProof, $key): void {
                if ($diffusion->getId() !== null) {
                    $db->delete('diffusion', ['id' => $diffusion->getId(), 'emission_id' => $emission->getId()]);
                }
                if ($emission->getId() !== null) {
                    $db->delete('emission', ['id' => $emission->getId()]);
                }
                if ($ownsProof) {
                    $db->delete('grid_week_validation', ['week_start' => $key, 'manual_revision' => 1]);
                }
            });
        }
    }
}
