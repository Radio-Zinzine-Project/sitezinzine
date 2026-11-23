<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary;

use App\Enum\Mp3TrashPhase as Phase;
use App\Enum\Mp3AdministrativeEventType as EventType;
use App\Exception\Mp3TrashFailure;
use App\Exception\Mp3TrashRecoveryFailure;
use App\Service\Mp3AdministrativeJournal;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Mp3TrashServiceIntegrationTest extends KernelTestCase
{
    public function testIntentSurvivesFailedDisassociationAndCanBeRecoveredWithAFreshManager(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => true]);
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        self::assertSame('symfony_test', $db->fetchOne('SELECT DATABASE()'));
        self::assertSame(Mp3TestStorage::library(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::root() . '/trash', $container->getParameter('app.mp3_trash_dir'));
        if (!$db->createSchemaManager()->tablesExist(['mp3_trash_operation'])) {
            self::markTestSkipped('The MP3 journal migration must already be applied to TEST.');
        }
        $observer = DriverManager::getConnection($db->getParams());
        $marker = 'trash-test-' . bin2hex(random_bytes(8));
        $relative = $marker . '.mp3';
        $url = $container->getParameter('app.mp3_public_base_url') . '/' . $relative;
        $public = Mp3TestStorage::library() . '/' . $relative;
        $id = null;
        $requestId = bin2hex(random_bytes(16));
        $failedRequestId = bin2hex(random_bytes(16));
        $listener = null;
        try {
            self::assertSame(1, $db->insert('emission', [
                'titre' => $marker, 'keyword' => $marker, 'datepub' => '2026-11-19 00:00:00',
                'ref' => $marker, 'duree' => 1, 'descriptif' => 'synthetic fixture',
                'thumbnail_mp3' => $relative, 'url' => $url,
            ]));
            $id = (int) $db->lastInsertId();
            self::assertGreaterThan(0, $id);
            self::assertSame(9, file_put_contents($public, 'synthetic'));
            $listener = new class {
                private int $flushes = 0;

                public function onFlush(): void
                {
                    if (++$this->flushes === 2) {
                        throw new \RuntimeException('synthetic second flush failure');
                    }
                }
            };
            $em->getEventManager()->addEventListener([Events::onFlush], $listener);
            try {
                $container->get('test.mp3_trash_service')->trash($em, $id, $relative, $url, 7);
                self::fail('The injected flush must fail.');
            } catch (Mp3TrashFailure $failure) {
                self::assertSame('disassociation', $failure->step);
                self::assertFalse($failure->commitUncertain);
                self::assertSame('synthetic second flush failure', $failure->getPrevious()?->getMessage());
                $operationId = $failure->operationId;
                self::assertNotNull($operationId);
            }
            self::assertSame(0, $db->getTransactionNestingLevel());
            self::assertSame(['thumbnail_mp3' => $relative, 'url' => $url],
                $observer->fetchAssociative('SELECT thumbnail_mp3, url FROM emission WHERE id = ?', [$id]));
            self::assertSame(Phase::SUPPRESSION_PREPAREE->value,
                $observer->fetchOne('SELECT phase FROM mp3_trash_operation WHERE id = ?', [$operationId]));
            self::assertSame('synthetic', file_get_contents($public));
            $private = Mp3TestStorage::root() . '/trash/' . $operationId . '.mp3';
            self::assertSame('synthetic', file_get_contents($private));
            self::assertSame(fileinode($public), fileinode($private));

            $recovery = $container->get('test.mp3_trash_recovery');
            $trace = new Mp3AdministrativeJournal();
            $failingManager = new EntityManager($observer, $em->getConfiguration());
            $failingManager->getEventManager()->addEventListener([Events::onFlush], new class ($trace, $observer, $failedRequestId) {
                public function __construct(private Mp3AdministrativeJournal $trace, private \Doctrine\DBAL\Connection $db,
                    private string $requestId) {}

                public function onFlush(): void
                {
                    // Occupy the same confirmation slot inside the business transaction.
                    $this->trace->recordInTransaction($this->db, $this->requestId, EventType::TRANSITION_CONFIRMED,
                        Phase::RETRAIT_PUBLIC_EN_ATTENTE, 'injected_duplicate', new \DateTimeImmutable());
                }
            });
            try {
                $recovery->resumeDeletion($failingManager, $operationId, 9, Phase::SUPPRESSION_PREPAREE, $failedRequestId);
                self::fail('The duplicate transactional confirmation should have aborted recovery.');
            } catch (Mp3TrashRecoveryFailure $error) {
                self::assertSame('trace_unavailable', $error->reason);
                self::assertNull($error->traceFailure);
            }
            self::assertSame(['request', 'failure'], array_column($trace->eventsForRequest($db, $failedRequestId), 'event_type'));
            self::assertSame(Phase::SUPPRESSION_PREPAREE->value,
                $db->fetchOne('SELECT phase FROM mp3_trash_operation WHERE id = ?', [$operationId]));
            self::assertSame(['thumbnail_mp3' => $relative, 'url' => $url],
                $db->fetchAssociative('SELECT thumbnail_mp3, url FROM emission WHERE id = ?', [$id]));
            self::assertSame(fileinode($public), fileinode($private));

            $recoveryManager = new EntityManager($observer, $em->getConfiguration());
            $phase = $recovery->resumeDeletion($recoveryManager, $operationId, 9, Phase::SUPPRESSION_PREPAREE, $requestId);
            $events = $trace->eventsForRequest($db, $requestId);
            self::assertSame(['request', 'transition_confirmed', 'transition_confirmed'], array_column($events, 'event_type'));
            self::assertSame([null, 'RETRAIT_PUBLIC_EN_ATTENTE', 'EN_CORBEILLE'], array_column($events, 'observed_phase'));
            self::assertSame(Phase::EN_CORBEILLE, $phase);
            self::assertSame(['thumbnail_mp3' => null, 'url' => null],
                $db->fetchAssociative('SELECT thumbnail_mp3, url FROM emission WHERE id = ?', [$id]));
            self::assertSame(Phase::EN_CORBEILLE->value,
                $db->fetchOne('SELECT phase FROM mp3_trash_operation WHERE id = ?', [$operationId]));
            self::assertFalse($observer->isTransactionActive());
            self::assertFileDoesNotExist($public);
            self::assertSame('synthetic', file_get_contents($private));
            try {
                $recovery->resumeDeletion(new EntityManager($observer, $em->getConfiguration()), $operationId, 9,
                    Phase::SUPPRESSION_PREPAREE, $requestId);
                self::fail('A duplicate administrative request must not be resumed.');
            } catch (Mp3TrashRecoveryFailure $error) {
                self::assertSame('duplicate_request', $error->reason);
                self::assertSame($events, $trace->eventsForRequest($db, $requestId));
            }
        } finally {
            if ($listener !== null) {
                $em->getEventManager()->removeEventListener([Events::onFlush], $listener);
            }
            try {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                $observer->delete('mp3_administrative_event', ['request_id' => $requestId]);
                $observer->delete('mp3_administrative_event', ['request_id' => $failedRequestId]);
                if ($id !== null) {
                    $observer->delete('mp3_trash_operation', ['emission_id' => $id]);
                    $observer->delete('emission', ['id' => $id, 'ref' => $marker]);
                }
            } finally {
                $observer->close();
            }
        }
    }
}
