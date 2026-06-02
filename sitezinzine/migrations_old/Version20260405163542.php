<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260405163542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE programmation_rule_slot ADD recurrence_type VARCHAR(20) NOT NULL, ADD monthly_occurrence INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_programmation_rule_slot_recurrence ON programmation_rule_slot (recurrence_type)');
        $this->addSql('CREATE INDEX idx_programmation_rule_slot_monthly_occurrence ON programmation_rule_slot (monthly_occurrence)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_programmation_rule_slot_recurrence ON programmation_rule_slot');
        $this->addSql('DROP INDEX idx_programmation_rule_slot_monthly_occurrence ON programmation_rule_slot');
        $this->addSql('ALTER TABLE programmation_rule_slot DROP recurrence_type, DROP monthly_occurrence');
    }
}
