<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PerfectworldBundle schema: accounts, game profiles and imported pulls.
 */
final class Version20261121140308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create PerfectworldBundle tables (account, game profile, pull)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE perfectworld_account (id INT AUTO_INCREMENT NOT NULL, pw_uid VARCHAR(32) NOT NULL, token LONGTEXT NOT NULL, ndid VARCHAR(64) NOT NULL, email VARCHAR(255) DEFAULT NULL, login_method VARCHAR(16) NOT NULL, token_expires_at DATETIME DEFAULT NULL, token_invalid TINYINT DEFAULT 0 NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, last_synced_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX FK_perfectworld_account_user (user_id), UNIQUE INDEX IDX_perfectworld_account_user_pw_uid (user_id, pw_uid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE perfectworld_game_profile (id INT AUTO_INCREMENT NOT NULL, active TINYINT DEFAULT 1 NOT NULL, game_id INT NOT NULL, game_biz VARCHAR(50) NOT NULL, game_name VARCHAR(50) NOT NULL, game_uid VARCHAR(50) NOT NULL, nickname VARCHAR(100) DEFAULT NULL, level INT DEFAULT NULL, server_id VARCHAR(50) DEFAULT NULL, region VARCHAR(50) DEFAULT NULL, region_name VARCHAR(50) DEFAULT NULL, parsed_region VARCHAR(50) DEFAULT NULL, parsed_timezone VARCHAR(50) DEFAULT \'UTC\' NOT NULL, avatar_id VARCHAR(100) DEFAULT NULL, icon_url VARCHAR(250) DEFAULT NULL, first_login_at DATETIME DEFAULT NULL, notification_platforms JSON NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, perfectworld_account_id INT NOT NULL, INDEX FK_perfectworld_game_profile_account (perfectworld_account_id), INDEX IDX_perfectworld_game_profile_game_uid (game_id, game_uid), UNIQUE INDEX IDX_perfectworld_game_profile_account_game_uid (perfectworld_account_id, game_uid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE perfectworld_pull (id INT AUTO_INCREMENT NOT NULL, banner_key VARCHAR(64) NOT NULL, pool_id VARCHAR(64) DEFAULT NULL, pulled_at DATETIME NOT NULL, group_position SMALLINT NOT NULL, kind VARCHAR(16) NOT NULL, roll_result SMALLINT DEFAULT NULL, reward_type VARCHAR(16) NOT NULL, reward_id VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, rarity SMALLINT DEFAULT NULL, quantity INT DEFAULT 1 NOT NULL, export_uid VARCHAR(32) DEFAULT NULL, exporter_version VARCHAR(16) NOT NULL, raw_payload JSON DEFAULT NULL, perfectworld_game_profile_id INT NOT NULL, INDEX FK_perfectworld_pull_game_profile (perfectworld_game_profile_id), INDEX IDX_perfectworld_pull_profile_banner_pulled_at (perfectworld_game_profile_id, banner_key, pulled_at), INDEX IDX_perfectworld_pull_profile_rarity (perfectworld_game_profile_id, rarity), UNIQUE INDEX IDX_perfectworld_pull_identity (perfectworld_game_profile_id, banner_key, pulled_at, group_position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE perfectworld_account ADD CONSTRAINT FK_F3E3B5DAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE perfectworld_game_profile ADD CONSTRAINT FK_B8FF274646EE9C1A FOREIGN KEY (perfectworld_account_id) REFERENCES perfectworld_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE perfectworld_pull ADD CONSTRAINT FK_9E2E4078E5916912 FOREIGN KEY (perfectworld_game_profile_id) REFERENCES perfectworld_game_profile (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE perfectworld_account DROP FOREIGN KEY FK_F3E3B5DAA76ED395');
        $this->addSql('ALTER TABLE perfectworld_game_profile DROP FOREIGN KEY FK_B8FF274646EE9C1A');
        $this->addSql('ALTER TABLE perfectworld_pull DROP FOREIGN KEY FK_9E2E4078E5916912');
        $this->addSql('DROP TABLE perfectworld_account');
        $this->addSql('DROP TABLE perfectworld_game_profile');
        $this->addSql('DROP TABLE perfectworld_pull');
    }
}
