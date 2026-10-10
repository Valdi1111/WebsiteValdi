<?php

namespace App\AnimeBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'episode_download_tracker')]
#[ORM\UniqueConstraint(name: 'IDX_episode_download_tracker', columns: ['episode_download_id', 'tracker'])]
#[ORM\Index(name: 'FK_episode_download', columns: ['episode_download_id'])]
#[ORM\Index(name: 'IDX_episode_download_is_trigger', columns: ['episode_download_id', '`is_trigger`'])]
#[ORM\Index(name: 'IDX_tracker_tracker_id', columns: ['tracker', 'tracker_id'])]
#[ORM\Index(name: 'IDX_is_trigger', columns: ['is_trigger'])]
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

    #[ORM\Column(name: 'is_trigger', options: ['default' => "0"])]
    private bool $isTrigger = false;

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

    public function isTrigger(): bool
    {
        return $this->isTrigger;
    }

    public function setIsTrigger(bool $isTrigger): static
    {
        $this->isTrigger = $isTrigger;
        return $this;
    }
}
