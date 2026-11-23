<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Mp3TrashConfirmationAction as Action;
use App\Exception\Mp3TrashRecoveryFailure;
use App\Service\AudioLibrary\Mp3TrashRecoveryAction;
use App\Service\{Mp3AdministrativeJournal, Mp3DiskIo, Mp3StorageLock, Mp3TrashDisk, Mp3TrashPath, Mp3TrashRecovery};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

final class Mp3TrashRecoveryActionTest extends TestCase
{
    public function testDedicatedManagerPassesRecoveryPreconditionsWithoutUsingDefaultSession(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'dbname' => 'synthetic']);
        $db->expects(self::never())->method('beginTransaction');
        $db->expects(self::never())->method('close');
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $default = $this->createMock(EntityManagerInterface::class);
        $default->method('getConnection')->willReturn($db);
        $default->method('getConfiguration')->willReturn($config);
        $default->expects(self::never())->method('getUnitOfWork');
        $root = Mp3TestStorage::root() . '/recovery-action-' . bin2hex(random_bytes(8));
        $config->setProxyDir($root);
        $config->setProxyNamespace('AppTestsRecoveryProxy');
        $fs = new Filesystem();
        $fs->mkdir([$root . '/trash', $root . '/library', $root . '/work', $root . '/public'], 0700);
        try {
            $paths = new Mp3TrashPath($root . '/trash', $root . '/library', $root . '/work', $root . '/public');
            $lock = new Mp3StorageLock($paths, 0);
            $logger = new NullLogger();
            $recovery = new Mp3TrashRecovery($lock, new Mp3TrashDisk($root . '/library', $paths, new Mp3DiskIo()),
                $logger, 'https://example.test/uploads/emissionsMp3', new Mp3AdministrativeJournal(), new \App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver(['https://example.test/uploads/emissionsMp3', 'https://legacy.example.test/audio']));
            $action = new Mp3TrashRecoveryAction($default, $recovery);
            $record = [
                'operation_id' => str_repeat('a', 32), 'actor_id' => 42, 'expected_phase' => 'RESTAURATION_PREPAREE',
                'expected_attempt_id' => str_repeat('b', 32), 'request_id' => str_repeat('c', 32), 'expected_block_version' => null,
            ];
            try {
                // The nested lock refusal happens before any SQL connection is opened.
                $lock->synchronized(fn () => $action->execute(Action::RESUME_RESTORATION, $record));
                self::fail('A nested storage lock must be refused.');
            } catch (Mp3TrashRecoveryFailure $error) {
                self::assertSame('storage_lock', $error->step);
                self::assertSame($record['actor_id'], $error->actorId);
                self::assertSame($record['expected_phase'], $error->expectedPhase->value);
                self::assertSame($record['expected_attempt_id'], $error->attemptId);
                self::assertSame($record['request_id'], $error->requestId);
            }
        } finally {
            $fs->remove($root);
        }
    }
}
