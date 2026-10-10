<?php

namespace App\AnimeBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'episode_download_tracker')]
#[ORM\UniqueConstraint(name: 'IDX_episode_download_tracker', columns: ['episode_download_id', 'tracker'])]
#[ORM\Index(name: 'FK_episode_download', columns: ['episode_download_id'])]
#[ORM\Index(name: 'IDX_episode_download_default', columns: ['episode_download_id', 'default'])]
#[ORM\Index(name: 'IDX_tracker_tracker_id', columns: ['tracker', 'tracker_id'])]
#[ORM\Index(name: 'IDX_default', columns: ['default'])]
#[ORM\Entity]
class EpisodeDownloadTracker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EpisodeDownload::class, inversedBy: 'trackers')]
    #[ORM\JoinColumn(name: 'episode_download_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?EpisodeDownload $episodeDownload = null;

    #[ORM\Column(length: 50)]
    private ?string $tracker = null;

    #[ORM\Column(options: ["unsigned" => true])]
    private ?int $trackerId = null;

    #[ORM\Column(options: ['default' => "0"])]
    private bool $default = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[Ignore]
    public function getEpisodeDownload(): ?EpisodeDownload
    {
        return $this->episodeDownload;
    }

    public function setEpisodeDownload(?EpisodeDownload $episodeDownload): static
    {
        $this->episodeDownload = $episodeDownload;
        return $this;
    }

    public function getTracker(): string
    {
        return $this->tracker;
    }

    public function setTracker(string $tracker): static
    {
        $this->tracker = $tracker;
        return $this;
    }

    public function getTrackerId(): int
    {
        return $this->trackerId;
    }

    public function setTrackerId(int $trackerId): static
    {
        $this->trackerId = $trackerId;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function setDefault(bool $default): static
    {
        $this->default = $default;
        return $this;
    }
}
