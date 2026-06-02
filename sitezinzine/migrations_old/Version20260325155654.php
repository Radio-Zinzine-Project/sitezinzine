<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260325155654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE categorie_tag_image (id INT AUTO_INCREMENT NOT NULL, annee INT NOT NULL, image VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, categorie_id INT NOT NULL, INDEX IDX_4A8E125EBCF5E72D (categorie_id), UNIQUE INDEX uniq_categorie_annee_tag_image (categorie_id, annee), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE categorie_tag_image ADD CONSTRAINT FK_4A8E125EBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categories (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categorie_tag_image DROP FOREIGN KEY FK_4A8E125EBCF5E72D');
        $this->addSql('DROP TABLE categorie_tag_image');
    }
}
