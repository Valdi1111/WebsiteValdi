<?php

namespace App\GryphlineBundle\Model\Pull;

use App\GryphlineBundle\Entity\GryphlinePull;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

#[Map(target: GryphlinePull::class)]
class GryphlinePullItem
{
    #[Map(target: 'seqId')]
    #[SerializedName('seqId')]
    private ?string $seqId = null;

    #[Map(target: 'poolId')]
    #[SerializedName('poolId')]
    private ?string $poolId = null;

    #[Map(target: 'poolName')]
    #[SerializedName('poolName')]
    private ?string $poolName = null;

    #[Map(target: 'itemId')]
    #[SerializedName('itemId')]
    private ?string $itemId = null;

    #[Map(target: 'name')]
    #[SerializedName('itemName')]
    private ?string $itemName = null;

    #[Map(target: 'rarity')]
    #[SerializedName('rarity')]
    private ?int $rarity = null;

    #[Map(target: 'gachaTs')]
    #[SerializedName('gachaTs')]
    private ?string $gachaTs = null;

    #[Map(target: 'pulledAt')]
    private ?\DateTimeImmutable $gachaTime = null;

    #[Map(target: 'kind')]
    #[SerializedName('kind')]
    private string $kind = GryphlinePull::KIND_DRAW;

    #[Map(target: 'isFree')]
    #[SerializedName('isFree')]
    private bool $isFree = false;

    #[Map(target: 'poolVersion')]
    #[SerializedName('poolVersion')]
    private ?int $poolVersion = null;

    public function getSeqId(): ?string
    {
        return $this->seqId;
    }

    public function setSeqId(string|int|null $seqId): static
    {
        $this->seqId = $seqId !== null ? (string) $seqId : null;

        return $this;
    }

    public function getPoolId(): ?string
    {
        return $this->poolId;
    }

    public function setPoolId(?string $poolId): static
    {
        $this->poolId = $poolId;

        return $this;
    }

    public function getPoolName(): ?string
    {
        return $this->poolName;
    }

    public function setPoolName(?string $poolName): static
    {
        $this->poolName = $poolName;

        return $this;
    }

    public function getItemId(): ?string
    {
        return $this->itemId;
    }

    public function setItemId(?string $itemId): static
    {
        if ($itemId !== null && $itemId !== '') {
            $this->itemId = $itemId;
        }

        return $this;
    }

    #[SerializedName('charId')]
    public function setCharId(?string $charId): static
    {
        if ($charId !== null && $charId !== '') {
            $this->itemId = $charId;
        }

        return $this;
    }

    #[SerializedName('weaponId')]
    public function setWeaponId(?string $weaponId): static
    {
        if ($weaponId !== null && $weaponId !== '') {
            $this->itemId = $weaponId;
        }

        return $this;
    }

    public function getItemName(): ?string
    {
        return $this->itemName;
    }

    public function setItemName(?string $itemName): static
    {
        if ($itemName !== null && $itemName !== '') {
            $this->itemName = $itemName;
        }

        return $this;
    }

    #[SerializedName('nameText')]
    public function setNameText(?string $nameText): static
    {
        if ($nameText !== null && $nameText !== '') {
            $this->itemName = $nameText;
        }

        return $this;
    }

    #[SerializedName('charName')]
    public function setCharName(?string $charName): static
    {
        if ($charName !== null && $charName !== '' && $this->itemName === null) {
            $this->itemName = $charName;
        }

        return $this;
    }

    #[SerializedName('weaponName')]
    public function setWeaponName(?string $weaponName): static
    {
        if ($weaponName !== null && $weaponName !== '' && $this->itemName === null) {
            $this->itemName = $weaponName;
        }

        return $this;
    }

    public function getRarity(): ?int
    {
        return $this->rarity;
    }

    public function setRarity(int|string|null $rarity): static
    {
        $this->rarity = $rarity !== null ? (int) $rarity : null;

        return $this;
    }

    public function getGachaTs(): ?string
    {
        return $this->gachaTs;
    }

    public function setGachaTs(string|int|null $gachaTs): static
    {
        $this->gachaTs = $gachaTs !== null ? (string) $gachaTs : null;

        if ($this->gachaTs !== null && is_numeric($this->gachaTs)) {
            $tsSeconds = (int) floor(((float) $this->gachaTs) / 1000);
            $this->gachaTime = (new \DateTimeImmutable('@' . $tsSeconds))->setTimezone(new \DateTimeZone('UTC'));
        }

        return $this;
    }

    public function getGachaTime(): ?\DateTimeImmutable
    {
        return $this->gachaTime;
    }

    public function setGachaTime(?\DateTimeImmutable $gachaTime): static
    {
        $this->gachaTime = $gachaTime;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Records without a kind are pulls (the official page reads a missing kind as "draw").
     */
    public function setKind(?string $kind): static
    {
        $this->kind = $kind !== null && $kind !== '' ? $kind : GryphlinePull::KIND_DRAW;

        return $this;
    }

    public function isFree(): bool
    {
        return $this->isFree;
    }

    public function setIsFree(bool|int|string|null $isFree): static
    {
        $this->isFree = filter_var($isFree, \FILTER_VALIDATE_BOOLEAN);

        return $this;
    }

    public function getPoolVersion(): ?int
    {
        return $this->poolVersion;
    }

    /**
     * Rerun number of the banner; anything but a positive integer means "none".
     */
    public function setPoolVersion(int|string|null $poolVersion): static
    {
        $version = is_numeric($poolVersion) ? (int) $poolVersion : 0;
        $this->poolVersion = $version > 0 ? $version : null;

        return $this;
    }
}
