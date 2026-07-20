<?php

namespace App\AnimeBundle\Entity;

use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedPath;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;

#[Map(target: ListManga::class)]
class MalListManga
{
    #[SerializedPath("[node][id]")]
    private ?int $id = null;

    #[SerializedPath("[node][title]")]
    private ?string $title = '';

    #[SerializedPath("[node][alternative_titles][en]")]
    private ?string $titleEn = '';

    #[SerializedPath("[node][nsfw]")]
    #[Context(denormalizationContext: [BackedEnumNormalizer::ALLOW_INVALID_VALUES => true])]
    private ?Nsfw $nsfw = Nsfw::white;

    #[SerializedPath("[node][media_type]")]
    #[Context(denormalizationContext: [BackedEnumNormalizer::ALLOW_INVALID_VALUES => true])]
    private ?ListMangaType $mediaType = ListMangaType::unknown;

    #[SerializedPath("[node][num_volumes]")]
    private ?int $numVolumes = 0;

    #[SerializedPath("[node][num_chapters]")]
    private ?int $numChapters = 0;

    #[SerializedPath("[list_status][status]")]
    #[Context(denormalizationContext: [BackedEnumNormalizer::ALLOW_INVALID_VALUES => true])]
    private ?ListMangaStatus $status = ListMangaStatus::reading;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): MalListManga
    {
        $this->id = $id;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): MalListManga
    {
        $this->title = $title;
        return $this;
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): MalListManga
    {
        $this->titleEn = $titleEn;
        return $this;
    }

    public function getNsfw(): ?Nsfw
    {
        return $this->nsfw;
    }

    public function setNsfw(?Nsfw $nsfw): MalListManga
    {
        $this->nsfw = $nsfw;
        return $this;
    }

    public function getMediaType(): ?ListMangaType
    {
        return $this->mediaType;
    }

    public function setMediaType(?ListMangaType $mediaType): MalListManga
    {
        $this->mediaType = $mediaType;
        return $this;
    }

    public function getNumVolumes(): ?int
    {
        return $this->numVolumes;
    }

    public function setNumVolumes(?int $numVolumes): MalListManga
    {
        $this->numVolumes = $numVolumes;
        return $this;
    }

    public function getNumChapters(): ?int
    {
        return $this->numChapters;
    }

    public function setNumChapters(?int $numChapters): MalListManga
    {
        $this->numChapters = $numChapters;
        return $this;
    }

    public function getStatus(): ?ListMangaStatus
    {
        return $this->status;
    }

    public function setStatus(?ListMangaStatus $status): MalListManga
    {
        $this->status = $status;
        return $this;
    }

}