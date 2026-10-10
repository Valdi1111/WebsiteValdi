<?php

namespace App\AnimeBundle\Model;

class ScrapedEpisode
{
    private string $serviceName;
    private string $episodeUrl;
    private string $episodeNumber;
    private ?string $downloadUrl = null;
    private ?string $filename = null;
    private ?int $malId = null;
    private ?int $alId = null;

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function setServiceName(string $serviceName): ScrapedEpisode
    {
        $this->serviceName = $serviceName;
        return $this;
    }

    public function getEpisodeUrl(): string
    {
        return $this->episodeUrl;
    }

    public function setEpisodeUrl(string $episodeUrl): ScrapedEpisode
    {
        $this->episodeUrl = $episodeUrl;
        return $this;
    }

    public function getEpisodeNumber(): string
    {
        return $this->episodeNumber;
    }

    public function setEpisodeNumber(string $episodeNumber): ScrapedEpisode
    {
        $this->episodeNumber = $episodeNumber;
        return $this;
    }

    public function getDownloadUrl(): ?string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(?string $downloadUrl): ScrapedEpisode
    {
        $this->downloadUrl = $downloadUrl;
        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): ScrapedEpisode
    {
        $this->filename = $filename;
        return $this;
    }

    public function getMalId(): ?int
    {
        return $this->malId;
    }

    public function setMalId(?int $malId): ScrapedEpisode
    {
        $this->malId = $malId;
        return $this;
    }

    public function getAlId(): ?int
    {
        return $this->alId;
    }

    public function setAlId(?int $alId): ScrapedEpisode
    {
        $this->alId = $alId;
        return $this;
    }
}
