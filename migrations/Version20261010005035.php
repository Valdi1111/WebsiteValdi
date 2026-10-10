<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010005035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE episode_download_attempt (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(50) DEFAULT \'created\' NOT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, started DATETIME DEFAULT NULL, completed DATETIME DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, error_trace LONGTEXT DEFAULT NULL, episode_download_id INT DEFAULT NULL, INDEX FK_episode_download_episode_download_attempt (episode_download_id), INDEX IDX_state (state), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE episode_download_attempt ADD CONSTRAINT FK_episode_download_episode_download_attempt FOREIGN KEY (episode_download_id) REFERENCES episode_download (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_provider ON list_anime (provider)');
        $this->addSql('CREATE INDEX IDX_provider ON list_manga (provider)');
        $this->addSql('CREATE INDEX IDX_provider ON season_folder (provider)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE episode_download_attempt DROP FOREIGN KEY FK_episode_download_episode_download_attempt');
        $this->addSql('DROP TABLE episode_download_attempt');
        $this->addSql('DROP INDEX IDX_provider ON list_anime');
        $this->addSql('DROP INDEX IDX_provider ON list_manga');
        $this->addSql('DROP INDEX IDX_provider ON season_folder');
    }
}
