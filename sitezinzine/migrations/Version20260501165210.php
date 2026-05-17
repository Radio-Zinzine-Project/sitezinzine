<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260501165210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE diffusion_draft ADD assignment_group_key VARCHAR(80) DEFAULT NULL, CHANGE ends_at ends_at DATETIME NOT NULL');
        $this->addSql('CREATE INDEX idx_diffusion_draft_assignment_group ON diffusion_draft (assignment_group_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_diffusion_draft_assignment_group ON diffusion_draft');
        $this->addSql('ALTER TABLE diffusion_draft DROP assignment_group_key, CHANGE ends_at ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
