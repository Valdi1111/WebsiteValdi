<?php

namespace App\GryphlineBundle\Service\Pull;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Catalog\AkeCatalog;

/**
 * Pity and summary statistics of Endfield banners, in the JSON shape shared by the pull pages.
 *
 * Only paid pulls count: a row is a headhunt when it is a "draw" record and not a free Urgent Recruitment pull.
 * - Paid pulls: S pity resets on an S (rarity >= S tier), A pity on an A (and on an S when the rarity config says
 *   so). Filler rows (unknown pulls of the missing history) count as headhunts but not as B, and reset the A pity.
 * - Free pulls: each free ten-pull (same pool and gachaTs) has its own S/A counters from its first pull, as the game
 *   guarantees a 5★ in it. They never touch the paid counters, the totals or the averages.
 * - Bonus entries (Headhunting Dossier, Token Tribute, weapon Tribute...): listed with a label, never counted, even
 *   when they carry a 6★ item.
 * Banners whose pity resets with each banner (Special Headhunting, Arsenal Issue, RE-Factor Issue) keep separate
 * paid counters per pool id (and rerun number). Rows are ordered by pulledAt, then by numeric seqId.
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

    /** Rarity of a featured item whose catalog entry has none: the rate-up items of a banner are its 6★ */
    private const int FEATURED_FALLBACK_RARITY = 6;

    /**
     * Banners with a per-banner breakdown in the summary: Chartered and RE-Factor Headhunting (pity shared across
     * their banners, so the breakdown only counts) and the per-banner pity ones
     */
    private const array POOLS_BREAKDOWN_BANNERS = [
        ArknightsEndfieldService::POOL_CHARTERED,
        ArknightsEndfieldService::POOL_RERUN,
        ...ArknightsEndfieldService::POOL_PITY_BANNERS,
    ];

    public function __construct(
        private readonly PullGapPlanner $planner,
        /** Rate-up items and runs of each banner; optional so tests can leave it out */
        private readonly ?AkeCatalog $catalog = null,
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
        $poolPity = $this->isPoolPityGroup($bannerGroup);
        $now ??= self::now();

        $rows = $this->sortRows($rows);
        $windows = [];
        $openWindows = [];
        foreach ($this->partitionByPityPool($rows, $bannerGroup) as $poolKey => $poolRows) {
            $described = $this->planner->describe($this->buildTimelineEntries($poolRows, $rarityConfig), $now);
            $windows += $described['windows'];
            $openWindows[$poolKey] = $described['open'];
        }

        $pullCount = 0;
        /** @var array<string, array{s: int, a: int}> $counters Paid counters, one per pity pool ('' when shared) */
        $counters = [];
        /** @var array<string, array{s: int, a: int}> $freeCounters Counters of each free ten-pull */
        $freeCounters = [];
        $lastPityPoolKey = '';
        $sCount = 0;
        $aCount = 0;
        $bCount = 0;
        $unknownCount = 0;
        $sPitySum = 0;
        $aPitySum = 0;
        // Paid S/A only: the averages leave out the free pulls (their pity counts inside their ten-pull)
        $paidSCount = 0;
        $paidACount = 0;
        $recentSPulls = [];
        $oldestS = null;
        $items = [];
        $monthly = [];
        $pools = [];

        foreach ($rows as $row) {
            $isDraw = self::isDraw($row);
            $isFree = $isDraw && self::isFree($row);
            $countsAsPull = $this->countsAsPull($row);
            $poolKey = $poolPity ? self::pityPoolKey($row) : '';
            $counters[$poolKey] ??= ['s' => 0, 'a' => 0];

            if ($countsAsPull) {
                $pullCount++;
                $counters[$poolKey]['s']++;
                $counters[$poolKey]['a']++;
                $lastPityPoolKey = $poolKey;
            }
            $pullNumber = $countsAsPull ? $pullCount : null;
            $rarity = isset($row['rarity']) ? (int) $row['rarity'] : null;
            $origin = self::originOf($row);
            $isFiller = $origin === PullOrigin::Filler;
            $isS = $isDraw && !$isFiller && $this->isS($row, $rarityConfig);
            $isA = $isDraw && !$isFiller && !$isS && $rarity === $aRarity;
            $declared = [
                'origin' => $origin->value,
                'declared_pity' => self::declaredPityOf($row),
                // Rate-up flag of an S: stored by a source, else from the banner data (null = unknown, never a lost 50/50)
                'is_up' => $this->isUpOf($row, $isS),
            ];
            $recordFields = [
                'kind' => self::kindOf($row),
                'is_free' => $isFree,
                'pool_version' => self::poolVersionOf($row),
                'bonus_label' => $isDraw ? null : GryphlinePull::getBonusLabel(self::kindOf($row)),
            ];

            /** @var \DateTimeInterface|string|null $pulledAt */
            $pulledAt = $row['pulledAt'] ?? null;
            $pulledAtFormatted = $pulledAt instanceof \DateTimeInterface
                ? $pulledAt->format('Y-m-d H:i:s')
                : (string) $pulledAt;

            if (!$isDraw) {
                // Bonus entry: listed only
                $itemPity = null;
            } elseif ($isFiller) {
                // Unknown pull: an A may have happened in it, so the A pity starts again
                $itemPity = null;
                $unknownCount++;
                $counters[$poolKey]['a'] = 0;
            } elseif ($isFree) {
                $blockKey = self::freeBlockKey($row);
                $freeCounters[$blockKey] ??= ['s' => 0, 'a' => 0];
                $freeCounters[$blockKey]['s']++;
                $freeCounters[$blockKey]['a']++;

                if ($isS) {
                    $itemPity = $freeCounters[$blockKey]['s'];
                    $sCount++;
                    $recentSPulls[] = $this->recentSPull($row, $pullNumber, $rarity, $itemPity, $pulledAtFormatted) + $declared + $recordFields;
                    $freeCounters[$blockKey]['s'] = 0;
                    if ($sResetsAPity) {
                        $freeCounters[$blockKey]['a'] = 0;
                    }
                } elseif ($isA) {
                    $itemPity = $freeCounters[$blockKey]['a'];
                    $aCount++;
                    $freeCounters[$blockKey]['a'] = 0;
                } else {
                    $itemPity = 1;
                    $bCount++;
                }
            } elseif ($isS) {
                $itemPity = $counters[$poolKey]['s'];
                $sCount++;
                $paidSCount++;
                $sPitySum += $itemPity;
                $recentSPulls[] = $this->recentSPull($row, $pullNumber, $rarity, $itemPity, $pulledAtFormatted)
                    + $declared + $recordFields + $windows[$row['id']]->toArray();
                $counters[$poolKey]['s'] = 0;
                if ($sResetsAPity) {
                    $counters[$poolKey]['a'] = 0;
                }
            } elseif ($isA) {
                $itemPity = $counters[$poolKey]['a'];
                $aCount++;
                $paidACount++;
                $aPitySum += $itemPity;
                $counters[$poolKey]['a'] = 0;
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
            ] + $declared + $recordFields;
            if ($isS && !$isFree) {
                // Limits of the manual pull forms (declare a pity, add an earlier S)
                $item += $windows[$row['id']]->toArray();
                $oldestS ??= $item;
            }
            $items[] = $item;

            if ($isDraw && \in_array($bannerGroup['key'], self::POOLS_BREAKDOWN_BANNERS, true)) {
                $this->addToPool($pools, $row, $bannerGroup, $countsAsPull, $isS, $pulledAtFormatted);
            }

            if ($isDraw && $pulledAt instanceof \DateTimeInterface) {
                $month = $pulledAt->format('Y-m');
                $monthly[$month] ??= ['month' => $month, 'total' => 0, 's_count' => 0, 'a_count' => 0];
                if ($countsAsPull) {
                    $monthly[$month]['total']++;
                }
                if ($isS) {
                    $monthly[$month]['s_count']++;
                } elseif ($isA) {
                    $monthly[$month]['a_count']++;
                }
            }
        }

        $current = $counters[$lastPityPoolKey] ?? ['s' => 0, 'a' => 0];
        foreach ($pools as $key => $pool) {
            $pools[$key]['s_pity'] = $poolPity ? ($counters[$key]['s'] ?? 0) : null;
            $pools[$key]['a_pity'] = $poolPity ? ($counters[$key]['a'] ?? 0) : null;
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
                's_pity' => $current['s'],
                's_max_pity' => $bannerGroup['s_max_pity'],
                'a_pity' => $current['a'],
                'a_max_pity' => $bannerGroup['a_max_pity'],
                'avg_s_pity' => $paidSCount > 0 ? round($sPitySum / $paidSCount, 2) : 0.0,
                'avg_a_pity' => $paidACount > 0 ? round($aPitySum / $paidACount, 2) : 0.0,
                'recent_s_pulls' => array_reverse($recentSPulls),
                'unknown_count' => $unknownCount,
                // Window after the last S (of the banner the pity refers to): limits of a manual S added without an anchor
                'open_window' => ($openWindows[$lastPityPoolKey] ?? $openWindows[array_key_last($openWindows)] ?? $this->planner->describe([], $now)['open'])->toArray(),
                // First S of the banner (full item): anchor of "Add missing S"
                'oldest_s' => $oldestS,
                // Per-banner breakdown (RE-Factor Headhunting, per-banner pity groups), oldest banner first
                'pools' => array_values($pools),
                // Banner the S/A pity refers to, for the groups whose pity resets with each banner
                'pity_pool_name' => $poolPity && isset($pools[$lastPityPoolKey]) ? self::poolLabel($pools[$lastPityPoolKey]) : null,
                // "Add missing S" makes no sense when the pity ends with each banner (ManualPullService refuses it)
                'allow_add_s' => !$poolPity,
            ],
            'items' => $items,
            'monthly' => $monthly,
        ];
    }

    /**
     * Newest-first items filtered by rarity (0: unknown pulls) and name, then paginated. Bonus entries are not
     * pulls, so the rarity filter (the pull pages always send one) never hides them; the name search does.
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
            $isBonus = ($item['bonus_label'] ?? null) !== null;
            if ($raritySet !== null && !$isBonus && ($item['rarity'] === null || !isset($raritySet[(int) $item['rarity']]))) {
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
     * Whether the pity of a banner group resets with each banner (pool id) instead of carrying over.
     *
     * @param array{key: string} $bannerGroup
     */
    public function isPoolPityGroup(array $bannerGroup): bool
    {
        return \in_array($bannerGroup['key'], ArknightsEndfieldService::POOL_PITY_BANNERS, true);
    }

    /**
     * Rows of a banner group split by pity pool, in chronological order: one list per banner (pool id and rerun
     * number) for the per-banner pity groups, a single list ('' key) otherwise. The missing-pulls logic runs on
     * each list on its own.
     *
     * @param list<array<string, mixed>> $rows Rows of one banner group, in any order
     * @param array{key: string} $bannerGroup
     * @return array<string, list<array<string, mixed>>>
     */
    public function partitionByPityPool(array $rows, array $bannerGroup): array
    {
        $rows = $this->sortRows($rows);
        if (!$this->isPoolPityGroup($bannerGroup)) {
            return ['' => $rows];
        }

        $partitions = [];
        foreach ($rows as $row) {
            $partitions[self::pityPoolKey($row)][] = $row;
        }

        return $partitions === [] ? ['' => []] : $partitions;
    }

    /**
     * Rows of one banner group (or of one pity pool of it) as entries of the shared missing-pulls logic
     * (PullGapPlanner, ManualPullGuard), in the order compute() counts them and with the same isS() /
     * countsAsPull() rules.
     *
     * @param list<array<string, mixed>> $rows Rows of one banner group, in any order
     * @return list<PullTimelineEntry>
     */
    public function toTimelineEntries(array $rows, RarityConfig $rarityConfig): array
    {
        return $this->buildTimelineEntries($this->sortRows($rows), $rarityConfig);
    }

    /**
     * Whether a row has an S rarity (6★). Filler rows (rarity 0) and bonus entries without rarity never do;
     * compute() also leaves out bonus entries and free pulls when it resets the pity.
     *
     * @param array<string, mixed> $row
     */
    public function isS(array $row, RarityConfig $rarityConfig): bool
    {
        return isset($row['rarity']) && (int) $row['rarity'] >= $rarityConfig->getS()->getRarity();
    }

    /**
     * Whether a row is a paid headhunt: it advances the pity, the pull number and the currency spent.
     * Bonus entries and free Urgent Recruitment pulls never do.
     *
     * @param array<string, mixed> $row
     */
    public function countsAsPull(array $row): bool
    {
        return self::isDraw($row) && !self::isFree($row);
    }

    /**
     * Pity pool of a row in the per-banner pity groups: its pool id, plus the rerun number when there is one.
     * Rows without a pool id (manual rows of older versions) share their own pool.
     *
     * @param array<string, mixed> $row
     */
    public static function pityPoolKey(array $row): string
    {
        $poolId = (string) ($row['poolId'] ?? '');
        $version = self::poolVersionOf($row);

        return $version !== null ? $poolId . '#' . $version : $poolId;
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
                // Only a paid S closes a window: a free or bonus 6★ never resets the paid pity
                isS: $origin !== PullOrigin::Filler && $this->countsAsPull($row) && $this->isS($row, $rarityConfig),
                countsAsPull: $this->countsAsPull($row),
                declaredPity: self::declaredPityOf($row),
                itemId: $itemId !== null && $itemId !== '' ? (string) $itemId : null,
                name: isset($row['name']) ? (string) $row['name'] : null,
            );
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function recentSPull(array $row, ?int $pullNumber, ?int $rarity, ?int $pity, string $pulledAt): array
    {
        return [
            'id' => $row['id'],
            'pull_number' => $pullNumber,
            'item_id' => $row['itemId'],
            'name' => $row['name'],
            'item_type' => $row['poolCategory'] ?? 'char',
            'rarity' => $rarity,
            'pity' => $pity,
            'pulled_at' => $pulledAt,
        ];
    }

    /**
     * Adds a pull to the per-banner breakdown. A new entry also gets the run of its banner (start_at, end_at in
     * UTC, the pull wall clock, null when unknown), looked up with its first pull, and its rate-up items
     * (featured) from the catalog.
     *
     * @param array<string, array<string, mixed>> $pools
     * @param array<string, mixed> $row
     * @param array{name: string} $bannerGroup
     */
    private function addToPool(array &$pools, array $row, array $bannerGroup, bool $countsAsPull, bool $isS, string $pulledAt): void
    {
        $key = self::pityPoolKey($row);
        $pools[$key] ??= [
            'pool_id' => isset($row['poolId']) && $row['poolId'] !== '' ? (string) $row['poolId'] : null,
            'pool_name' => (string) ($row['poolName'] ?: $bannerGroup['name']),
            'pool_version' => self::poolVersionOf($row),
            'total_pulls' => 0,
            's_count' => 0,
            's_pity' => null,
            'a_pity' => null,
            'first_pulled_at' => $pulledAt,
            'last_pulled_at' => $pulledAt,
        ] + $this->poolCatalogFields($row, $pulledAt);
        if ($countsAsPull) {
            $pools[$key]['total_pulls']++;
        }
        if ($isS) {
            $pools[$key]['s_count']++;
        }
        $pools[$key]['last_pulled_at'] = $pulledAt;
    }

    /**
     * Run and rate-up items of the banner of a row. The run: the one containing the pull time; when none does
     * (run missing from the catalog, pull without a usable time), for a rerun pool the one of the row's rerun
     * number (#1 is the first run, none when the catalog has fewer runs), for any other pool (or a rerun row
     * without number) its first run. The rate-up items (the same for every run): the catalog "up" list of the
     * pool, in order, as pull-row-like entries so the shared icon pipeline resolves them like the pulls; ids the
     * catalog does not know are skipped.
     *
     * @param array<string, mixed> $row
     * @param string               $pulledAt Pull wall clock (UTC, "Y-m-d H:i:s")
     * @return array{start_at: ?string, end_at: ?string, featured: list<array{item_id: string, name: string, item_type: string, rarity: int}>}
     */
    private function poolCatalogFields(array $row, string $pulledAt): array
    {
        $poolId = (string) ($row['poolId'] ?? '');
        $catalog = $this->catalog;
        $pool = $catalog !== null && $poolId !== '' ? $catalog->findPool($poolId) : null;
        if ($catalog === null || $pool === null) {
            return ['start_at' => null, 'end_at' => null, 'featured' => []];
        }

        $run = self::runContaining($pool['runs'], $pulledAt);
        if ($run === null) {
            $isRerunPool = $pool['type'] === ($pool['category'] === AkeCatalog::TYPE_WEAPON ? AkeCatalog::WEAPON_POOL_TYPE_RERUN : AkeCatalog::CHAR_POOL_TYPE_RERUN);
            $version = self::poolVersionOf($row);
            $run = $pool['runs'][$isRerunPool && $version !== null ? $version - 1 : 0] ?? null;
        }

        $featured = [];
        foreach ($pool['up'] as $itemId) {
            $item = $catalog->find($itemId);
            if ($item === null) {
                continue;
            }
            $featured[] = [
                'item_id' => $item['id'],
                'name' => $item['name'],
                // Catalog type (char|weapon): the pool_category of the pulls, which the icon resolver reads
                'item_type' => $item['type'],
                'rarity' => $item['rarity'] ?? self::FEATURED_FALLBACK_RARITY,
            ];
        }

        return [
            'start_at' => $run['start_at'] ?? null,
            'end_at' => $run['end_at'] ?? null,
            'featured' => $featured,
        ];
    }

    /**
     * First run whose limits (inclusive) contain the pull wall clock, null when none does or the time is not a
     * "Y-m-d H:i:s" string. Both are UTC in that fixed-width format, so they compare as strings.
     *
     * @param list<array{start_at: string, end_at: string}> $runs
     * @return array{start_at: string, end_at: string}|null
     */
    private static function runContaining(array $runs, string $pulledAt): ?array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $pulledAt)) {
            return null;
        }

        foreach ($runs as $run) {
            if ($run['start_at'] <= $pulledAt && $pulledAt <= $run['end_at']) {
                return $run;
            }
        }

        return null;
    }

    /**
     * Rate-up flag of a row: the stored one (sources, manual pulls), else for an S the banner data of the
     * catalog (null when the banner is unknown or has no rate-up item, e.g. Basic Headhunting).
     *
     * @param array<string, mixed> $row
     */
    private function isUpOf(array $row, bool $isS): ?bool
    {
        if (isset($row['isUp'])) {
            return (bool) $row['isUp'];
        }
        if (!$isS || $this->catalog === null || empty($row['poolId']) || empty($row['itemId'])) {
            return null;
        }

        $pool = $this->catalog->findPool((string) $row['poolId']);
        if ($pool === null || $pool['up'] === []) {
            return null;
        }

        return \in_array((string) $row['itemId'], $pool['up'], true);
    }

    /**
     * @param array<string, mixed> $pool
     */
    private static function poolLabel(array $pool): string
    {
        return $pool['pool_version'] !== null ? sprintf('%s #%d', $pool['pool_name'], $pool['pool_version']) : (string) $pool['pool_name'];
    }

    /**
     * Free ten-pull of a free row: the pulls of one Urgent Recruitment share their pool and timestamp.
     *
     * @param array<string, mixed> $row
     */
    private static function freeBlockKey(array $row): string
    {
        $pulledAt = $row['pulledAt'] ?? null;

        return sprintf(
            '%s|%s',
            (string) ($row['poolId'] ?? ''),
            (string) ($row['gachaTs'] ?? ($pulledAt instanceof \DateTimeInterface ? $pulledAt->format('U') : (string) $pulledAt))
        );
    }

    /**
     * Rows queried before the kind column existed (or built by hand) are pulls.
     *
     * @param array<string, mixed> $row
     */
    private static function kindOf(array $row): string
    {
        $kind = (string) ($row['kind'] ?? '');

        return $kind !== '' ? $kind : GryphlinePull::KIND_DRAW;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isDraw(array $row): bool
    {
        return self::kindOf($row) === GryphlinePull::KIND_DRAW;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isFree(array $row): bool
    {
        return (bool) ($row['isFree'] ?? false);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function poolVersionOf(array $row): ?int
    {
        $version = isset($row['poolVersion']) ? (int) $row['poolVersion'] : 0;

        return $version > 0 ? $version : null;
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
