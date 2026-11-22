<?php

namespace App\GamesBundle\Model\Pull;

/**
 * A provider pull row normalized for the shared missing-pulls logic (PullGapPlanner, ManualPullGuard).
 *
 * A list of entries always describes ONE banner group, in the provider's chronological order
 * (oldest first, with the provider's own tie-break for rows sharing the same second).
 * $isS and $countsAsPull must come from the provider's own pity calculator rules, so the
 * realign step counts pulls exactly like the pity statistics do.
 */
final readonly class PullTimelineEntry
{
    /**
     * @param int         $id           Provider row id (auto-increment: a higher id means inserted later)
     * @param bool        $isS          The row resets the S pity
     * @param bool        $countsAsPull The row counts as one pull for the S pity
     * @param int|null    $declaredPity Real pity declared by the user (S rows only)
     * @param string|null $itemId       Provider item id, used to match a manual S with a recorded one
     * @param string|null $name         Item name, used for matching when an item id is missing
     */
    public function __construct(
        public int $id,
        public \DateTimeImmutable $pulledAt,
        public PullOrigin $origin,
        public bool $isS,
        public bool $countsAsPull,
        public ?int $declaredPity = null,
        public ?string $itemId = null,
        public ?string $name = null,
    ) {
    }
}
