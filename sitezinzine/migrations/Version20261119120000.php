<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the durable MP3 trash operation journal.';
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
            CREATE TABLE mp3_trash_operation (
                id VARCHAR(32) NOT NULL,
                emission_id INT NOT NULL,
                original_thumbnail_mp3 VARCHAR(255) NOT NULL,
                original_url VARCHAR(250) DEFAULT NULL,
                size_bytes BIGINT NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                author_id INT NOT NULL,
                phase VARCHAR(25) COLLATE utf8mb4_bin NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                blocked TINYINT(1) NOT NULL,
                last_diagnostic_code VARCHAR(64) DEFAULT NULL,
                last_diagnostic_at DATETIME(6) DEFAULT NULL,
                last_attempt_id VARCHAR(32) DEFAULT NULL,
                restoration_attempts JSON NOT NULL,
                active_marker SMALLINT GENERATED ALWAYS AS (
                    CASE WHEN phase IN ('SUPPRESSION_PREPAREE', 'RETRAIT_PUBLIC_EN_ATTENTE', 'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE')
                        THEN 1 ELSE NULL END
                ) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_mp3_trash_emission_active (emission_id, active_marker),
                CONSTRAINT chk_mp3_trash_phase CHECK (
                    CAST(phase AS BINARY) IN ('SUPPRESSION_PREPAREE', 'RETRAIT_PUBLIC_EN_ATTENTE', 'EN_CORBEILLE',
                        'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE', 'ANNULEE')
                ),
                CONSTRAINT chk_mp3_trash_identity CHECK (emission_id > 0 AND author_id > 0 AND size_bytes > 0)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The MP3 trash journal contains recovery history that must be preserved.');
    }
}
