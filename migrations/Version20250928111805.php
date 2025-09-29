<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250928111805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Role and Permission entities';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE permission (id INT AUTO_INCREMENT NOT NULL, namespace VARCHAR(100) NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL, bundle VARCHAR(100) DEFAULT NULL, UNIQUE INDEX IDX_namespace_name (namespace, name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE role (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL, bundle VARCHAR(100) DEFAULT NULL, UNIQUE INDEX IDX_name (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE role_hierarchy (id INT AUTO_INCREMENT NOT NULL, parent_role_id INT NOT NULL, child_role_id INT NOT NULL, INDEX FK_role_hierarchy_parent_role (parent_role_id), INDEX FK_role_hierarchy_child_role (child_role_id), UNIQUE INDEX IDX_parent_role_child_role (parent_role_id, child_role_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE role_permission (id INT AUTO_INCREMENT NOT NULL, role_id INT NOT NULL, permission_id INT NOT NULL, INDEX FK_role_permission_role (role_id), INDEX FK_role_permission_permission (permission_id), UNIQUE INDEX IDX_role_permission (role_id, permission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_role (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, role_id INT NOT NULL, INDEX FK_user_role_user (user_id), INDEX FK_user_role_role (role_id), UNIQUE INDEX IDX_user_role (user_id, role_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE role_hierarchy ADD CONSTRAINT FK_role_hierarchy_parent_role FOREIGN KEY (parent_role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_hierarchy ADD CONSTRAINT FK_role_hierarchy_child_role FOREIGN KEY (child_role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permission ADD CONSTRAINT FK_role_permission_role FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permission ADD CONSTRAINT FK_role_permission_permission FOREIGN KEY (permission_id) REFERENCES permission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_role ADD CONSTRAINT FK_user_role_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_role ADD CONSTRAINT FK_user_role_role FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user DROP roles');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE role_hierarchy DROP FOREIGN KEY FK_role_hierarchy_parent_role');
        $this->addSql('ALTER TABLE role_hierarchy DROP FOREIGN KEY FK_role_hierarchy_child_role');
        $this->addSql('ALTER TABLE role_permission DROP FOREIGN KEY FK_role_permission_role');
        $this->addSql('ALTER TABLE role_permission DROP FOREIGN KEY FK_role_permission_permission');
        $this->addSql('ALTER TABLE user_role DROP FOREIGN KEY FK_user_role_user');
        $this->addSql('ALTER TABLE user_role DROP FOREIGN KEY FK_user_role_role');
        $this->addSql('DROP TABLE permission');
        $this->addSql('DROP TABLE role');
        $this->addSql('DROP TABLE role_hierarchy');
        $this->addSql('DROP TABLE role_permission');
        $this->addSql('DROP TABLE user_role');
        $this->addSql('ALTER TABLE user ADD roles JSON NOT NULL');
    }
}
