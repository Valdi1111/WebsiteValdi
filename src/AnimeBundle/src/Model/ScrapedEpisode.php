<?php

namespace App\AnimeBundle\Model;

class ScrapedEpisode
{
    private string $provider;
    private string $episodeUrl;
    private string $episodeNumber;
    private ?string $downloadUrl = null;
    private ?string $filename = null;

    /**
     * @var array<string, TrackerIdentifier>
     */
    private array $trackers = [];

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function getEpisodeUrl(): string
    {
        return $this->episodeUrl;
    }

    public function setEpisodeUrl(string $episodeUrl): self
    {
        $this->episodeUrl = $episodeUrl;
        return $this;
    }

    public function getEpisodeNumber(): string
    {
        return $this->episodeNumber;
    }

    public function setEpisodeNumber(string $episodeNumber): self
    {
        $this->episodeNumber = $episodeNumber;
        return $this;
    }

    public function getDownloadUrl(): ?string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(?string $downloadUrl): self
    {
        $this->downloadUrl = $downloadUrl;
        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;
        return $this;
    }

    /**
     * @return array<string, TrackerIdentifier>
     */
    public function getTrackers(): array
    {
        return $this->trackers;
    }

    /**
     * @param array<string, TrackerIdentifier>|TrackerIdentifier[] $trackers
     */
    public function setTrackers(array $trackers): self
    {
        $this->trackers = [];
        foreach ($trackers as $tracker) {
            $this->addTracker($tracker);
        }
        return $this;
    }

    public function addTracker(TrackerIdentifier $tracker): self
    {
        $this->trackers[$tracker->getTracker()] = $tracker;
        return $this;
    }

    public function getTracker(string $trackerName): ?TrackerIdentifier
    {
        return $this->trackers[$trackerName] ?? null;
    }

    public function getFirstTracker(): ?TrackerIdentifier
    {
        return array_values($this->trackers)[0] ?? null;
    }
}
