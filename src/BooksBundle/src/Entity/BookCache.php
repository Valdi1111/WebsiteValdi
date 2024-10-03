<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookCacheRepository;
use Doctrine\ORM\Mapping as ORM;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'book_cache')]
#[ORM\Entity(repositoryClass: BookCacheRepository::class)]
class BookCache
{
    #[ORM\Id]
    #[ORM\OneToOne(inversedBy: 'bookCache', targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false)]
    private ?Book $book = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $cover = null;

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

    #[Groups(['book:list'])]
    public function getPages(): ?int
    {
        return $this->pages;
    }

    /**
     * @param CacheManager $cacheManager
     * @param string $filter
     * @return string|null
     */
    public function generateCoverThumbnail(CacheManager $cacheManager, string $filter): ?string
    {
        if ($this->getCover()) {
            return $cacheManager->getBrowserPath("/" . $this->getCover(), $filter);
        }
        return null;
    }

}
