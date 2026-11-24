<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Imaginarium Theater of the player (game_record/genshin/api/role_combat): the current and the previous period.
 */
class TheaterLobbyData
{
    /**
     * @var TheaterLobbyType[]|null
     */
    #[SerializedName('data')]
    private ?array $data = null;

    #[SerializedName('is_unlock')]
    private ?bool $isUnlock = null;

    /**
     * @return TheaterLobbyType[]|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @param TheaterLobbyType[]|null $data
     */
    public function setData(?array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function getIsUnlock(): ?bool
    {
        return $this->isUnlock;
    }

    public function setIsUnlock(?bool $isUnlock): static
    {
        $this->isUnlock = $isUnlock;
        return $this;
    }

    /**
     * Locked only when the response says so (is_unlock false).
     */
    public function isUnlocked(): bool
    {
        return $this->getIsUnlock() !== false;
    }

    /**
     * The period with schedule_type 1, null when the response has none.
     */
    public function getCurrentPeriod(): ?TheaterLobbyType
    {
        foreach ($this->getData() ?? [] as $period) {
            if ($period->isCurrent()) {
                return $period;
            }
        }

        return null;
    }
}
