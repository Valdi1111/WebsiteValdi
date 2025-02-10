<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250210201927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BooksBundle Tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE book (id INT AUTO_INCREMENT NOT NULL, shelf_id INT DEFAULT NULL, library_id INT DEFAULT NULL, url VARCHAR(255) NOT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX FK_book_shelf (shelf_id), INDEX FK_book_library (library_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE book_cache (book_id INT NOT NULL, cover TINYINT(1) DEFAULT 0 NOT NULL, navigation JSON NOT NULL, locations JSON NOT NULL, pages INT DEFAULT NULL, PRIMARY KEY(book_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE book_metadata (book_id INT NOT NULL, identifier VARCHAR(255) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, creator VARCHAR(255) DEFAULT NULL, publisher VARCHAR(255) DEFAULT NULL, language VARCHAR(255) DEFAULT NULL, rights VARCHAR(255) DEFAULT NULL, publication VARCHAR(255) DEFAULT NULL, modified VARCHAR(255) DEFAULT NULL, PRIMARY KEY(book_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE book_progress (book_id INT NOT NULL, user_id INT NOT NULL, position VARCHAR(255) DEFAULT NULL, page INT DEFAULT 0 NOT NULL, last_read DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX FK_book_progress_book (book_id), INDEX FK_book_progress_user (user_id), PRIMARY KEY(book_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE library (id INT AUTO_INCREMENT NOT NULL, base_path VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX IDX_name (name), UNIQUE INDEX IDX_base_path (base_path), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE shelf (id INT AUTO_INCREMENT NOT NULL, library_id INT DEFAULT NULL, path VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX FK_shelf_library (library_id), UNIQUE INDEX IDX_name (name), UNIQUE INDEX IDX_path (path), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE book ADD CONSTRAINT FK_book_shelf FOREIGN KEY (shelf_id) REFERENCES shelf (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE book ADD CONSTRAINT FK_book_library FOREIGN KEY (library_id) REFERENCES library (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE book_cache ADD CONSTRAINT FK_book_cache_book FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_metadata ADD CONSTRAINT FK_book_metadata_book FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_progress ADD CONSTRAINT FK_book_progress_book FOREIGN KEY (book_id) REFERENCES book (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_progress ADD CONSTRAINT FK_book_progress_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shelf ADD CONSTRAINT FK_shelf_library FOREIGN KEY (library_id) REFERENCES library (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE book DROP FOREIGN KEY FK_book_shelf');
        $this->addSql('ALTER TABLE book DROP FOREIGN KEY FK_book_library');
        $this->addSql('ALTER TABLE book_cache DROP FOREIGN KEY FK_book_cache_book');
        $this->addSql('ALTER TABLE book_metadata DROP FOREIGN KEY FK_book_metadata_book');
        $this->addSql('ALTER TABLE book_progress DROP FOREIGN KEY FK_book_progress_book');
        $this->addSql('ALTER TABLE book_progress DROP FOREIGN KEY FK_book_progress_user');
        $this->addSql('ALTER TABLE shelf DROP FOREIGN KEY FK_shelf_library');
        $this->addSql('DROP TABLE book');
        $this->addSql('DROP TABLE book_cache');
        $this->addSql('DROP TABLE book_metadata');
        $this->addSql('DROP TABLE book_progress');
        $this->addSql('DROP TABLE library');
        $this->addSql('DROP TABLE shelf');
    }
}
