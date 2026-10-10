<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009211558 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE list_anime ADD provider VARCHAR(50) NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (id, provider)');
        $this->addSql('ALTER TABLE list_manga ADD provider VARCHAR(50) NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (id, provider)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE list_anime DROP provider, DROP PRIMARY KEY, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE list_manga DROP provider, DROP PRIMARY KEY, ADD PRIMARY KEY (id)');
    }
}
