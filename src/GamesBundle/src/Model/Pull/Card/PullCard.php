<?php

namespace App\GamesBundle\Model\Pull\Card;

/**
 * Content of the shareable pulls card: one banner, or every banner of a game profile.
 */
final readonly class PullCard
{
    /**
     * @param list<PullCardSection> $sections
     * @param ?string               $playerName shown only when the owner chose to
     * @param ?string               $playerUid  shown only when the owner chose to
     */
    public function __construct(
        public string $gameName,
        public string $title,
        public string $sLabel,
        public string $pullLabel,
        public array $sections,
        public \DateTimeImmutable $generatedAt,
        public ?string $playerName = null,
        public ?string $playerUid = null,
    ) {
    }
}
