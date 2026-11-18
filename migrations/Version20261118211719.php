<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118211719 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE gryphline_account ADD account_uid VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_gryphline_account_user_uid ON gryphline_account (user_id, account_uid)');
        $this->addSql('ALTER TABLE hoyoverse_account ADD account_uid VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_hoyoverse_account_user_uid ON hoyoverse_account (user_id, account_uid)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_gryphline_account_user_uid ON gryphline_account');
        $this->addSql('ALTER TABLE gryphline_account DROP account_uid');
        $this->addSql('DROP INDEX IDX_hoyoverse_account_user_uid ON hoyoverse_account');
        $this->addSql('ALTER TABLE hoyoverse_account DROP account_uid');
    }
}
