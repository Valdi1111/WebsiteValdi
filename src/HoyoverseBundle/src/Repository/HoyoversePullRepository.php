<?php

namespace App\HoyoverseBundle\Repository;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HoyoversePull>
 */
class HoyoversePullRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HoyoversePull::class);
    }

    public function existsByProfileAndPullId(HoyoverseGameProfile $profile, string $pullId): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('1')
            ->where('p.gameProfile = :profile')
            ->andWhere('p.pullId = :pullId')
            ->setParameter('profile', $profile)
            ->setParameter('pullId', $pullId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param array<string, array{
     *     key: string,
     *     name: string,
     *     gacha_types: array<string>,
     *     s_max_pity: int,
     *     a_max_pity: int
     * }> $bannerGroups
     * @param array{
     *     s_rarity: int,
     *     a_rarity: int,
     *     b_rarity: int
     * } $rarityConfig
     *
     * @return array{
     *     total_pulls: int,
     *     banners: array<int, array<string, mixed>>,
     *     monthly_timeline: array<int, array{month: string, total: int, s_count: int, a_count: int}>
     * }
     */
    public function buildOverview(
        HoyoverseGameProfile $profile,
        array $bannerGroups,
        array $rarityConfig
    ): array {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id, p.pullId, p.gachaType, p.gachaName, p.itemType, p.itemId, p.name, p.rarity, p.pulledAt')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile)
            ->orderBy('p.pulledAt', 'ASC')
            ->addOrderBy('p.pullId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $sRarity = $rarityConfig['s_rarity'];
        $aRarity = $rarityConfig['a_rarity'];

        // Map gacha_type => banner group key
        $typeToGroupKey = [];
        foreach ($bannerGroups as $key => $group) {
            foreach ($group['gacha_types'] as $gt) {
                $typeToGroupKey[(string) $gt] = (string) $key;
            }
        }

        $groupedRows = [];
        foreach (array_keys($bannerGroups) as $key) {
            $groupedRows[(string) $key] = [];
        }

        $monthlyMap = [];
        $totalPullsAllBanners = 0;

        foreach ($rows as $row) {
            $totalPullsAllBanners++;
            $gt = (string) ($row['gachaType'] ?? '');
            $groupKey = $typeToGroupKey[$gt] ?? $gt;

            if (!isset($groupedRows[$groupKey])) {
                $groupedRows[$groupKey] = [];
                $bannerGroups[$groupKey] = [
                    'key' => $groupKey,
                    'name' => $row['gachaName'] ?: ('Banner ' . $groupKey),
                    'gacha_types' => [$gt],
                    's_max_pity' => 90,
                    'a_max_pity' => 10,
                ];
            }

            $groupedRows[$groupKey][] = $row;

            /** @var \DateTimeInterface|null $pulledAt */
            $pulledAt = $row['pulledAt'] ?? null;
            if ($pulledAt instanceof \DateTimeInterface) {
                $monthKey = $pulledAt->format('Y-m');
                if (!isset($monthlyMap[$monthKey])) {
                    $monthlyMap[$monthKey] = [
                        'month' => $monthKey,
                        'total' => 0,
                        's_count' => 0,
                        'a_count' => 0,
                    ];
                }
                $monthlyMap[$monthKey]['total']++;
                $rarity = (int) ($row['rarity'] ?? 0);
                if ($rarity >= $sRarity) {
                    $monthlyMap[$monthKey]['s_count']++;
                } elseif ($rarity === $aRarity) {
                    $monthlyMap[$monthKey]['a_count']++;
                }
            }
        }

        ksort($monthlyMap);

        $bannersOutput = [];
        foreach ($bannerGroups as $key => $group) {
            $bRows = $groupedRows[(string) $key] ?? [];
            $computed = $this->computeBannerStats($bRows, $group, $sRarity, $aRarity);
            $bannersOutput[] = $computed['summary'];
        }

        return [
            'total_pulls' => $totalPullsAllBanners,
            'banners' => $bannersOutput,
            'monthly_timeline' => array_values($monthlyMap),
        ];
    }

    /**
     * @param array<string> $gachaTypes
     * @param array<int>    $rarities
     * @param array{
     *     key: string,
     *     name: string,
     *     gacha_types: array<string>,
     *     s_max_pity: int,
     *     a_max_pity: int
     * } $bannerGroup
     * @param array{
     *     s_rarity: int,
     *     a_rarity: int,
     *     b_rarity: int
     * } $rarityConfig
     *
     * @return array{
     *     banner: array<string, mixed>,
     *     items: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function findBannerDetailWithPity(
        HoyoverseGameProfile $profile,
        array $bannerGroup,
        array $rarityConfig,
        array $rarities = [],
        ?string $search = null,
        int $page = 1,
        int $limit = 20
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id, p.pullId, p.gachaType, p.gachaName, p.itemType, p.itemId, p.name, p.rarity, p.pulledAt')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile);

        if (!empty($bannerGroup['gacha_types'])) {
            $qb->andWhere('p.gachaType IN (:gachaTypes)')
                ->setParameter('gachaTypes', $bannerGroup['gacha_types']);
        }

        $rows = $qb->orderBy('p.pulledAt', 'ASC')
            ->addOrderBy('p.pullId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $sRarity = $rarityConfig['s_rarity'];
        $aRarity = $rarityConfig['a_rarity'];

        $computed = $this->computeBannerStats($rows, $bannerGroup, $sRarity, $aRarity);

        // Reverse annotated items so newest pull (#N) is first
        $annotatedDesc = array_reverse($computed['items']);

        $searchTrimmed = $search !== null ? mb_strtolower(trim($search)) : '';
        $raritySet = !empty($rarities) ? array_flip(array_map('intval', $rarities)) : null;

        $filtered = [];
        foreach ($annotatedDesc as $item) {
            if ($raritySet !== null && !isset($raritySet[(int) $item['rarity']])) {
                continue;
            }
            if ($searchTrimmed !== '' && !str_contains(mb_strtolower((string) $item['name']), $searchTrimmed)) {
                continue;
            }
            $filtered[] = $item;
        }

        $totalFiltered = count($filtered);
        $offset = max(0, ($page - 1) * $limit);
        $pagedItems = array_slice($filtered, $offset, $limit);

        return [
            'banner' => $computed['summary'],
            'items' => $pagedItems,
            'total' => $totalFiltered,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows Chronologically sorted rows (ASC)
     * @param array{
     *     key: string,
     *     name: string,
     *     gacha_types: array<string>,
     *     s_max_pity: int,
     *     a_max_pity: int
     * } $bannerGroup
     *
     * @return array{
     *     summary: array<string, mixed>,
     *     items: array<int, array<string, mixed>>
     * }
     */
    private function computeBannerStats(array $rows, array $bannerGroup, int $sRarity, int $aRarity): array
    {
        $sPity = 0;
        $aPity = 0;
        $sCount = 0;
        $aCount = 0;
        $bCount = 0;
        $sPitySum = 0;
        $aPitySum = 0;
        $recentSPulls = [];
        $annotatedItems = [];

        foreach ($rows as $idx => $row) {
            $pullNumber = $idx + 1;
            $sPity++;
            $aPity++;
            $rarity = (int) ($row['rarity'] ?? 0);

            /** @var \DateTimeInterface|null $pulledAt */
            $pulledAt = $row['pulledAt'] ?? null;
            $pulledAtFormatted = $pulledAt instanceof \DateTimeInterface
                ? $pulledAt->format('Y-m-d H:i:s')
                : (string) $pulledAt;

            if ($rarity >= $sRarity) {
                $itemPity = $sPity;
                $sCount++;
                $sPitySum += $sPity;
                $recentSPulls[] = [
                    'id' => $row['id'],
                    'pull_number' => $pullNumber,
                    'name' => $row['name'],
                    'item_type' => $row['itemType'],
                    'rarity' => $rarity,
                    'pity' => $itemPity,
                    'pulled_at' => $pulledAtFormatted,
                ];
                $sPity = 0;
            } elseif ($rarity === $aRarity) {
                $itemPity = $aPity;
                $aCount++;
                $aPitySum += $aPity;
                $aPity = 0;
            } else {
                $itemPity = 1;
                $bCount++;
            }

            $annotatedItems[] = [
                'id' => $row['id'],
                'pull_number' => $pullNumber,
                'pull_id' => $row['pullId'],
                'gacha_type' => $row['gachaType'],
                'gacha_name' => $row['gachaName'] ?: $bannerGroup['name'],
                'item_type' => $row['itemType'],
                'item_id' => $row['itemId'],
                'name' => $row['name'],
                'rarity' => $rarity,
                'pity' => $itemPity,
                'pulled_at' => $pulledAtFormatted,
            ];
        }

        // Order recent S pulls newest first
        $recentSPulls = array_reverse($recentSPulls);

        return [
            'summary' => [
                'key' => $bannerGroup['key'],
                'name' => $bannerGroup['name'],
                'gacha_types' => $bannerGroup['gacha_types'],
                'total_pulls' => count($rows),
                'currency_spent' => count($rows) * 160,
                's_count' => $sCount,
                'a_count' => $aCount,
                'b_count' => $bCount,
                's_pity' => $sPity,
                's_max_pity' => $bannerGroup['s_max_pity'],
                'a_pity' => $aPity,
                'a_max_pity' => $bannerGroup['a_max_pity'],
                'avg_s_pity' => $sCount > 0 ? round($sPitySum / $sCount, 2) : 0.0,
                'avg_a_pity' => $aCount > 0 ? round($aPitySum / $aCount, 2) : 0.0,
                'recent_s_pulls' => $recentSPulls,
            ],
            'items' => $annotatedItems,
        ];
    }
}
