<?php

namespace App\HoyoverseBundle\Model\Pull;

final readonly class ImportedPullRecord
{
    /**
     * @param array<string, mixed>|null $rawPayload
     */
    public function __construct(
        public string $pullId,
        public string $gachaType,
        public string $itemType,
        public ?string $itemId,
        public string $name,
        public int $rarity,
        public \DateTimeImmutable $pulledAt,
        public ?array $rawPayload = null,
        public bool $isSyntheticId = false,
        public ?string $warning = null,
    ) {
    }
}
