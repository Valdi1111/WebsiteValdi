<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\JoinColumn;

#[ORM\Table(name: 'book')]
#[ORM\Entity(repositoryClass: BookRepository::class)]
class Book implements \JsonSerializable
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

    /**
     * @return BookMetadata|null
     */
    public function getBookMetadata(): ?BookMetadata
    {
        return $this->bookMetadata;
    }

    /**
     * @return BookCache|null
     */
    public function getBookCache(): ?BookCache
    {
        return $this->bookCache;
    }

    /**
     * @return BookProgress|null
     */
    public function getBookProgress(): ?BookProgress
    {
        return $this->bookProgress;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'url' => $this->getUrl(),
            'shelf_id' => $this->getShelfId(),
            'book_cache' => [
                'cover' => $this->getBookCache()->getCover(),
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
    }
}
