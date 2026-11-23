<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\Mp3RestoreFailure;
use App\Service\AudioLibrary\Mp3RestoreAction;
use App\Service\{Mp3AdministrativeJournal, Mp3DiskIo, Mp3StorageLock, Mp3TrashDisk, Mp3TrashPath, Mp3RestoreService};
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class Mp3RestoreActionTest extends TestCase
{
    public function testDedicatedManagerPassesNormalRestorationPreconditionsWithoutUsingDefaultSession(): void
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
            $recovery = new Mp3RestoreService($lock, new Mp3TrashDisk($root . '/library', $paths, new Mp3DiskIo()),
                new Mp3AdministrativeJournal(), new \App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver(['https://example.test/uploads/emissionsMp3', 'https://legacy.example.test/audio']));
            $action = new Mp3RestoreAction($default, $recovery);
            $record = [
                'operation_id' => str_repeat('a', 32), 'actor_id' => 42, 'expected_phase' => 'EN_CORBEILLE',
                'expected_last_attempt_id' => null, 'request_id' => str_repeat('c', 32), 'expected_block_version' => null,
            ];
            try {
                // The nested lock refusal happens before any SQL connection is opened.
                $lock->synchronized(fn () => $action->execute($record));
                self::fail('A nested storage lock must be refused.');
            } catch (Mp3RestoreFailure $error) {
                self::assertSame('storage_lock', $error->step);
                self::assertSame($record['request_id'], $error->requestId);
            }
        } finally {
            $fs->remove($root);
        }
    }
}
