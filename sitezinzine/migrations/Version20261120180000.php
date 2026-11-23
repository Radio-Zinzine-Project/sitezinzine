<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal durable des tentatives de retrait différé MP3.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform, 'MySQL is required.');
        $version = (string) $this->connection->fetchOne('SELECT VERSION()');
        $this->abortIf(
            preg_match('/^8\.0\.(\d+)(?:\D|$)/', $version, $matches) !== 1 || (int) $matches[1] < 16,
            'MySQL 8.0.16+ is required for enforced CHECK constraints.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE mp3_deferred_cleanup_attempt (
                request_id CHAR(32) NOT NULL,
                issue_id INT NOT NULL,
                expected_event_id BIGINT UNSIGNED NOT NULL,
                actor_id INT DEFAULT NULL,
                requested_at DATETIME(6) NOT NULL,
                state VARCHAR(8) NOT NULL,
                result_code VARCHAR(32) DEFAULT NULL,
                finished_at DATETIME(6) DEFAULT NULL,
                active_marker TINYINT GENERATED ALWAYS AS (
                    CASE WHEN state = 'pending' OR result_code = 'indeterminate' THEN 1 ELSE NULL END
                ) STORED,
                PRIMARY KEY (request_id),
                UNIQUE KEY uniq_mp3_deferred_cleanup_active (issue_id, active_marker),
                KEY idx_mp3_deferred_cleanup_history (issue_id, requested_at, request_id),
                CONSTRAINT chk_mp3_deferred_cleanup_context CHECK (
                    issue_id > 0 AND expected_event_id > 0 AND (actor_id IS NULL OR actor_id > 0)
                ),
                CONSTRAINT chk_mp3_deferred_cleanup_state CHECK (
                    (state = 'pending' AND result_code IS NULL AND finished_at IS NULL)
                    OR (state = 'finished' AND finished_at IS NOT NULL AND result_code IS NOT NULL AND result_code IN (
                        'removed', 'already_absent', 'reference_active', 'reservation_active', 'already_resolved',
                        'stale_view', 'not_found', 'lock_unavailable', 'invalid_path', 'different', 'missing_proof',
                        'unusable_proof', 'conflicting_proofs', 'removal_refused', 'indeterminate'
                    ))
                )
            ) ENGINE=InnoDB DEFAULT CHARACTER SET ascii COLLATE ascii_bin
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deferred MP3 cleanup journal contains history that must be preserved.');
    }
}
