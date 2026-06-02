<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260421201509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE grid_slot_arbitration DROP FOREIGN KEY FK_4B0D264A9AC69B54');
        $this->addSql('DROP INDEX idx_grid_slot_arbitration_conflict_type ON grid_slot_arbitration');
        $this->addSql('DROP INDEX idx_grid_slot_arbitration_needs_reschedule ON grid_slot_arbitration');
        $this->addSql('DROP INDEX idx_grid_slot_arbitration_starts_at ON grid_slot_arbitration');
        $this->addSql('DROP INDEX IDX_4B0D264A9AC69B54 ON grid_slot_arbitration');
        $this->addSql('DROP INDEX uniq_grid_slot_arbitration_slot_start ON grid_slot_arbitration');
        $this->addSql('ALTER TABLE grid_slot_arbitration ADD original_starts_at DATETIME NOT NULL, ADD original_ends_at DATETIME NOT NULL, ADD action VARCHAR(50) NOT NULL, ADD rescheduled_starts_at DATETIME DEFAULT NULL, ADD rescheduled_ends_at DATETIME DEFAULT NULL, ADD resolved_at DATETIME DEFAULT NULL, DROP starts_at, DROP ends_at, DROP resolution_action, DROP needs_reschedule, DROP reschedule_status, DROP replaced_by_id, CHANGE status status VARCHAR(30) NOT NULL, CHANGE conflict_type type VARCHAR(50) NOT NULL, CHANGE note notes LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_grid_slot_arbitration_original_starts_at ON grid_slot_arbitration (original_starts_at)');
        $this->addSql('CREATE INDEX idx_grid_slot_arbitration_rescheduled_starts_at ON grid_slot_arbitration (rescheduled_starts_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_grid_slot_arbitration_original_starts_at ON grid_slot_arbitration');
        $this->addSql('DROP INDEX idx_grid_slot_arbitration_rescheduled_starts_at ON grid_slot_arbitration');
        $this->addSql('ALTER TABLE grid_slot_arbitration ADD starts_at DATETIME NOT NULL, ADD ends_at DATETIME NOT NULL, ADD conflict_type VARCHAR(50) NOT NULL, ADD resolution_action VARCHAR(40) DEFAULT NULL, ADD needs_reschedule TINYINT(1) NOT NULL, ADD reschedule_status VARCHAR(20) DEFAULT NULL, ADD replaced_by_id INT DEFAULT NULL, DROP original_starts_at, DROP original_ends_at, DROP type, DROP action, DROP rescheduled_starts_at, DROP rescheduled_ends_at, DROP resolved_at, CHANGE status status VARCHAR(40) NOT NULL, CHANGE notes note LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE grid_slot_arbitration ADD CONSTRAINT FK_4B0D264A9AC69B54 FOREIGN KEY (replaced_by_id) REFERENCES grid_slot_arbitration (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_grid_slot_arbitration_conflict_type ON grid_slot_arbitration (conflict_type)');
        $this->addSql('CREATE INDEX idx_grid_slot_arbitration_needs_reschedule ON grid_slot_arbitration (needs_reschedule)');
        $this->addSql('CREATE INDEX idx_grid_slot_arbitration_starts_at ON grid_slot_arbitration (starts_at)');
        $this->addSql('CREATE INDEX IDX_4B0D264A9AC69B54 ON grid_slot_arbitration (replaced_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_grid_slot_arbitration_slot_start ON grid_slot_arbitration (slot_id, starts_at)');
    }
}
