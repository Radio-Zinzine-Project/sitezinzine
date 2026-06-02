<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602204623 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE annonce (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(100) NOT NULL, organisateur VARCHAR(100) NOT NULL, ville VARCHAR(50) NOT NULL, departement VARCHAR(2) NOT NULL, adresse VARCHAR(50) NOT NULL, date_debut DATETIME NOT NULL, date_fin DATETIME NOT NULL, horaire VARCHAR(50) NOT NULL, prix VARCHAR(50) NOT NULL, presentation LONGTEXT DEFAULT NULL, contact VARCHAR(200) NOT NULL, type VARCHAR(50) NOT NULL, valid TINYINT(1) DEFAULT NULL, update_at DATETIME NOT NULL, thumbnail VARCHAR(255) DEFAULT NULL, soft_delete TINYINT(1) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE categorie_tag_image (id INT AUTO_INCREMENT NOT NULL, annee INT NOT NULL, image VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, categorie_id INT NOT NULL, INDEX IDX_4A8E125EBCF5E72D (categorie_id), UNIQUE INDEX uniq_categorie_annee_tag_image (categorie_id, annee), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE categories (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(50) NOT NULL, oldid INT DEFAULT NULL, duree INT NOT NULL, descriptif LONGTEXT NOT NULL, thumbnail VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, slug VARCHAR(3) DEFAULT NULL, active TINYINT(1) NOT NULL, soft_delete TINYINT(1) NOT NULL, editeur_id INT NOT NULL, INDEX IDX_3AF346683375BD21 (editeur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE categories_user (categories_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_55A08ECFA21214B7 (categories_id), INDEX IDX_55A08ECFA76ED395 (user_id), PRIMARY KEY(categories_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE categories_invite_old_animateur (categories_id INT NOT NULL, invite_old_animateur_id INT NOT NULL, INDEX IDX_F9265F93A21214B7 (categories_id), INDEX IDX_F9265F937F571CED (invite_old_animateur_id), PRIMARY KEY(categories_id, invite_old_animateur_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE diffusion (id INT AUTO_INCREMENT NOT NULL, horaire_diffusion DATETIME NOT NULL, nombre_diffusion INT NOT NULL, emission_id INT NOT NULL, INDEX idx_diffusion_horaire (horaire_diffusion), INDEX idx_diffusion_emission (emission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE diffusion_draft (id INT AUTO_INCREMENT NOT NULL, horaire_diffusion DATETIME NOT NULL, nombre_diffusion INT NOT NULL, draft_type VARCHAR(40) DEFAULT \'regular\' NOT NULL, duration_minutes INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, assignment_group_key VARCHAR(80) DEFAULT NULL, emission_id INT NOT NULL, slot_id INT DEFAULT NULL, INDEX idx_diffusion_draft_horaire (horaire_diffusion), INDEX idx_diffusion_draft_ends_at (ends_at), INDEX idx_diffusion_draft_emission (emission_id), INDEX idx_diffusion_draft_slot (slot_id), INDEX idx_diffusion_draft_type (draft_type), INDEX idx_diffusion_draft_assignment_group (assignment_group_key), UNIQUE INDEX uniq_diffusion_draft_slot_horaire (slot_id, horaire_diffusion), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE editeur (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, mail VARCHAR(255) DEFAULT NULL, phone VARCHAR(255) DEFAULT NULL, update_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE emission (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(250) NOT NULL, keyword VARCHAR(250) NOT NULL, datepub DATETIME NOT NULL, ref VARCHAR(250) NOT NULL, duree INT NOT NULL, url VARCHAR(250) DEFAULT NULL, descriptif LONGTEXT NOT NULL, is_auto_generated TINYINT(1) DEFAULT 0 NOT NULL, is_pending_completion TINYINT(1) DEFAULT 0 NOT NULL, thumbnail VARCHAR(255) DEFAULT NULL, thumbnail_mp3 VARCHAR(255) DEFAULT NULL, updatedat DATETIME DEFAULT NULL, auto_generated_for_starts_at DATETIME DEFAULT NULL, categorie_id INT DEFAULT NULL, theme_id INT DEFAULT NULL, editeur_id INT DEFAULT NULL, auto_generated_for_slot_id INT DEFAULT NULL, INDEX IDX_F0225CF43375BD21 (editeur_id), INDEX IDX_F0225CF4D13AF86D (auto_generated_for_slot_id), INDEX idx_emission_url (url), INDEX idx_emission_titre (titre), INDEX idx_emission_theme (theme_id), INDEX idx_emission_categorie (categorie_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE emission_user (emission_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_C3AC93E617E24D70 (emission_id), INDEX IDX_C3AC93E6A76ED395 (user_id), PRIMARY KEY(emission_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE emission_invite_old_animateur (emission_id INT NOT NULL, invite_old_animateur_id INT NOT NULL, INDEX IDX_15730A4E17E24D70 (emission_id), INDEX IDX_15730A4E7F571CED (invite_old_animateur_id), PRIMARY KEY(emission_id, invite_old_animateur_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE evenement (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, organisateur VARCHAR(255) DEFAULT NULL, ville VARCHAR(50) DEFAULT NULL, departement VARCHAR(2) DEFAULT NULL, adresse VARCHAR(50) DEFAULT NULL, date_debut DATETIME NOT NULL, date_fin DATETIME NOT NULL, horaire VARCHAR(50) DEFAULT NULL, prix VARCHAR(50) DEFAULT NULL, presentation LONGTEXT DEFAULT NULL, contact VARCHAR(200) DEFAULT NULL, type VARCHAR(50) DEFAULT NULL, valid TINYINT(1) DEFAULT NULL, update_at DATETIME NOT NULL, thumbnail VARCHAR(255) DEFAULT NULL, soft_delete TINYINT(1) DEFAULT NULL, user_id INT DEFAULT NULL, INDEX IDX_B26681EA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE grid_slot_arbitration (id INT AUTO_INCREMENT NOT NULL, original_starts_at DATETIME NOT NULL, original_ends_at DATETIME NOT NULL, type VARCHAR(50) NOT NULL, status VARCHAR(30) NOT NULL, action VARCHAR(50) NOT NULL, rescheduled_starts_at DATETIME DEFAULT NULL, rescheduled_ends_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, arbitration_group_key VARCHAR(120) DEFAULT NULL, slot_id INT NOT NULL, INDEX IDX_4B0D264A59E5119C (slot_id), INDEX idx_grid_slot_arbitration_original_starts_at (original_starts_at), INDEX idx_grid_slot_arbitration_rescheduled_starts_at (rescheduled_starts_at), INDEX idx_grid_slot_arbitration_status (status), INDEX idx_grid_slot_arbitration_group_key (arbitration_group_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE invite_old_animateur (id INT AUTO_INCREMENT NOT NULL, last_name VARCHAR(255) DEFAULT NULL, first_name VARCHAR(255) NOT NULL, phone_number VARCHAR(10) DEFAULT NULL, mail VARCHAR(255) DEFAULT NULL, ancienanimateur TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE page (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, title VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, main_image_name VARCHAR(255) DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_140AB620989D9B62 (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE password_reset_token (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_6B7BA4B65F37A13B (token), INDEX IDX_6B7BA4B6A76ED395 (user_id), INDEX idx_password_reset_token_token (token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE programmation_rule (id INT AUTO_INCREMENT NOT NULL, rule_number INT NOT NULL, valid_from DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, is_active TINYINT(1) NOT NULL, deleted_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, category_id INT NOT NULL, INDEX idx_programmation_rule_active (is_active), INDEX idx_programmation_rule_deleted (deleted_at), INDEX idx_programmation_rule_category (category_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE programmation_rule_slot (id INT AUTO_INCREMENT NOT NULL, day_of_week INT NOT NULL, start_time TIME NOT NULL, duration_minutes INT NOT NULL, broadcast_rank INT NOT NULL, week_offset INT NOT NULL, recurrence_type VARCHAR(20) NOT NULL, monthly_occurrence INT DEFAULT NULL, month_interval INT NOT NULL, week_parity VARCHAR(10) DEFAULT NULL, is_active TINYINT(1) NOT NULL, deleted_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, rule_id INT NOT NULL, INDEX IDX_8D9C5C87744E0351 (rule_id), INDEX idx_programmation_rule_slot_day (day_of_week), INDEX idx_programmation_rule_slot_rank (broadcast_rank), INDEX idx_programmation_rule_slot_week_offset (week_offset), INDEX idx_programmation_rule_slot_recurrence (recurrence_type), INDEX idx_programmation_rule_slot_monthly_occurrence (monthly_occurrence), INDEX idx_programmation_rule_slot_month_interval (month_interval), INDEX idx_programmation_rule_slot_active (is_active), INDEX idx_programmation_rule_slot_deleted (deleted_at), INDEX idx_programmation_rule_slot_week_parity (week_parity), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE theme (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) DEFAULT NULL, thumbnail VARCHAR(255) DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, pending_email VARCHAR(255) DEFAULT NULL, is_verified TINYINT(1) NOT NULL, pseudo VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), UNIQUE INDEX UNIQ_8D93D6497807FE7C (pending_email), UNIQUE INDEX UNIQ_8D93D64986CC499D (pseudo), UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME (username), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE categorie_tag_image ADD CONSTRAINT FK_4A8E125EBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categories (id)');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF346683375BD21 FOREIGN KEY (editeur_id) REFERENCES editeur (id)');
        $this->addSql('ALTER TABLE categories_user ADD CONSTRAINT FK_55A08ECFA21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categories_user ADD CONSTRAINT FK_55A08ECFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categories_invite_old_animateur ADD CONSTRAINT FK_F9265F93A21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categories_invite_old_animateur ADD CONSTRAINT FK_F9265F937F571CED FOREIGN KEY (invite_old_animateur_id) REFERENCES invite_old_animateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE diffusion ADD CONSTRAINT FK_5938415B17E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id)');
        $this->addSql('ALTER TABLE diffusion_draft ADD CONSTRAINT FK_996C36B417E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE diffusion_draft ADD CONSTRAINT FK_996C36B459E5119C FOREIGN KEY (slot_id) REFERENCES programmation_rule_slot (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission ADD CONSTRAINT FK_F0225CF4BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categories (id)');
        $this->addSql('ALTER TABLE emission ADD CONSTRAINT FK_F0225CF459027487 FOREIGN KEY (theme_id) REFERENCES theme (id)');
        $this->addSql('ALTER TABLE emission ADD CONSTRAINT FK_F0225CF43375BD21 FOREIGN KEY (editeur_id) REFERENCES editeur (id)');
        $this->addSql('ALTER TABLE emission ADD CONSTRAINT FK_F0225CF4D13AF86D FOREIGN KEY (auto_generated_for_slot_id) REFERENCES programmation_rule_slot (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE emission_user ADD CONSTRAINT FK_C3AC93E617E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_user ADD CONSTRAINT FK_C3AC93E6A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_invite_old_animateur ADD CONSTRAINT FK_15730A4E17E24D70 FOREIGN KEY (emission_id) REFERENCES emission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE emission_invite_old_animateur ADD CONSTRAINT FK_15730A4E7F571CED FOREIGN KEY (invite_old_animateur_id) REFERENCES invite_old_animateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evenement ADD CONSTRAINT FK_B26681EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE grid_slot_arbitration ADD CONSTRAINT FK_4B0D264A59E5119C FOREIGN KEY (slot_id) REFERENCES programmation_rule_slot (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE programmation_rule ADD CONSTRAINT FK_4BA758F412469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE programmation_rule_slot ADD CONSTRAINT FK_8D9C5C87744E0351 FOREIGN KEY (rule_id) REFERENCES programmation_rule (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categorie_tag_image DROP FOREIGN KEY FK_4A8E125EBCF5E72D');
        $this->addSql('ALTER TABLE categories DROP FOREIGN KEY FK_3AF346683375BD21');
        $this->addSql('ALTER TABLE categories_user DROP FOREIGN KEY FK_55A08ECFA21214B7');
        $this->addSql('ALTER TABLE categories_user DROP FOREIGN KEY FK_55A08ECFA76ED395');
        $this->addSql('ALTER TABLE categories_invite_old_animateur DROP FOREIGN KEY FK_F9265F93A21214B7');
        $this->addSql('ALTER TABLE categories_invite_old_animateur DROP FOREIGN KEY FK_F9265F937F571CED');
        $this->addSql('ALTER TABLE diffusion DROP FOREIGN KEY FK_5938415B17E24D70');
        $this->addSql('ALTER TABLE diffusion_draft DROP FOREIGN KEY FK_996C36B417E24D70');
        $this->addSql('ALTER TABLE diffusion_draft DROP FOREIGN KEY FK_996C36B459E5119C');
        $this->addSql('ALTER TABLE emission DROP FOREIGN KEY FK_F0225CF4BCF5E72D');
        $this->addSql('ALTER TABLE emission DROP FOREIGN KEY FK_F0225CF459027487');
        $this->addSql('ALTER TABLE emission DROP FOREIGN KEY FK_F0225CF43375BD21');
        $this->addSql('ALTER TABLE emission DROP FOREIGN KEY FK_F0225CF4D13AF86D');
        $this->addSql('ALTER TABLE emission_user DROP FOREIGN KEY FK_C3AC93E617E24D70');
        $this->addSql('ALTER TABLE emission_user DROP FOREIGN KEY FK_C3AC93E6A76ED395');
        $this->addSql('ALTER TABLE emission_invite_old_animateur DROP FOREIGN KEY FK_15730A4E17E24D70');
        $this->addSql('ALTER TABLE emission_invite_old_animateur DROP FOREIGN KEY FK_15730A4E7F571CED');
        $this->addSql('ALTER TABLE evenement DROP FOREIGN KEY FK_B26681EA76ED395');
        $this->addSql('ALTER TABLE grid_slot_arbitration DROP FOREIGN KEY FK_4B0D264A59E5119C');
        $this->addSql('ALTER TABLE password_reset_token DROP FOREIGN KEY FK_6B7BA4B6A76ED395');
        $this->addSql('ALTER TABLE programmation_rule DROP FOREIGN KEY FK_4BA758F412469DE2');
        $this->addSql('ALTER TABLE programmation_rule_slot DROP FOREIGN KEY FK_8D9C5C87744E0351');
        $this->addSql('DROP TABLE annonce');
        $this->addSql('DROP TABLE categorie_tag_image');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE categories_user');
        $this->addSql('DROP TABLE categories_invite_old_animateur');
        $this->addSql('DROP TABLE diffusion');
        $this->addSql('DROP TABLE diffusion_draft');
        $this->addSql('DROP TABLE editeur');
        $this->addSql('DROP TABLE emission');
        $this->addSql('DROP TABLE emission_user');
        $this->addSql('DROP TABLE emission_invite_old_animateur');
        $this->addSql('DROP TABLE evenement');
        $this->addSql('DROP TABLE grid_slot_arbitration');
        $this->addSql('DROP TABLE invite_old_animateur');
        $this->addSql('DROP TABLE page');
        $this->addSql('DROP TABLE password_reset_token');
        $this->addSql('DROP TABLE programmation_rule');
        $this->addSql('DROP TABLE programmation_rule_slot');
        $this->addSql('DROP TABLE theme');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
