<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Endgames reminder mode: off (default) = only modes not played yet, on = also modes below the maximum.
 */
final class Version20261122031050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add endgames_full_clear to hoyoverse_game_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hoyoverse_game_profile ADD endgames_full_clear TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hoyoverse_game_profile DROP endgames_full_clear');
    }
}
