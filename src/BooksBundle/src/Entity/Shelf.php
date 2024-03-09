<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\ShelfRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'shelf')]
#[ORM\Entity(repositoryClass: ShelfRepository::class)]
class Shelf implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var Collection<int, ShelfBook>
     */
    #[ORM\OneToMany(mappedBy: 'shelf', targetEntity: ShelfBook::class)]
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

    /**
     * @return Collection<int, ShelfBook>
     */
    public function getBooks(): Collection
    {
        return $this->books;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'path' => $this->getPath(),
            'name' => $this->getName(),
            '_count' => $this->getBooks()->count(),
        ];
    }
}
