<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Missing pulls: every pull row gets an origin (recorded, manual S or filler placeholder) and S rows can store
 * the real pity declared by the user. Existing rows become "recorded". Gryphline item_id becomes nullable for
 * filler rows and free-text manual S. down() fails if manual or filler rows without item_id exist: delete them first.
 */
final class Version20261121233050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add origin and declared_pity to the pull tables, make gryphline_pull.item_id nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gryphline_pull ADD origin VARCHAR(16) DEFAULT \'recorded\' NOT NULL, ADD declared_pity SMALLINT DEFAULT NULL, CHANGE item_id item_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE hoyoverse_pull ADD origin VARCHAR(16) DEFAULT \'recorded\' NOT NULL, ADD declared_pity SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE perfectworld_pull ADD origin VARCHAR(16) DEFAULT \'recorded\' NOT NULL, ADD declared_pity SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gryphline_pull DROP origin, DROP declared_pity, CHANGE item_id item_id VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE hoyoverse_pull DROP origin, DROP declared_pity');
        $this->addSql('ALTER TABLE perfectworld_pull DROP origin, DROP declared_pity');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
