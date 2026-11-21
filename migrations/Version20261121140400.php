<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the PerfectworldBundle roles: ROLE_ADMIN_PERFECTWORLD implies ROLE_USER_PERFECTWORLD,
 * which implies ROLE_USER_GAMES (access to the unified games frontend).
 */
final class Version20261121140400 extends AbstractMigration
{
    private const array ROLES = [
        ['ROLE_ADMIN_PERFECTWORLD', 'Admin perfectworld', 'PerfectworldBundle'],
        ['ROLE_USER_PERFECTWORLD', 'User perfectworld', 'PerfectworldBundle'],
    ];

    private const array ROLES_HIERARCHY = [
        'ROLE_ADMIN_PERFECTWORLD' => ['ROLE_USER_PERFECTWORLD'],
        'ROLE_USER_PERFECTWORLD' => ['ROLE_USER_GAMES'],
    ];

    public function getDescription(): string
    {
        return 'Add PerfectworldBundle roles and link them to ROLE_USER_GAMES';
    }

    public function up(Schema $schema): void
    {
        $sql = "INSERT IGNORE INTO `role` (`name`, `description`, `bundle`) VALUES (?, ?, ?)";
        $types = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];
        foreach (self::ROLES as $role) {
            $this->connection->executeStatement($sql, $role, $types);
        }

        $sql = "INSERT IGNORE INTO `role_hierarchy` (`parent_role_id`, `child_role_id`) VALUES ((SELECT `id` FROM `role` WHERE `name` = ?), (SELECT `id` FROM `role` WHERE `name` = ?))";
        $types = [ParameterType::STRING, ParameterType::STRING];
        foreach (self::ROLES_HIERARCHY as $parentRole => $childRoles) {
            foreach ($childRoles as $childRole) {
                $this->connection->executeStatement($sql, [$parentRole, $childRole], $types);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $sql = "DELETE FROM `role_hierarchy` WHERE `parent_role_id` = (SELECT `id` FROM `role` WHERE `name` = ?) AND `child_role_id` = (SELECT `id` FROM `role` WHERE `name` = ?)";
        $types = [ParameterType::STRING, ParameterType::STRING];
        foreach (self::ROLES_HIERARCHY as $parentRole => $childRoles) {
            foreach ($childRoles as $childRole) {
                $this->connection->executeStatement($sql, [$parentRole, $childRole], $types);
            }
        }

        $sql = "DELETE FROM `role` WHERE `name` = ?";
        $types = [ParameterType::STRING];
        foreach (self::ROLES as $role) {
            $this->connection->executeStatement($sql, [$role[0]], $types);
        }
    }
}
