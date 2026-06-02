<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260421142214 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories CHANGE editeur editeur_id INT NOT NULL');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF346683375BD21 FOREIGN KEY (editeur_id) REFERENCES editeur (id)');
        $this->addSql('CREATE INDEX IDX_3AF346683375BD21 ON categories (editeur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories DROP FOREIGN KEY FK_3AF346683375BD21');
        $this->addSql('DROP INDEX IDX_3AF346683375BD21 ON categories');
        $this->addSql('ALTER TABLE categories CHANGE editeur_id editeur INT NOT NULL');
    }
}
