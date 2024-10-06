<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\LibraryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\OneToMany;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'library')]
#[ORM\Entity(repositoryClass: LibraryRepository::class)]
class Library
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $basePath = null;

    #[ORM\Column(length: 255)]
    private ?string $coversFolder = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, insertable: false, updatable: false)]
    private ?\DateTimeInterface $created = null;

    /** @var Collection<int, Shelf> */
    #[OneToMany(mappedBy: 'library', targetEntity: Shelf::class, indexBy: 'shelf_id')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $shelves;

    public function __construct()
    {
        $this->shelves = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBasePath(): ?string
    {
        return $this->basePath;
    }

    public function setBasePath(string $basePath): static
    {
        $this->basePath = $basePath;

        return $this;
    }

    public function getCoversFolder(): ?string
    {
        return $this->coversFolder;
    }

    public function setCoversFolder(string $coversFolder): static
    {
        $this->coversFolder = $coversFolder;

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
     * @return Collection<int, Shelf>
     */
    #[Ignore]
    public function getShelves(): Collection
    {
        return $this->shelves;
    }

    public function addBook(Shelf $shelf): self
    {
        if (!$this->getShelves()->contains($shelf)) {
            $this->shelves[] = $shelf;
            $shelf->setLibrary($this);
        }

        return $this;
    }

    public function removeBook(Shelf $shelf): self
    {
        if ($this->getShelves()->removeElement($shelf)) {
            if ($shelf->getLibrary() === $this) {
                $shelf->setLibrary(null);
            }
        }

        return $this;
    }

    public function getShelvesCount(): int
    {
        return $this->getShelves()->count();
    }

}