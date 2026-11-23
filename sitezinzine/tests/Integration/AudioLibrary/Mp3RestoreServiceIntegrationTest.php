<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3AdministrativeEventType;
use App\Enum\Mp3TrashPhase;
use App\Exception\Mp3RestoreFailure;
use App\Service\Mp3AdministrativeJournal;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Mp3RestoreServiceIntegrationTest extends KernelTestCase
{
    /** @dataProvider scenarios */
    public function testRestorationAndItsAdministrativeTraceInMysql(bool $interrupt): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => true]);
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        self::assertSame('symfony_test', $db->fetchOne('SELECT DATABASE()'));
        self::assertSame(Mp3TestStorage::library(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::root() . '/trash', $container->getParameter('app.mp3_trash_dir'));
        self::assertTrue($db->createSchemaManager()->tablesExist(['mp3_trash_operation']));
        $observer = DriverManager::getConnection($db->getParams());
        $marker = 'mp3-restore-test-' . bin2hex(random_bytes(8));
        $relative = $marker . '-Émission.mp3';
        $url = 'https://example.test/uploads/emissionsMp3/' . $relative;
        $payload = 'synthetic';
        $operationId = bin2hex(random_bytes(16));
        $private = Mp3TestStorage::root() . '/trash/' . $operationId . '.mp3';
        $id = null;
        $requestId = bin2hex(random_bytes(16));
        $restoreRequestId = bin2hex(random_bytes(16));
        try {
            self::assertSame(1, $db->insert('emission', [
                'titre' => $marker, 'keyword' => $marker, 'datepub' => '2026-11-19 00:00:00',
                'ref' => $marker, 'duree' => 1, 'descriptif' => 'synthetic fixture',
            ]));
            $id = (int) $db->lastInsertId();
            self::assertGreaterThan(0, $id);
            $at = new \DateTimeImmutable();
            $operation = new Mp3TrashOperation($operationId, $id, $relative, $url, strlen($payload),
                hash('sha256', $payload), 7, $at);
            $operation->confirmDisassociation($at);
            $operation->confirmPublicRemoval($at);
            $em->persist($operation);
            $em->flush();
            $em->clear();
            self::assertSame(strlen($payload), file_put_contents($private, $payload));

            $restore = $container->get('test.mp3_restore_service');
            $administrativeJournal = new Mp3AdministrativeJournal();
            if (!$interrupt) {
                $restored = $restore->restore($em, $operationId, 9, Mp3TrashPhase::EN_CORBEILLE, null, $restoreRequestId);
                $events = $administrativeJournal->eventsForRequest($observer, $restoreRequestId);
                self::assertSame(Mp3TrashPhase::RESTAUREE, $restored->phase());
                self::assertSame(['request', 'transition_confirmed', 'transition_confirmed', 'transition_confirmed'], array_column($events, 'event_type'));
                self::assertSame([null, 'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE'], array_column($events, 'observed_phase'));
                self::assertSame(array_fill(0, 4, $restored->lastAttemptId()), array_column($events, 'attempt_id'));
                self::assertSame(['thumbnail_mp3' => $relative, 'url' => $url],
                    $observer->fetchAssociative('SELECT thumbnail_mp3, url FROM emission WHERE id = ?', [$id]));
                self::assertSame($payload, file_get_contents(Mp3TestStorage::library() . '/' . $relative));
                self::assertFileDoesNotExist($private);
                return;
            }

            $listener = new class ($administrativeJournal, $db, $restoreRequestId) {
                private int $flushes = 0;

                public function __construct(private Mp3AdministrativeJournal $journal, private Connection $db, private string $requestId) {}

                public function onFlush(): void
                {
                    if (++$this->flushes === 2) {
                        $this->journal->recordInTransaction($this->db, $this->requestId, Mp3AdministrativeEventType::TRANSITION_CONFIRMED,
                            Mp3TrashPhase::RETRAIT_PRIVE_EN_ATTENTE, 'injected_duplicate', new \DateTimeImmutable());
                    }
                }
            };
            $em->getEventManager()->addEventListener([Events::onFlush], $listener);
            try {
                $restore->restore($em, $operationId, 9, Mp3TrashPhase::EN_CORBEILLE, null, $restoreRequestId);
                self::fail('The duplicate administrative confirmation should have failed.');
            } catch (Mp3RestoreFailure $failure) {
                self::assertSame('administrative_confirmation', $failure->step);
                self::assertSame('trace_unavailable', $failure->reason);
                self::assertFalse($failure->commitUncertain);
                $attemptId = $failure->attemptId;
                self::assertNotNull($attemptId);
            } finally {
                $em->getEventManager()->removeEventListener([Events::onFlush], $listener);
            }
            self::assertFalse($db->isTransactionActive());
            self::assertSame(['thumbnail_mp3' => null, 'url' => null],
                $observer->fetchAssociative('SELECT thumbnail_mp3, url FROM emission WHERE id = ?', [$id]));
            self::assertSame(Mp3TrashPhase::RESTAURATION_PREPAREE->value,
                $observer->fetchOne('SELECT phase FROM mp3_trash_operation WHERE id = ?', [$operationId]));
            $public = Mp3TestStorage::library() . '/' . $relative;
            self::assertSame($payload, file_get_contents($private));
            self::assertSame($payload, file_get_contents($public));
            self::assertSame(fileinode($private), fileinode($public));
            $restoreEvents = $administrativeJournal->eventsForRequest($observer, $restoreRequestId);
            self::assertSame(['request', 'transition_confirmed', 'failure'], array_column($restoreEvents, 'event_type'));
            self::assertSame([null, 'RESTAURATION_PREPAREE', 'RESTAURATION_PREPAREE'], array_column($restoreEvents, 'observed_phase'));
            self::assertSame([$attemptId, $attemptId, $attemptId], array_column($restoreEvents, 'attempt_id'));
            try {
                $restore->restore(new EntityManager($db, $em->getConfiguration()), $operationId, 9, Mp3TrashPhase::EN_CORBEILLE, null, $restoreRequestId);
                self::fail('A second submission with the same request must be refused.');
            } catch (Mp3RestoreFailure $failure) {
                self::assertSame('duplicate_request', $failure->reason);
                self::assertNull($failure->attemptId);
                self::assertSame($restoreEvents, $administrativeJournal->eventsForRequest($observer, $restoreRequestId));
            }

            $blockingManager = new EntityManager($db, $em->getConfiguration());
            $blocked = $blockingManager->find(Mp3TrashOperation::class, $operationId);
            self::assertInstanceOf(Mp3TrashOperation::class, $blocked);
            $blocked->block(Mp3TrashPhase::RESTAURATION_PREPAREE, 'private_file_missing', new \DateTimeImmutable());
            $blockingManager->flush();
            $version = $blocked->blockVersion();

            $restored = $container->get('test.mp3_trash_recovery')->resumeRestoration(
                new EntityManager($db, $em->getConfiguration()), $operationId, 10, Mp3TrashPhase::RESTAURATION_PREPAREE, $attemptId, $requestId, $version,
            );

            $events = (new Mp3AdministrativeJournal())->eventsForRequest($observer, $requestId);
            self::assertSame(['request', 'transition_confirmed', 'transition_confirmed'], array_column($events, 'event_type'));
            self::assertSame([null, 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE'], array_column($events, 'observed_phase'));
            self::assertSame([$attemptId, $attemptId, $attemptId], array_column($events, 'attempt_id'));
            self::assertSame(Mp3TrashPhase::RESTAUREE, $restored->phase());
            self::assertSame('unblocked_associated', $events[1]['result_code']);
            self::assertSame('private_removed', $events[2]['result_code']);
            self::assertFalse($restored->isBlocked());
            self::assertSame('private_file_missing', $restored->lastDiagnosticCode());
            self::assertSame(['thumbnail_mp3' => $relative, 'url' => $url],
                $observer->fetchAssociative('SELECT thumbnail_mp3, url FROM emission WHERE id = ?', [$id]));
            $journal = $observer->fetchAssociative(
                'SELECT phase, last_attempt_id, restoration_attempts, active_marker FROM mp3_trash_operation WHERE id = ?',
                [$operationId],
            );
            self::assertSame('RESTAUREE', $journal['phase']);
            self::assertSame($attemptId, $journal['last_attempt_id']);
            self::assertNull($journal['active_marker']);
            $attempts = json_decode($journal['restoration_attempts'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(9, $attempts[$journal['last_attempt_id']]['actor_id']);
            self::assertSame('restored', $attempts[$journal['last_attempt_id']]['outcome']);
            self::assertSame($payload, file_get_contents(Mp3TestStorage::library() . '/' . $relative));
            self::assertFileDoesNotExist($private);
        } finally {
            try {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                $observer->delete('mp3_administrative_event', ['request_id' => $requestId]);
                $observer->delete('mp3_administrative_event', ['request_id' => $restoreRequestId]);
                if ($id !== null) {
                    $observer->delete('mp3_trash_operation', ['id' => $operationId, 'emission_id' => $id]);
                    $observer->delete('emission', ['id' => $id, 'ref' => $marker]);
                }
            } finally {
                $observer->close();
            }
        }
    }

    public static function scenarios(): iterable
    {
        yield 'all phases confirmed' => [false];
        yield 'trace rollback and manual recovery' => [true];
    }
}
