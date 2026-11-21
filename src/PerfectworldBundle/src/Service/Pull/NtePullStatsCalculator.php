<?php

namespace App\PerfectworldBundle\Service\Pull;

use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Service\NevernessToEvernessService;

/**
 * Pity and summary statistics of NTE banners, in the same JSON shape as the other providers'
 * pull repositories so the shared PullsPage works unchanged.
 *
 * Board banners: only rolls count; S pity resets only on an S character (S items do not);
 * A pity is the number of rolls since the last Points Gift (a guaranteed A every 10 rolls).
 * Arc banner: every Arc counts; S pity resets on any S Arc; A pity resets on an A or S Arc.
 */
class NtePullStatsCalculator
{
    private const int RECENT_S_PULLS = 50;

    private const array ITEM_TYPE_LABELS = [
        'character' => 'Character',
        'arc' => 'Arc',
        'item' => 'Item',
        'cosmetic' => 'Cosmetic',
    ];

    /**
     * @param list<array<string, mixed>> $rows Chronologically sorted rows of one banner (oldest first)
     * @param array{key: string, name: string, categories: array<string>, s_max_pity: int, a_max_pity: int} $bannerGroup
     * @param array{plural: string, singular: string} $pullLabels Unit counted by the banner
     * @param (callable(?string): ?string)|null $poolNameResolver Display name of a rotation (pool id)
     * @return array{
     *     summary: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     monthly: array<string, array{month: string, total: int, s_count: int, a_count: int}>
     * }
     */
    public function compute(array $rows, array $bannerGroup, array $pullLabels, ?callable $poolNameResolver = null): array
    {
        $isBoard = NevernessToEvernessService::isBoardBanner($bannerGroup['key']);

        $unitNumber = 0;
        $sPity = 0;
        $aPity = 0;
        $sCount = 0;
        $aCount = 0;
        $bCount = 0;
        $sPitySum = 0;
        $aPitySum = 0;
        $aPityCount = 0;
        $recentSPulls = [];
        $items = [];
        $monthly = [];

        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            $rarity = isset($row['rarity']) ? (int) $row['rarity'] : null;
            $rewardType = (string) $row['rewardType'];
            $isCountedUnit = $isBoard ? $kind === PerfectworldPull::KIND_ROLL : true;
            $isS = $rarity === NevernessToEvernessService::RARITY_S;
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

            if ($isBoard) {
                if ($kind === PerfectworldPull::KIND_POINTS_GIFT) {
                    $aPity = 0;
                }
                if ($isCountedUnit && $isS && $rewardType === 'character') {
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

            $item = $this->mapItem($row, $bannerGroup, $isBoard, $pullNumber, $pity, $pulledAt, $poolNameResolver);
            $items[] = $item;

            if ($countsAsS) {
                $sCount++;
                $sPitySum += (int) $pity;
                $recentSPulls[] = $item;
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

        $avgAPity = $isBoard
            ? ($aCount > 0 ? round($unitNumber / $aCount, 2) : 0.0)
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
                'recent_s_pulls' => array_slice(array_reverse($recentSPulls), 0, self::RECENT_S_PULLS),
            ],
            'items' => $items,
            'monthly' => $monthly,
        ];
    }

    /**
     * Newest-first rows filtered by rarity (unknown rarity counts as B) and name, then paginated.
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

    /**
     * @param array<string, mixed> $row
     * @param array{key: string, name: string} $bannerGroup
     * @return array<string, mixed>
     */
    private function mapItem(array $row, array $bannerGroup, bool $isBoard, ?int $pullNumber, ?int $pity, string $pulledAt, ?callable $poolNameResolver): array
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
            'item_type_label' => self::ITEM_TYPE_LABELS[$rewardType] ?? ucfirst($rewardType),
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

        return $item;
    }
}
