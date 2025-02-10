<?php

namespace App\PasswordsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class WebsiteCredential extends Credential
{

    #[ORM\Column(nullable: true)]
    private ?array $websites = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recoveryCodes = null;

    public function getType(): string
    {
        return 'website';
    }

    public function getWebsites(): ?array
    {
        return $this->websites;
    }

    public function setWebsites(?array $websites): static
    {
        $this->websites = $websites;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getRecoveryCodes(): ?string
    {
        return $this->recoveryCodes;
    }

    public function setRecoveryCodes(?string $recoveryCodes): static
    {
        $this->recoveryCodes = $recoveryCodes;
        return $this;
    }

}