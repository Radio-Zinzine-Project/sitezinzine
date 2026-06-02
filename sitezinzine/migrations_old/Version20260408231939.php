<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260408231939 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE diffusion_draft (id INT AUTO_INCREMENT NOT NULL, horaire_diffusion DATETIME NOT NULL, nombre_diffusion INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, emission_id INT NOT NULL, slot_id INT NOT NULL, INDEX idx_diffusion_draft_horaire (horaire_diffusion), INDEX idx_diffusion_draft_emission (emission_id), INDEX idx_diffusion_draft_slot (slot_id), UNIQUE INDEX uniq_diffusion_draft_slot_horaire (slot_id, horaire_diffusion), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE diffusion_draft ADD CONSTRAINT FK_996C36B417E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE diffusion_draft ADD CONSTRAINT FK_996C36B459E5119C FOREIGN KEY (slot_id) REFERENCES programmation_rule_slot (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE diffusion_draft DROP FOREIGN KEY FK_996C36B417E24D70');
        $this->addSql('ALTER TABLE diffusion_draft DROP FOREIGN KEY FK_996C36B459E5119C');
        $this->addSql('DROP TABLE diffusion_draft');
    }
}
