<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\EpisodeDownloadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'episode_download')]
#[ORM\Entity(repositoryClass: EpisodeDownloadRepository::class)]
class EpisodeDownload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $episodeUrl = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $episode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloadUrl = null;

    #[ORM\Column(length: 32, enumType: EpisodeDownloadState::class)]
    private ?EpisodeDownloadState $state = EpisodeDownloadState::created;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $file = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, insertable: false, updatable: false)]
    private ?\DateTimeInterface $created = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $started = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completed = null;

    #[ORM\Column(nullable: true)]
    private ?int $malId = null;

    #[ORM\Column(nullable: true)]
    private ?int $alId = null;

    public function __construct()
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEpisodeUrl(): ?string
    {
        return $this->episodeUrl;
    }

    public function setEpisodeUrl(string $episodeUrl): static
    {
        $this->episodeUrl = $episodeUrl;

        return $this;
    }

    public function getEpisode(): ?string
    {
        return $this->episode;
    }

    public function setEpisode(?string $episode): static
    {
        $this->episode = $episode;

        return $this;
    }

    public function getDownloadUrl(): ?string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(?string $downloadUrl): static
    {
        $this->downloadUrl = $downloadUrl;

        return $this;
    }

    public function getState(): ?EpisodeDownloadState
    {
        return $this->state;
    }

    public function setState(EpisodeDownloadState $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getFolder(): ?string
    {
        return $this->folder;
    }

    public function setFolder(?string $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function setFile(?string $file): static
    {
        $this->file = $file;

        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function getStarted(): ?\DateTimeInterface
    {
        return $this->started;
    }

    public function setStarted(?\DateTimeInterface $started): static
    {
        $this->started = $started;

        return $this;
    }

    public function getCompleted(): ?\DateTimeInterface
    {
        return $this->completed;
    }

    public function setCompleted(?\DateTimeInterface $completed): static
    {
        $this->completed = $completed;

        return $this;
    }

    public function getMalId(): ?int
    {
        return $this->malId;
    }

    public function setMalId(?int $malId): static
    {
        $this->malId = $malId;

        return $this;
    }

    public function getAlId(): ?int
    {
        return $this->alId;
    }

    public function setAlId(?int $alId): static
    {
        $this->alId = $alId;

        return $this;
    }

}
