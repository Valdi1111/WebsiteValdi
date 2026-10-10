<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\SeasonFolderRepository;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'IDX_provider', columns: ['provider'])]
#[ORM\Table(name: 'season_folder')]
#[ORM\Entity(repositoryClass: SeasonFolderRepository::class)]
class SeasonFolder
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Id]
    #[ORM\Column(length: 50)]
    private ?string $provider = null;

    #[ORM\Column(length: 255)]
    private ?string $folder = null;

    #[ORM\Column(options: ["default" => 0])]
    private ?int $episodeOffset = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => new CurrentTimestamp()])]
    private ?\DateTimeInterface $created = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

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

    public function getEpisodeOffset(): ?int
    {
        return $this->episodeOffset;
    }

    public function setEpisodeOffset(int $episodeOffset): static
    {
        $this->episodeOffset = $episodeOffset;

        return $this;
    }

    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
    }

}
