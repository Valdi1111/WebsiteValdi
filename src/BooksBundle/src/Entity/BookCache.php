<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookCacheRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'book_cache')]
#[ORM\Entity(repositoryClass: BookCacheRepository::class)]
class BookCache
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Book::class, inversedBy: 'bookCache')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Book $book = null;

    #[ORM\Column(options: ["default" => "0"])]
    private bool $cover = false;

    #[ORM\Column]
    private array $navigation = [];

    #[ORM\Column]
    private array $locations = [];

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    private ?int $pages = null;

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

    #[Groups(['book:list', 'book:metadata'])]
    public function hasCover(): bool
    {
        return $this->cover;
    }

    public function setCover(bool $cover): static
    {
        $this->cover = $cover;

        return $this;
    }

    public function getNavigation(): array
    {
        return $this->navigation;
    }

    public function setNavigation(array $navigation): static
    {
        $this->navigation = $navigation;

        return $this;
    }

    public function getLocations(): array
    {
        return $this->locations;
    }

    public function setLocations(array $locations): static
    {
        $this->locations = $locations;

        return $this;
    }

    #[Groups(['book:list'])]
    public function getPages(): ?int
    {
        return $this->pages;
    }

}
