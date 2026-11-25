<?php

namespace App\Tests\GamesBundle\Status\Fixtures;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;

/**
 * Game profile of a fake provider, with its account (id and owner).
 */
class FakeGameProfile implements GameProfileInterface
{
    private ?GameAccountInterface $account = null;

    public function __construct(
        private ?int $id = 1,
        private ?string $gameBiz = 'fake_game',
        private ?bool $active = true,
        private ?int $staminaThreshold = -1,
        private ?string $gameName = 'Fake Game',
    ) {
    }

    public static function ownedBy(User $user, int $id, string $gameBiz = 'fake_game', int $accountId = 1, bool $active = true): static
    {
        $profile = new static($id, $gameBiz, $active);
        $profile->account = new class($accountId, $user) implements GameAccountInterface {
            public function __construct(private readonly int $id, private readonly User $user)
            {
            }

            public function getId(): ?int
            {
                return $this->id;
            }

            public function getUser(): ?User
            {
                return $this->user;
            }
        };

        return $profile;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function getGameUid(): ?string
    {
        return '800000' . $this->id;
    }

    public function getNickname(): ?string
    {
        return 'Player' . $this->id;
    }

    public function getLevel(): ?int
    {
        return 60;
    }

    public function getGameBiz(): ?string
    {
        return $this->gameBiz;
    }

    public function getGameName(): ?string
    {
        return $this->gameName;
    }

    public function getIconUrl(): ?string
    {
        return null;
    }

    public function getProviderIconUrl(): ?string
    {
        return 'https://example.test/provider.ico';
    }

    public function getParsedRegion(): ?string
    {
        return 'Europe';
    }

    public function getParsedTimezone(): ?string
    {
        return 'EU';
    }

    public function getStaminaThreshold(): ?int
    {
        return $this->staminaThreshold;
    }

    public function setStaminaThreshold(?int $threshold): static
    {
        $this->staminaThreshold = $threshold;

        return $this;
    }

    public function getNotificationPlatforms(): array
    {
        return [];
    }

    public function getAccount(): ?GameAccountInterface
    {
        return $this->account;
    }
}
