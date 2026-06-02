<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241125193557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE emission_invite_old_animateur (emission_id INT NOT NULL, invite_old_animateur_id INT NOT NULL, INDEX IDX_15730A4E17E24D70 (emission_id), INDEX IDX_15730A4E7F571CED (invite_old_animateur_id), PRIMARY KEY(emission_id, invite_old_animateur_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invite_old_animateur (id INT AUTO_INCREMENT NOT NULL, last_name VARCHAR(255) DEFAULT NULL, first_name VARCHAR(255) NOT NULL, phone_number VARCHAR(10) DEFAULT NULL, mail VARCHAR(255) NOT NULL, ancienanimateur TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE emission_invite_old_animateur ADD CONSTRAINT FK_15730A4E17E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_invite_old_animateur ADD CONSTRAINT FK_15730A4E7F571CED FOREIGN KEY (invite_old_animateur_id) REFERENCES invite_old_animateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_invite DROP FOREIGN KEY FK_E8CB4BD117E24D70');
        $this->addSql('ALTER TABLE emission_invite DROP FOREIGN KEY FK_E8CB4BD1EA417747');
        $this->addSql('DROP TABLE emission_invite');
        $this->addSql('DROP TABLE invite');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE emission_invite (emission_id INT NOT NULL, invite_id INT NOT NULL, INDEX IDX_E8CB4BD117E24D70 (emission_id), INDEX IDX_E8CB4BD1EA417747 (invite_id), PRIMARY KEY(emission_id, invite_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE invite (id INT AUTO_INCREMENT NOT NULL, last_name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, first_name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, phone_number VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, mail VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE emission_invite ADD CONSTRAINT FK_E8CB4BD117E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_invite ADD CONSTRAINT FK_E8CB4BD1EA417747 FOREIGN KEY (invite_id) REFERENCES invite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_invite_old_animateur DROP FOREIGN KEY FK_15730A4E17E24D70');
        $this->addSql('ALTER TABLE emission_invite_old_animateur DROP FOREIGN KEY FK_15730A4E7F571CED');
        $this->addSql('DROP TABLE emission_invite_old_animateur');
        $this->addSql('DROP TABLE invite_old_animateur');
    }
}
