<?php

namespace App\AnimeBundle\Model;

use App\AnimeBundle\Entity\ListAnime;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(target: ListAnime::class)]
class AlListAnime
{
    private int $id = 0;
    private string $title = '';
    private string $titleEn = '';
    private Nsfw $nsfw = Nsfw::white;
    private ListAnimeType $mediaType = ListAnimeType::unknown;
    private int $numEpisodes = 0;
    private ListAnimeStatus $status = ListAnimeStatus::watching;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getTitleEn(): string
    {
        return $this->titleEn;
    }

    public function setTitleEn(string $titleEn): self
    {
        $this->titleEn = $titleEn;
        return $this;
    }

    public function getNsfw(): Nsfw
    {
        return $this->nsfw;
    }

    public function setNsfw(Nsfw $nsfw): self
    {
        $this->nsfw = $nsfw;
        return $this;
    }

    public function getMediaType(): ListAnimeType
    {
        return $this->mediaType;
    }

    public function setMediaType(ListAnimeType $mediaType): self
    {
        $this->mediaType = $mediaType;
        return $this;
    }

    public function getNumEpisodes(): int
    {
        return $this->numEpisodes;
    }

    public function setNumEpisodes(int $numEpisodes): self
    {
        $this->numEpisodes = $numEpisodes;
        return $this;
    }

    public function getStatus(): ListAnimeStatus
    {
        return $this->status;
    }

    public function setStatus(ListAnimeStatus $status): self
    {
        $this->status = $status;
        return $this;
    }
}
