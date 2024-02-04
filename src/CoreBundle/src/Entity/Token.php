<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\TokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'token')]
#[ORM\Entity(repositoryClass: TokenRepository::class)]
class Token
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $access_token = null;

    #[ORM\Column]
    private ?bool $valid = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->access_token;
    }

    public function setAccessToken(string $access_token): static
    {
        $this->access_token = $access_token;

        return $this;
    }

    public function isValid(): ?bool
    {
        return $this->valid;
    }

    public function setValid(bool $valid): static
    {
        $this->valid = $valid;

        return $this;
    }
}
