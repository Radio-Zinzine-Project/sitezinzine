<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260918212950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pending_rebroadcast (id INT AUTO_INCREMENT NOT NULL, assignment_group_key VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, emission_id INT NOT NULL, INDEX idx_pending_rebroadcast_emission (emission_id), INDEX idx_pending_rebroadcast_group (assignment_group_key), INDEX idx_pending_rebroadcast_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE pending_rebroadcast ADD CONSTRAINT FK_DC5B727517E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pending_rebroadcast DROP FOREIGN KEY FK_DC5B727517E24D70');
        $this->addSql('DROP TABLE pending_rebroadcast');
    }
}
