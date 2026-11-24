<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124210115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Telegram account linked from the profile page: username and name, unique Telegram id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD telegram_username VARCHAR(64) DEFAULT NULL, ADD telegram_name VARCHAR(64) DEFAULT NULL');
        // Fails on the same id set by hand on two users, or on empty strings: set them to NULL first
        $this->addSql('CREATE UNIQUE INDEX IDX_telegram_chat_id ON user (telegram_chat_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_telegram_chat_id ON user');
        $this->addSql('ALTER TABLE user DROP telegram_username, DROP telegram_name');
    }
}
