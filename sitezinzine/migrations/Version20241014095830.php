<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241014095830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE emission_invite (emission_id INT NOT NULL, invite_id INT NOT NULL, INDEX IDX_E8CB4BD117E24D70 (emission_id), INDEX IDX_E8CB4BD1EA417747 (invite_id), PRIMARY KEY(emission_id, invite_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE emission_invite ADD CONSTRAINT FK_E8CB4BD117E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_invite ADD CONSTRAINT FK_E8CB4BD1EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE emission_invite DROP FOREIGN KEY FK_E8CB4BD117E24D70');
        $this->addSql('ALTER TABLE emission_invite DROP FOREIGN KEY FK_E8CB4BD1EA417747');
        $this->addSql('DROP TABLE emission_invite');
    }
}
