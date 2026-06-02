<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250722012909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_diffusion_horaire ON diffusion (horaire_diffusion)');
        $this->addSql('ALTER TABLE diffusion RENAME INDEX idx_5938415b17e24d70 TO idx_diffusion_emission');
        $this->addSql('CREATE INDEX idx_emission_url ON emission (url)');
        $this->addSql('CREATE INDEX idx_emission_titre ON emission (titre)');
        $this->addSql('ALTER TABLE emission RENAME INDEX idx_f0225cf4a76ed395 TO idx_emission_user');
        $this->addSql('ALTER TABLE emission RENAME INDEX idx_f0225cf459027487 TO idx_emission_theme');
        $this->addSql('ALTER TABLE emission RENAME INDEX idx_f0225cf4bcf5e72d TO idx_emission_categorie');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_diffusion_horaire ON diffusion');
        $this->addSql('ALTER TABLE diffusion RENAME INDEX idx_diffusion_emission TO IDX_5938415B17E24D70');
        $this->addSql('DROP INDEX idx_emission_url ON emission');
        $this->addSql('DROP INDEX idx_emission_titre ON emission');
        $this->addSql('ALTER TABLE emission RENAME INDEX idx_emission_user TO IDX_F0225CF4A76ED395');
        $this->addSql('ALTER TABLE emission RENAME INDEX idx_emission_theme TO IDX_F0225CF459027487');
        $this->addSql('ALTER TABLE emission RENAME INDEX idx_emission_categorie TO IDX_F0225CF4BCF5E72D');
    }
}
