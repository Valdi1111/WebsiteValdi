<?php

declare(strict_types=1);

namespace App\GamesBundle\Model\Game;

class GachaBannerConfig
{
    /**
     * @param list<string> $gachaTypes Underlying API banner/pool type IDs grouped under this banner (defaults to [$key]).
     */
    public function __construct(
        private string $key = '',
        private string $name = '',
        private array $gachaTypes = [],
        private int $sMaxPity = 90,
        private int $aMaxPity = 10,
        private ?string $endpointUrl = null,
    ) {
        if ($this->gachaTypes === [] && $this->key !== '') {
            $this->gachaTypes = [$this->key];
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;
        if ($this->gachaTypes === [] && $key !== '') {
            $this->gachaTypes = [$key];
        }

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getGachaTypes(): array
    {
        if ($this->gachaTypes === [] && $this->key !== '') {
            return [$this->key];
        }

        return $this->gachaTypes;
    }

    /**
     * @param list<string> $gachaTypes
     */
    public function setGachaTypes(array $gachaTypes): static
    {
        $this->gachaTypes = array_values(array_map('strval', $gachaTypes));

        return $this;
    }

    public function getSMaxPity(): int
    {
        return $this->sMaxPity;
    }

    public function setSMaxPity(int $sMaxPity): static
    {
        $this->sMaxPity = $sMaxPity;

        return $this;
    }

    public function getAMaxPity(): int
    {
        return $this->aMaxPity;
    }

    public function setAMaxPity(int $aMaxPity): static
    {
        $this->aMaxPity = $aMaxPity;

        return $this;
    }

    public function getEndpointUrl(): ?string
    {
        return $this->endpointUrl;
    }

    public function setEndpointUrl(?string $endpointUrl): static
    {
        $this->endpointUrl = $endpointUrl;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toGroupArray(string $typesKey = 'gacha_types'): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            $typesKey => $this->getGachaTypes(),
            's_max_pity' => $this->sMaxPity,
            'a_max_pity' => $this->aMaxPity,
        ];
    }
}
