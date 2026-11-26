<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds user.disabled_at: when an admin disabled the account, null while it is enabled.
 */
final class Version20261124220118 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.disabled_at for the accounts disabled by an admin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD disabled_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP disabled_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
