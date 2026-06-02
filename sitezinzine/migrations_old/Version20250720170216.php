<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250720170216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE diffusion (id INT AUTO_INCREMENT NOT NULL, horaire_diffusion DATETIME NOT NULL, nombre_diffusion INT NOT NULL, emission_id INT NOT NULL, INDEX IDX_5938415B17E24D70 (emission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE diffusion ADD CONSTRAINT FK_5938415B17E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id)');
        $this->addSql('ALTER TABLE evenement CHANGE titre titre VARCHAR(255) NOT NULL, CHANGE organisateur organisateur VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE diffusion DROP FOREIGN KEY FK_5938415B17E24D70');
        $this->addSql('DROP TABLE diffusion');
        $this->addSql('ALTER TABLE evenement CHANGE titre titre VARCHAR(100) NOT NULL, CHANGE organisateur organisateur VARCHAR(100) DEFAULT NULL');
    }
}
