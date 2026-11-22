<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pull rows can store whether an S was the rate-up item (is_up, null = unknown: every existing row), and HoYoverse
 * rows can store their pull number in the banner (pull_number, null on every existing and recorded row), which orders
 * the rows of one second that come from an official S-rank history. Schema only: no existing value is touched.
 */
final class Version20261122151328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_up to the pull tables and pull_number to hoyoverse_pull';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gryphline_pull ADD is_up TINYINT DEFAULT NULL');
        $this->addSql('ALTER TABLE hoyoverse_pull ADD is_up TINYINT DEFAULT NULL, ADD pull_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE perfectworld_pull ADD is_up TINYINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gryphline_pull DROP is_up');
        $this->addSql('ALTER TABLE hoyoverse_pull DROP is_up, DROP pull_number');
        $this->addSql('ALTER TABLE perfectworld_pull DROP is_up');
    }
}
