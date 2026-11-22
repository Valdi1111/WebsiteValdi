<?php

namespace App\GryphlineBundle\Service\Pull;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\PullGapPlanner;

/**
 * Pity and summary statistics of Endfield banners, in the JSON shape shared by the pull pages.
 *
 * Every row counts as a headhunt; S pity resets on an S (rarity >= S tier), A pity on an A (and on an S
 * when the rarity config says so). Filler rows (unknown pulls of the missing history) count as headhunts
 * but not as B, and reset the A pity. Rows are ordered by pulledAt, then by numeric seqId.
 *
 * Wall clock: pulled_at holds the UTC wall time of the API gachaTs, without offset, and is hydrated as
 * UTC by WallClockDateTimeImmutableType. The missing-pulls logic (timeline entries, "now", manual dates)
 * works on UTC objects, so it never depends on the PHP default timezone.
 */
class GryphlinePullStatsCalculator
{
    public const string WALL_CLOCK_TIMEZONE = 'UTC';

    private const int CURRENCY_PER_PULL = 500;
    private const int DYNAMIC_S_MAX_PITY = 80;
    private const int DYNAMIC_A_MAX_PITY = 10;

    public function __construct(
        private readonly PullGapPlanner $planner,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows Rows of one banner, in any order
     * @param array{key: string, name: string, categories: array<string>, s_max_pity: int, a_max_pity: int} $bannerGroup
     * @param \DateTimeImmutable|null $now Upper limit of the suggested manual dates (null: now, see self::now())
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

        $rows = $this->sortRows($rows);
        $windows = $this->planner->describe($this->buildTimelineEntries($rows, $rarityConfig), $now ?? self::now());

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
            if ($this->countsAsPull($row)) {
                $pullCount++;
                $sPity++;
                $aPity++;
            }
            $pullNumber = $pullCount;
            $rarity = (int) ($row['rarity'] ?? 0);
            $origin = self::originOf($row);
            $isFiller = $origin === PullOrigin::Filler;
            $isS = !$isFiller && $this->isS($row, $rarityConfig);
            $isA = !$isFiller && !$isS && $rarity === $aRarity;
            $declared = [
                'origin' => $origin->value,
                'declared_pity' => self::declaredPityOf($row),
                // Rate-up flag of an S when a source gives it (null = unknown, never a lost 50/50)
                'is_up' => isset($row['isUp']) ? (bool) $row['isUp'] : null,
            ];

            /** @var \DateTimeInterface|string|null $pulledAt */
            $pulledAt = $row['pulledAt'] ?? null;
            $pulledAtFormatted = $pulledAt instanceof \DateTimeInterface
                ? $pulledAt->format('Y-m-d H:i:s')
                : (string) $pulledAt;

            if ($isFiller) {
                // Unknown pull: an A may have happened in it, so the A pity starts again
                $itemPity = null;
                $unknownCount++;
                $aPity = 0;
            } elseif ($isS) {
                $itemPity = $sPity;
                $sCount++;
                $sPitySum += $sPity;
                $recentSPulls[] = [
                    'id' => $row['id'],
                    'pull_number' => $pullNumber,
                    'item_id' => $row['itemId'],
                    'name' => $row['name'],
                    'item_type' => $row['poolCategory'] ?? 'char',
                    'rarity' => $rarity,
                    'pity' => $itemPity,
                    'pulled_at' => $pulledAtFormatted,
                ] + $declared + $windows['windows'][$row['id']]->toArray();
                $sPity = 0;
                if ($sResetsAPity) {
                    $aPity = 0;
                }
            } elseif ($isA) {
                $itemPity = $aPity;
                $aCount++;
                $aPitySum += $aPity;
                $aPity = 0;
            } else {
                $itemPity = 1;
                $bCount++;
            }

            $item = [
                'id' => $row['id'],
                'pull_number' => $pullNumber,
                'seq_id' => $row['seqId'],
                'pool_type' => $row['poolType'],
                'pool_category' => $row['poolCategory'],
                'pool_name' => $row['poolName'] ?: $bannerGroup['name'],
                'gacha_name' => $row['poolName'] ?: $bannerGroup['name'],
                // Raw category: also consumed by the pull item icon resolver
                'item_type' => $row['poolCategory'],
                'item_type_label' => self::getItemTypeLabel($row['poolCategory']),
                'item_id' => $row['itemId'],
                'name' => $row['name'],
                'rarity' => $rarity,
                'pity' => $itemPity,
                'pulled_at' => $pulledAtFormatted,
            ] + $declared;
            if ($isS) {
                // Limits of the manual pull forms (declare a pity, add an earlier S)
                $item += $windows['windows'][$row['id']]->toArray();
                $oldestS ??= $item;
            }
            $items[] = $item;

            if ($pulledAt instanceof \DateTimeInterface) {
                $month = $pulledAt->format('Y-m');
                $monthly[$month] ??= ['month' => $month, 'total' => 0, 's_count' => 0, 'a_count' => 0];
                $monthly[$month]['total']++;
                if ($isS) {
                    $monthly[$month]['s_count']++;
                } elseif ($isA) {
                    $monthly[$month]['a_count']++;
                }
            }
        }

        return [
            'summary' => [
                'key' => $bannerGroup['key'],
                'name' => $bannerGroup['name'],
                'categories' => $bannerGroup['categories'],
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
                'recent_s_pulls' => array_reverse($recentSPulls),
                'unknown_count' => $unknownCount,
                // Window after the last S: limits of a manual S added without an anchor
                'open_window' => $windows['open']->toArray(),
                // First S of the banner (full item): anchor of "Add missing S"
                'oldest_s' => $oldestS,
            ],
            'items' => $items,
            'monthly' => $monthly,
        ];
    }

    /**
     * Newest-first items filtered by rarity (0: unknown pulls) and name, then paginated.
     *
     * @param list<array<string, mixed>> $items Items as returned by compute() (oldest first)
     * @param list<int> $rarities
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
     * Splits a profile's rows by banner group. A row belongs to the group listing its pool type (or, without
     * one, its pool category); a pool type no group lists gets its own group, named after its first pool,
     * with default pity limits. Configured groups come first, in config order, even when empty.
     *
     * @param list<array<string, mixed>> $rows All rows of a profile, in any order
     * @param array<array-key, array{key: string, name: string, categories: array<string>, s_max_pity: int, a_max_pity: int}> $bannerGroups
     * @return list<array{
     *     group: array{key: string, name: string, categories: array<string>, s_max_pity: int, a_max_pity: int},
     *     rows: list<array<string, mixed>>
     * }>
     */
    public function groupRowsByBanner(array $rows, array $bannerGroups): array
    {
        // Indexes into $grouped by group key; a list keeps numeric-looking keys as strings
        $indexByKey = [];
        $categoryToGroupKey = [];
        $grouped = [];
        foreach ($bannerGroups as $key => $group) {
            foreach ($group['categories'] as $category) {
                $categoryToGroupKey[(string) $category] = (string) $key;
            }
            $indexByKey[(string) $key] = \count($grouped);
            $grouped[] = ['group' => $group, 'rows' => []];
        }

        foreach ($this->sortRows($rows) as $row) {
            $category = (string) ($row['poolType'] ?: ($row['poolCategory'] ?? 'char'));
            $groupKey = $categoryToGroupKey[$category] ?? $category;

            if (!isset($indexByKey[$groupKey])) {
                $indexByKey[$groupKey] = \count($grouped);
                $grouped[] = [
                    'group' => [
                        'key' => $groupKey,
                        'name' => $row['poolName'] ?: ('Headhunting ' . $groupKey),
                        'categories' => [$category],
                        's_max_pity' => self::DYNAMIC_S_MAX_PITY,
                        'a_max_pity' => self::DYNAMIC_A_MAX_PITY,
                    ],
                    'rows' => [],
                ];
            }
            $grouped[$indexByKey[$groupKey]]['rows'][] = $row;
        }

        return $grouped;
    }

    /**
     * Chronological order: pulledAt, then numeric seqId (pulls of a ten-pull share the same timestamp;
     * the "manual_" / "filler_" seq ids count as 0, but those rows never share a second with another row).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function sortRows(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => ($a['pulledAt'] <=> $b['pulledAt']) ?: (((int) $a['seqId']) <=> ((int) $b['seqId'])));

        return $rows;
    }

    /**
     * Rows of one banner group as entries of the shared missing-pulls logic (PullGapPlanner, ManualPullGuard),
     * in the order compute() counts them and with the same isS() / countsAsPull() rules.
     *
     * @param list<array<string, mixed>> $rows Rows of one banner group, in any order
     * @return list<PullTimelineEntry>
     */
    public function toTimelineEntries(array $rows, RarityConfig $rarityConfig): array
    {
        return $this->buildTimelineEntries($this->sortRows($rows), $rarityConfig);
    }

    /**
     * Whether a row is an S (6★): it resets the S pity. Filler rows (rarity 0) never are.
     *
     * @param array<string, mixed> $row
     */
    public function isS(array $row, RarityConfig $rarityConfig): bool
    {
        return (int) ($row['rarity'] ?? 0) >= $rarityConfig->getS()->getRarity();
    }

    /**
     * Whether a row is a headhunt: it advances the pity, the pull number and the currency spent.
     *
     * @param array<string, mixed> $row
     */
    public function countsAsPull(array $row): bool
    {
        return true;
    }

    /**
     * Human-readable label of a pool category, shown next to item names in the pulls table.
     */
    public static function getItemTypeLabel(?string $poolCategory): ?string
    {
        return match ($poolCategory) {
            null, '' => null,
            'char' => 'Operator',
            'weapon' => 'Weapon',
            default => ucfirst($poolCategory),
        };
    }

    /**
     * Current time in the pull wall-clock convention (UTC).
     */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::WALL_CLOCK_TIMEZONE));
    }

    /**
     * @param list<array<string, mixed>> $sortedRows
     * @return list<PullTimelineEntry>
     */
    private function buildTimelineEntries(array $sortedRows, RarityConfig $rarityConfig): array
    {
        $entries = [];
        foreach ($sortedRows as $row) {
            $origin = self::originOf($row);
            $itemId = $row['itemId'] ?? null;
            $entries[] = new PullTimelineEntry(
                id: (int) $row['id'],
                pulledAt: WallClockDateTimeImmutableType::fromDateTime($row['pulledAt']),
                origin: $origin,
                isS: $origin !== PullOrigin::Filler && $this->isS($row, $rarityConfig),
                countsAsPull: $this->countsAsPull($row),
                declaredPity: self::declaredPityOf($row),
                itemId: $itemId !== null && $itemId !== '' ? (string) $itemId : null,
                name: isset($row['name']) ? (string) $row['name'] : null,
            );
        }

        return $entries;
    }

    /**
     * Rows queried before the origin column existed (or built by hand) are recorded pulls.
     *
     * @param array<string, mixed> $row
     */
    private static function originOf(array $row): PullOrigin
    {
        $origin = $row['origin'] ?? null;

        return $origin instanceof PullOrigin ? $origin : (PullOrigin::tryFrom((string) $origin) ?? PullOrigin::Recorded);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function declaredPityOf(array $row): ?int
    {
        return isset($row['declaredPity']) ? (int) $row['declaredPity'] : null;
    }
}
