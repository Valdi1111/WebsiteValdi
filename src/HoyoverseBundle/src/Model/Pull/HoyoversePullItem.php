<?php

namespace App\HoyoverseBundle\Model\Pull;

use App\HoyoverseBundle\Entity\HoyoversePull;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

#[Map(target: HoyoversePull::class)]
class HoyoversePullItem
{
    #[Map(target: 'pullId')]
    #[SerializedName('id')]
    private ?string $id = null;

    #[Map(target: 'gachaType')]
    #[SerializedName('gacha_type')]
    private ?string $gachaType = null;

    #[Map(target: 'itemType')]
    #[SerializedName('item_type')]
    private ?string $itemType = null;

    #[Map(target: 'itemId')]
    #[SerializedName('item_id')]
    private ?string $itemId = null;

    #[Map(target: 'name')]
    #[SerializedName('name')]
    private ?string $name = null;

    #[Map(target: 'rarity')]
    #[SerializedName('rank_type')]
    private ?int $rankType = null;

    #[Map(target: 'pulledAt')]
    #[SerializedName('time')]
    #[Context([
        DateTimeNormalizer::FORMAT_KEY => 'Y-m-d H:i:s',
        DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
    ])]
    private ?\DateTimeImmutable $time = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getGachaType(): ?string
    {
        return $this->gachaType;
    }

    public function setGachaType(?string $gachaType): static
    {
        $this->gachaType = $gachaType;

        return $this;
    }

    public function getItemType(): ?string
    {
        return $this->itemType;
    }

    public function setItemType(?string $itemType): static
    {
        $this->itemType = $itemType;

        return $this;
    }

    public function getItemId(): ?string
    {
        return $this->itemId;
    }

    public function setItemId(?string $itemId): static
    {
        $this->itemId = $itemId;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getRankType(): ?int
    {
        return $this->rankType;
    }

    public function setRankType(int|string|null $rankType): static
    {
        $this->rankType = $rankType !== null ? (int) $rankType : null;

        return $this;
    }

    public function getTime(): ?\DateTimeImmutable
    {
        return $this->time;
    }

    public function setTime(?\DateTimeImmutable $time): static
    {
        $this->time = $time;

        return $this;
    }
}
