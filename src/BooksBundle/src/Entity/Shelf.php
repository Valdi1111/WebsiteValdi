<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\ShelfRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Table(name: 'shelf')]
#[ORM\Entity(repositoryClass: ShelfRepository::class)]
class Shelf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, insertable: false, updatable: false)]
    private ?\DateTimeInterface $created = null;

    /**
     * @var Collection<int, Book>
     */
    #[ORM\OneToMany(mappedBy: 'shelf', targetEntity: Book::class)]
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
