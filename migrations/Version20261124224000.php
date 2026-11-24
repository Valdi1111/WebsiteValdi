<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124224000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profile picture of the linked Telegram account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD telegram_picture VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP telegram_picture');
    }
}
