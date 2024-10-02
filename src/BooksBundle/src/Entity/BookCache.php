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
    #[Ignore]
    #[ORM\Id]
    #[ORM\Column]
    private ?int $bookId = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $cover = null;

    #[ORM\Column]
    private array $navigation = [];

    #[ORM\Column]
    private array $locations = [];

    #[Groups(['book:list'])]
    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    private ?int $pages = null;

    public function getBookId(): ?int
    {
        return $this->bookId;
    }

    public function setBookId(int $bookId): static
    {
        $this->bookId = $bookId;

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

    public function getPages(): ?int
    {
        return $this->pages;
    }

}
