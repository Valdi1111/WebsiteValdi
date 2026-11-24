<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds ROLE_ADMIN, the site-wide admin role (the users admin page), which implies ROLE_USER. It is not the parent of
 * the ROLE_ADMIN_* roles of the bundles. It is assigned to nobody: give it to the first admin by hand.
 * Clear core.security_hierarchy.cache after running it, the role hierarchy changes.
 */
final class Version20261124220130 extends AbstractMigration
{
    /** Site-wide roles: no bundle */
    private const array ROLES = [
        ['ROLE_ADMIN', 'Admin'],
    ];

    private const array ROLES_HIERARCHY = [
        'ROLE_ADMIN' => ['ROLE_USER'],
    ];

    public function getDescription(): string
    {
        return 'Add the site-wide ROLE_ADMIN role, parent of ROLE_USER';
    }

    public function up(Schema $schema): void
    {
        // addSql, not executeStatement: nothing runs with --dry-run
        // NULL in the SQL, not as a parameter: the SQL logger of the migrations cannot print a null parameter
        $sql = 'INSERT IGNORE INTO `role` (`name`, `description`, `bundle`) VALUES (?, ?, NULL)';
        $types = [ParameterType::STRING, ParameterType::STRING];
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

    public function down(Schema $schema): void
    {
        // The foreign keys of role_hierarchy, role_permission and user_role cascade
        foreach (self::ROLES as $role) {
            $this->addSql('DELETE FROM `role` WHERE `name` = ?', [$role[0]], [ParameterType::STRING]);
        }
    }
}
