<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the roles of the removed PasswordsBundle. The foreign keys of role_hierarchy, role_permission and
 * user_role cascade, so their rows go with the roles. down() restores the roles and their hierarchy, not the
 * users they were assigned to. Clear core.security_hierarchy.cache after running it.
 */
final class Version20261122225140 extends AbstractMigration
{
    private const array ROLES = [
        ['ROLE_ADMIN_PASSWORDS', 'Admin passwords', 'PasswordsBundle'],
        ['ROLE_USER_PASSWORDS', 'User passwords', 'PasswordsBundle'],
    ];

    private const array ROLES_HIERARCHY = [
        'ROLE_ADMIN_PASSWORDS' => ['ROLE_USER_PASSWORDS'],
        'ROLE_USER_PASSWORDS' => ['ROLE_USER'],
    ];

    public function getDescription(): string
    {
        return 'Remove the PasswordsBundle roles';
    }

    public function up(Schema $schema): void
    {
        // addSql, not executeStatement: nothing runs with --dry-run
        foreach (self::ROLES as $role) {
            $this->addSql('DELETE FROM `role` WHERE `name` = ?', [$role[0]], [ParameterType::STRING]);
        }
    }

    public function down(Schema $schema): void
    {
        $sql = 'INSERT IGNORE INTO `role` (`name`, `description`, `bundle`) VALUES (?, ?, ?)';
        $types = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];
        foreach (self::ROLES as $role) {
            $this->addSql($sql, $role, $types);
        }

        $sql = 'INSERT IGNORE INTO `role_hierarchy` (`parent_role_id`, `child_role_id`) VALUES ((SELECT `id` FROM `role` WHERE `name` = ?), (SELECT `id` FROM `role` WHERE `name` = ?))';
        $types = [ParameterType::STRING, ParameterType::STRING];
        foreach (self::ROLES_HIERARCHY as $parentRole => $childRoles) {
            foreach ($childRoles as $childRole) {
                $this->addSql($sql, [$parentRole, $childRole], $types);
            }
        }
    }
}
