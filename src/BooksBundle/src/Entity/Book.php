<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Normalizer\CollectionBookProgressNormalizer;
use App\BooksBundle\Repository\BookRepository;
use App\CoreBundle\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\OneToMany;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Table(name: 'book')]
#[ORM\Entity(repositoryClass: BookRepository::class)]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $url = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $created;

    #[ORM\ManyToOne(targetEntity: Shelf::class, inversedBy: 'books')]
    #[ORM\JoinColumn(name: 'shelf_id', referencedColumnName: 'id')]
    private ?Shelf $shelf = null;

    #[ORM\OneToOne(mappedBy: 'book', targetEntity: BookMetadata::class, cascade: ['persist', 'remove'])]
    private ?BookMetadata $bookMetadata = null;

    #[ORM\OneToOne(mappedBy: 'book', targetEntity: BookCache::class, cascade: ['persist', 'remove'])]
    private ?BookCache $bookCache = null;

    /** @var Collection<int, BookProgress> */
    #[OneToMany(mappedBy: 'book', targetEntity: BookProgress::class, cascade: ['persist', 'remove'], indexBy: 'user_id')]
    private Collection $bookProgresses;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->bookProgresses = new ArrayCollection();
    }

    #[Groups(['book:list', 'book:metadata'])]
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

    #[Ignore]
    public function getShelf(): ?Shelf
    {
        return $this->shelf;
    }

    public function setShelf(?Shelf $shelf): self
    {
        $this->shelf = $shelf;

        return $this;
    }

    #[Groups(['book:list'])]
    public function getShelfId(): ?int
    {
        return $this->getShelf()?->getId();
    }

    #[Groups(['book:list', 'book:metadata'])]
    public function getBookMetadata(): ?BookMetadata
    {
        return $this->bookMetadata;
    }

    public function setBookMetadata(BookMetadata $bookMetadata): static
    {
        $this->bookMetadata = $bookMetadata;
        $bookMetadata->setBook($this);

        return $this;
    }

    #[Groups(['book:list', 'book:metadata'])]
    public function getBookCache(): ?BookCache
    {
        return $this->bookCache;
    }

    public function setBookCache(BookCache $bookCache): static
    {
        $this->bookCache = $bookCache;
        $bookCache->setBook($this);

        return $this;
    }

    /** @return Collection<int, BookProgress> */
    #[Groups(['book:list'])]
    #[SerializedName('book_progress')]
    #[Context(normalizationContext: [CollectionBookProgressNormalizer::SERIALIZE => true])]
    public function getBookProgresses(): Collection
    {
        return $this->bookProgresses;
    }

    public function getBookProgress(User $user): ?BookProgress
    {
        return $this->getBookProgresses()->get($user->getId());
    }

    public function addBookProgress(BookProgress $progress): self
    {
        if (!$this->getBookProgresses()->contains($progress)) {
            $this->bookProgresses[] = $progress;
            $progress->setBook($this);
        }

        return $this;
    }

    public function removeBookProgress(BookProgress $progress): self
    {
        if ($this->getBookProgresses()->removeElement($progress)) {
            if ($progress->getBook() === $this) {
                $progress->setBook(null);
            }
        }

        return $this;
    }

}
