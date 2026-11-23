<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the append-only MP3 administrative event journal.';
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
            CREATE TABLE mp3_administrative_event (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id VARCHAR(32) NOT NULL,
                operation_id VARCHAR(32) NOT NULL,
                actor_id INT NOT NULL,
                attempt_id VARCHAR(32) DEFAULT NULL,
                action VARCHAR(64) NOT NULL,
                expected_phase VARCHAR(25) NOT NULL,
                observed_phase VARCHAR(25) DEFAULT NULL,
                event_type VARCHAR(24) NOT NULL,
                result_code VARCHAR(64) NOT NULL,
                recorded_at DATETIME(6) NOT NULL,
                confirmed_phase_key VARCHAR(25) GENERATED ALWAYS AS (
                    CASE WHEN event_type = 'transition_confirmed' THEN observed_phase ELSE '' END
                ) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_mp3_admin_request_event (request_id, event_type, confirmed_phase_key),
                KEY idx_mp3_admin_request_order (request_id, id),
                KEY idx_mp3_admin_operation_order (operation_id, id),
                CONSTRAINT chk_mp3_admin_actor CHECK (actor_id > 0),
                CONSTRAINT chk_mp3_admin_event_type CHECK (
                    event_type IN ('request', 'transition_confirmed', 'refusal', 'failure', 'uncertain')
                ),
                CONSTRAINT chk_mp3_admin_event_phase CHECK (
                    (event_type <> 'request' OR observed_phase IS NULL)
                    AND (event_type <> 'transition_confirmed' OR observed_phase IS NOT NULL)
                ),
                CONSTRAINT chk_mp3_admin_expected_phase CHECK (
                    expected_phase IN ('SUPPRESSION_PREPAREE', 'RETRAIT_PUBLIC_EN_ATTENTE', 'EN_CORBEILLE',
                        'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE', 'ANNULEE')
                ),
                CONSTRAINT chk_mp3_admin_observed_phase CHECK (
                    observed_phase IN ('SUPPRESSION_PREPAREE', 'RETRAIT_PUBLIC_EN_ATTENTE', 'EN_CORBEILLE',
                        'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE', 'RESTAUREE', 'ANNULEE')
                )
            ) ENGINE=InnoDB DEFAULT CHARACTER SET ascii COLLATE ascii_bin
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The MP3 administrative journal contains history that must be preserved.');
    }
}
