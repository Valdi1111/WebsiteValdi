<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\TokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\UniqueConstraint(name: 'IDX_access_token', columns: ['access_token'])]
#[ORM\Table(name: 'token')]
#[ORM\Entity(repositoryClass: TokenRepository::class)]
class Token
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $accessToken = null;

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $valid = true;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(string $accessToken): static
    {
        $this->accessToken = $accessToken;

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
