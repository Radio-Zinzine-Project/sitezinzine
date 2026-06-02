<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250303204112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evenement CHANGE organisateur organisateur VARCHAR(100) DEFAULT NULL, CHANGE ville ville VARCHAR(50) DEFAULT NULL, CHANGE adresse adresse VARCHAR(50) DEFAULT NULL, CHANGE horaire horaire VARCHAR(50) DEFAULT NULL, CHANGE prix prix VARCHAR(50) DEFAULT NULL, CHANGE contact contact VARCHAR(200) DEFAULT NULL, CHANGE type type VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evenement CHANGE organisateur organisateur VARCHAR(100) NOT NULL, CHANGE ville ville VARCHAR(50) NOT NULL, CHANGE adresse adresse VARCHAR(50) NOT NULL, CHANGE horaire horaire VARCHAR(50) NOT NULL, CHANGE prix prix VARCHAR(50) NOT NULL, CHANGE contact contact VARCHAR(200) NOT NULL, CHANGE type type VARCHAR(50) NOT NULL');
    }
}
