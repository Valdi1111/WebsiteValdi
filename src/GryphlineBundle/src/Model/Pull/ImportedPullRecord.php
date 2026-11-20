<?php

namespace App\GryphlineBundle\Model\Pull;

final readonly class ImportedPullRecord
{
    /**
     * @param array<string, mixed>|null $rawPayload
     */
    public function __construct(
        public string $seqId,
        public string $poolType,
        public string $poolCategory,
        public ?string $poolId,
        public ?string $poolName,
        public string $itemId,
        public string $name,
        public int $rarity,
        public \DateTimeImmutable $pulledAt,
        public ?string $gachaTs = null,
        public ?array $rawPayload = null,
        public bool $isSyntheticId = false,
        public ?string $warning = null,
    ) {
    }
}
