<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617205752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE diffusion ADD duration_minutes INT DEFAULT NULL, ADD ends_at DATETIME DEFAULT NULL, ADD assignment_group_key VARCHAR(80) DEFAULT NULL, ADD created_at DATETIME DEFAULT NULL, ADD updated_at DATETIME DEFAULT NULL');

        $this->addSql('UPDATE diffusion SET created_at = COALESCE(horaire_diffusion, NOW()), updated_at = NOW()');

        $this->addSql('ALTER TABLE diffusion MODIFY created_at DATETIME NOT NULL, MODIFY updated_at DATETIME NOT NULL');

        $this->addSql('CREATE INDEX idx_diffusion_ends_at ON diffusion (ends_at)');
        $this->addSql('CREATE INDEX idx_diffusion_assignment_group ON diffusion (assignment_group_key)');

        $this->addSql('ALTER TABLE diffusion_draft ADD publication_status VARCHAR(30) DEFAULT \'draft\' NOT NULL, ADD published_at DATETIME DEFAULT NULL, ADD deleted_at DATETIME DEFAULT NULL, ADD published_diffusion_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE diffusion_draft ADD CONSTRAINT FK_996C36B49CF5F19E FOREIGN KEY (published_diffusion_id) REFERENCES diffusion (id) ON DELETE SET NULL');

        $this->addSql('CREATE INDEX idx_diffusion_draft_publication_status ON diffusion_draft (publication_status)');
        $this->addSql('CREATE INDEX idx_diffusion_draft_deleted_at ON diffusion_draft (deleted_at)');
        $this->addSql('CREATE INDEX idx_diffusion_draft_published_diffusion ON diffusion_draft (published_diffusion_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_diffusion_ends_at ON diffusion');
        $this->addSql('DROP INDEX idx_diffusion_assignment_group ON diffusion');
        $this->addSql('ALTER TABLE diffusion DROP duration_minutes, DROP ends_at, DROP assignment_group_key, DROP created_at, DROP updated_at');
        $this->addSql('ALTER TABLE diffusion_draft DROP FOREIGN KEY FK_996C36B49CF5F19E');
        $this->addSql('DROP INDEX idx_diffusion_draft_publication_status ON diffusion_draft');
        $this->addSql('DROP INDEX idx_diffusion_draft_deleted_at ON diffusion_draft');
        $this->addSql('DROP INDEX idx_diffusion_draft_published_diffusion ON diffusion_draft');
        $this->addSql('ALTER TABLE diffusion_draft DROP publication_status, DROP published_at, DROP deleted_at, DROP published_diffusion_id');
    }
}
