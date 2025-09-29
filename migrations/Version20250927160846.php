<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250927160846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Edit Token entity';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE token DROP FOREIGN KEY FK_token_user');
        $this->addSql('ALTER TABLE token ADD class VARCHAR(100) NOT NULL, ADD username VARCHAR(200) NOT NULL, CHANGE id id INT AUTO_INCREMENT NOT NULL');
        $this->addSql('CREATE INDEX IDX_class_username ON token (class, username)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_class_username ON token');
        $this->addSql('ALTER TABLE token DROP class, DROP username, CHANGE id id INT NOT NULL');
        $this->addSql('ALTER TABLE token ADD CONSTRAINT FK_token_user FOREIGN KEY (id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE');
    }
}
