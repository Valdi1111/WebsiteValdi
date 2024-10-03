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
    #[ORM\OneToOne(inversedBy: 'bookMetadata', targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false)]
    private ?Book $book = null;

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
    private ?string $modifiedDate = null;

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
    public function getPubdate(): ?string
    {
        return $this->pubdate;
    }

    public function setPubdate(string $pubdate): static
    {
        $this->pubdate = $pubdate;

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
    public function getModifiedDate(): ?string
    {
        return $this->modifiedDate;
    }

    public function setModifiedDate(string $modified_date): static
    {
        $this->modifiedDate = $modified_date;

        return $this;
    }

}
