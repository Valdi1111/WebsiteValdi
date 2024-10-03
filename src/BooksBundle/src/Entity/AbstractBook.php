<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Normalizer\CollectionBookProgressNormalizer;
use App\CoreBundle\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToMany;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

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

    #[Groups(['book:list'])]
    #[ORM\Column(nullable: true)]
    private ?int $shelfId = null;

    #[ORM\OneToOne(targetEntity: BookMetadata::class, cascade: ['persist', 'remove'])]
    #[JoinColumn(name: 'id', referencedColumnName: 'book_id')]
    private ?BookMetadata $metadata = null;

    #[ORM\OneToOne(targetEntity: BookCache::class, cascade: ['persist', 'remove'])]
    #[JoinColumn(name: 'id', referencedColumnName: 'book_id')]
    private ?BookCache $cache = null;

    /** @var Collection<int, BookProgress> */
    #[OneToMany(mappedBy: 'book', targetEntity: BookProgress::class, cascade: ['persist', 'remove'], fetch: 'EAGER', indexBy: 'user_id')]
    private Collection $progresses;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->progresses = new ArrayCollection();
    }

    #[Groups(['book:list'])]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[Groups(['book:list'])]
    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    #[Groups(['book:list'])]
    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(\DateTimeInterface $created): static
    {
        $this->created = $created;

        return $this;
    }

    #[Groups(['book:list'])]
    public function getShelfId(): ?int
    {
        return $this->shelfId;
    }

    public function setShelfId(?int $shelfId): static
    {
        $this->shelfId = $shelfId;

        return $this;
    }

    #[Groups(['book:list'])]
    #[SerializedName('book_metadata')]
    public function getMetadata(): ?BookMetadata
    {
        return $this->metadata;
    }

    public function setMetadata(BookMetadata $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    #[Groups(['book:list'])]
    #[SerializedName('book_cache')]
    public function getCache(): ?BookCache
    {
        return $this->cache;
    }

    public function setCache(BookCache $cache): static
    {
        $this->cache = $cache;

        return $this;
    }

    /** @return Collection<int, BookProgress> */
    #[Groups(['book:list'])]
    #[SerializedName('book_progress')]
    #[Context(normalizationContext: [CollectionBookProgressNormalizer::SERIALIZE => true])]
    public function getProgresses(): Collection
    {
        return $this->progresses;
    }

    public function getProgress(User $user): ?BookProgress
    {
        return $this->getProgresses()->get($user->getId());
    }

    public function addProgress(BookProgress $progress): self
    {
        if (!$this->getProgresses()->contains($progress)) {
            $this->progresses[] = $progress;
            $progress->setBook($this);
        }

        return $this;
    }

    public function removeProgress(BookProgress $progress): self
    {
        if ($this->getProgresses()->removeElement($progress)) {
            if ($progress->getBook() === $this) {
                $progress->setBook(null);
            }
        }

        return $this;
    }

    /**
     * @param CacheManager $cacheManager
     * @param string $cover
     * @return array
     */
    public function toJsonMetadata(CacheManager $cacheManager, string $cover = 'cover'): array
    {
        $json = $this->getMetadata()->toJson();
        $json['cover'] = $this->generateCoverThumbnail($cacheManager, "books_$cover");
        return $json;
    }

    /**
     * @param User $user
     * @param CacheManager $cacheManager
     * @param string $cover
     * @param bool $fullCache
     * @param bool $fullProgress
     * @return array
     */
    public function toJson(User $user, CacheManager $cacheManager, string $cover = 'thumb', bool $fullCache = false, bool $fullProgress = false): array
    {
        $bp = $this->getProgress($user);
        $json = [
            'id' => $this->getId(),
            'url' => $this->getUrl(),
            'shelf_id' => $this->getShelfId(),
            'book_cache' => [
                'cover' => $this->generateCoverThumbnail($cacheManager, "books_$cover"),
            ],
            'book_metadata' => [
                'title' => $this->getMetadata()->getTitle(),
                'creator' => $this->getMetadata()->getCreator(),
            ],
            'book_progress' => [
                'page' => $bp ? $bp->getPage() : 0,
                'total' => count($this->getCache()->getLocations()),
            ],
        ];
        if ($fullCache) {
            $json['book_cache']['navigation'] = $this->getCache()->getNavigation();
            $json['book_cache']['locations'] = $this->getCache()->getLocations();
        }
        if ($fullProgress) {
            $json['book_progress']['position'] = $bp?->getPosition();
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
        if ($this->getCache()->getCover()) {
            return $cacheManager->getBrowserPath("/" . $this->getCache()->getCover(), $filter);
        }
        return null;
    }
}
