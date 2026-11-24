<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Groups the roles of the game providers (Hoyoverse, Gryphline, Perfect World) under GamesBundle, next to
 * ROLE_USER_GAMES: the profile page and the users admin page group the roles by bundle. role.bundle is only shown,
 * no access check reads it, and the cached role hierarchy does not hold it: nothing to clear.
 */
final class Version20261124231711 extends AbstractMigration
{
    private const string GAMES_BUNDLE = 'GamesBundle';

    /** Role name => bundle before this migration */
    private const array ROLES = [
        'ROLE_ADMIN_HOYOVERSE' => 'HoyoverseBundle',
        'ROLE_USER_HOYOVERSE' => 'HoyoverseBundle',
        'ROLE_ADMIN_GRYPHLINE' => 'GryphlineBundle',
        'ROLE_USER_GRYPHLINE' => 'GryphlineBundle',
        'ROLE_ADMIN_PERFECTWORLD' => 'PerfectworldBundle',
        'ROLE_USER_PERFECTWORLD' => 'PerfectworldBundle',
    ];

    public function getDescription(): string
    {
        return 'Group the Hoyoverse, Gryphline and Perfect World roles under GamesBundle';
    }

    public function up(Schema $schema): void
    {
        foreach (array_keys(self::ROLES) as $name) {
            $this->addSql('UPDATE `role` SET `bundle` = ? WHERE `name` = ?', [self::GAMES_BUNDLE, $name], [ParameterType::STRING, ParameterType::STRING]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ROLES as $name => $bundle) {
            $this->addSql('UPDATE `role` SET `bundle` = ? WHERE `name` = ?', [$bundle, $name], [ParameterType::STRING, ParameterType::STRING]);
        }
    }
}
