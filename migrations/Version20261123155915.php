<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123155915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the user_avatar table (profile images, one per user, deleted with it)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_avatar (name CHAR(32) NOT NULL, content MEDIUMBLOB NOT NULL, updated_at DATETIME NOT NULL, id INT NOT NULL, UNIQUE INDEX IDX_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_avatar ADD CONSTRAINT FK_73256912BF396750 FOREIGN KEY (id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_avatar DROP FOREIGN KEY FK_73256912BF396750');
        $this->addSql('DROP TABLE user_avatar');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
