<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010162203 extends AbstractMigration
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
        $this->addSql('ALTER TABLE episode_download ADD original_episode VARCHAR(32) DEFAULT NULL, ADD episodes JSON DEFAULT NULL, ADD original_file VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE list_anime ADD tracker VARCHAR(50) NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (id, tracker)');
        $this->addSql('CREATE INDEX IDX_tracker ON list_anime (tracker)');
        $this->addSql('ALTER TABLE list_manga ADD tracker VARCHAR(50) NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (id, tracker)');
        $this->addSql('CREATE INDEX IDX_tracker ON list_manga (tracker)');
        $this->addSql('ALTER TABLE season_folder ADD tracker VARCHAR(50) NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (id, tracker)');
        $this->addSql('CREATE INDEX IDX_tracker ON season_folder (tracker)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE episode_download_attempt DROP FOREIGN KEY FK_episode_download_episode_download_attempt');
        $this->addSql('DROP TABLE episode_download_attempt');
        $this->addSql('ALTER TABLE episode_download DROP original_episode, DROP episodes, DROP original_file');
        $this->addSql('DROP INDEX IDX_tracker ON list_anime');
        $this->addSql('ALTER TABLE list_anime DROP tracker, DROP PRIMARY KEY, ADD PRIMARY KEY (id)');
        $this->addSql('DROP INDEX IDX_tracker ON list_manga');
        $this->addSql('ALTER TABLE list_manga DROP tracker, DROP PRIMARY KEY, ADD PRIMARY KEY (id)');
        $this->addSql('DROP INDEX IDX_tracker ON season_folder');
        $this->addSql('ALTER TABLE season_folder DROP tracker, DROP PRIMARY KEY, ADD PRIMARY KEY (id)');
    }
}
