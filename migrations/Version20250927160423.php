<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250927160423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes to tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX IDX_url ON book (url)');
        $this->addSql('CREATE INDEX IDX_state ON episode_download (state)');
        $this->addSql('CREATE INDEX IDX_mal_id ON episode_download (mal_id)');
        $this->addSql('CREATE INDEX IDX_al_id ON episode_download (al_id)');
        $this->addSql('CREATE INDEX IDX_service_name ON episode_download (service_name)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_url ON book');
        $this->addSql('DROP INDEX IDX_state ON episode_download');
        $this->addSql('DROP INDEX IDX_mal_id ON episode_download');
        $this->addSql('DROP INDEX IDX_al_id ON episode_download');
        $this->addSql('DROP INDEX IDX_service_name ON episode_download');
    }
}
