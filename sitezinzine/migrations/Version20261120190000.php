<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persistent administrative closure evidence for MP3 cleanup incidents.';
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
        $this->abortIf(preg_match('/^8\.0\.(\d+)(?:\D|$)/', $version, $matches) !== 1 || (int) $matches[1] < 16,
            'MySQL 8.0.16+ is required for enforced CHECK constraints.');

        $this->addSql(<<<'SQL'
            CREATE TABLE mp3_cleanup_issue_closure (
                issue_id INT NOT NULL,
                actor_id INT NOT NULL,
                closed_at DATETIME(6) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                proof_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                expected_event_id INT NOT NULL,
                PRIMARY KEY (issue_id),
                CONSTRAINT fk_mp3_cleanup_closure_issue FOREIGN KEY (issue_id)
                    REFERENCES mp3_cleanup_issue (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT chk_mp3_cleanup_closure_context CHECK (actor_id > 0 AND expected_event_id > 0 AND CHAR_LENGTH(TRIM(reason)) > 0),
                CONSTRAINT chk_mp3_cleanup_closure_proof CHECK (
                    (proof_type = 'currently_absent' AND request_id IS NULL)
                    OR (proof_type = 'confirmed_removal' AND request_id IS NOT NULL)
                )
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('MP3 incident closure evidence must be preserved.');
    }
}
