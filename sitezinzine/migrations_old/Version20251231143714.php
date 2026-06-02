<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251231143714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
{
    // this up() migration is auto-generated, please modify it to your needs
    $this->addSql('CREATE TABLE categories_user (categories_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_55A08ECFA21214B7 (categories_id), INDEX IDX_55A08ECFA76ED395 (user_id), PRIMARY KEY(categories_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    $this->addSql('ALTER TABLE categories_user ADD CONSTRAINT FK_55A08ECFA21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE');
    $this->addSql('ALTER TABLE categories_user ADD CONSTRAINT FK_55A08ECFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');

    // ✅ IMPORTANT : migrer les liens existants (categories.user_id -> categories_user)
    $this->addSql('
        INSERT INTO categories_user (categories_id, user_id)
        SELECT id AS categories_id, user_id
        FROM categories
        WHERE user_id IS NOT NULL
    ');

    $this->addSql('ALTER TABLE categories DROP FOREIGN KEY FK_3AF34668A76ED395');
    $this->addSql('DROP INDEX IDX_3AF34668A76ED395 ON categories');
    $this->addSql('ALTER TABLE categories DROP user_id');
}


    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories_user DROP FOREIGN KEY FK_55A08ECFA21214B7');
        $this->addSql('ALTER TABLE categories_user DROP FOREIGN KEY FK_55A08ECFA76ED395');
        $this->addSql('DROP TABLE categories_user');
        $this->addSql('ALTER TABLE categories ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF34668A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_3AF34668A76ED395 ON categories (user_id)');
    }
}
