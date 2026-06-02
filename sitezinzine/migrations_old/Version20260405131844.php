<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260405131844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE programmation_rule (id INT AUTO_INCREMENT NOT NULL, day_of_week INT NOT NULL, start_time TIME NOT NULL, duration_minutes INT NOT NULL, frequency VARCHAR(20) NOT NULL, interval_value INT DEFAULT NULL, valid_from DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, is_active TINYINT(1) NOT NULL, label VARCHAR(255) DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, category_id INT NOT NULL, INDEX IDX_4BA758F412469DE2 (category_id), INDEX idx_rule_day (day_of_week), INDEX idx_rule_active (is_active), INDEX idx_rule_deleted (deleted_at), INDEX idx_rule_day_active (day_of_week, is_active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE programmation_rule ADD CONSTRAINT FK_4BA758F412469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE programmation_rule DROP FOREIGN KEY FK_4BA758F412469DE2');
        $this->addSql('DROP TABLE programmation_rule');
    }
}
