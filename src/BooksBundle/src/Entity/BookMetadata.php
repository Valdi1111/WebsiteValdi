<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookMetadataRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'book_metadata')]
#[ORM\Entity(repositoryClass: BookMetadataRepository::class)]
class BookMetadata
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $book_id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $identifier = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $creator = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pubdate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publisher = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rights = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $modified_date = null;

    public function getBookId(): ?int
    {
        return $this->book_id;
    }

    public function setBookId(int $book_id): static
    {
        $this->book_id = $book_id;

        return $this;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(string $creator): static
    {
        $this->creator = $creator;

        return $this;
    }

    public function getPubdate(): ?string
    {
        return $this->pubdate;
    }

    public function setPubdate(string $pubdate): static
    {
        $this->pubdate = $pubdate;

        return $this;
    }

    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function setPublisher(string $publisher): static
    {
        $this->publisher = $publisher;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getRights(): ?string
    {
        return $this->rights;
    }

    public function setRights(string $rights): static
    {
        $this->rights = $rights;

        return $this;
    }

    public function getModifiedDate(): ?string
    {
        return $this->modified_date;
    }

    public function setModifiedDate(string $modified_date): static
    {
        $this->modified_date = $modified_date;

        return $this;
    }

    public function toJson(): array
    {
        return [
            'title' => $this->getTitle(),
            'creator' => $this->getCreator(),
            'publisher' => $this->getPublisher(),
            'pubdate' => $this->getPubdate(),
            'modified_date' => $this->getModifiedDate(),
            'language' => $this->getLanguage(),
            'identifier' => $this->getIdentifier(),
            'rights' => $this->getRights(),
        ];
    }

    public function fromJson(array $json): static
    {
        if(array_key_exists('title', $json)) {
            $this->setTitle($json['title']);
        }
        if(array_key_exists('creator', $json)) {
            $this->setCreator($json['creator']);
        }
        if(array_key_exists('publisher', $json)) {
            $this->setPublisher($json['publisher']);
        }
        if(array_key_exists('pubdate', $json)) {
            $this->setPubdate($json['pubdate']);
        }
        if(array_key_exists('modified_date', $json)) {
            $this->setModifiedDate($json['modified_date']);
        }
        if(array_key_exists('language', $json)) {
            $this->setLanguage($json['language']);
        }
        if(array_key_exists('identifier', $json)) {
            $this->setIdentifier($json['identifier']);
        }
        if(array_key_exists('rights', $json)) {
            $this->setRights($json['rights']);
        }
        return $this;
    }
}
