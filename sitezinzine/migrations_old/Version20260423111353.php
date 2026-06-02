<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423111353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de draft_type, duration_minutes et ends_at sur diffusion_draft avec backfill des anciennes lignes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            ALTER TABLE diffusion_draft
                ADD draft_type VARCHAR(40) DEFAULT 'regular' NOT NULL,
                ADD duration_minutes INT DEFAULT NULL,
                ADD ends_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                CHANGE slot_id slot_id INT DEFAULT NULL
        ");

        $this->addSql("
            UPDATE diffusion_draft dd
            INNER JOIN emission e ON e.id = dd.emission_id
            SET
                dd.duration_minutes = CASE
                    WHEN dd.duration_minutes IS NOT NULL AND dd.duration_minutes > 0 THEN dd.duration_minutes
                    WHEN e.duree IS NOT NULL AND e.duree > 0 THEN e.duree
                    ELSE 15
                END,
                dd.ends_at = DATE_ADD(
                    dd.horaire_diffusion,
                    INTERVAL CASE
                        WHEN dd.duration_minutes IS NOT NULL AND dd.duration_minutes > 0 THEN dd.duration_minutes
                        WHEN e.duree IS NOT NULL AND e.duree > 0 THEN e.duree
                        ELSE 15
                    END MINUTE
                )
            WHERE dd.ends_at IS NULL
        ");

        $this->addSql('CREATE INDEX idx_diffusion_draft_ends_at ON diffusion_draft (ends_at)');
        $this->addSql('CREATE INDEX idx_diffusion_draft_type ON diffusion_draft (draft_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_diffusion_draft_ends_at ON diffusion_draft');
        $this->addSql('DROP INDEX idx_diffusion_draft_type ON diffusion_draft');
        $this->addSql("
            ALTER TABLE diffusion_draft
                DROP draft_type,
                DROP duration_minutes,
                DROP ends_at,
                CHANGE slot_id slot_id INT NOT NULL
        ");
    }
}