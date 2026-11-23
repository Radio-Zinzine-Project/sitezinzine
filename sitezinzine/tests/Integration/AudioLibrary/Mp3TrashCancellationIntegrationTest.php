<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary;

use App\Entity\AudioLibrary\Mp3TrashOperation;
use App\Enum\Mp3AdministrativeEventType as Type;
use App\Enum\Mp3TrashPhase as Phase;
use App\Exception\Mp3TrashCancellationFailure;
use App\Service\Mp3AdministrativeJournal;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Mp3TrashCancellationIntegrationTest extends KernelTestCase
{
    public function testCancellationAndAuditCommitTogetherInMysql(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => true]);
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        self::assertSame('symfony_test', $db->fetchOne('SELECT DATABASE()'));
        self::assertSame(Mp3TestStorage::library(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::root() . '/trash', $container->getParameter('app.mp3_trash_dir'));
        $observer = DriverManager::getConnection($db->getParams());
        $journal = new Mp3AdministrativeJournal();
        $cancellation = $container->get('test.mp3_trash_cancellation');
        try {
            foreach ([false, true] as $restoring) {
                $marker = 'mp3-cancel-test-' . bin2hex(random_bytes(8));
                $operationId = bin2hex(random_bytes(16));
                $request = bin2hex(random_bytes(16));
                $failed = bin2hex(random_bytes(16));
                $attempt = bin2hex(random_bytes(16));
                $at = new \DateTimeImmutable();
                $path = $marker . '.mp3';
                $url = $container->getParameter('app.mp3_public_base_url') . '/' . $path;
                $audio = ['thumbnail_mp3' => $restoring ? null : $path, 'url' => $restoring ? null : $url];
                $expected = $restoring ? Phase::RESTAURATION_PREPAREE : Phase::SUPPRESSION_PREPAREE;
                $target = $restoring ? Phase::EN_CORBEILLE : Phase::ANNULEE;
                $id = null;
                try {
                    $db->insert('emission', [
                        'titre' => $marker, 'keyword' => $marker, 'datepub' => '2026-11-19 00:00:00',
                        'ref' => $marker, 'duree' => 1, 'descriptif' => 'synthetic fixture',
                    ] + $audio);
                    $id = (int) $db->lastInsertId();
                    $operation = new Mp3TrashOperation($operationId, $id, $path, $url, 9, hash('sha256', 'synthetic'), 7, $at);
                    if ($restoring) {
                        $operation->confirmDisassociation($at);
                        $operation->confirmPublicRemoval($at);
                        $operation->prepareRestoration($attempt, 8, $at);
                    }
                    $diagnostic = $restoring ? 'private_file_missing' : 'public_file_missing';
                    $operation->block($expected, $diagnostic, $at);
                    $version = $operation->blockVersion();
                    $em->persist($operation);
                    $em->flush();
                    $em->clear();
                    $public = Mp3TestStorage::library() . '/' . $path;
                    $private = Mp3TestStorage::root() . '/trash/' . $operation->trashRelativePath();
                    [$keep, $absent] = $restoring ? [$private, $public] : [$public, $private];
                    self::assertSame(9, file_put_contents($keep, 'synthetic'));
                    if (!$restoring) {
                        $manager = new EntityManager($db, $em->getConfiguration());
                        $manager->getEventManager()->addEventListener([Events::onFlush], new class ($journal, $db, $failed) {
                            public function __construct(private Mp3AdministrativeJournal $journal, private Connection $db,
                                private string $request) {}
                            public function onFlush(): void
                            {
                                $this->journal->recordInTransaction($this->db, $this->request, Type::TRANSITION_CONFIRMED,
                                    Phase::ANNULEE, 'injected_duplicate', new \DateTimeImmutable());
                            }
                        });
                        try {
                            $cancellation->cancelDeletion($manager, $operationId, 9, $expected, $failed, $version);
                            self::fail('The duplicate confirmation should abort the cancellation.');
                        } catch (Mp3TrashCancellationFailure $failure) {
                            self::assertSame('trace_unavailable', $failure->reason);
                            self::assertNull($failure->traceFailure);
                        }
                        $retained = $observer->fetchAssociative(
                            'SELECT phase, blocked, last_diagnostic_code, last_diagnostic_at FROM mp3_trash_operation WHERE id = ?', [$operationId],
                        );
                        self::assertSame($expected->value, $retained['phase']);
                        self::assertSame(1, (int) $retained['blocked']);
                        self::assertSame($diagnostic, $retained['last_diagnostic_code']);
                        self::assertSame($version, $retained['last_diagnostic_at']);
                        self::assertSame(['request', 'failure'], array_column($journal->eventsForRequest($observer, $failed), 'event_type'));
                    }
                    $manager = new EntityManager($db, $em->getConfiguration());
                    $phase = $restoring
                        ? $cancellation->cancelRestoration($manager, $operationId, 9, $expected, $request, $attempt, $version)
                        : $cancellation->cancelDeletion($manager, $operationId, 9, $expected, $request, $version);
                    self::assertSame($target, $phase);
                    $row = $observer->fetchAssociative(
                        'SELECT phase, active_marker, restoration_attempts, blocked, last_diagnostic_code, last_diagnostic_at FROM mp3_trash_operation WHERE id = ?', [$operationId],
                    );
                    self::assertSame($target->value, $row['phase']);
                    self::assertNull($row['active_marker']);
                    self::assertSame(0, (int) $row['blocked']);
                    self::assertSame($diagnostic, $row['last_diagnostic_code']);
                    self::assertSame($version, $row['last_diagnostic_at']);
                    if ($restoring) {
                        $history = json_decode($row['restoration_attempts'], true, flags: JSON_THROW_ON_ERROR);
                        self::assertSame('cancelled', $history[$attempt]['outcome']);
                        self::assertSame(8, $history[$attempt]['actor_id']);
                    }
                    $events = $journal->eventsForRequest($observer, $request);
                    self::assertSame(['request', 'transition_confirmed'], array_column($events, 'event_type'));
                    self::assertSame([null, $target->value], array_column($events, 'observed_phase'));
                    self::assertSame('unblocked_cancelled', $events[1]['result_code']);
                    self::assertSame($audio, $observer->fetchAssociative('SELECT thumbnail_mp3, url FROM emission WHERE id = ?', [$id]));
                    self::assertSame('synthetic', file_get_contents($keep));
                    self::assertFileDoesNotExist($absent);
                } finally {
                    if ($db->isTransactionActive()) {
                        $db->rollBack();
                    }
                    $observer->delete('mp3_administrative_event', ['request_id' => $request, 'operation_id' => $operationId]);
                    $observer->delete('mp3_administrative_event', ['request_id' => $failed, 'operation_id' => $operationId]);
                    if ($id !== null) {
                        $observer->delete('mp3_trash_operation', ['id' => $operationId, 'emission_id' => $id]);
                        $observer->delete('emission', ['id' => $id, 'ref' => $marker]);
                    }
                }
            }
        } finally {
            $observer->close();
        }
    }
}
