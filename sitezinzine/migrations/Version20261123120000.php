<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist explicit manual validation of radio weeks.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform, 'MySQL is required.');
        $this->addSql('CREATE TABLE grid_week_validation (
            week_start DATE NOT NULL,
            validated TINYINT(1) NOT NULL,
            manual_revision BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (week_start)
        ) ENGINE=InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE grid_week_validation');
    }
}
