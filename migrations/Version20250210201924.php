<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250210201924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AnimeBundle Tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE episode_download (id INT AUTO_INCREMENT NOT NULL, episode_url VARCHAR(255) NOT NULL, episode VARCHAR(16) DEFAULT NULL, download_url VARCHAR(255) DEFAULT NULL, state VARCHAR(50) DEFAULT \'created\' NOT NULL, folder VARCHAR(255) DEFAULT NULL, file VARCHAR(255) DEFAULT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', started DATETIME DEFAULT NULL, completed DATETIME DEFAULT NULL, mal_id INT UNSIGNED DEFAULT NULL, al_id INT UNSIGNED DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE list_anime (id INT NOT NULL, title VARCHAR(255) DEFAULT \'\' NOT NULL, title_en VARCHAR(255) DEFAULT \'\' NOT NULL, nsfw VARCHAR(50) DEFAULT \'white\' NOT NULL, media_type VARCHAR(50) DEFAULT \'unknown\' NOT NULL, num_episodes INT DEFAULT 0 NOT NULL, status VARCHAR(50) DEFAULT \'watching\' NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE list_manga (id INT NOT NULL, title VARCHAR(255) DEFAULT \'\' NOT NULL, title_en VARCHAR(255) DEFAULT \'\' NOT NULL, nsfw VARCHAR(50) DEFAULT \'white\' NOT NULL, media_type VARCHAR(50) DEFAULT \'unknown\' NOT NULL, num_volumes INT DEFAULT 0 NOT NULL, num_chapters INT DEFAULT 0 NOT NULL, status VARCHAR(50) DEFAULT \'reading\' NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE season_folder (id INT NOT NULL, folder VARCHAR(255) NOT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE episode_download');
        $this->addSql('DROP TABLE list_anime');
        $this->addSql('DROP TABLE list_manga');
        $this->addSql('DROP TABLE season_folder');
    }
}
