<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\MalListMangaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'list_manga')]
#[ORM\Entity(repositoryClass: MalListMangaRepository::class)]
class ListManga implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: false)]
    private ?string $title = '';

    #[ORM\Column(length: 255, nullable: false)]
    private ?string $title_en = '';

    #[ORM\Column(length: 32, nullable: false, enumType: Nsfw::class)]
    private ?Nsfw $nsfw = Nsfw::white;

    #[ORM\Column(length: 32, nullable: false, enumType: ListMangaType::class)]
    private ?ListMangaType $media_type = ListMangaType::unknown;

    #[ORM\Column(nullable: false)]
    private ?int $num_volumes = 0;

    #[ORM\Column(nullable: false)]
    private ?int $num_chapters = 0;

    #[ORM\Column(length: 32, nullable: false, enumType: ListMangaStatus::class)]
    private ?ListMangaStatus $status = ListMangaStatus::reading;

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
        return $this->title_en;
    }

    public function setTitleEn(string $titleEn): static
    {
        $this->title_en = $titleEn;

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

    public function getMediaType(): ListMangaType
    {
        return $this->media_type;
    }

    public function setMediaType(ListMangaType $mediaType): static
    {
        $this->media_type = $mediaType;

        return $this;
    }

    public function getNumVolumes(): int
    {
        return $this->num_volumes;
    }

    public function setNumVolumes(int $numVolumes): static
    {
        $this->num_volumes = $numVolumes;

        return $this;
    }

    public function getNumChapters(): int
    {
        return $this->num_chapters;
    }

    public function setNumChapters(int $numChapters): static
    {
        $this->num_chapters = $numChapters;

        return $this;
    }

    public function getStatus(): ListMangaStatus
    {
        return $this->status;
    }

    public function setStatus(ListMangaStatus $status): static
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
            ->setMediaType(ListMangaType::tryFrom($data['node']['media_type']))
            ->setNumVolumes($data['node']['num_volumes'])
            ->setNumChapters($data['node']['num_chapters'])
            ->setStatus(ListMangaStatus::tryFrom($data['list_status']['status']));
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'status' => $this->getStatus(),
        ];
    }
}
