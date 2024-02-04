<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\AwDownloadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'episode_download')]
#[ORM\Entity(repositoryClass: AwDownloadRepository::class)]
class EpisodeDownload implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $episode_url = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $episode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $download_url = null;

    #[ORM\Column(length: 32, enumType: EpisodeDownloadState::class)]
    private ?EpisodeDownloadState $state = EpisodeDownloadState::created;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $file = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $created = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $started = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completed = null;

    #[ORM\Column(nullable: true)]
    private ?int $mal_id = null;

    #[ORM\Column(nullable: true)]
    private ?int $al_id = null;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEpisodeUrl(): ?string
    {
        return $this->episode_url;
    }

    public function setEpisodeUrl(string $episode_url): static
    {
        $this->episode_url = $episode_url;

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
        return $this->download_url;
    }

    public function setDownloadUrl(?string $download_url): static
    {
        $this->download_url = $download_url;

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

    public function setCreated(\DateTimeInterface $created): static
    {
        $this->created = $created;

        return $this;
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
        return $this->mal_id;
    }

    public function setMalId(?int $mal_id): static
    {
        $this->mal_id = $mal_id;

        return $this;
    }

    public function getAlId(): ?int
    {
        return $this->al_id;
    }

    public function setAlId(?int $al_id): static
    {
        $this->al_id = $al_id;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'episode_url' => $this->getEpisodeUrl(),
            'episode' => $this->getEpisode(),
            'download_url' => $this->getDownloadUrl(),
            'state' => $this->getState(),
            'folder' => $this->getFolder(),
            'file' => $this->getFile(),
            'created' => $this->getCreated(),
            'started' => $this->getStarted(),
            'completed' => $this->getCompleted(),
            'mal_id' => $this->getMalId(),
            'al_id' => $this->getAlId(),
        ];
    }
}
