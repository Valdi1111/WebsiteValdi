<?php

namespace App\PerfectworldBundle\Model\Pull;

/**
 * One normalized pull record produced by an importer, ready to be upserted.
 */
final readonly class ImportedPullRecord
{
    /**
     * @param string                    $pulledAt      Wall-clock "Y-m-d H:i:s" exactly as exported
     * @param int                       $groupPosition Index among records of the same second, 0 = most recent
     * @param array<string, mixed>|null $rawPayload
     */
    public function __construct(
        public string $bannerKey,
        public ?string $poolId,
        public string $pulledAt,
        public int $groupPosition,
        public string $kind,
        public ?int $rollResult,
        public string $rewardType,
        public string $rewardId,
        public string $name,
        public ?int $rarity,
        public int $quantity,
        public ?string $exportUid,
        public string $exporterVersion,
        public ?array $rawPayload = null,
        public ?string $warning = null,
    ) {
    }

    public function withWarning(string $warning): self
    {
        return new self(
            $this->bannerKey,
            $this->poolId,
            $this->pulledAt,
            $this->groupPosition,
            $this->kind,
            $this->rollResult,
            $this->rewardType,
            $this->rewardId,
            $this->name,
            $this->rarity,
            $this->quantity,
            $this->exportUid,
            $this->exporterVersion,
            $this->rawPayload,
            $warning,
        );
    }

    public function getIdentityKey(): string
    {
        return $this->pulledAt . '|' . $this->groupPosition;
    }
}
