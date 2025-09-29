<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\TokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'IDX_class_username', columns: ['class', 'username'])]
#[ORM\UniqueConstraint(name: 'IDX_access_token', columns: ['access_token'])]
#[ORM\Table(name: 'token')]
#[ORM\Entity(repositoryClass: TokenRepository::class)]
class Token
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $class = null;

    #[ORM\Column(length: 200)]
    private ?string $username = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $accessToken = null;

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $valid = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClass(): ?string
    {
        return $this->class;
    }

    public function setClass(string $class): static
    {
        $this->class = $class;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

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
