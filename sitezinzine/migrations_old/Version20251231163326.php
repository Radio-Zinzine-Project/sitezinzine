<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251231163326 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE categories_invite_old_animateur (categories_id INT NOT NULL, invite_old_animateur_id INT NOT NULL, INDEX IDX_F9265F93A21214B7 (categories_id), INDEX IDX_F9265F937F571CED (invite_old_animateur_id), PRIMARY KEY(categories_id, invite_old_animateur_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE categories_invite_old_animateur ADD CONSTRAINT FK_F9265F93A21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categories_invite_old_animateur ADD CONSTRAINT FK_F9265F937F571CED FOREIGN KEY (invite_old_animateur_id) REFERENCES invite_old_animateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories_invite_old_animateur DROP FOREIGN KEY FK_F9265F93A21214B7');
        $this->addSql('ALTER TABLE categories_invite_old_animateur DROP FOREIGN KEY FK_F9265F937F571CED');
        $this->addSql('DROP TABLE categories_invite_old_animateur');
    }
}
