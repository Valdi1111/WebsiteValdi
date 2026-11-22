<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Best attempt on one Deadly Assault boss (list[] and hard_list[] of hadal_mem_detail_v2).
 */
class DeadlyAssaultChallenge
{
    #[SerializedName('score')]
    private ?int $score = null;

    #[SerializedName('star')]
    private ?int $star = null;

    /**
     * @var DeadlyAssaultBoss[]|null
     */
    #[SerializedName('boss')]
    private ?array $bosses = null;

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;
        return $this;
    }

    public function getStar(): ?int
    {
        return $this->star;
    }

    public function setStar(?int $star): static
    {
        $this->star = $star;
        return $this;
    }

    /**
     * @return DeadlyAssaultBoss[]|null
     */
    public function getBosses(): ?array
    {
        return $this->bosses;
    }

    /**
     * @param DeadlyAssaultBoss[]|null $bosses
     */
    public function setBosses(?array $bosses): static
    {
        $this->bosses = $bosses;
        return $this;
    }

    public function getBossName(): ?string
    {
        return ($this->getBosses()[0] ?? null)?->getName();
    }

    public function isPlayed(): bool
    {
        return ($this->getScore() ?? 0) > 0;
    }

    public function isFullyCleared(): bool
    {
        return ($this->getStar() ?? 0) >= DeadlyAssaultData::BOSS_MAX_STARS;
    }
}
