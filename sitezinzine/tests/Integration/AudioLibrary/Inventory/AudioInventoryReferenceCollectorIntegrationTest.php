<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryReferenceCollector;
use App\Service\AudioLibrary\Inventory\AudioInventoryReferenceReader;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\KernelInterface;

/** Real MySQL references only: no MP3, ORM entity or shared fixture transaction. */
final class AudioInventoryReferenceCollectorIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    private array $fixtureRefs = [];
    private array $ownedConnections = [];
    private ?array $baseline = null;
    private int $baselineMaxId = 0;
    private array $evidence = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(array_replace($options, ['environment' => 'test', 'debug' => true]));
    }

    public function testRealSnapshotPaginationAndInterruptionPreserveExistingReferences(): void
    {
        self::assertSame('test', self::$kernel->getEnvironment());
        $writer = $this->newConnection();
        $this->ownedConnections[] = $writer;
        /** @var AudioInventoryCollectorObservedConnection $reader */
        $reader = $this->newConnection(AudioInventoryCollectorObservedConnection::class);
        $this->ownedConnections[] = $reader;
        $observer = $this->newConnection();
        $this->ownedConnections[] = $observer;
        $sessions = [$this->connectionId($writer), $this->connectionId($reader), $this->connectionId($observer)];
        self::assertCount(3, array_unique($sessions));
        foreach ([$writer, $reader, $observer] as $connection) {
            self::assertSame('symfony_test', $connection->getParams()['dbname'] ?? null);
            self::assertSame('symfony_test', $connection->fetchOne('SELECT DATABASE()'));
            // Local bounds only, also covering a future unexpected lock during fixture cleanup.
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
        }
        self::assertSame('InnoDB', $writer->fetchOne(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emission'",
        ));
        $columns = $writer->fetchAllAssociative(
            "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emission' ORDER BY ORDINAL_POSITION",
        );
        $byName = array_column($columns, null, 'COLUMN_NAME');
        self::assertStringContainsString('auto_increment', $byName['id']['EXTRA'] ?? '');
        foreach (['thumbnail_mp3', 'url', 'deleted_at', 'ref'] as $column) { self::assertArrayHasKey($column, $byName); }
        $this->baselineMaxId = (int) $writer->fetchOne('SELECT COALESCE(MAX(id), 0) FROM emission');
        $this->baseline = $this->referenceFingerprint($writer, $this->baselineMaxId);

        $marker = '__test_inventory_7h__' . bin2hex(random_bytes(12));
        $upper = $marker . '/Été.MP3';
        $lower = $marker . '/été.mp3';
        $oldBase = 'http://radiozinzine.org/emissions/';
        $newBase = 'http://radiozinzine.org/uploads/emissionsMp3/';
        $a = $this->insertFixture($writer, $columns, $marker . '-a', $upper, null);
        $b = $this->insertFixture($writer, $columns, $marker . '-b', null, $oldBase . $marker . '/historique.mp3', true);
        $c = $this->insertFixture($writer, $columns, $marker . '-c', $lower, $newBase . $lower);
        self::assertGreaterThan($this->baselineMaxId, $a);
        self::assertGreaterThan($a, $b);
        self::assertGreaterThan($b, $c);
        self::assertNotNull($observer->fetchOne('SELECT deleted_at FROM emission WHERE id = ?', [$b]));

        // With small databases leave the final fixture for page two; larger ones
        // retain the standard 500-row bound rather than issuing one query per row.
        $rowCount = (int) $writer->fetchOne('SELECT COUNT(*) FROM emission WHERE id > 0');
        $pageSize = min(500, max(1, $rowCount - 1));
        $resolver = new AudioLocalUrlResolver([$oldBase, $newBase]);
        $reader->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $reader->resetTransactionObservations();
        $fixtureIds = [$a, $b, $c];
        $seen = [];
        $pages = $uncertainties = 0;
        $previousCursor = 0;
        $mutated = false;
        $replacement = $marker . '/changed-after-first-page.mp3';
        $result = (new AudioInventoryReferenceCollector($reader, $resolver))->collect(
            function (array $page) use ($reader, $writer, $observer, $fixtureIds, $c, $marker, $replacement, $newBase, &$seen, &$pages, &$uncertainties, &$previousCursor, &$mutated): bool {
                self::assertTrue($reader->isConnected());
                self::assertSame(1, $reader->getTransactionNestingLevel());
                self::assertLessThanOrEqual(1000, count($page['associations']));
                self::assertGreaterThanOrEqual($previousCursor, $page['next_after_id']);
                if (!$page['exhausted']) { self::assertGreaterThan($previousCursor, $page['next_after_id']); }
                $previousCursor = $page['next_after_id'];
                ++$pages;
                $uncertainties += count($page['uncertainties']);
                foreach ($page['associations'] as $association) {
                    if (in_array($association['emission_id'], $fixtureIds, true)) { $seen[] = $association; }
                }
                if (!$mutated) {
                    self::assertLessThan($c, $page['next_after_id'], 'The changed fixture must not have been read yet.');
                    self::assertSame(0, $writer->getTransactionNestingLevel());
                    self::assertSame(1, $writer->executeStatement(
                        'UPDATE emission SET thumbnail_mp3 = :path, url = :url WHERE id = :id AND BINARY ref = :ref',
                        ['path' => $replacement, 'url' => $newBase . $replacement, 'id' => $c, 'ref' => $marker . '-c'],
                        ['path' => ParameterType::STRING, 'url' => ParameterType::STRING, 'id' => ParameterType::INTEGER, 'ref' => ParameterType::STRING],
                    ));
                    self::assertSame($replacement, $observer->fetchOne('SELECT thumbnail_mp3 FROM emission WHERE id = ?', [$c]));
                    $mutated = true;
                }
                return true;
            },
            $pageSize,
        );
        self::assertTrue($mutated);
        self::assertGreaterThanOrEqual(2, $pages);
        self::assertSame('completed', $result['status']);
        self::assertSame('repeatable_read', $result['temporal_consistency']);
        self::assertSame($pages, $result['pages_read']);
        self::assertSame($uncertainties === 0, $result['references_complete']);
        self::assertSame([
            $this->association($a, $upper),
            $this->association($b, $marker . '/historique.mp3', 'local_audio_url', true),
            $this->association($c, $lower),
            $this->association($c, $lower, 'local_audio_url'),
        ], $seen, 'The later page must retain the pre-update bytes, including their case and accents.');
        self::assertSame(['begin', 'commit'], $reader->transactionObservations);
        self::assertSame('READ-COMMITTED', $reader->isolationBeforeClose);
        $this->assertSessionClosed($reader, $observer, $sessions[1]);

        // A fresh ordinary read sees the committed change excluded from the old snapshot.
        $fresh = (new AudioInventoryReferenceReader($observer, $resolver))->readPage($c - 1, 1);
        self::assertSame([
            $this->association($c, $replacement), $this->association($c, $replacement, 'local_audio_url'),
        ], $fresh['associations']);

        // Reuse the same owned DBAL object; opening a fresh physical session is explicit.
        $interruptedSession = $this->connectionId($reader);
        self::assertNotContains($interruptedSession, $sessions);
        $reader->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
        $reader->resetTransactionObservations();
        $delivered = 0;
        $interrupted = (new AudioInventoryReferenceCollector($reader, $resolver))->collect(
            static function (array $page) use (&$delivered): bool { ++$delivered; return false; },
            $pageSize,
        );
        self::assertSame(1, $delivered);
        self::assertSame('interrupted', $interrupted['status']);
        self::assertFalse($interrupted['references_complete']);
        self::assertSame(['begin', 'rollback'], $reader->transactionObservations);
        $this->assertSessionClosed($reader, $observer, $interruptedSession);
        self::assertSame(0, $writer->getTransactionNestingLevel());
        self::assertSame(0, $observer->getTransactionNestingLevel());
        self::assertSame($this->baseline, $this->referenceFingerprint($observer, $this->baselineMaxId));
        $this->evidence = [
            'database' => 'symfony_test', 'sessions' => $sessions, 'interrupted_session' => $interruptedSession,
            'pages' => $pages, 'fixture_emissions' => 3, 'fixture_associations' => count($seen),
            'snapshot_preserved' => true, 'interruption_rolled_back' => true, 'collector_sessions_closed' => true,
            'collations' => ['thumbnail_mp3' => $byName['thumbnail_mp3']['COLLATION_NAME'], 'url' => $byName['url']['COLLATION_NAME']],
        ];
    }

    private function insertFixture(Connection $writer, array $columns, string $ref, ?string $physical, ?string $url, bool $deleted = false): int
    {
        self::assertSame('symfony_test', $writer->getParams()['dbname'] ?? null);
        self::assertSame('symfony_test', $writer->fetchOne('SELECT DATABASE()'));
        self::assertSame(0, (int) $writer->fetchOne('SELECT COUNT(*) FROM emission WHERE BINARY ref = ?', [$ref]));
        $data = [
            'titre' => $ref, 'keyword' => $ref, 'datepub' => '2026-11-16 12:00:00', 'ref' => $ref,
            'duree' => 60, 'descriptif' => 'Synthetic SQL inventory fixture.', 'is_auto_generated' => 0,
            'is_pending_completion' => 0, 'is_live' => 0, 'thumbnail_mp3' => $physical, 'url' => $url,
            'deleted_at' => $deleted ? '2026-11-16 12:01:00' : null,
        ];
        foreach ($columns as $column) {
            if ($column['IS_NULLABLE'] === 'NO' && $column['COLUMN_DEFAULT'] === null
                && !str_contains($column['EXTRA'], 'auto_increment') && !str_contains($column['EXTRA'], 'GENERATED')
                && !array_key_exists($column['COLUMN_NAME'], $data)) {
                throw new \LogicException('Unexpected required emission column; no fixture may be inserted.');
            }
        }
        // Register the immutable identity before INSERT, even if its outcome is uncertain.
        $this->fixtureRefs[] = $ref;
        $writer->insert('emission', $data);
        $id = (int) $writer->lastInsertId();
        self::assertGreaterThan(0, $id);
        self::assertSame($ref, $writer->fetchOne('SELECT ref FROM emission WHERE id = ?', [$id]));
        return $id;
    }

    private function assertSessionClosed(Connection $reader, Connection $observer, int $sessionId): void
    {
        self::assertFalse($reader->isConnected(), 'Do not reconnect the collector to inspect its closure.');
        self::assertSame(0, $reader->getTransactionNestingLevel());
        self::assertSame(0, (int) $observer->fetchOne('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ?', [$sessionId]));
    }

    /** Bounded scalar fingerprint of pre-existing reference columns, never audio content. */
    private function referenceFingerprint(Connection $connection, int $upperId): array
    {
        $hash = hash_init('sha256');
        $cursor = $count = 0;
        do {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, thumbnail_mp3, url, deleted_at FROM emission WHERE id > :cursor AND id <= :upperId ORDER BY id ASC LIMIT 500',
                ['cursor' => $cursor, 'upperId' => $upperId], ['cursor' => ParameterType::INTEGER, 'upperId' => ParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $cursor = (int) $row['id']; ++$count;
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
            }
        } while (count($rows) === 500);
        return ['rows' => $count, 'sha256' => hash_final($hash)];
    }

    private function association(int $id, string $path, string $origin = 'thumbnailMp3', bool $missing = false): array
    {
        return ['storage' => 'emissionsMp3', 'relative_path' => $path, 'emission_id' => $id, 'origin' => $origin, 'thumbnail_missing' => $missing];
    }

    protected function tearDown(): void
    {
        $failures = [];
        try {
            foreach ($this->ownedConnections as $connection) {
                try { if ($connection->isConnected() && $connection->getTransactionNestingLevel() > 0) { $connection->rollBack(); } }
                catch (\Throwable $error) { $failures[] = $error; }
                finally { try { $connection->close(); } catch (\Throwable $error) { $failures[] = $error; } }
            }
            if ($this->fixtureRefs !== []) {
                $cleanup = $this->newConnection();
                $cleanup->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
                $cleanup->beginTransaction();
                foreach ($this->fixtureRefs as $ref) {
                    $ids = $cleanup->fetchFirstColumn('SELECT id FROM emission WHERE BINARY ref = ? FOR UPDATE', [$ref]);
                    foreach ($ids as $id) {
                        self::assertGreaterThan($this->baselineMaxId, (int) $id);
                        self::assertSame(1, $cleanup->executeStatement('DELETE FROM emission WHERE id = ? AND BINARY ref = ?', [(int) $id, $ref]));
                    }
                }
                $cleanup->commit();
                foreach ($this->fixtureRefs as $ref) { self::assertSame(0, (int) $cleanup->fetchOne('SELECT COUNT(*) FROM emission WHERE BINARY ref = ?', [$ref])); }
                if ($this->baseline !== null) { self::assertSame($this->baseline, $this->referenceFingerprint($cleanup, $this->baselineMaxId)); }
                $this->evidence['targeted_cleanup_verified'] = true;
                $this->evidence['existing_references_unchanged'] = true;
            }
        } catch (\Throwable $error) {
            $failures[] = $error;
        } finally {
            // Parent owns and closes all DBAL objects, including the fresh cleanup
            // connection; its incident-fixture registry was intentionally never used.
            try { parent::tearDown(); } catch (\Throwable $error) { $failures[] = $error; }
            $this->fixtureRefs = $this->ownedConnections = [];
            $this->baseline = null;
        }
        if ($this->evidence !== []) { fwrite(STDERR, 'Audio inventory SQL integration: ' . json_encode($this->evidence, JSON_THROW_ON_ERROR) . PHP_EOL); }
        foreach ($failures as $error) { fwrite(STDERR, 'Audio inventory cleanup failed: ' . $error::class . PHP_EOL); }
        if ($failures !== [] && !$this->hasFailed()) { throw $failures[0]; }
    }
}

/** Test-local observer: every transaction method delegates to actual DBAL/MySQL. */
final class AudioInventoryCollectorObservedConnection extends Connection
{
    public array $transactionObservations = [];
    public ?string $isolationBeforeClose = null;

    public function resetTransactionObservations(): void
    {
        $this->transactionObservations = [];
        $this->isolationBeforeClose = null;
    }

    public function beginTransaction(): void { parent::beginTransaction(); $this->transactionObservations[] = 'begin'; }
    public function commit(): void { parent::commit(); $this->transactionObservations[] = 'commit'; }
    public function rollBack(): void { parent::rollBack(); $this->transactionObservations[] = 'rollback'; }

    public function close(): void
    {
        try {
            if ($this->isConnected() && $this->getTransactionNestingLevel() === 0) {
                $this->isolationBeforeClose = $this->fetchOne('SELECT @@SESSION.transaction_isolation');
            }
        } finally { parent::close(); }
    }
}
