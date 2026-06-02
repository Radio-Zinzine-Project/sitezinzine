<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260405174508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE programmation_rule_slot ADD month_interval INT NOT NULL');
        $this->addSql('CREATE INDEX idx_programmation_rule_slot_month_interval ON programmation_rule_slot (month_interval)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_programmation_rule_slot_month_interval ON programmation_rule_slot');
        $this->addSql('ALTER TABLE programmation_rule_slot DROP month_interval');
    }
}
