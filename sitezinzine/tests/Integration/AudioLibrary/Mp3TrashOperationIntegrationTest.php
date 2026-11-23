<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3TrashPhase;
use App\Service\Mp3PublicationReservations;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Mp3TrashOperationIntegrationTest extends KernelTestCase
{
    public function testPublicationReadsCurrentReservationsInsideAnOlderTransactionSnapshot(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $reader = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('symfony_test', $reader->fetchOne('SELECT DATABASE()'));
        self::assertTrue($reader->createSchemaManager()->tablesExist(['mp3_trash_operation']));
        $writer = DriverManager::getConnection($reader->getParams());
        $prefix = 'publication-reservation-' . bin2hex(random_bytes(8));
        $emission = random_int(1_000_000_000, 1_400_000_000);
        $ids = [];
        try {
            foreach ([
                ['terminal-trash', 'RETRAIT_PUBLIC_EN_ATTENTE', 'EN_CORBEILLE'],
                ['terminal-restore', 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE'],
                ['terminal-cancel', 'SUPPRESSION_PREPAREE', 'ANNULEE'],
                ['same-emission', 'SUPPRESSION_PREPAREE', null],
                ['other-emission', 'RESTAURATION_PREPAREE', null],
            ] as $i => [$name, $phase, $terminal]) {
                $id = bin2hex(random_bytes(16));
                $path = 'TST/2026/' . $prefix . '-' . $name . '.mp3';
                $writer->insert('mp3_trash_operation', [
                    'id' => $id, 'emission_id' => $emission + $i, 'original_thumbnail_mp3' => $path,
                    'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'author_id' => 7,
                    'phase' => $phase, 'created_at' => '2026-11-19 00:00:00', 'updated_at' => '2026-11-19 00:00:00',
                    'blocked' => $name === 'same-emission' ? 1 : 0, 'restoration_attempts' => '[]',
                ]);
                $ids[$id] = [$path, $terminal, $emission + $i];
            }
            $writer->beginTransaction();
            foreach ($ids as [, , $owner]) {
                self::assertTrue(Mp3PublicationReservations::hasActiveForEmission($writer, $owner));
            }
            $writer->commit();
            $reader->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $reader->beginTransaction();
            $snapshot = static fn (): array => $reader->fetchFirstColumn(
                'SELECT original_thumbnail_mp3 FROM mp3_trash_operation WHERE active_marker = 1 AND original_thumbnail_mp3 LIKE ?',
                ['TST/2026/' . $prefix . '-%'],
            );
            self::assertCount(5, $snapshot());
            foreach ($ids as $id => [, $terminal]) {
                if ($terminal !== null) {
                    self::assertSame(1, $writer->update('mp3_trash_operation', ['phase' => $terminal], ['id' => $id]));
                }
            }
            self::assertCount(5, $snapshot(), 'The transaction still has an older nonlocking snapshot.');
            $reserved = Mp3PublicationReservations::read($reader);
            foreach ($ids as [$path, $terminal, $owner]) {
                self::assertSame($terminal === null, isset($reserved[$path]), $path);
                self::assertSame($terminal === null, Mp3PublicationReservations::hasActiveForEmission($reader, $owner), $path);
            }
        } finally {
            try {
                if ($reader->isTransactionActive()) {
                    $reader->rollBack();
                }
                if ($writer->isTransactionActive()) {
                    $writer->rollBack();
                }
                foreach (array_keys($ids) as $id) {
                    $writer->delete('mp3_trash_operation', ['id' => $id]);
                }
            } finally {
                $writer->close();
            }
        }
    }

    public function testRoundTripAndSingleActiveOperationWithoutEmissionForeignKey(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        self::assertMatchesRegularExpression('/_test[a-zA-Z0-9_]*$/', (string) $connection->fetchOne('SELECT DATABASE()'));
        if (!$connection->createSchemaManager()->tablesExist(['mp3_trash_operation'])) {
            self::markTestSkipped('Apply the journal migration manually to an isolated MySQL TEST schema first.');
        }

        $at = new \DateTimeImmutable('2026-11-19T09:00:00.123456+01:00');
        $emissionId = random_int(1_500_000_000, 2_000_000_000);
        self::assertFalse($connection->fetchOne('SELECT 1 FROM emission WHERE id = ?', [$emissionId]));
        $newOperation = static fn (): Mp3TrashOperation => new Mp3TrashOperation(
            bin2hex(random_bytes(16)), $emissionId, 'ABC/2026/Émission.mp3', null, 17, str_repeat('a', 64), 7, $at
        );
        $connection->beginTransaction();
        try {
            $old = $newOperation();
            $old->confirmDisassociation($at);
            $old->confirmPublicRemoval($at);
            $active = $newOperation();
            $active->block(Mp3TrashPhase::SUPPRESSION_PREPAREE, 'file_missing', $at);
            $em->persist($old);
            $em->persist($active);
            $em->flush();
            $em->clear();

            $stored = $em->find(Mp3TrashOperation::class, $active->id());
            self::assertInstanceOf(Mp3TrashOperation::class, $stored);
            self::assertSame(Mp3TrashPhase::SUPPRESSION_PREPAREE, $stored->phase());
            self::assertSame('ABC/2026/Émission.mp3', $stored->originalThumbnailMp3());
            self::assertNull($stored->originalUrl());
            self::assertTrue($stored->isBlocked());
            self::assertSame('file_missing', $stored->lastDiagnosticCode());
            self::assertSame('2026-11-19T08:00:00.123456+00:00', $stored->lastDiagnosticAt()?->format('Y-m-d\TH:i:s.uP'));
            self::assertSame($active->trashRelativePath(), $stored->trashRelativePath());

            $em->persist($newOperation());
            $this->expectException(UniqueConstraintViolationException::class);
            $this->expectExceptionMessage('uniq_mp3_trash_emission_active');
            $em->flush();
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
