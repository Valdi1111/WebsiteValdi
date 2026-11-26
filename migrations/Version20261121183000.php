<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widens the Gryphline session credentials to LONGTEXT (encrypted_text type): once DATABASE_ENCRYPTION_KEY is set
 * they are stored encrypted, longer than 255 characters. Schema only: existing values are converted by the
 * core:database:encrypt command, and down() needs them in plain text again (--decrypt).
 */
final class Version20261121183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen gryphline_account session_cred and session_salt to LONGTEXT for encrypted credentials';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gryphline_account CHANGE session_cred session_cred LONGTEXT DEFAULT NULL, CHANGE session_salt session_salt LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gryphline_account CHANGE session_cred session_cred VARCHAR(255) DEFAULT NULL, CHANGE session_salt session_salt VARCHAR(255) DEFAULT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
