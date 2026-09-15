<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915095551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la validation administrative des comptes et synchronise les index Doctrine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_programmation_rule_category_number ON programmation_rule (category_id, rule_number)'
        );

        /*
         * Tous les comptes déjà présents doivent rester approuvés.
         */
        $this->addSql(
            'ALTER TABLE user ADD is_approved TINYINT(1) NOT NULL DEFAULT 1'
        );

        /*
         * On retire ensuite le DEFAULT SQL.
         * Pour les nouveaux User, l'entité définit isApproved à false.
         */
        $this->addSql(
            'ALTER TABLE user ALTER is_approved DROP DEFAULT'
        );

        $this->addSql(
            'DROP INDEX IDX_75EA56E016BA31DB ON messenger_messages'
        );
        $this->addSql(
            'DROP INDEX IDX_75EA56E0E3BD61CE ON messenger_messages'
        );
        $this->addSql(
            'DROP INDEX IDX_75EA56E0FB7336F0 ON messenger_messages'
        );
        $this->addSql(
            'CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages'
        );
        $this->addSql(
            'CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)'
        );
        $this->addSql(
            'CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)'
        );
        $this->addSql(
            'CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)'
        );

        $this->addSql(
            'DROP INDEX uniq_programmation_rule_category_number ON programmation_rule'
        );

        $this->addSql(
            'ALTER TABLE user DROP is_approved'
        );
    }
}