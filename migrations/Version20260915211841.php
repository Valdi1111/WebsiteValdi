<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915211841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add HoyoverseDiaryEntry';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hoyoverse_diary_entry (id INT AUTO_INCREMENT NOT NULL, period VARCHAR(7) NOT NULL, currency VARCHAR(50) NOT NULL, recorded_at DATETIME NOT NULL, amount INT NOT NULL, action_key VARCHAR(64) DEFAULT NULL, action_name VARCHAR(128) DEFAULT NULL, hoyoverse_game_profile_id INT NOT NULL, INDEX FK_hoyoverse_diary_entry_hoyoverse_game_profile (hoyoverse_game_profile_id), INDEX IDX_hoyoverse_game_profile_id_period_currency (hoyoverse_game_profile_id, period, currency), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE hoyoverse_diary_entry ADD CONSTRAINT FK_hoyoverse_diary_entry_hoyoverse_game_profile FOREIGN KEY (hoyoverse_game_profile_id) REFERENCES hoyoverse_game_profile (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hoyoverse_diary_entry DROP FOREIGN KEY FK_hoyoverse_diary_entry_hoyoverse_game_profile');
        $this->addSql('DROP TABLE hoyoverse_diary_entry');
    }
}
