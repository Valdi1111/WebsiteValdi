<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\MalListAnimeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'list_anime')]
#[ORM\Entity(repositoryClass: MalListAnimeRepository::class)]
class ListAnime implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = '';

    #[ORM\Column(length: 32, enumType: ListAnimeStatus::class)]
    private ?ListAnimeStatus $status = ListAnimeStatus::watching;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getStatus(): ?ListAnimeStatus
    {
        return $this->status;
    }

    public function setStatus(?ListAnimeStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'status' => $this->getStatus(),
        ];
    }
}
