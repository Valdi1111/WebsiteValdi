<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookMetadataRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'book_metadata')]
#[ORM\Entity(repositoryClass: BookMetadataRepository::class)]
class BookMetadata
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Book::class, inversedBy: 'bookMetadata')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Book $book = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $identifier = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $creator = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publisher = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rights = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publication = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $modified = null;

    #[Ignore]
    public function getBook(): ?Book
    {
        return $this->book;
    }

    public function setBook(?Book $book): self
    {
        $this->book = $book;

        return $this;
    }

    public function getBookId(): ?int
    {
        return $this->getBook()?->getId();
    }

    #[Groups(['book:metadata'])]
    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    #[Groups(['book:list', 'book:metadata'])]
    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    #[Groups(['book:list', 'book:metadata'])]
    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(string $creator): static
    {
        $this->creator = $creator;

        return $this;
    }

    #[Groups(['book:metadata'])]
    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function setPublisher(string $publisher): static
    {
        $this->publisher = $publisher;

        return $this;
    }

    #[Groups(['book:metadata'])]
    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    #[Groups(['book:metadata'])]
    public function getRights(): ?string
    {
        return $this->rights;
    }

    public function setRights(string $rights): static
    {
        $this->rights = $rights;

        return $this;
    }

    #[Groups(['book:metadata'])]
    public function getPublication(): ?string
    {
        return $this->publication;
    }

    public function setPublication(string $publication): static
    {
        $this->publication = $publication;

        return $this;
    }

    #[Groups(['book:metadata'])]
    public function getModified(): ?string
    {
        return $this->modified;
    }

    public function setModified(string $modified): static
    {
        $this->modified = $modified;

        return $this;
    }

}
