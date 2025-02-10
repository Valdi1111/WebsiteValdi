<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\SeasonFolderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'season_folder')]
#[ORM\Entity(repositoryClass: SeasonFolderRepository::class)]
class SeasonFolder
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $folder = null;

    #[ORM\Column(insertable: false, updatable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeImmutable $created = null;

    public function __construct()
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getFolder(): ?string
    {
        return $this->folder;
    }

    public function setFolder(string $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
    }

}
