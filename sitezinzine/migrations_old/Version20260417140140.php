<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260417140140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE emission ADD auto_generated_for_starts_at DATETIME DEFAULT NULL, ADD auto_generated_for_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE emission ADD CONSTRAINT FK_F0225CF4D13AF86D FOREIGN KEY (auto_generated_for_slot_id) REFERENCES programmation_rule_slot (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F0225CF4D13AF86D ON emission (auto_generated_for_slot_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE emission DROP FOREIGN KEY FK_F0225CF4D13AF86D');
        $this->addSql('DROP INDEX IDX_F0225CF4D13AF86D ON emission');
        $this->addSql('ALTER TABLE emission DROP auto_generated_for_starts_at, DROP auto_generated_for_slot_id');
    }
}
