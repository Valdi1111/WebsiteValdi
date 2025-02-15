<?php

namespace App\PasswordsBundle\Entity;

use App\PasswordsBundle\Repository\CredentialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute as Serializer;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Table(name: 'credential')]
#[ORM\Entity(repositoryClass: CredentialRepository::class)]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', columnDefinition: "ENUM('device','website') NOT NULL")]
#[ORM\DiscriminatorMap(['device' => DeviceCredential::class, 'website' => WebsiteCredential::class])]
#[Serializer\DiscriminatorMap('type', ['device' => DeviceCredential::class, 'website' => WebsiteCredential::class])]
abstract class Credential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?array $tags = [];

    #[Groups(['credential:list'])]
    abstract public function getType(): string;

    #[Groups(['credential:list'])]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[Groups(['credential:list'])]
    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    #[Groups(['credential:list'])]
    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function setTags(?array $tags): static
    {
        $this->tags = $tags;
        return $this;
    }

}