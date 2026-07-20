<?php

namespace App\AnimeBundle\Entity;

use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedPath;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;

#[Map(target: ListAnime::class)]
class MalListAnime
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
    private ?ListAnimeType $mediaType = ListAnimeType::unknown;

    #[SerializedPath("[node][num_episodes]")]
    private ?int $numEpisodes = 0;

    #[SerializedPath("[list_status][status]")]
    #[Context(denormalizationContext: [BackedEnumNormalizer::ALLOW_INVALID_VALUES => true])]
    private ?ListAnimeStatus $status = ListAnimeStatus::watching;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): MalListAnime
    {
        $this->id = $id;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): MalListAnime
    {
        $this->title = $title;
        return $this;
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): MalListAnime
    {
        $this->titleEn = $titleEn;
        return $this;
    }

    public function getNsfw(): ?Nsfw
    {
        return $this->nsfw;
    }

    public function setNsfw(?Nsfw $nsfw): MalListAnime
    {
        $this->nsfw = $nsfw;
        return $this;
    }

    public function getMediaType(): ?ListAnimeType
    {
        return $this->mediaType;
    }

    public function setMediaType(?ListAnimeType $mediaType): MalListAnime
    {
        $this->mediaType = $mediaType;
        return $this;
    }

    public function getNumEpisodes(): ?int
    {
        return $this->numEpisodes;
    }

    public function setNumEpisodes(?int $numEpisodes): MalListAnime
    {
        $this->numEpisodes = $numEpisodes;
        return $this;
    }

    public function getStatus(): ?ListAnimeStatus
    {
        return $this->status;
    }

    public function setStatus(?ListAnimeStatus $status): MalListAnime
    {
        $this->status = $status;
        return $this;
    }

}