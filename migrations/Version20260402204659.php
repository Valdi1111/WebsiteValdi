<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260402204659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add EpisodeRelease entity';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE episode_release (id INT AUTO_INCREMENT NOT NULL, episode_url VARCHAR(255) NOT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, service_name VARCHAR(255) NOT NULL, UNIQUE INDEX `IDX_episode_service` (`episode_url`, `service_name`), INDEX IDX_episode_url (episode_url), INDEX IDX_service_name (service_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE episode_release');
    }
}
