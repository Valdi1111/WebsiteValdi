<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250928113435 extends AbstractMigration
{
    private const array ROLES = [
        ['ROLE_ADMIN_ANIME', 'Admin anime', 'AnimeBundle'],
        ['ROLE_USER_ANIME', 'User anime', 'AnimeBundle'],
        ['ROLE_ADMIN_BOOKS', 'Admin books', 'BooksBundle'],
        ['ROLE_USER_BOOKS', 'User books', 'BooksBundle'],
        ['ROLE_ADMIN_PASSWORDS', 'Admin passwords', 'PasswordsBundle'],
        ['ROLE_USER_PASSWORDS', 'User passwords', 'PasswordsBundle'],
        ['ROLE_ADMIN_VIDEOS', 'Admin videos', 'VideosBundle'],
        ['ROLE_USER_VIDEOS', 'User videos', 'VideosBundle'],
        ['ROLE_USER', 'User', null],
    ];

    public function getDescription(): string
    {
        return 'Add default Roles';
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
