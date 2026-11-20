<?php

namespace App\GamesBundle\Model\Game;

final readonly class PullItemIconQuery
{
    public function __construct(
        public ?string $itemId = null,
        public ?string $name = null,
        public ?string $itemType = null,
        public ?int $rarity = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromPullRow(array $row): self
    {
        $itemId = $row['item_id'] ?? $row['itemId'] ?? null;
        $name = $row['name'] ?? null;
        $itemType = $row['item_type'] ?? $row['itemType'] ?? $row['pool_category'] ?? $row['poolCategory'] ?? null;
        $rarity = isset($row['rarity']) ? (int) $row['rarity'] : null;

        return new self(
            itemId: $itemId !== null && $itemId !== '' ? (string) $itemId : null,
            name: $name !== null && $name !== '' ? (string) $name : null,
            itemType: $itemType !== null && $itemType !== '' ? (string) $itemType : null,
            rarity: $rarity,
        );
    }
}
