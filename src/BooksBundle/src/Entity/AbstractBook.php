<?php

namespace App\BooksBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\JoinColumn;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

#[ORM\MappedSuperclass]
class AbstractBook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $url = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $created;

    #[ORM\Column(nullable: true)]
    private ?int $shelf_id = null;

    #[ORM\OneToOne(targetEntity: BookMetadata::class)]
    #[JoinColumn(name: 'id', referencedColumnName: 'book_id')]
    private ?BookMetadata $bookMetadata = null;

    #[ORM\OneToOne(targetEntity: BookCache::class)]
    #[JoinColumn(name: 'id', referencedColumnName: 'book_id')]
    private ?BookCache $bookCache = null;

    #[ORM\OneToOne(targetEntity: BookProgress::class)]
    #[JoinColumn(name: 'id', referencedColumnName: 'book_id')]
    private ?BookProgress $bookProgress = null;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(\DateTimeInterface $created): static
    {
        $this->created = $created;

        return $this;
    }

    public function getShelfId(): ?int
    {
        return $this->shelf_id;
    }

    public function setShelfId(?int $shelf_id): static
    {
        $this->shelf_id = $shelf_id;

        return $this;
    }

    public function getBookMetadata(): ?BookMetadata
    {
        return $this->bookMetadata;
    }

    public function setBookMetadata(BookMetadata $bookMetadata): static
    {
        $this->bookMetadata = $bookMetadata;

        return $this;
    }

    public function getBookCache(): ?BookCache
    {
        return $this->bookCache;
    }

    public function setBookCache(BookCache $bookCache): static
    {
        $this->bookCache = $bookCache;

        return $this;
    }

    public function getBookProgress(): ?BookProgress
    {
        return $this->bookProgress;
    }

    public function setBookProgress(BookProgress $bookProgress): static
    {
        $this->bookProgress = $bookProgress;

        return $this;
    }

    /**
     * @param CacheManager $cacheManager
     * @param string $cover
     * @return array
     */
    public function toJsonMetadata(CacheManager $cacheManager, string $cover = 'cover'): array
    {
        $json = $this->getBookMetadata()->toJson();
        $json['cover'] = $this->generateCoverThumbnail($cacheManager, "books_$cover");
        return $json;
    }

    /**
     * @param CacheManager $cacheManager
     * @param string $cover
     * @param bool $fullCache
     * @param bool $fullProgress
     * @return array
     */
    public function toJson(CacheManager $cacheManager, string $cover = 'thumb', bool $fullCache = false, bool $fullProgress = false): array
    {
        $json = [
            'id' => $this->getId(),
            'url' => $this->getUrl(),
            'shelf_id' => $this->getShelfId(),
            'book_cache' => [
                'cover' => $this->generateCoverThumbnail($cacheManager, "books_$cover"),
            ],
            'book_metadata' => [
                'title' => $this->getBookMetadata()->getTitle(),
                'creator' => $this->getBookMetadata()->getCreator(),
            ],
            'book_progress' => [
                'page' => $this->getBookProgress()->getPage(),
                'total' => count($this->getBookCache()->getLocations()),
            ],
        ];
        if($fullCache) {
            $json['book_cache']['navigation'] = $this->getBookCache()->getNavigation();
            $json['book_cache']['locations'] = $this->getBookCache()->getLocations();
        }
        if($fullProgress) {
            $json['book_progress']['position'] = $this->getBookProgress()->getPosition();
        }
        return $json;
    }

    /**
     * @param CacheManager $cacheManager
     * @param string $filter
     * @return string|null
     */
    public function generateCoverThumbnail(CacheManager $cacheManager, string $filter): ?string
    {
        if ($this->getBookCache()->getCover()) {
            return $cacheManager->getBrowserPath("/" . $this->getBookCache()->getCover(), $filter);
        }
        return null;
    }
}
