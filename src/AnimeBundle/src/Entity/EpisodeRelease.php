<?php

namespace App\AnimeBundle\Entity;

use App\AnimeBundle\Repository\EpisodeReleaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'IDX_episode_url', columns: ['episode_url'])]
#[ORM\Index(name: 'IDX_service_name', columns: ['service_name'])]
#[ORM\UniqueConstraint(name: 'IDX_episode_service', columns: ['episode_url', 'service_name'])]
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeInterface $created = null;
    #[ORM\Column(length: 255)]
    private ?string $serviceName = null;

    public function __construct()
    {
    }

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

    public function getServiceName(): ?string
    {
        return $this->serviceName;
    }

    public function setServiceName(string $serviceName): static
    {
        $this->serviceName = $serviceName;

        return $this;
    }

}
