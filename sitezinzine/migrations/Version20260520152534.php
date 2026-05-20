<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520152534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE grid_slot_arbitration ADD arbitration_group_key VARCHAR(120) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_grid_slot_arbitration_group_key ON grid_slot_arbitration (arbitration_group_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_grid_slot_arbitration_group_key ON grid_slot_arbitration');
        $this->addSql('ALTER TABLE grid_slot_arbitration DROP arbitration_group_key');
    }
}
