<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260405141119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE programmation_rule_slot (id INT AUTO_INCREMENT NOT NULL, day_of_week INT NOT NULL, start_time TIME NOT NULL, duration_minutes INT NOT NULL, broadcast_rank INT NOT NULL, is_active TINYINT(1) NOT NULL, deleted_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, rule_id INT NOT NULL, INDEX IDX_8D9C5C87744E0351 (rule_id), INDEX idx_programmation_rule_slot_day (day_of_week), INDEX idx_programmation_rule_slot_rank (broadcast_rank), INDEX idx_programmation_rule_slot_active (is_active), INDEX idx_programmation_rule_slot_deleted (deleted_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE programmation_rule_slot ADD CONSTRAINT FK_8D9C5C87744E0351 FOREIGN KEY (rule_id) REFERENCES programmation_rule (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX idx_rule_day ON programmation_rule');
        $this->addSql('DROP INDEX idx_rule_day_active ON programmation_rule');
        $this->addSql('ALTER TABLE programmation_rule DROP day_of_week, DROP start_time, DROP duration_minutes, DROP frequency, DROP interval_value');
        $this->addSql('ALTER TABLE programmation_rule RENAME INDEX idx_rule_active TO idx_programmation_rule_active');
        $this->addSql('ALTER TABLE programmation_rule RENAME INDEX idx_rule_deleted TO idx_programmation_rule_deleted');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE programmation_rule_slot DROP FOREIGN KEY FK_8D9C5C87744E0351');
        $this->addSql('DROP TABLE programmation_rule_slot');
        $this->addSql('ALTER TABLE programmation_rule ADD day_of_week INT NOT NULL, ADD start_time TIME NOT NULL, ADD duration_minutes INT NOT NULL, ADD frequency VARCHAR(20) NOT NULL, ADD interval_value INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_rule_day ON programmation_rule (day_of_week)');
        $this->addSql('CREATE INDEX idx_rule_day_active ON programmation_rule (day_of_week, is_active)');
        $this->addSql('ALTER TABLE programmation_rule RENAME INDEX idx_programmation_rule_active TO idx_rule_active');
        $this->addSql('ALTER TABLE programmation_rule RENAME INDEX idx_programmation_rule_deleted TO idx_rule_deleted');
    }
}
