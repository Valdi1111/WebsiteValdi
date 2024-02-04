<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\ShelfRepository;
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

    private ?int $_count = null;

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
     * @return ?int
     */
    public function getCount(): ?int
    {
        return $this->_count;
    }

    /**
     * @param int $count
     */
    public function setCount(int $count): void
    {
        $this->_count = $count;
    }

    public function jsonSerialize(): array
    {
        $json = [
            'id' => $this->getId(),
            'path' => $this->getPath(),
            'name' => $this->getName(),
        ];
        if($this->getCount() !== null) {
            $json['_count'] = $this->getCount();
        }
        return $json;
    }
}
