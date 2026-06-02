<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412193424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE programmation_rule_slot ADD week_parity VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_programmation_rule_slot_week_parity ON programmation_rule_slot (week_parity)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_programmation_rule_slot_week_parity ON programmation_rule_slot');
        $this->addSql('ALTER TABLE programmation_rule_slot DROP week_parity');
    }
}
