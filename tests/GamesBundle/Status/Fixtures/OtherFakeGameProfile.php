<?php

namespace App\Tests\GamesBundle\Status\Fixtures;

use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;

/**
 * Profile of a second fake provider: not an instance of FakeGameProfile, as profiles of two providers never are.
 */
class OtherFakeGameProfile implements GameProfileInterface
{
    private FakeGameProfile $inner;

    public function __construct(FakeGameProfile $inner)
    {
        $this->inner = $inner;
    }

    public function getId(): ?int
    {
        return $this->inner->getId();
    }

    public function isActive(): ?bool
    {
        return $this->inner->isActive();
    }

    public function getGameUid(): ?string
    {
        return $this->inner->getGameUid();
    }

    public function getNickname(): ?string
    {
        return $this->inner->getNickname();
    }

    public function getLevel(): ?int
    {
        return $this->inner->getLevel();
    }

    public function getGameBiz(): ?string
    {
        return $this->inner->getGameBiz();
    }

    public function getGameName(): ?string
    {
        return $this->inner->getGameName();
    }

    public function getIconUrl(): ?string
    {
        return null;
    }

    public function getProviderIconUrl(): ?string
    {
        return null;
    }

    public function getParsedRegion(): ?string
    {
        return null;
    }

    public function getParsedTimezone(): ?string
    {
        return null;
    }

    public function getStaminaThreshold(): ?int
    {
        return null;
    }

    public function getNotificationPlatforms(): array
    {
        return [];
    }

    public function getAccount(): ?GameAccountInterface
    {
        return $this->inner->getAccount();
    }
}
