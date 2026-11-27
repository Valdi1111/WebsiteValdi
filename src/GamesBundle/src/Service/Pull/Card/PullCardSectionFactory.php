<?php

namespace App\GamesBundle\Service\Pull\Card;

use App\GamesBundle\Model\Pull\Card\PullCardItem;
use App\GamesBundle\Model\Pull\Card\PullCardSection;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\PullItemIconUrlBuilder;

/**
 * Card section of a banner, from the summary of the pull stats calculators (the same shape for every provider).
 */
class PullCardSectionFactory
{
    public function __construct(
        private readonly PullItemIconUrlBuilder $iconUrlBuilder,
    ) {
    }

    /**
     * @param class-string|object             $gameService
     * @param array<string, mixed>            $summary     summary of a banner, recent_s_pulls newest first
     * @param ?int                            $limit       S pulls shown at most, the newest ones; null shows them all
     * @param list<array<string, mixed>>|null $sPulls      every S pull of the banner, newest first, when the summary
     *                                                     caps recent_s_pulls (NTE); null reads recent_s_pulls
     */
    public function fromSummary(string|object $gameService, array $summary, ?int $limit, ?array $sPulls = null): PullCardSection
    {
        $sPulls = array_values($sPulls ?? $summary['recent_s_pulls'] ?? []);
        $sCount = (int) ($summary['s_count'] ?? count($sPulls));

        $upCount = 0;
        $upKnown = 0;
        foreach ($sPulls as $row) {
            if (($row['is_up'] ?? null) !== null) {
                ++$upKnown;
                // The same reading as the UP badge of the item
                $upCount += (bool) $row['is_up'] ? 1 : 0;
            }
        }

        $items = [];
        foreach ($limit === null ? $sPulls : array_slice($sPulls, 0, max(0, $limit)) as $row) {
            $items[] = new PullCardItem(
                name: (string) ($row['name'] ?? ''),
                pity: isset($row['pity']) ? (int) $row['pity'] : null,
                isUp: isset($row['is_up']) ? (bool) $row['is_up'] : null,
                isFree: ($row['is_free'] ?? false) === true,
                isManual: ($row['origin'] ?? null) === PullOrigin::Manual->value,
                hasDeclaredPity: ($row['declared_pity'] ?? null) !== null,
                iconPath: $this->iconUrlBuilder->buildRowPath($gameService, $row),
            );
        }

        $pullLabel = $summary['pull_label'] ?? null;

        return new PullCardSection(
            name: (string) ($summary['name'] ?? ''),
            totalPulls: (int) ($summary['total_pulls'] ?? 0),
            sCount: $sCount,
            avgSPity: (float) ($summary['avg_s_pity'] ?? 0.0),
            sPity: (int) ($summary['s_pity'] ?? 0),
            sMaxPity: max(1, (int) ($summary['s_max_pity'] ?? 90)),
            items: $items,
            // The S count tells how many exist when the list given is shorter
            hiddenCount: max(0, max($sCount, count($sPulls)) - count($items)),
            upCount: $upCount,
            upKnown: $upKnown,
            pullLabel: is_string($pullLabel) && $pullLabel !== '' ? $pullLabel : null,
        );
    }
}
