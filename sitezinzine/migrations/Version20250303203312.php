<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250303203312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE evenement (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(100) NOT NULL, organisateur VARCHAR(100) NOT NULL, ville VARCHAR(50) NOT NULL, departement VARCHAR(2) NOT NULL, adresse VARCHAR(50) NOT NULL, date_debut DATETIME NOT NULL, date_fin DATETIME NOT NULL, horaire VARCHAR(50) NOT NULL, prix VARCHAR(50) NOT NULL, presentation LONGTEXT DEFAULT NULL, contact VARCHAR(200) NOT NULL, type VARCHAR(50) NOT NULL, valid TINYINT(1) DEFAULT NULL, update_at DATETIME NOT NULL, thumbnail VARCHAR(255) DEFAULT NULL, soft_delete TINYINT(1) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE annonce CHANGE horaire horaire VARCHAR(50) NOT NULL, CHANGE prix prix VARCHAR(50) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE evenement');
        $this->addSql('ALTER TABLE annonce CHANGE horaire horaire LONGTEXT NOT NULL, CHANGE prix prix LONGTEXT NOT NULL');
    }
}
