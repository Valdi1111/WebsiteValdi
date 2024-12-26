<?php

namespace App\AnimeBundle\Entity;

use Symfony\Component\Validator\Constraints as Assert;

class EpisodeDownloadRequest
{

    #[Assert\Url]
    private string $url;

    private bool $all = false;

    private bool $filter = true;

    private bool $save = true;

    /**
     * Episode url without hostname
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getBaseUrl(): string
    {
        $urlSplits = parse_url($this->getUrl());
        return "{$urlSplits['scheme']}://{$urlSplits['host']}";
    }

    public function getUrlPath(): string
    {
        $urlSplits = parse_url($this->getUrl());
        return $urlSplits['path'];
    }

    /**
     * Query all episodes
     * @return bool
     */
    public function isAll(): bool
    {
        return $this->all;
    }

    public function setAll(bool $all): static
    {
        $this->all = $all;
        return $this;
    }

    /**
     * Ignore if not present in anime cache
     * @return bool
     */
    public function isFilter(): bool
    {
        return $this->filter;
    }

    public function setFilter(bool $filter): static
    {
        $this->filter = $filter;
        return $this;
    }

    /**
     * Save to database
     * @return bool
     */
    public function isSave(): bool
    {
        return $this->save;
    }

    public function setSave(bool $save): static
    {
        $this->save = $save;
        return $this;
    }

}