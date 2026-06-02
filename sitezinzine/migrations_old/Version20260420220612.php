<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260420220612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE grid_slot_arbitration (id INT AUTO_INCREMENT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, status VARCHAR(40) NOT NULL, conflict_type VARCHAR(50) NOT NULL, resolution_action VARCHAR(40) DEFAULT NULL, needs_reschedule TINYINT(1) NOT NULL, reschedule_status VARCHAR(20) DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, slot_id INT NOT NULL, replaced_by_id INT DEFAULT NULL, INDEX IDX_4B0D264A59E5119C (slot_id), INDEX IDX_4B0D264A9AC69B54 (replaced_by_id), INDEX idx_grid_slot_arbitration_status (status), INDEX idx_grid_slot_arbitration_starts_at (starts_at), INDEX idx_grid_slot_arbitration_needs_reschedule (needs_reschedule), INDEX idx_grid_slot_arbitration_conflict_type (conflict_type), UNIQUE INDEX uniq_grid_slot_arbitration_slot_start (slot_id, starts_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE grid_slot_arbitration ADD CONSTRAINT FK_4B0D264A59E5119C FOREIGN KEY (slot_id) REFERENCES programmation_rule_slot (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grid_slot_arbitration ADD CONSTRAINT FK_4B0D264A9AC69B54 FOREIGN KEY (replaced_by_id) REFERENCES grid_slot_arbitration (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE grid_slot_arbitration DROP FOREIGN KEY FK_4B0D264A59E5119C');
        $this->addSql('ALTER TABLE grid_slot_arbitration DROP FOREIGN KEY FK_4B0D264A9AC69B54');
        $this->addSql('DROP TABLE grid_slot_arbitration');
    }
}
