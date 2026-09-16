<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915233147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lastCookieRefreshedAt, cookieRefreshFailed to HoyoverseAccount';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hoyoverse_account ADD last_cookie_refreshed_at DATETIME DEFAULT NULL, ADD cookie_refresh_failed TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hoyoverse_account DROP last_cookie_refreshed_at, DROP cookie_refresh_failed');
    }
}
