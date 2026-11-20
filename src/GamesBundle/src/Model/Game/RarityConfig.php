<?php

declare(strict_types=1);

namespace App\GamesBundle\Model\Game;

use Symfony\Component\Serializer\Attribute\SerializedName;

class RarityConfig
{
    public function __construct(
        private RarityTierConfig $s,
        private RarityTierConfig $a,
        private RarityTierConfig $b,
        #[SerializedName('pull_label')]
        private string $pullLabel,
        #[SerializedName('pull_singular_label')]
        private string $pullSingularLabel,
        #[SerializedName('s_resets_a_pity')]
        private bool $sResetsAPity,
    ) {
    }

    public function getS(): RarityTierConfig
    {
        return $this->s;
    }

    public function setS(RarityTierConfig $s): static
    {
        $this->s = $s;

        return $this;
    }

    public function getA(): RarityTierConfig
    {
        return $this->a;
    }

    public function setA(RarityTierConfig $a): static
    {
        $this->a = $a;

        return $this;
    }

    public function getB(): RarityTierConfig
    {
        return $this->b;
    }

    public function setB(RarityTierConfig $b): static
    {
        $this->b = $b;

        return $this;
    }

    public function getPullLabel(): string
    {
        return $this->pullLabel;
    }

    public function setPullLabel(string $pullLabel): static
    {
        $this->pullLabel = $pullLabel;

        return $this;
    }

    public function getPullSingularLabel(): string
    {
        return $this->pullSingularLabel;
    }

    public function setPullSingularLabel(string $pullSingularLabel): static
    {
        $this->pullSingularLabel = $pullSingularLabel;

        return $this;
    }

    public function isSResetsAPity(): bool
    {
        return $this->sResetsAPity;
    }

    public function setSResetsAPity(bool $sResetsAPity): static
    {
        $this->sResetsAPity = $sResetsAPity;

        return $this;
    }
}
