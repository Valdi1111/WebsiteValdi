<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123180127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API tokens: SHA-256 of the value to find them, value as encrypted_text (LONGTEXT), description';
    }

    public function up(Schema $schema): void
    {
        // The value becomes encrypted_text: an encrypted value cannot be indexed, the hash takes its place
        $this->addSql('DROP INDEX IDX_access_token ON token');
        // token_hash is filled before it becomes NOT NULL and unique: with two rows, an empty default would clash
        $this->addSql('ALTER TABLE token ADD token_hash CHAR(64) DEFAULT NULL, ADD description VARCHAR(255) DEFAULT NULL, CHANGE access_token access_token LONGTEXT NOT NULL');
        // Same as Token::hashAccessToken(): lowercase hexadecimal SHA-256 of the UTF-8 value
        $this->addSql('UPDATE token SET token_hash = SHA2(access_token, 256)');
        $this->addSql('ALTER TABLE token CHANGE token_hash token_hash CHAR(64) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX IDX_token_hash ON token (token_hash)');
    }

    public function down(Schema $schema): void
    {
        // Encrypted values do not fit in VARCHAR(255) and could not be read back: core:database:encrypt --decrypt first
        $this->abortIf(
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM token WHERE access_token LIKE 'enc:v1:%'") > 0,
            'Some API tokens are encrypted: run "php bin/console core:database:encrypt --decrypt" first.',
        );

        $this->addSql('DROP INDEX IDX_token_hash ON token');
        $this->addSql('ALTER TABLE token DROP token_hash, DROP description, CHANGE access_token access_token VARCHAR(255) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX IDX_access_token ON token (access_token)');
    }
}
