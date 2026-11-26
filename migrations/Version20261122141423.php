<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Endgames Reminder settings per mode and variant (off / not_played / full_clear). Null = the defaults declared by
 * the game, so existing profiles need no data.
 */
final class Version20261122141423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add endgames_settings to hoyoverse_game_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hoyoverse_game_profile ADD endgames_settings JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hoyoverse_game_profile DROP endgames_settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
