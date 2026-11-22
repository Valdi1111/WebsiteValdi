<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Pull\Manual\SWindow;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\PullGapPlanner;

/**
 * Pity and summary statistics of HoYoverse banners, computed from the plain rows returned by
 * HoyoversePullRepository. Pure: no I/O.
 *
 * Every row is one pull. S pity resets on a row whose rarity is at least the S rarity; A pity resets
 * on a row of exactly the A rarity, and also on an S when the game's RarityConfig says so. Filler rows
 * (unknown pulls created by the realign step) count as pulls but not as B, and reset the A pity: the
 * unknown pulls may have contained an A. Any other row is a B.
 *
 * Dates: rows store the server wall-clock time without an offset. Doctrine reads it back tagged with
 * PHP's default timezone, so toTimelineEntries() re-tags the same digits as UTC (the convention of
 * HoyoverseServerTimezoneResolver), which keeps the planner's arithmetic free of DST shifts.
 *
 * @phpstan-type BannerGroup array{key: string, name: string, gacha_types: list<string>, s_max_pity: int, a_max_pity: int}
 */
class HoyoversePullStatsCalculator
{
    /** Pity limits of a group created for a gacha type missing from the game's configuration. */
    public const int DYNAMIC_S_MAX_PITY = 90;
    public const int DYNAMIC_A_MAX_PITY = 10;

    /** Premium currency spent per pull (Primogems, Stellar Jade, Polychromes). */
    public const int CURRENCY_PER_PULL = 160;

    private const string WALL_CLOCK_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PullGapPlanner $planner,
    ) {
    }

    /**
     * Splits the rows of a profile into banner groups: every configured group first, in configuration
     * order and even when empty, then one dynamic group per unconfigured gacha type, in order of first
     * appearance and named after its first row.
     *
     * @param list<array<string, mixed>>    $rows         All rows of a profile, oldest first
     * @param array<array-key, BannerGroup> $bannerGroups Configured groups, as GachaConfig::getBannerGroups()
     *
     * @return list<array{group: BannerGroup, rows: list<array<string, mixed>>}>
     */
    public function groupRowsByBanner(array $rows, array $bannerGroups): array
    {
        $typeToGroupKey = [];
        foreach ($bannerGroups as $key => $group) {
            foreach ($group['gacha_types'] as $gachaType) {
                $typeToGroupKey[(string) $gachaType] = (string) $key;
            }
        }

        // Keyed by group key only to find the group of a row; numeric keys become ints, so callers get a list
        $grouped = [];
        foreach ($bannerGroups as $key => $group) {
            $grouped[(string) $key] = ['group' => $group, 'rows' => []];
        }

        foreach ($rows as $row) {
            $gachaType = (string) ($row['gachaType'] ?? '');
            // An unconfigured type whose value equals a configured group key joins that group
            $groupKey = $typeToGroupKey[$gachaType] ?? $gachaType;
            $grouped[$groupKey] ??= [
                'group' => [
                    'key' => $groupKey,
                    'name' => ($row['gachaName'] ?? null) ?: 'Banner '.$groupKey,
                    'gacha_types' => [$gachaType],
                    's_max_pity' => self::DYNAMIC_S_MAX_PITY,
                    'a_max_pity' => self::DYNAMIC_A_MAX_PITY,
                ],
                'rows' => [],
            ];
            $grouped[$groupKey]['rows'][] = $row;
        }

        return array_values($grouped);
    }

    /**
     * @param list<array<string, mixed>> $rows        Rows of one banner group, oldest first (HoyoversePullRepository::findBannerRows() order)
     * @param BannerGroup                $bannerGroup
     * @param \DateTimeImmutable|null    $now         Server wall-clock "now" tagged as UTC (upper limit of the
     *                                                suggested dates); null uses the current UTC time
     *
     * @return array{
     *     summary: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     monthly: array<string, array{month: string, total: int, s_count: int, a_count: int}>
     * }
     */
    public function compute(array $rows, array $bannerGroup, RarityConfig $rarityConfig, ?\DateTimeImmutable $now = null): array
    {
        $aRarity = $rarityConfig->getA()->getRarity();
        $sResetsAPity = $rarityConfig->isSResetsAPity();
        $windows = $this->planner->describe(
            $this->toTimelineEntries($rows, $rarityConfig),
            $now ?? WallClockDateTimeImmutableType::fromDateTime(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
        );

        $pullCount = 0;
        $sPity = 0;
        $aPity = 0;
        $sCount = 0;
        $aCount = 0;
        $bCount = 0;
        $unknownCount = 0;
        $sPitySum = 0;
        $aPitySum = 0;
        $recentSPulls = [];
        $oldestS = null;
        $items = [];
        $monthly = [];

        foreach ($rows as $row) {
            $countsAsPull = $this->countsAsPull($row);
            if ($countsAsPull) {
                ++$pullCount;
                ++$sPity;
                ++$aPity;
            }

            $origin = self::originOf($row);
            $isFiller = $origin === PullOrigin::Filler;
            $rarity = (int) ($row['rarity'] ?? 0);
            $isS = !$isFiller && $this->isS($row, $rarityConfig);
            $isA = !$isFiller && !$isS && $rarity === $aRarity;
            $declaredPity = isset($row['declaredPity']) ? (int) $row['declaredPity'] : null;
            // Rate-up flag of an S when a source gives it (null = unknown, never a lost 50/50)
            $isUp = isset($row['isUp']) ? (bool) $row['isUp'] : null;

            /** @var \DateTimeInterface|string|null $pulledAt */
            $pulledAt = $row['pulledAt'] ?? null;
            $pulledAtFormatted = $pulledAt instanceof \DateTimeInterface
                ? $pulledAt->format(self::WALL_CLOCK_FORMAT)
                : (string) $pulledAt;
            $pullNumber = $countsAsPull ? $pullCount : null;
            // Window limits of the manual pull forms, on S rows only
            $window = $isS ? self::windowFields($windows['windows'][(int) $row['id']] ?? null) : [];

            if ($isFiller) {
                $pity = null;
                ++$unknownCount;
                $aPity = 0;
            } elseif ($isS) {
                $pity = $sPity;
                ++$sCount;
                $sPitySum += $sPity;
                $recentSPulls[] = [
                    'id' => $row['id'],
                    'pull_number' => $pullNumber,
                    'item_id' => $row['itemId'],
                    'name' => $row['name'],
                    'item_type' => $row['itemType'],
                    'rarity' => $rarity,
                    'pity' => $pity,
                    'pulled_at' => $pulledAtFormatted,
                    'origin' => $origin->value,
                    'declared_pity' => $declaredPity,
                    'is_up' => $isUp,
                    ...$window,
                ];
                $sPity = 0;
                if ($sResetsAPity) {
                    $aPity = 0;
                }
            } elseif ($isA) {
                $pity = $aPity;
                ++$aCount;
                $aPitySum += $aPity;
                $aPity = 0;
            } else {
                $pity = 1;
                ++$bCount;
            }

            $item = [
                'id' => $row['id'],
                'pull_number' => $pullNumber,
                'pull_id' => $row['pullId'],
                'gacha_type' => $row['gachaType'],
                'gacha_name' => $row['gachaName'] ?: $bannerGroup['name'],
                'item_type' => $row['itemType'],
                'item_id' => $row['itemId'],
                'name' => $row['name'],
                'rarity' => $rarity,
                'pity' => $pity,
                'pulled_at' => $pulledAtFormatted,
                'origin' => $origin->value,
                'declared_pity' => $declaredPity,
                'is_up' => $isUp,
                ...$window,
            ];
            $items[] = $item;
            if ($isS) {
                $oldestS ??= $item;
            }

            if ($pulledAt instanceof \DateTimeInterface) {
                $month = $pulledAt->format('Y-m');
                $monthly[$month] ??= ['month' => $month, 'total' => 0, 's_count' => 0, 'a_count' => 0];
                $monthly[$month]['total'] += $countsAsPull ? 1 : 0;
                $monthly[$month]['s_count'] += $isS ? 1 : 0;
                $monthly[$month]['a_count'] += $isA ? 1 : 0;
            }
        }

        return [
            'summary' => [
                'key' => $bannerGroup['key'],
                'name' => $bannerGroup['name'],
                'gacha_types' => $bannerGroup['gacha_types'],
                'total_pulls' => $pullCount,
                'currency_spent' => $pullCount * self::CURRENCY_PER_PULL,
                's_count' => $sCount,
                'a_count' => $aCount,
                'b_count' => $bCount,
                's_pity' => $sPity,
                's_max_pity' => $bannerGroup['s_max_pity'],
                'a_pity' => $aPity,
                'a_max_pity' => $bannerGroup['a_max_pity'],
                'avg_s_pity' => $sCount > 0 ? round($sPitySum / $sCount, 2) : 0.0,
                'avg_a_pity' => $aCount > 0 ? round($aPitySum / $aCount, 2) : 0.0,
                // Newest first
                'recent_s_pulls' => array_reverse($recentSPulls),
                'unknown_count' => $unknownCount,
                // Window after the last S: where a missing S newer than every recorded one goes
                'open_window' => $windows['open']->toArray(),
                // First S of the banner (with its window): anchor of a missing S older than every recorded one
                'oldest_s' => $oldestS,
            ],
            'items' => $items,
            'monthly' => $monthly,
        ];
    }

    /**
     * Newest-first items filtered by rarity (0 = unknown pulls) and name (case-insensitive), then paginated.
     *
     * @param list<array<string, mixed>> $items    Items as returned by compute() (oldest first)
     * @param list<int>                  $rarities Empty: every rarity
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function filterAndPaginate(array $items, array $rarities, ?string $search, int $page, int $limit): array
    {
        $raritySet = $rarities !== [] ? array_flip(array_map('intval', $rarities)) : null;
        $search = $search !== null ? mb_strtolower(trim($search)) : '';

        $filtered = [];
        foreach (array_reverse($items) as $item) {
            if ($raritySet !== null && !isset($raritySet[(int) $item['rarity']])) {
                continue;
            }
            if ($search !== '' && !str_contains(mb_strtolower((string) $item['name']), $search)) {
                continue;
            }
            $filtered[] = $item;
        }

        return [
            'items' => array_slice($filtered, max(0, ($page - 1) * $limit), $limit),
            'total' => count($filtered),
        ];
    }

    /**
     * The rows of one banner group as entries of the shared missing-pulls logic, built with the same
     * rules as compute(). Dates are re-tagged as UTC wall-clock values (see the class comment).
     *
     * @param list<array<string, mixed>> $rows Rows of one banner group, oldest first, fillers and manual rows included
     *
     * @return list<PullTimelineEntry>
     */
    public function toTimelineEntries(array $rows, RarityConfig $rarityConfig): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $itemId = $row['itemId'] ?? null;
            $entries[] = new PullTimelineEntry(
                id: (int) $row['id'],
                pulledAt: self::wallClockOf($row['pulledAt']),
                origin: self::originOf($row),
                isS: $this->isS($row, $rarityConfig),
                countsAsPull: $this->countsAsPull($row),
                declaredPity: isset($row['declaredPity']) ? (int) $row['declaredPity'] : null,
                itemId: $itemId !== null && $itemId !== '' ? (string) $itemId : null,
                name: isset($row['name']) ? (string) $row['name'] : null,
                pullNumber: isset($row['pullNumber']) ? (int) $row['pullNumber'] : null,
            );
        }

        return $entries;
    }

    /**
     * Whether the row is an S: resets S pity and lists in recent_s_pulls. Filler rows (rarity 0) never are.
     *
     * @param array<string, mixed> $row
     */
    public function isS(array $row, RarityConfig $rarityConfig): bool
    {
        return (int) ($row['rarity'] ?? 0) >= $rarityConfig->getS()->getRarity();
    }

    /**
     * Whether the row is one pull of its banner: pull number, pity, totals, currency and monthly total.
     * Every HoYoverse row is, fillers included.
     *
     * @param array<string, mixed> $row
     */
    public function countsAsPull(array $row): bool
    {
        return true;
    }

    /**
     * Server wall-clock digits tagged as UTC. Hydrated rows already are (wall-clock column type);
     * values built elsewhere (tests, strings) are normalized the same way.
     */
    private static function wallClockOf(\DateTimeInterface|string $dateTime): \DateTimeImmutable
    {
        return $dateTime instanceof \DateTimeInterface
            ? WallClockDateTimeImmutableType::fromDateTime($dateTime)
            : WallClockDateTimeImmutableType::fromString(trim($dateTime));
    }

    /**
     * Origin of a row: the hydrated enum, its string value, or recorded when the column is not selected.
     *
     * @param array<string, mixed> $row
     */
    private static function originOf(array $row): PullOrigin
    {
        $origin = $row['origin'] ?? null;

        return match (true) {
            $origin instanceof PullOrigin => $origin,
            \is_string($origin) => PullOrigin::from($origin),
            default => PullOrigin::Recorded,
        };
    }

    /**
     * Window fields of an S item (its declared pity is already on the row).
     *
     * @return array<string, mixed>
     */
    private static function windowFields(?SWindow $window): array
    {
        if ($window === null) {
            return [];
        }

        $fields = $window->toArray();
        unset($fields['declared_pity']);

        return $fields;
    }
}
