<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416214220 extends AbstractMigration
{
    

    public function getDescription(): string
    {
        return 'Migration neutralisée : doublon déjà appliqué en base.';
    }

    public function up(Schema $schema): void
    {
        // Migration neutralisée : changements déjà présents en base
    }

    public function down(Schema $schema): void
    {
        // Migration neutralisée
    }
}

