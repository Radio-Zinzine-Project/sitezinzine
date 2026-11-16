<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create MP3 cleanup investigation issues and immutable-by-workflow event history.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->assertPreconditions();

        // MySQL DDL is not atomic across both statements: never clean up partial creation automatically.
        $this->addSql(<<<'SQL'
            CREATE TABLE mp3_cleanup_issue (
                id INT NOT NULL AUTO_INCREMENT,
                storage VARBINARY(12) NOT NULL,
                obsolete_relative_path VARBINARY(1020) NOT NULL,
                status VARBINARY(13) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
                resolved_at DATETIME(6) NULL DEFAULT NULL,
                active_marker TINYINT GENERATED ALWAYS AS (
                    CASE WHEN status IN ('open', 'investigating') THEN 1 ELSE NULL END
                ) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_mp3_cleanup_active (storage, obsolete_relative_path, active_marker),
                CONSTRAINT chk_mp3_cleanup_issue_status
                    CHECK (status IN ('open', 'investigating', 'resolved'))
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE mp3_cleanup_event (
                id INT NOT NULL AUTO_INCREMENT,
                issue_id INT NOT NULL,
                occurred_at DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
                reason VARBINARY(23) NOT NULL,
                emission_id INT NOT NULL,
                obsolete_relative_path VARBINARY(1020) NOT NULL,
                context JSON NOT NULL,
                diagnostic JSON NOT NULL,
                PRIMARY KEY (id),
                KEY idx_mp3_cleanup_event_issue_id (issue_id, id),
                CONSTRAINT fk_mp3_cleanup_event_issue
                    FOREIGN KEY (issue_id) REFERENCES mp3_cleanup_issue (id)
                    ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT chk_mp3_cleanup_event_reason
                    CHECK (reason IN ('reference_check_failed', 'obsolete_cleanup_failed'))
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Automatic rollback is forbidden: mp3_cleanup_issue and mp3_cleanup_event contain investigation history that must be preserved.',
        );
    }

    private function assertPreconditions(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform,
            'This migration requires the MySQL platform, not MariaDB.',
        );

        // Query the actual server; a configured server_version is not evidence of its version.
        $version = (string) $this->connection->fetchOne('SELECT VERSION()');
        $matches = [];
        $this->abortIf(
            preg_match('/^8\.0\.(\d+)(?:\D|$)/', $version, $matches) !== 1
                || str_contains(strtolower($version), 'mariadb')
                || (int) ($matches[1] ?? 0) < 16,
            'This migration requires MySQL 8.0.16 or later within the 8.0 series, with enforced CHECK constraints.',
        );

        $database = $this->connection->fetchOne('SELECT DATABASE()');
        $this->abortIf(!is_string($database) || $database === '', 'No database selected.');

        $engine = $this->connection->fetchOne(
            "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'",
        );
        $this->abortIf(!in_array($engine, ['YES', 'DEFAULT'], true), 'InnoDB must be available.');

        // DYNAMIC is explicit in both CREATE statements; 4 KiB pages cannot hold the full 1033-byte key.
        $pageSize = (int) $this->connection->fetchOne('SELECT @@innodb_page_size');
        $this->abortIf(
            !in_array($pageSize, [8192, 16384, 32768, 65536], true),
            'InnoDB pages must support the complete active-issue key: expected 8, 16, 32 or 64 KiB pages.',
        );

        $sqlModes = explode(',', strtoupper((string) $this->connection->fetchOne('SELECT @@SESSION.sql_mode')));
        $this->abortIf(
            !in_array('STRICT_TRANS_TABLES', $sqlModes, true) && !in_array('STRICT_ALL_TABLES', $sqlModes, true),
            'Strict SQL mode is required to avoid silently truncating binary file identities.',
        );

        // Check both names before queuing either CREATE, including collisions with existing views.
        $existing = $this->connection->fetchOne(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) IN (?, ?) LIMIT 1',
            [$database, 'mp3_cleanup_issue', 'mp3_cleanup_event'],
        );
        $this->abortIf(
            $existing !== false,
            'MP3 cleanup tables or views already exist; refusing to replace or reuse investigation history.',
        );
    }
}
