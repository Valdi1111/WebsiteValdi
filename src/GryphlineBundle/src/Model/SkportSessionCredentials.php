<?php

namespace App\GryphlineBundle\Model;

class SkportSessionCredentials
{
    public function __construct(
        private readonly string $cred,
        private readonly string $salt,
        private readonly string $userId,
        private readonly ?string $hgId = null,
        private readonly ?string $nickname = null,
        private readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function getCred(): string
    {
        return $this->cred;
    }

    public function getSalt(): string
    {
        return $this->salt;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getHgId(): ?string
    {
        return $this->hgId;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= new \DateTimeImmutable();
    }
}
