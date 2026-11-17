<?php

declare(strict_types=1);

namespace App\CoreBundle\Model\Game;

class RarityTierConfig
{
    public function __construct(
        private int $rarity,
        private string $label,
        private string $short,
    ) {
    }

    public function getRarity(): int
    {
        return $this->rarity;
    }

    public function setRarity(int $rarity): static
    {
        $this->rarity = $rarity;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getShort(): string
    {
        return $this->short;
    }

    public function setShort(string $short): static
    {
        $this->short = $short;

        return $this;
    }
}
