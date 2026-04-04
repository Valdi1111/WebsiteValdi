<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251009153902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add episodeOffset to SeasonFolder';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE season_folder ADD episode_offset INT DEFAULT 0 NOT NULL AFTER folder');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE season_folder DROP episode_offset');
    }
}
