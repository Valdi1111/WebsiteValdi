<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Model\EpisodeDownloadState;
use App\AnimeBundle\Repository\EpisodeDownloadAttemptRepository;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'episode_download_attempt')]
#[ORM\Index(name: 'FK_episode_download_episode_download_attempt', columns: ['episode_download_id'])]
#[ORM\Index(name: 'IDX_state', columns: ['state'])]
#[ORM\Entity(repositoryClass: EpisodeDownloadAttemptRepository::class)]
class EpisodeDownloadAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EpisodeDownload::class, inversedBy: 'episodeDownloadAttempts')]
    #[ORM\JoinColumn(name: 'episode_download_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?EpisodeDownload $episodeDownload = null;

    #[ORM\Column(length: 50, enumType: EpisodeDownloadState::class, options: ["default" => EpisodeDownloadState::created])]
    private ?EpisodeDownloadState $state = EpisodeDownloadState::created;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => new CurrentTimestamp()])]
    private ?\DateTimeInterface $created = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $started = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completed = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorTrace = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEpisodeDownload(): ?EpisodeDownload
    {
        return $this->episodeDownload;
    }

    public function setEpisodeDownload(?EpisodeDownload $episodeDownload): static
    {
        $this->episodeDownload = $episodeDownload;

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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getErrorTrace(): ?string
    {
        return $this->errorTrace;
    }

    public function setErrorTrace(?string $errorTrace): static
    {
        $this->errorTrace = $errorTrace;

        return $this;
    }
}
