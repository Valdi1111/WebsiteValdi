<?php

namespace App\GamesBundle\Model\Pull\Card;

/**
 * One S pull on the shareable card.
 */
final readonly class PullCardItem
{
    /**
     * @param ?bool   $isUp     rate-up flag, null when unknown
     * @param ?string $iconPath path of the item in the games_pull_item_icon filter set, null without an image
     */
    public function __construct(
        public string $name,
        public ?int $pity,
        public ?bool $isUp = null,
        public bool $isFree = false,
        public bool $isManual = false,
        public bool $hasDeclaredPity = false,
        public ?string $iconPath = null,
    ) {
    }
}
