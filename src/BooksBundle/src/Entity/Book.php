<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Normalizer\CollectionBookProgressNormalizer;
use App\BooksBundle\Repository\BookRepository;
use App\CoreBundle\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\OneToMany;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Index(name: 'FK_book_shelf', columns: ['shelf_id'])]
#[ORM\Index(name: 'FK_book_library', columns: ['library_id'])]
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

    #[ORM\Column(insertable: false, updatable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeImmutable $created = null;

    #[ORM\ManyToOne(targetEntity: Shelf::class, inversedBy: 'books')]
    #[ORM\JoinColumn(name: 'shelf_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?Shelf $shelf = null;

    #[ORM\ManyToOne(targetEntity: Library::class)]
    #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?Library $library = null;

    #[ORM\OneToOne(targetEntity: BookMetadata::class, mappedBy: 'book', cascade: ['persist', 'remove'])]
    private ?BookMetadata $bookMetadata = null;

    #[ORM\OneToOne(targetEntity: BookCache::class, mappedBy: 'book', cascade: ['persist', 'remove'])]
    private ?BookCache $bookCache = null;

    /** @var Collection<int, BookProgress> */
    #[OneToMany(targetEntity: BookProgress::class, mappedBy: 'book', cascade: ['persist', 'remove'], indexBy: 'user_id')]
    private Collection $bookProgresses;

    public function __construct()
    {
        $this->bookProgresses = new ArrayCollection();
    }

    #[Groups(['book:list', 'book:metadata'])]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[Groups(['book:list', 'book:metadata'])]
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

    #[Ignore]
    public function getLibrary(): ?Library
    {
        return $this->library;
    }

    public function setLibrary(?Library $library): self
    {
        $this->library = $library;

        return $this;
    }

    #[Groups(['book:list'])]
    public function getLibraryId(): ?int
    {
        return $this->getLibrary()?->getId();
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
