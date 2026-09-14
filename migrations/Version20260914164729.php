<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260914164729 extends AbstractMigration
{
    private const array ROLES_HIERARCHY = [
        'ROLE_ADMIN_HOYOVERSE' => ['ROLE_USER_HOYOVERSE'],
        'ROLE_USER_HOYOVERSE' => ['ROLE_USER'],
    ];

    public function getDescription(): string
    {
        return 'Add default Roles Hierarchy';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
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
        // this down() migration is auto-generated, please modify it to your needs
        $sql = "DELETE FROM `role_hierarchy` WHERE `parent_role_id` = (SELECT `id` FROM `role` WHERE `name` = ?) AND `child_role_id` = (SELECT `id` FROM `role` WHERE `name` = ?)";
        $types = [ParameterType::STRING, ParameterType::STRING];
        foreach (self::ROLES_HIERARCHY as $parentRole => $childRoles) {
            foreach ($childRoles as $childRole) {
                $this->connection->executeStatement($sql, [$parentRole, $childRole], $types);
            }
        }
    }
}
