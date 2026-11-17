<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;

interface HasPullTrackerInterface
{
    /**
     * @return array<int|string, string> gacha_type => Banner Display Name
     */
    public function getGachaBannerTypes(): array;

    /**
     * Returns banner groups configuration for analytics and pity computation.
     *
     * @return array<string, array{
     *     key: string,
     *     name: string,
     *     gacha_types: array<string>,
     *     s_max_pity: int,
     *     a_max_pity: int
     * }>
     */
    public function getGachaBannerGroups(): array;

    /**
     * Returns rarity tier definitions for this game (top, secondary, base).
     *
     * @return array{
     *     s_rarity: int,
     *     s_label: string,
     *     s_short: string,
     *     a_rarity: int,
     *     a_label: string,
     *     a_short: string,
     *     b_rarity: int,
     *     b_label: string,
     *     b_short: string,
     *     pull_label: string
     * }
     */
    public function getRarityConfig(): array;

    /**
     * Base URL for the gacha log API endpoint.
     */
    public function getGachaEndpointUrl(): string;

    /**
     * Fetches a single page of pulls for the given gacha_type and optional end_id cursor.
     *
     * @param array<string, mixed> $extraQueryParams
     *
     * @return array<HoyoversePull>
     */
    public function fetchPullsPage(
        HoyoverseGameProfile $profile,
        string|int $gachaType,
        ?string $authkey = null,
        ?string $endId = '0',
        array $extraQueryParams = [],
        int $size = 20
    ): array;
}
