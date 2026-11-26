<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108204259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gryphline_pull (id INT AUTO_INCREMENT NOT NULL, seq_id VARCHAR(64) NOT NULL, pool_type VARCHAR(64) NOT NULL, pool_category VARCHAR(32) NOT NULL, pool_id VARCHAR(64) DEFAULT NULL, pool_name VARCHAR(128) DEFAULT NULL, item_id VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, rarity SMALLINT NOT NULL, pulled_at DATETIME NOT NULL, gacha_ts BIGINT DEFAULT NULL, raw_payload JSON DEFAULT NULL, gryphline_game_profile_id INT NOT NULL, INDEX FK_gryphline_pull_gryphline_game_profile (gryphline_game_profile_id), INDEX IDX_gryphline_game_profile_id_pool_type (gryphline_game_profile_id, pool_type), INDEX IDX_gryphline_game_profile_id_rarity (gryphline_game_profile_id, rarity), INDEX IDX_gryphline_game_profile_id_pulled_at (gryphline_game_profile_id, pulled_at), UNIQUE INDEX IDX_gryphline_game_profile_id_pool_category_seq_id (gryphline_game_profile_id, pool_category, seq_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hoyoverse_pull (id INT AUTO_INCREMENT NOT NULL, pull_id VARCHAR(64) NOT NULL, gacha_type VARCHAR(16) NOT NULL, gacha_name VARCHAR(64) DEFAULT NULL, item_type VARCHAR(32) NOT NULL, item_id VARCHAR(32) DEFAULT NULL, name VARCHAR(128) NOT NULL, rarity SMALLINT NOT NULL, pulled_at DATETIME NOT NULL, raw_payload JSON DEFAULT NULL, hoyoverse_game_profile_id INT NOT NULL, INDEX FK_hoyoverse_pull_hoyoverse_game_profile (hoyoverse_game_profile_id), INDEX IDX_hoyoverse_game_profile_id_gacha_type (hoyoverse_game_profile_id, gacha_type), INDEX IDX_hoyoverse_game_profile_id_rarity (hoyoverse_game_profile_id, rarity), INDEX IDX_hoyoverse_game_profile_id_pulled_at (hoyoverse_game_profile_id, pulled_at), UNIQUE INDEX IDX_hoyoverse_game_profile_id_pull_id (hoyoverse_game_profile_id, pull_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gryphline_pull ADD CONSTRAINT FK_gryphline_pull_gryphline_game_profile FOREIGN KEY (gryphline_game_profile_id) REFERENCES gryphline_game_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hoyoverse_pull ADD CONSTRAINT FK_hoyoverse_pull_hoyoverse_game_profile FOREIGN KEY (hoyoverse_game_profile_id) REFERENCES hoyoverse_game_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE gryphline_game_profile ADD sync_pulls TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE hoyoverse_game_profile ADD sync_pulls TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE gryphline_pull DROP FOREIGN KEY FK_gryphline_pull_gryphline_game_profile');
        $this->addSql('ALTER TABLE hoyoverse_pull DROP FOREIGN KEY FK_hoyoverse_pull_hoyoverse_game_profile');
        $this->addSql('DROP TABLE gryphline_pull');
        $this->addSql('DROP TABLE hoyoverse_pull');
        $this->addSql('ALTER TABLE gryphline_game_profile DROP sync_pulls');
        $this->addSql('ALTER TABLE hoyoverse_game_profile DROP sync_pulls');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
