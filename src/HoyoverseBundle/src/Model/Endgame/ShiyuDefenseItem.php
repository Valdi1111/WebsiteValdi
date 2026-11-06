<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

class ShiyuDefenseItem
{
    #[SerializedName('score')]
    private ?int $score = null;

    #[SerializedName('star')]
    private ?int $star = null;

    #[SerializedName('nest_type')]
    private ?string $nestType = null;

    #[SerializedName('rank')]
    private ?int $rank = null;

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

    public function getNestType(): ?string
    {
        return $this->nestType;
    }

    public function setNestType(?string $nestType): static
    {
        $this->nestType = $nestType;
        return $this;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(?int $rank): static
    {
        $this->rank = $rank;
        return $this;
    }

}
