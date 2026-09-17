<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911192920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HoyoverseBundle Tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hoyoverse_account (id INT AUTO_INCREMENT NOT NULL, cookie LONGTEXT NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, user_id INT NOT NULL, INDEX FK_hoyoverse_account_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hoyoverse_game_profile (id INT AUTO_INCREMENT NOT NULL, active TINYINT DEFAULT 1 NOT NULL, game_id INT NOT NULL, game_biz VARCHAR(50) NOT NULL, game_name VARCHAR(50) NOT NULL, region VARCHAR(50) NOT NULL, region_name VARCHAR(50) NOT NULL, game_uid VARCHAR(50) NOT NULL, nickname VARCHAR(50) NOT NULL, level INT NOT NULL, hoyolab_url VARCHAR(250) DEFAULT NULL, icon_url VARCHAR(250) DEFAULT NULL, parsed_region VARCHAR(50) NOT NULL, parsed_timezone VARCHAR(50) NOT NULL, hoyolab_check_in TINYINT DEFAULT 1 NOT NULL, hoyolab_missed_check_in TINYINT DEFAULT 1 NOT NULL, code_redeem TINYINT DEFAULT 1 NOT NULL, stamina_check TINYINT DEFAULT 0 NOT NULL, stamina_threshold INT DEFAULT -1 NOT NULL, expeditions_check TINYINT DEFAULT 0 NOT NULL, realm_currency_check TINYINT DEFAULT 0 NOT NULL, realm_currency_threshold INT DEFAULT -1 NOT NULL, shop_status_check TINYINT DEFAULT 0 NOT NULL, mimo_check TINYINT DEFAULT 0 NOT NULL, mimo_redeem TINYINT DEFAULT 0 NOT NULL, mimo_redeem_draw TINYINT DEFAULT 0 NOT NULL, mimo_lottery TINYINT DEFAULT 0 NOT NULL, mimo_reserve_points INT DEFAULT -1 NOT NULL, hilichurl_check TINYINT DEFAULT 0 NOT NULL, hilichurl_redeem TINYINT DEFAULT 0 NOT NULL, dailies_check TINYINT DEFAULT 0 NOT NULL, weeklies_check TINYINT DEFAULT 0 NOT NULL, notification_platforms JSON NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, hoyoverse_account_id INT NOT NULL, INDEX IDX_game_id_game_uid (game_id, game_uid), INDEX IDX_game_biz_game_uid (game_biz, game_uid), INDEX IDX_game_id_hoyoverse_account (game_id, hoyoverse_account_id), INDEX IDX_game_biz_hoyoverse_account (game_biz, hoyoverse_account_id), INDEX FK_hoyoverse_game_profile_hoyoverse_account (hoyoverse_account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE hoyoverse_account ADD CONSTRAINT FK_hoyoverse_account_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hoyoverse_game_profile ADD CONSTRAINT FK_hoyoverse_game_profile_hoyoverse_account FOREIGN KEY (hoyoverse_account_id) REFERENCES hoyoverse_account (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hoyoverse_account DROP FOREIGN KEY FK_hoyoverse_account_user');
        $this->addSql('ALTER TABLE hoyoverse_game_profile DROP FOREIGN KEY FK_hoyoverse_game_profile_hoyoverse_account');
        $this->addSql('DROP TABLE hoyoverse_account');
        $this->addSql('DROP TABLE hoyoverse_game_profile');
    }
}
