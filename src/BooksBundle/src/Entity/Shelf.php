<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\ShelfRepository;
use App\CoreBundle\Normalizer\CollectionCountNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
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

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $created;

    /**
     * @var Collection<int, ShelfBook>
     */
    #[SerializedName('_count')]
    #[Context(normalizationContext: [CollectionCountNormalizer::SERIALIZE => true])]
    #[ORM\OneToMany(mappedBy: 'shelf', targetEntity: ShelfBook::class)]
    #[ORM\OrderBy(['url' => 'ASC'])]
    private Collection $books;

    public function __construct()
    {
        $this->created = new \DateTime();
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

    public function setCreated(\DateTimeInterface $created): static
    {
        $this->created = $created;

        return $this;
    }

    /**
     * @return Collection<int, ShelfBook>
     */
    public function getBooks(): Collection
    {
        return $this->books;
    }

}
