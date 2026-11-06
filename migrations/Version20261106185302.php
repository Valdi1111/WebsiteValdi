<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106185302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE gryphline_account (id INT AUTO_INCREMENT NOT NULL, account_token LONGTEXT NOT NULL, session_cred VARCHAR(255) DEFAULT NULL, session_salt VARCHAR(255) DEFAULT NULL, session_expires_at DATETIME DEFAULT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, last_token_refreshed_at DATETIME DEFAULT NULL, token_refresh_failed TINYINT DEFAULT 0 NOT NULL, user_id INT NOT NULL, INDEX FK_gryphline_account_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE gryphline_game_profile (id INT AUTO_INCREMENT NOT NULL, active TINYINT DEFAULT 1 NOT NULL, game_id INT DEFAULT NULL, game_biz VARCHAR(50) NOT NULL, game_name VARCHAR(50) NOT NULL, region VARCHAR(50) DEFAULT NULL, region_name VARCHAR(50) DEFAULT NULL, game_uid VARCHAR(50) NOT NULL, nickname VARCHAR(50) DEFAULT NULL, level INT DEFAULT NULL, server_id VARCHAR(50) NOT NULL, icon_url VARCHAR(250) DEFAULT NULL, parsed_region VARCHAR(50) DEFAULT NULL, parsed_timezone VARCHAR(50) DEFAULT \'UTC\' NOT NULL, skport_check_in TINYINT DEFAULT 1 NOT NULL, stamina_check TINYINT DEFAULT 0 NOT NULL, stamina_threshold INT DEFAULT -1 NOT NULL, dailies_check TINYINT DEFAULT 0 NOT NULL, weeklies_check TINYINT DEFAULT 0 NOT NULL, notification_platforms JSON NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, gryphline_account_id INT NOT NULL, INDEX IDX_game_id_game_uid (game_id, game_uid), INDEX IDX_game_biz_game_uid (game_biz, game_uid), INDEX IDX_game_id_gryphline_account (game_id, gryphline_account_id), INDEX IDX_game_biz_gryphline_account (game_biz, gryphline_account_id), INDEX FK_gryphline_game_profile_gryphline_account (gryphline_account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gryphline_account ADD CONSTRAINT FK_gryphline_account_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE gryphline_game_profile ADD CONSTRAINT FK_gryphline_game_profile_gryphline_account FOREIGN KEY (gryphline_account_id) REFERENCES gryphline_account (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE gryphline_account DROP FOREIGN KEY FK_gryphline_account_user');
        $this->addSql('ALTER TABLE gryphline_game_profile DROP FOREIGN KEY FK_gryphline_game_profile_gryphline_account');
        $this->addSql('DROP TABLE gryphline_account');
        $this->addSql('DROP TABLE gryphline_game_profile');
    }
}
