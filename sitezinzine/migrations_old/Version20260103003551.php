<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260103003551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE emission_user (emission_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_C3AC93E617E24D70 (emission_id), INDEX IDX_C3AC93E6A76ED395 (user_id), PRIMARY KEY(emission_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE emission_user ADD CONSTRAINT FK_C3AC93E617E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_user ADD CONSTRAINT FK_C3AC93E6A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');

        // ✅ Reprise des données existantes (ManyToOne -> ManyToMany)
        $this->addSql('
    INSERT INTO emission_user (emission_id, user_id)
    SELECT e.id, e.user_id
    FROM emission e
    INNER JOIN user u ON u.id = e.user_id
    WHERE e.user_id IS NOT NULL
');

        $this->addSql('ALTER TABLE emission DROP FOREIGN KEY FK_F0225CF4A76ED395');
        $this->addSql('DROP INDEX idx_emission_user ON emission');
        $this->addSql('ALTER TABLE emission DROP user_id');
    }


    public function down(Schema $schema): void
    {
        // drop FKs sur la table pivot
        $this->addSql('ALTER TABLE emission_user DROP FOREIGN KEY FK_C3AC93E617E24D70');
        $this->addSql('ALTER TABLE emission_user DROP FOREIGN KEY FK_C3AC93E6A76ED395');

        // recréer la colonne user_id
        $this->addSql('ALTER TABLE emission ADD user_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_emission_user ON emission (user_id)');
        $this->addSql('ALTER TABLE emission ADD CONSTRAINT FK_F0225CF4A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');

        // restaurer les données (un seul user par émission)
        $this->addSql('UPDATE emission e
        SET e.user_id = (
            SELECT eu.user_id
            FROM emission_user eu
            WHERE eu.emission_id = e.id
            LIMIT 1
        )');

        // supprimer la table pivot
        $this->addSql('DROP TABLE emission_user');
    }
}
