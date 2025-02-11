<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\ShelfRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Ignore;

#[ORM\UniqueConstraint(name: 'IDX_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'IDX_path', columns: ['path'])]
#[ORM\Index(name: 'FK_shelf_library', columns: ['library_id'])]
#[ORM\Table(name: 'shelf')]
#[ORM\Entity(repositoryClass: ShelfRepository::class)]
class Shelf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $path = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeInterface $created = null;

    #[ORM\ManyToOne(targetEntity: Library::class, inversedBy: 'shelves')]
    #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?Library $library = null;

    /**
     * @var Collection<int, Book>
     */
    #[ORM\OneToMany(targetEntity: Book::class, mappedBy: 'shelf', indexBy: 'book_id')]
    #[ORM\OrderBy(['url' => 'ASC'])]
    private Collection $books;

    public function __construct()
    {
        $this->books = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
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

    public function getLibraryId(): ?int
    {
        return $this->getLibrary()?->getId();
    }

    /**
     * @return Collection<int, Book>
     */
    #[Ignore]
    public function getBooks(): Collection
    {
        return $this->books;
    }

    public function addBook(Book $book): self
    {
        if (!$this->getBooks()->contains($book)) {
            $this->books[] = $book;
            $book->setShelf($this);
        }

        return $this;
    }

    public function removeBook(Book $book): self
    {
        if ($this->getBooks()->removeElement($book)) {
            if ($book->getShelf() === $this) {
                $book->setShelf(null);
            }
        }

        return $this;
    }

    public function getBooksCount(): int
    {
        return $this->getBooks()->count();
    }

}
