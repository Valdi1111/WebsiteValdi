<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010221337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE episode_download_tracker (id INT AUTO_INCREMENT NOT NULL, tracker VARCHAR(50) NOT NULL, tracker_id INT UNSIGNED NOT NULL, `default` TINYINT DEFAULT 0 NOT NULL, episode_download_id INT DEFAULT NULL, INDEX FK_episode_download (episode_download_id), INDEX IDX_episode_download_is_trigger (episode_download_id, `is_trigger`), INDEX IDX_tracker_tracker_id (tracker, tracker_id), INDEX IDX_is_trigger (`is_trigger`), UNIQUE INDEX IDX_episode_download_tracker (episode_download_id, tracker), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE episode_download_tracker ADD CONSTRAINT FK_episode_download FOREIGN KEY (episode_download_id) REFERENCES episode_download (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE episode_download_tracker DROP FOREIGN KEY FK_episode_download');
        $this->addSql('DROP TABLE episode_download_tracker');
    }
}
