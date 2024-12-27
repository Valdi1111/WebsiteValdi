<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\ListAnimeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\SerializedPath;

#[ORM\Table(name: 'list_anime')]
#[ORM\Entity(repositoryClass: ListAnimeRepository::class)]
class ListAnime
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: false)]
    private ?string $title = '';

    #[ORM\Column(length: 255, nullable: false)]
    private ?string $titleEn = '';

    #[ORM\Column(length: 32, nullable: false, enumType: Nsfw::class)]
    private ?Nsfw $nsfw = Nsfw::white;

    #[ORM\Column(length: 32, nullable: false, enumType: ListAnimeType::class)]
    private ?ListAnimeType $mediaType = ListAnimeType::unknown;

    #[ORM\Column(nullable: false)]
    private ?int $numEpisodes = 0;

    #[ORM\Column(length: 32, nullable: false, enumType: ListAnimeStatus::class)]
    private ?ListAnimeStatus $status = ListAnimeStatus::watching;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitleEn(): string
    {
        return $this->titleEn;
    }

    public function setTitleEn(string $titleEn): static
    {
        $this->titleEn = $titleEn;

        return $this;
    }

    public function getNsfw(): Nsfw
    {
        return $this->nsfw;
    }

    public function setNsfw(Nsfw $nsfw): static
    {
        $this->nsfw = $nsfw;

        return $this;
    }

    public function getMediaType(): ListAnimeType
    {
        return $this->mediaType;
    }

    public function setMediaType(ListAnimeType $mediaType): static
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    public function getNumEpisodes(): int
    {
        return $this->numEpisodes;
    }

    public function setNumEpisodes(int $numEpisodes): static
    {
        $this->numEpisodes = $numEpisodes;

        return $this;
    }

    public function getStatus(): ListAnimeStatus
    {
        return $this->status;
    }

    public function setStatus(ListAnimeStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function deserializeMal($data): static
    {
        $this->setId($data['node']['id'])
            ->setTitle($data['node']['title'])
            ->setTitleEn($data['node']['alternative_titles']['en'])
            ->setNsfw(Nsfw::tryFrom($data['node']['nsfw']))
            ->setMediaType(ListAnimeType::tryFrom($data['node']['media_type']) ?: ListAnimeType::unknown)
            ->setNumEpisodes($data['node']['num_episodes'])
            ->setStatus(ListAnimeStatus::tryFrom($data['list_status']['status']));
        return $this;
    }

}
