<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241007081556 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE emission CHANGE editeur_id editeur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE emission ADD CONSTRAINT FK_F0225CF43375BD21 FOREIGN KEY (editeur_id) REFERENCES editeur (id)');
        $this->addSql('CREATE INDEX IDX_F0225CF43375BD21 ON emission (editeur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE emission DROP FOREIGN KEY FK_F0225CF43375BD21');
        $this->addSql('DROP INDEX IDX_F0225CF43375BD21 ON emission');
        $this->addSql('ALTER TABLE emission CHANGE editeur_id editeur_id INT NOT NULL');
    }
}
