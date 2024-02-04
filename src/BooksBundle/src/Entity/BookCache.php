<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookCacheRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'book_cache')]
#[ORM\Entity(repositoryClass: BookCacheRepository::class)]
class BookCache
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $book_id = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $cover = null;

    #[ORM\Column]
    private array $navigation = [];

    #[ORM\Column]
    private array $locations = [];

    public function getBookId(): ?int
    {
        return $this->book_id;
    }

    public function setBookId(int $book_id): static
    {
        $this->book_id = $book_id;

        return $this;
    }

    public function getCover(): ?string
    {
        return $this->cover;
    }

    public function setCover(?string $cover): static
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
}
