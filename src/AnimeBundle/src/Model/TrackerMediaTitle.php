<?php

namespace App\AnimeBundle\Model;

readonly class TrackerMediaTitle
{
    public function __construct(
        private int     $id,
        private string  $url,
        private ?string $title,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }
}
