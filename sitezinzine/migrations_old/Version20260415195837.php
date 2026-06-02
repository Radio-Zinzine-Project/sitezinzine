<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415195837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la colonne rule_number nullable à programmation_rule.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE programmation_rule ADD rule_number INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE programmation_rule DROP COLUMN rule_number');
    }
}