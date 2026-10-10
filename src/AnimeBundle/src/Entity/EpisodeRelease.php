<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\EpisodeReleaseRepository;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'IDX_episode_url', columns: ['episode_url'])]
#[ORM\Index(name: 'IDX_provider', columns: ['provider'])]
#[ORM\UniqueConstraint(name: 'IDX_episode_url_provider', columns: ['episode_url', 'provider'])]
#[ORM\Table(name: 'episode_release')]
#[ORM\Entity(repositoryClass: EpisodeReleaseRepository::class)]
class EpisodeRelease
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $episodeUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => new CurrentTimestamp()])]
    private ?\DateTimeInterface $created = null;
    #[ORM\Column(length: 50)]
    private ?string $provider = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEpisodeUrl(): ?string
    {
        return $this->episodeUrl;
    }

    public function setEpisodeUrl(string $episodeUrl): static
    {
        $this->episodeUrl = $episodeUrl;

        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

}
