<?php

namespace App\PerfectworldBundle\Service\Pull;

use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;

/**
 * Pity and summary statistics of NTE banners, in the same JSON shape as the other providers'
 * pull repositories so the shared PullsPage works unchanged.
 *
 * Board banners: only rolls count; S pity resets only on an S character (S items do not);
 * A pity is the number of rolls since the last Points Gift (a guaranteed A every 10 rolls).
 * Arc banner: every Arc counts; S pity resets on any S Arc; A pity resets on an A or S Arc.
 *
 * Filler rows (unknown pulls created by the realign step) count like any other pull for the S pity,
 * the pull numbers and the monthly totals, but are never B ("unknown_count"), carry no pity and
 * restart the A pity (the gap held As that are not known).
 *
 * Rate-up: an S Arc is "UP" when the catalog banners say so (NteCatalog::isArcUp(), stored is_up first);
 * boards have no lost 50/50 (their S is always the featured character), so their rows stay null.
 * The Arc banner and the limited boards also list their rotations ("pools"): pity is shared, so the
 * breakdown only counts pulls and S per rotation and catalog run (a rerun is its own entry), with the
 * run limits and the featured items from the catalog.
 *
 * Pure: the window info of the missing pulls feature comes from PullGapPlanner::describe().
 */
class PerfectworldPullStatsCalculator
{
    private const int RECENT_S_PULLS = 50;

    private const array ITEM_TYPE_LABELS = [
        'character' => 'Character',
        'arc' => 'Arc',
        'item' => 'Item',
        'cosmetic' => 'Cosmetic',
    ];

    /** Banner groups whose summary lists their rotations (the standard board has a single pool). */
    private const array POOLS_BREAKDOWN_BANNERS = [
        NevernessToEvernessService::BANNER_LIMITED,
        NevernessToEvernessService::BANNER_ARC,
    ];

    public function __construct(
        private readonly PullGapPlanner $planner,
        private readonly ?NteCatalog $catalog = null,
    ) {
    }

    /**
     * Whether a row counts as one pull for the S pity: only rolls on boards (Points Gifts and
     * Slumberland chases are not rolls), every Arc on the Arc banner.
     *
     * @param array<string, mixed> $row
     * @param array{key: string}   $bannerGroup
     */
    public function countsAsPull(array $row, array $bannerGroup): bool
    {
        return !NevernessToEvernessService::isBoardBanner($bannerGroup['key'])
            || (string) ($row['kind'] ?? '') === PerfectworldPull::KIND_ROLL;
    }

    /**
     * Whether a row resets the S pity: a rolled S character on boards (S items and S rewards of
     * gifts or chases do not), any S Arc on the Arc banner.
     *
     * @param array<string, mixed> $row
     * @param array{key: string}   $bannerGroup
     */
    public function isS(array $row, array $bannerGroup): bool
    {
        $rarity = isset($row['rarity']) ? (int) $row['rarity'] : null;
        if ($rarity !== NevernessToEvernessService::RARITY_S) {
            return false;
        }
        if (!NevernessToEvernessService::isBoardBanner($bannerGroup['key'])) {
            return true;
        }

        return $this->countsAsPull($row, $bannerGroup)
            && (string) ($row['rewardType'] ?? '') === NteCatalog::TYPE_CHARACTER;
    }

    /**
     * Rows of one banner as entries of the shared missing pulls logic, with the same predicates as
     * compute(). Every row is kept (Points Gifts and chases bound the windows without counting).
     *
     * @param list<array<string, mixed>> $rows        Chronologically sorted rows of one banner (oldest first)
     * @param array{key: string}         $bannerGroup
     * @return list<PullTimelineEntry>
     */
    public function toTimelineEntries(array $rows, array $bannerGroup): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $rewardId = isset($row['rewardId']) ? trim((string) $row['rewardId']) : '';
            $entries[] = new PullTimelineEntry(
                id: (int) $row['id'],
                pulledAt: self::toWallClock($row['pulledAt']),
                origin: self::getOrigin($row),
                isS: $this->isS($row, $bannerGroup),
                countsAsPull: $this->countsAsPull($row, $bannerGroup),
                declaredPity: isset($row['declaredPity']) ? (int) $row['declaredPity'] : null,
                // Exports and the catalog spell some ids with a different case
                itemId: $rewardId !== '' ? NteCatalog::normalizeId($rewardId) : null,
                name: isset($row['name']) ? (string) $row['name'] : null,
            );
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $rows Chronologically sorted rows of one banner (oldest first)
     * @param array{key: string, name: string, categories: array<string>, s_max_pity: int, a_max_pity: int} $bannerGroup
     * @param array{plural: string, singular: string} $pullLabels Unit counted by the banner
     * @param (callable(?string): ?string)|null $poolNameResolver Display name of a rotation (pool id)
     * @param \DateTimeImmutable|null $now Upper limit of the suggested dates (wall clock); null = current server time
     * @return array{
     *     summary: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     monthly: array<string, array{month: string, total: int, s_count: int, a_count: int}>
     * }
     */
    public function compute(array $rows, array $bannerGroup, array $pullLabels, ?callable $poolNameResolver = null, ?\DateTimeImmutable $now = null): array
    {
        $isBoard = NevernessToEvernessService::isBoardBanner($bannerGroup['key']);
        $description = $this->planner->describe(
            $this->toTimelineEntries($rows, $bannerGroup),
            $now ?? WallClockDateTimeImmutableType::now(),
        );

        $unitNumber = 0;
        $fillerUnits = 0;
        $sPity = 0;
        $aPity = 0;
        $sCount = 0;
        $aCount = 0;
        $bCount = 0;
        $unknownCount = 0;
        $sPitySum = 0;
        $aPitySum = 0;
        $aPityCount = 0;
        $sPulls = [];
        $items = [];
        $monthly = [];
        $pools = [];
        // Catalog runs per normalized pool id, read once per compute()
        $poolRuns = [];
        $listPools = in_array($bannerGroup['key'], self::POOLS_BREAKDOWN_BANNERS, true);

        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            $rarity = isset($row['rarity']) ? (int) $row['rarity'] : null;
            $rewardType = (string) $row['rewardType'];
            $isCountedUnit = $this->countsAsPull($row, $bannerGroup);
            $isS = $this->isS($row, $bannerGroup);
            $isA = $rarity === NevernessToEvernessService::RARITY_A;
            $isUnit = in_array($rewardType, ['character', 'arc'], true);

            $pullNumber = null;
            $pity = null;
            $countsAsS = false;
            $countsAsA = false;

            if ($isCountedUnit) {
                $unitNumber++;
                $pullNumber = $unitNumber;
                $sPity++;
                $aPity++;
            }

            if (self::getOrigin($row) === PullOrigin::Filler) {
                // Unknown pull: never a B, no pity, and the As of the gap are unknown
                $unknownCount++;
                $fillerUnits += $isCountedUnit ? 1 : 0;
                $aPity = 0;
            } elseif ($isBoard) {
                if ($kind === PerfectworldPull::KIND_POINTS_GIFT) {
                    $aPity = 0;
                }
                if ($isS) {
                    $pity = $sPity;
                    $countsAsS = true;
                    $sPity = 0;
                } elseif ($kind !== PerfectworldPull::KIND_SLUMBERLAND && $isA && $isUnit) {
                    $countsAsA = true;
                } elseif ($isCountedUnit) {
                    $bCount++;
                }
            } elseif ($isS) {
                $pity = $sPity;
                $countsAsS = true;
                $sPity = 0;
                $aPity = 0;
            } elseif ($isA) {
                $pity = $aPity;
                $countsAsA = true;
                $aPitySum += $aPity;
                $aPityCount++;
                $aPity = 0;
            } else {
                $bCount++;
            }

            $pulledAt = $row['pulledAt'] instanceof \DateTimeInterface
                ? $row['pulledAt']->format('Y-m-d H:i:s')
                : (string) $row['pulledAt'];

            $item = $this->mapItem($row, $bannerGroup, $isBoard, $countsAsS, $pullNumber, $pity, $pulledAt, $poolNameResolver);
            if ($listPools) {
                $this->addToPool($pools, $poolRuns, $item, $bannerGroup, $isCountedUnit, $countsAsS);
            }
            if ($countsAsS) {
                // Limits of the manual pull forms (the S item already carries declared_pity)
                $window = $description['windows'][(int) $row['id']] ?? null;
                if ($window !== null) {
                    $item += $window->toArray();
                }
            }
            $items[] = $item;

            if ($countsAsS) {
                $sCount++;
                $sPitySum += (int) $pity;
                $sPulls[] = $item;
            }
            if ($countsAsA) {
                $aCount++;
            }

            if ($isCountedUnit || $countsAsA) {
                $month = substr($pulledAt, 0, 7);
                $monthly[$month] ??= ['month' => $month, 'total' => 0, 's_count' => 0, 'a_count' => 0];
                $monthly[$month]['total'] += $isCountedUnit ? 1 : 0;
                $monthly[$month]['s_count'] += $countsAsS ? 1 : 0;
                $monthly[$month]['a_count'] += $countsAsA ? 1 : 0;
            }
        }

        // Boards: rolls per A obtained, without the unknown rolls (their As are unknown too)
        $avgAPity = $isBoard
            ? ($aCount > 0 ? round(($unitNumber - $fillerUnits) / $aCount, 2) : 0.0)
            : ($aPityCount > 0 ? round($aPitySum / $aPityCount, 2) : 0.0);

        return [
            'summary' => [
                'key' => $bannerGroup['key'],
                'name' => $bannerGroup['name'],
                'categories' => $bannerGroup['categories'],
                'pull_label' => $pullLabels['plural'],
                'pull_singular_label' => $pullLabels['singular'],
                'total_pulls' => $unitNumber,
                's_count' => $sCount,
                'a_count' => $aCount,
                'b_count' => $bCount,
                's_pity' => $sPity,
                's_max_pity' => $bannerGroup['s_max_pity'],
                'a_pity' => $aPity,
                'a_max_pity' => $bannerGroup['a_max_pity'],
                'avg_s_pity' => $sCount > 0 ? round($sPitySum / $sCount, 2) : 0.0,
                'avg_a_pity' => $avgAPity,
                'recent_s_pulls' => array_slice(array_reverse($sPulls), 0, self::RECENT_S_PULLS),
                'unknown_count' => $unknownCount,
                'open_window' => $description['open']->toArray(),
                // recent_s_pulls is capped: the anchor of "add a missing S" is the oldest S of all
                'oldest_s' => $sPulls[0] ?? null,
            ] + ($listPools ? ['pools' => array_values($pools)] : []),
            'items' => $items,
            'monthly' => $monthly,
        ];
    }

    /**
     * Newest-first rows filtered by rarity (unknown rarity counts as B, fillers are rarity 0) and
     * name, then paginated.
     *
     * @param list<array<string, mixed>> $items Items as returned by compute() (oldest first)
     * @param list<int> $rarities
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function filterAndPaginate(array $items, array $rarities, ?string $search, int $page, int $limit): array
    {
        $raritySet = $rarities !== [] ? array_flip($rarities) : null;
        $search = $search !== null ? mb_strtolower(trim($search)) : '';

        $filtered = [];
        foreach (array_reverse($items) as $item) {
            $rarity = $item['rarity'] ?? NevernessToEvernessService::RARITY_B;
            if ($raritySet !== null && !isset($raritySet[(int) $rarity])) {
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

    public static function getItemTypeLabel(string $rewardType): string
    {
        return self::ITEM_TYPE_LABELS[$rewardType] ?? ucfirst($rewardType);
    }

    /**
     * Origin of a row: a PullOrigin case when hydrated by Doctrine, its string value, or missing (recorded).
     *
     * @param array<string, mixed> $row
     */
    public static function getOrigin(array $row): PullOrigin
    {
        $origin = $row['origin'] ?? null;
        if ($origin instanceof PullOrigin) {
            return $origin;
        }

        return is_string($origin) ? (PullOrigin::tryFrom($origin) ?? PullOrigin::Recorded) : PullOrigin::Recorded;
    }

    private static function toWallClock(mixed $pulledAt): \DateTimeImmutable
    {
        if ($pulledAt instanceof \DateTimeImmutable) {
            return $pulledAt;
        }
        if ($pulledAt instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($pulledAt);
        }

        return WallClockDateTimeImmutableType::fromString((string) $pulledAt);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{key: string, name: string} $bannerGroup
     * @return array<string, mixed>
     */
    private function mapItem(array $row, array $bannerGroup, bool $isBoard, bool $isS, ?int $pullNumber, ?int $pity, string $pulledAt, ?callable $poolNameResolver): array
    {
        $quantity = (int) ($row['quantity'] ?? 1);
        $poolId = $row['poolId'] ?? null;
        $gachaName = ($poolNameResolver !== null ? $poolNameResolver($poolId) : null) ?? $bannerGroup['name'];
        $rewardType = (string) $row['rewardType'];

        $item = [
            'id' => $row['id'],
            'pull_number' => $pullNumber,
            'kind' => $row['kind'],
            'pool_id' => $poolId,
            'gacha_name' => $gachaName,
            'pool_name' => $gachaName,
            // Raw reward data: item_id and item_type are also read by the pull item icon resolver
            'item_id' => $row['rewardId'],
            'item_type' => $rewardType,
            'item_type_label' => self::getItemTypeLabel($rewardType),
            'name' => $quantity > 1 ? sprintf('%s ×%d', $row['name'], $quantity) : $row['name'],
            'quantity' => $quantity,
            'rarity' => isset($row['rarity']) ? (int) $row['rarity'] : null,
            'pity' => $pity,
            'pulled_at' => $pulledAt,
        ];

        if ($isBoard) {
            $item['roll_label'] = match ($row['kind']) {
                PerfectworldPull::KIND_POINTS_GIFT => 'Points Gift',
                PerfectworldPull::KIND_SLUMBERLAND => 'Slumberland',
                default => isset($row['rollResult']) ? (string) $row['rollResult'] : '?',
            };
        }

        $item['origin'] = self::getOrigin($row)->value;
        $item['declared_pity'] = isset($row['declaredPity']) ? (int) $row['declaredPity'] : null;
        // Rate-up flag of an S (null = unknown or not applicable)
        $item['is_up'] = $this->isUpOf($row, $isBoard, $isS);

        return $item;
    }

    /**
     * Rate-up flag of a row: the stored one (sources, manual pulls), else for an S Arc the catalog banners.
     * Board rows stay null: a limited board's S is always its featured character.
     *
     * @param array<string, mixed> $row
     */
    private function isUpOf(array $row, bool $isBoard, bool $isS): ?bool
    {
        if (isset($row['isUp'])) {
            return (bool) $row['isUp'];
        }
        if ($isBoard || !$isS || $this->catalog === null) {
            return null;
        }

        $rewardId = isset($row['rewardId']) ? trim((string) $row['rewardId']) : '';
        $poolId = isset($row['poolId']) && trim((string) $row['poolId']) !== '' ? (string) $row['poolId'] : null;

        return $rewardId !== '' ? $this->catalog->isArcUp($rewardId, $poolId) : null;
    }

    /**
     * Adds a row to the per-rotation breakdown, in the shape of the other providers' "pools" (the pity is
     * shared by the rotations, so it is not split). Rows without a pool id (old exports, manual and
     * filler rows) share one entry named after the banner group, without run or featured item.
     *
     * A rotation has one entry per catalog run holding its pulls (see findRunIndex()), so the pulls of a
     * launch and of its rerun are counted apart: "start_at"/"end_at" are the UTC limits of that run and
     * "pool_version" its 1-based number when the rotation has more than one run (shown as "Name #2"), else
     * null. Pulls in no run of their rotation (unknown pool, pool without runs, pull before its first run)
     * share one entry of the rotation without dates or number. "featured" holds the featured S items of the
     * rotation (see featuredOf()).
     *
     * @param array<string, array<string, mixed>> $pools
     * @param array<string, list<array{start_at: string, end_at: string}>> $poolRuns Catalog runs by normalized pool id (memo of the compute() call)
     * @param array<string, mixed> $item Mapped item (pool_id, pool_name, pulled_at)
     * @param array{name: string} $bannerGroup
     */
    private function addToPool(array &$pools, array &$poolRuns, array $item, array $bannerGroup, bool $countsAsPull, bool $isS): void
    {
        $poolId = is_string($item['pool_id']) && trim($item['pool_id']) !== '' ? $item['pool_id'] : null;
        $key = '';
        $runs = [];
        $runIndex = null;
        if ($poolId !== null) {
            $poolKey = NteCatalog::normalizeId($poolId);
            $runs = $poolRuns[$poolKey] ??= $this->catalog?->findPool($poolId)['runs'] ?? [];
            $runIndex = self::findRunIndex($runs, (string) $item['pulled_at']);
            // "#" alone: the pulls of the rotation in none of its runs
            $key = $poolKey . '#' . ($runIndex ?? '');
        }

        if (!isset($pools[$key])) {
            $run = $runIndex !== null ? $runs[$runIndex] : null;
            $pools[$key] = [
                'pool_id' => $poolId,
                'pool_name' => $poolId !== null ? (string) $item['pool_name'] : $bannerGroup['name'],
                'pool_version' => $runIndex !== null && count($runs) > 1 ? $runIndex + 1 : null,
                'total_pulls' => 0,
                's_count' => 0,
                's_pity' => null,
                'a_pity' => null,
                'first_pulled_at' => $item['pulled_at'],
                'last_pulled_at' => $item['pulled_at'],
                'start_at' => $run['start_at'] ?? null,
                'end_at' => $run['end_at'] ?? null,
                'featured' => $poolId !== null ? $this->featuredOf($poolId) : [],
            ];
        }
        if ($countsAsPull) {
            $pools[$key]['total_pulls']++;
        }
        if ($isS) {
            $pools[$key]['s_count']++;
        }
        $pools[$key]['last_pulled_at'] = $item['pulled_at'];
    }

    /**
     * Featured S items of a rotation (the "up" of its catalog banner: the rate-up Arc of an Arc issue, the
     * featured character of a limited board), in catalog order, as pull-row-like entries so the shared icon
     * pipeline resolves them like the pulls. Ids the catalog items do not know are skipped; an unknown pool,
     * a pool without featured item or a missing catalog give none.
     *
     * @return list<array{item_id: string, name: string, item_type: string, rarity: int}>
     */
    private function featuredOf(string $poolId): array
    {
        $featured = [];
        foreach ($this->catalog?->findPool($poolId)['up'] ?? [] as $id) {
            $item = $this->catalog?->find($id);
            if ($item === null) {
                continue;
            }
            $featured[] = [
                // Catalog id in its original case (the banner may spell it differently)
                'item_id' => $item['id'],
                'name' => $item['name'],
                'item_type' => $item['type'],
                'rarity' => NevernessToEvernessService::RARITY_S,
            ];
        }

        return $featured;
    }

    /**
     * Index of the catalog run holding a pull among the runs of its rotation: the one containing it, else the
     * latest one started before it, else null. The stored pulled_at is the exported wall clock (not guaranteed
     * UTC), so it is compared as digits with the UTC run limits, without tolerance: runs last weeks, so only a
     * pull within hours of a run limit can be misplaced, and one just after the end (a zone ahead of UTC) still
     * falls back to its run.
     *
     * @param list<array{start_at: string, end_at: string}> $runs Runs of the rotation, sorted by start
     */
    private static function findRunIndex(array $runs, string $pulledAt): ?int
    {
        $latestStarted = null;
        // The fixed-width "Y-m-d H:i:s" strings sort chronologically
        foreach ($runs as $index => $run) {
            if (strcmp($run['start_at'], $pulledAt) > 0) {
                break;
            }
            if (strcmp($pulledAt, $run['end_at']) <= 0) {
                return $index;
            }
            $latestStarted = $index;
        }

        return $latestStarted;
    }
}
