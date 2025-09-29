<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250928115849 extends AbstractMigration
{
    private const array ROLES_HIERARCHY = [
        'ROLE_ADMIN_ANIME' => ['ROLE_USER_ANIME'],
        'ROLE_USER_ANIME' => ['ROLE_USER'],
        'ROLE_ADMIN_BOOKS' => ['ROLE_USER_BOOKS'],
        'ROLE_USER_BOOKS' => ['ROLE_USER'],
        'ROLE_ADMIN_PASSWORDS' => ['ROLE_USER_PASSWORDS'],
        'ROLE_USER_PASSWORDS' => ['ROLE_USER'],
        'ROLE_ADMIN_VIDEOS' => ['ROLE_USER_VIDEOS'],
        'ROLE_USER_VIDEOS' => ['ROLE_USER'],
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
