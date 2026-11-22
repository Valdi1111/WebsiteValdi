<?php

namespace App\GamesBundle\Model\Pull;

/**
 * A provider pull row normalized for the shared missing-pulls logic (PullGapPlanner, ManualPullGuard).
 *
 * A list of entries always describes ONE banner group, in the provider's chronological order
 * (oldest first, with the provider's own tie-break for rows sharing the same second).
 * $isS and $countsAsPull must come from the provider's own pity calculator rules, so the
 * realign step counts pulls exactly like the pity statistics do.
 *
 * Inside one second, rows without a pull number come first (provider tie-break), then the numbered
 * rows by number (pullNumberOrderKey()). A second is not supposed to hold both: numbers only exist
 * on rows built from an official S-rank history, whose seconds hold no recorded row.
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
     * @param int|null    $pullNumber   Pull number in the banner, known only for rows built from an official
     *                                  S-rank history and the fillers placed between two of them (null otherwise)
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
        public ?int $pullNumber = null,
    ) {
    }

    /**
     * Sort key of a pull number inside one second, the order of MySQL for a nullable column in ascending order:
     * unnumbered rows first, then numbered rows by number. Compare the keys with <=> (PHP alone would treat
     * null as equal to 0).
     *
     * @return array{0: bool, 1: int}
     */
    public static function pullNumberOrderKey(?int $pullNumber): array
    {
        return [$pullNumber !== null, $pullNumber ?? 0];
    }
}
