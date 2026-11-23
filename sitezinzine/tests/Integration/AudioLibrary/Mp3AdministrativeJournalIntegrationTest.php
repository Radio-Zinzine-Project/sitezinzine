<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary;

use App\Enum\Mp3AdministrativeEventType as Type;
use App\Enum\Mp3TrashPhase as Phase;
use App\Service\Mp3AdministrativeJournal;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Mp3AdministrativeJournalIntegrationTest extends KernelTestCase
{
    public function testPersistsOrderedEventsIndependentlyOfTheBusinessJournal(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => true]);
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('symfony_test', $db->fetchOne('SELECT DATABASE()'));
        self::assertStringStartsWith('8.0.', (string) $db->fetchOne('SELECT VERSION()'));
        self::assertSame('InnoDB', $db->fetchOne(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mp3_administrative_event'",
        ));
        $observer = DriverManager::getConnection($db->getParams());
        $service = new Mp3AdministrativeJournal();
        $request = bin2hex(random_bytes(16));
        $operation = bin2hex(random_bytes(16));
        $attempt = bin2hex(random_bytes(16));
        $at = new \DateTimeImmutable('2026-11-19T12:00:00.123456+01:00');
        $journalSize = $db->fetchOne('SELECT COUNT(*) FROM mp3_trash_operation');
        self::assertFalse($db->fetchOne('SELECT id FROM mp3_trash_operation WHERE id = ?', [$operation]));
        try {
            $record = fn () => $service->recordRequest($db, $request, $operation, 42, 'resume_restoration',
                Phase::RESTAURATION_PREPAREE, $attempt, $at);
            $record();
            $initial = $service->eventsForRequest($observer, $request);
            self::assertCount(1, $initial);
            self::assertSame('2026-11-19 11:00:00.123456', $initial[0]['recorded_at']);
            try {
                $record();
                self::fail('A duplicate initial request was accepted.');
            } catch (UniqueConstraintViolationException) {
                self::assertFalse($db->isTransactionActive());
            }

            $db->beginTransaction();
            $service->recordInTransaction($db, $request, Type::TRANSITION_CONFIRMED, Phase::RETRAIT_PRIVE_EN_ATTENTE, 'associated', $at);
            self::assertSame($initial, $service->eventsForRequest($observer, $request));
            $db->rollBack();
            self::assertSame($initial, $service->eventsForRequest($observer, $request));
            $service->recordOutcome($observer, $request, Type::REFUSAL, Phase::RESTAURATION_PREPAREE, 'phase_conflict', $at->modify('+1 second'));
            self::assertSame(['request', 'refusal'], array_column($service->eventsForRequest($db, $request), 'event_type'));

            $db->transactional(function () use ($db, $service, $request, $at): void {
                $service->recordInTransaction($db, $request, Type::TRANSITION_CONFIRMED, Phase::RETRAIT_PRIVE_EN_ATTENTE, 'associated', $at);
                $service->recordInTransaction($db, $request, Type::TRANSITION_CONFIRMED, Phase::RESTAUREE, 'restored', $at);
            });
            try {
                $db->transactional(fn () => $service->recordInTransaction(
                    $db, $request, Type::TRANSITION_CONFIRMED, Phase::RESTAUREE, 'duplicate', $at,
                ));
                self::fail('A duplicate phase confirmation was accepted.');
            } catch (UniqueConstraintViolationException) {
                self::assertFalse($db->isTransactionActive());
            }
            $events = $service->eventsForRequest($observer, $request);
            self::assertSame(['requested', 'phase_conflict', 'associated', 'restored'], array_column($events, 'result_code'));
            self::assertSame([null, 'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE'], array_column($events, 'observed_phase'));
            self::assertSame(array_fill(0, 4, $operation), array_column($events, 'operation_id'));
            self::assertSame(array_fill(0, 4, $attempt), array_column($events, 'attempt_id'));
            self::assertSame(array_fill(0, 4, 42), array_map('intval', array_column($events, 'actor_id')));
            self::assertFalse($db->fetchOne('SELECT id FROM mp3_trash_operation WHERE id = ?', [$operation]));
            self::assertSame($journalSize, $db->fetchOne('SELECT COUNT(*) FROM mp3_trash_operation'));
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            try {
                $observer->delete('mp3_administrative_event', ['request_id' => $request, 'operation_id' => $operation]);
            } finally {
                $observer->close();
            }
        }
    }
}
