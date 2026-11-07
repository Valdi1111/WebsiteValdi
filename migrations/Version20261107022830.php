<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107022830 extends AbstractMigration
{
    private const array ROLES = [
        ['ROLE_ADMIN_GRYPHLINE', 'Admin gryphline', 'GryphlineBundle'],
        ['ROLE_USER_GRYPHLINE', 'User gryphline', 'GryphlineBundle'],
    ];

    public function getDescription(): string
    {
        return 'Add default Gryphline Roles';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $sql = "INSERT IGNORE INTO `role` (`name`, `description`, `bundle`) VALUES (?, ?, ?)";
        $types = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];
        foreach (self::ROLES as $role) {
            $this->connection->executeStatement($sql, $role, $types);
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $sql = "DELETE FROM `role` WHERE `name` = ?";
        $types = [ParameterType::STRING];
        foreach (self::ROLES as $role) {
            $this->connection->executeStatement($sql, [$role[0]], $types);
        }
    }
}
