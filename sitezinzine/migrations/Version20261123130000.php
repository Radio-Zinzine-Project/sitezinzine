<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Append-only declarations of manual radio file transfers.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform, 'MySQL is required.');
        $this->addSql(<<<'SQL'
            CREATE TABLE radio_transfer_confirmation (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                request_id CHAR(32) NOT NULL,
                destination_key VARCHAR(64) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                sha256 CHAR(64) NOT NULL,
                week_start DATE NOT NULL,
                preparation_id VARCHAR(64) NOT NULL,
                origin_attempt_id VARCHAR(64) NOT NULL,
                diffusion_id INT NOT NULL,
                actor_id INT NOT NULL,
                artifact_size BIGINT UNSIGNED NOT NULL,
                confirmed_at DATETIME(6) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_radio_transfer_request (request_id),
                KEY idx_radio_transfer_history (destination_key, filename, id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET ascii COLLATE ascii_bin
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE radio_transfer_confirmation');
    }
}
