<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Endfield headhunting records: the record kind (pulls and bonus entries such as the Headhunting Dossier), the free
 * Urgent Recruitment flag and the rerun number of the banner. Bonus entries may have no rarity, so rarity becomes
 * nullable.
 *
 * Only is_free needs existing rows: the sync stops at the first stored pull, so free pulls already stored are never
 * fetched again. Every stored row is a pull (bonus entries were discarded) and has no rerun number.
 */
final class Version20261126173559 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Endfield pulls: record kind, free Urgent Recruitment flag, rerun number, nullable rarity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE gryphline_pull ADD kind VARCHAR(32) DEFAULT 'draw' NOT NULL, ADD is_free TINYINT DEFAULT 0 NOT NULL, ADD pool_version SMALLINT DEFAULT NULL, CHANGE rarity rarity SMALLINT DEFAULT NULL");
        $this->addSql("UPDATE gryphline_pull SET is_free = 1 WHERE JSON_EXTRACT(raw_payload, '$.isFree') = true");
    }

    public function down(Schema $schema): void
    {
        // Bonus entries cannot go back to a NOT NULL rarity: they did not exist before this migration
        $this->addSql("DELETE FROM gryphline_pull WHERE kind <> 'draw'");
        $this->addSql('ALTER TABLE gryphline_pull DROP kind, DROP is_free, DROP pool_version, CHANGE rarity rarity SMALLINT NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
