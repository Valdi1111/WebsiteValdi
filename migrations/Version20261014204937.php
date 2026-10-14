<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014204937 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE book_bookmark (id INT AUTO_INCREMENT NOT NULL, cfi LONGTEXT NOT NULL, label VARCHAR(255) DEFAULT NULL, chapter VARCHAR(255) DEFAULT NULL, fraction DOUBLE PRECISION DEFAULT 0 NOT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, book_id INT DEFAULT NULL, user_id INT DEFAULT NULL, INDEX FK_book_bookmark_book (book_id), INDEX FK_book_bookmark_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE book_note (id INT AUTO_INCREMENT NOT NULL, cfi LONGTEXT NOT NULL, text LONGTEXT DEFAULT NULL, note LONGTEXT DEFAULT NULL, color VARCHAR(32) DEFAULT \'#ffeb3b\' NOT NULL, chapter VARCHAR(255) DEFAULT NULL, section_index INT DEFAULT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, book_id INT NOT NULL, user_id INT NOT NULL, INDEX FK_book_note_book (book_id), INDEX FK_book_note_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE book_bookmark ADD CONSTRAINT FK_book_bookmark_book FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_bookmark ADD CONSTRAINT FK_book_bookmark_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_note ADD CONSTRAINT FK_book_note_book FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_note ADD CONSTRAINT FK_book_note_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE book_bookmark DROP FOREIGN KEY FK_book_bookmark_book');
        $this->addSql('ALTER TABLE book_bookmark DROP FOREIGN KEY FK_book_bookmark_user');
        $this->addSql('ALTER TABLE book_note DROP FOREIGN KEY FK_book_note_book');
        $this->addSql('ALTER TABLE book_note DROP FOREIGN KEY FK_book_note_user');
        $this->addSql('DROP TABLE book_bookmark');
        $this->addSql('DROP TABLE book_note');
    }
}
