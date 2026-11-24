<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261124155108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discord account linked from the profile page: username, display name and avatar hash, unique Discord id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD discord_username VARCHAR(64) DEFAULT NULL, ADD discord_global_name VARCHAR(64) DEFAULT NULL, ADD discord_avatar VARCHAR(64) DEFAULT NULL');
        // No id set by hand so far is an empty string, which would clash between users; repeated NULLs do not
        $this->addSql('CREATE UNIQUE INDEX IDX_discord_user_id ON user (discord_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_discord_user_id ON user');
        $this->addSql('ALTER TABLE user DROP discord_username, DROP discord_global_name, DROP discord_avatar');
    }
}
