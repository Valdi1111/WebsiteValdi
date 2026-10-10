<?php

namespace App\AnimeBundle\Model;

use App\AnimeBundle\Entity\ListManga;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(target: ListManga::class)]
class AlListManga
{
    private int $id = 0;
    private string $title = '';
    private string $titleEn = '';
    private Nsfw $nsfw = Nsfw::white;
    private ListMangaType $mediaType = ListMangaType::unknown;
    private int $numVolumes = 0;
    private int $numChapters = 0;
    private ListMangaStatus $status = ListMangaStatus::reading;

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

    public function getMediaType(): ListMangaType
    {
        return $this->mediaType;
    }

    public function setMediaType(ListMangaType $mediaType): self
    {
        $this->mediaType = $mediaType;
        return $this;
    }

    public function getNumVolumes(): int
    {
        return $this->numVolumes;
    }

    public function setNumVolumes(int $numVolumes): self
    {
        $this->numVolumes = $numVolumes;
        return $this;
    }

    public function getNumChapters(): int
    {
        return $this->numChapters;
    }

    public function setNumChapters(int $numChapters): self
    {
        $this->numChapters = $numChapters;
        return $this;
    }

    public function getStatus(): ListMangaStatus
    {
        return $this->status;
    }

    public function setStatus(ListMangaStatus $status): self
    {
        $this->status = $status;
        return $this;
    }
}
