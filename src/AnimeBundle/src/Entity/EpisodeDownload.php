<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Model\EpisodeDownloadState;
use App\AnimeBundle\Repository\EpisodeDownloadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'IDX_state', columns: ['state'])]
#[ORM\Index(name: 'IDX_mal_id', columns: ['mal_id'])]
#[ORM\Index(name: 'IDX_al_id', columns: ['al_id'])]
#[ORM\Index(name: 'IDX_provider', columns: ['provider'])]
#[ORM\Index(name: 'IDX_tracker', columns: ['tracker'])]
#[ORM\Index(name: 'IDX_tracker_tracker_id', columns: ['tracker', 'tracker_id'])]
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

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $originalEpisode = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $episode = null;

    /**
     * Normalized episode numbers (e.g. [7.0, 8.0] or [7.5])
     *
     * @var array<int|float>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $episodes = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloadUrl = null;

    #[ORM\Column(length: 50, enumType: EpisodeDownloadState::class, options: ["default" => EpisodeDownloadState::created])]
    private ?EpisodeDownloadState $state = EpisodeDownloadState::created;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $file = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => new CurrentTimestamp()])]
    private ?\DateTimeInterface $created = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $started = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completed = null;

    #[ORM\Column(nullable: true, options: ["unsigned" => true])]
    private ?int $malId = null;

    #[ORM\Column(nullable: true, options: ["unsigned" => true])]
    private ?int $alId = null;

    #[ORM\Column(length: 50)]
    private ?string $provider = null;

    /** @var Collection<int, EpisodeDownloadAttempt> */
    #[ORM\OneToMany(targetEntity: EpisodeDownloadAttempt::class, mappedBy: 'episodeDownload', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['created' => \SortDirection::Ascending])]
    private Collection $episodeDownloadAttempts;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tracker = null;

    #[ORM\Column(nullable: true, options: ["unsigned" => true])]
    private ?int $trackerId = null;

    public function __construct()
    {
        $this->episodeDownloadAttempts = new ArrayCollection();
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

    public function getOriginalEpisode(): ?string
    {
        return $this->originalEpisode;
    }

    public function setOriginalEpisode(?string $originalEpisode): static
    {
        $this->originalEpisode = $originalEpisode;

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

    /**
     * @return array<int|float>|null
     */
    public function getEpisodes(): ?array
    {
        return $this->episodes;
    }

    /**
     * @param array<int|float>|null $episodes
     */
    public function setEpisodes(?array $episodes): static
    {
        $this->episodes = $episodes;

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

    public function getOriginalFile(): ?string
    {
        return $this->originalFile;
    }

    public function setOriginalFile(?string $originalFile): static
    {
        $this->originalFile = $originalFile;

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

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * @return Collection<int, EpisodeDownloadAttempt>
     */
    public function getEpisodeDownloadAttempts(): Collection
    {
        return $this->episodeDownloadAttempts;
    }

    public function addEpisodeDownloadAttempt(EpisodeDownloadAttempt $attempt): static
    {
        if (!$this->getEpisodeDownloadAttempts()->contains($attempt)) {
            $this->episodeDownloadAttempts[] = $attempt;
            $attempt->setEpisodeDownload($this);
        }

        return $this;
    }

    public function removeEpisodeDownloadAttempt(EpisodeDownloadAttempt $attempt): static
    {
        if ($this->getEpisodeDownloadAttempts()->removeElement($attempt)) {
            if ($attempt->getEpisodeDownload() === $this) {
                $attempt->setEpisodeDownload(null);
            }
        }

        return $this;
    }

    /**
     * Returns the latest attempt
     */
    public function getLastAttempt(): ?EpisodeDownloadAttempt
    {
        return $this->episodeDownloadAttempts->last() ?: null;
    }

    public function getTracker(): ?string
    {
        return $this->tracker;
    }

    public function setTracker(?string $tracker): static
    {
        $this->tracker = $tracker;

        return $this;
    }

    public function getTrackerId(): ?int
    {
        return $this->trackerId;
    }

    public function setTrackerId(?int $trackerId): static
    {
        $this->trackerId = $trackerId;

        return $this;
    }

}
