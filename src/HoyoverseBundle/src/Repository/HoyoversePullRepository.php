<?php

namespace App\HoyoverseBundle\Repository;

use App\CoreBundle\Model\Game\RarityConfig;
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
     * Returns a set (pullId => true) of all existing pull IDs for the profile.
     *
     * @return array<string, true>
     */
    public function findExistingPullIdSet(HoyoverseGameProfile $profile): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.pullId')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile)
            ->getQuery()
            ->getSingleColumnResult();

        $set = [];
        foreach ($rows as $pullId) {
            $set[(string) $pullId] = true;
        }

        return $set;
    }

    /**
     * Returns a multiset count map keyed by "{groupKey}|{Y-m-d H:i:s}|{normalizedLooseName}"
     * used to deduplicate imports from sources without real HoYoverse pull IDs.
     *
     * @param array<string, string> $gachaTypeToGroupKey
     * @return array<string, int>
     */
    public function findExistingLogicalPullCounts(HoyoverseGameProfile $profile, array $gachaTypeToGroupKey): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.gachaType, p.pulledAt, p.name')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            /** @var \DateTimeInterface|null $pulledAt */
            $pulledAt = $row['pulledAt'] ?? null;
            if (!$pulledAt instanceof \DateTimeInterface) {
                continue;
            }
            $gt = (string) ($row['gachaType'] ?? '');
            $groupKey = $gachaTypeToGroupKey[$gt] ?? $gt;
            $key = self::buildLogicalKey($groupKey, $pulledAt, (string) ($row['name'] ?? ''));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Finds synthetic pull entities matching a specific prefix, banner group gacha types,
     * and exact timestamp, ordered newest-first (pullId DESC) to align with HoYoverse's
     * descending API pagination order.
     *
     * @param list<string> $gachaTypes
     * @return list<HoyoversePull>
     */
    public function findSyntheticCandidatesForReconciliation(
        HoyoverseGameProfile $profile,
        string $pullIdPrefix,
        array $gachaTypes,
        \DateTimeImmutable $pulledAt,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->where('p.gameProfile = :profile')
            ->andWhere('p.pullId LIKE :prefix')
            ->andWhere('p.pulledAt = :pulledAt')
            ->setParameter('profile', $profile)
            ->setParameter('prefix', $pullIdPrefix . '%')
            ->setParameter('pulledAt', $pulledAt)
            ->orderBy('p.pullId', 'DESC');

        if ($gachaTypes !== []) {
            $qb->andWhere('p.gachaType IN (:gachaTypes)')
                ->setParameter('gachaTypes', $gachaTypes);
        }

        /** @var list<HoyoversePull> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public static function buildLogicalKey(string $groupKey, \DateTimeInterface $pulledAt, string $name): string
    {
        $looseName = (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($name)));

        return sprintf('%s|%s|%s', $groupKey, $pulledAt->format('Y-m-d H:i:s'), $looseName);
    }

    /**
     * @param array<string, array{
     *     key: string,
     *     name: string,
     *     gacha_types: array<string>,
     *     s_max_pity: int,
     *     a_max_pity: int
     * }> $bannerGroups
     * @return array{
     *     total_pulls: int,
     *     banners: array<int, array<string, mixed>>,
     *     monthly_timeline: array<int, array{month: string, total: int, s_count: int, a_count: int}>
     * }
     */
    public function buildOverview(
        HoyoverseGameProfile $profile,
        array $bannerGroups,
        RarityConfig $rarityConfig
    ): array {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id, p.pullId, p.gachaType, p.gachaName, p.itemType, p.itemId, p.name, p.rarity, p.pulledAt')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile)
            ->orderBy('p.pulledAt', 'ASC')
            ->addOrderBy('p.pullId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $sRarity = $rarityConfig->getS()->getRarity();
        $aRarity = $rarityConfig->getA()->getRarity();

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
            $computed = $this->computeBannerStats($bRows, $group, $rarityConfig);
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
     * @return array{
     *     banner: array<string, mixed>,
     *     items: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function findBannerDetailWithPity(
        HoyoverseGameProfile $profile,
        array $bannerGroup,
        RarityConfig $rarityConfig,
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

        $computed = $this->computeBannerStats($rows, $bannerGroup, $rarityConfig);

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
    private function computeBannerStats(array $rows, array $bannerGroup, RarityConfig $rarityConfig): array
    {
        $sRarity = $rarityConfig->getS()->getRarity();
        $aRarity = $rarityConfig->getA()->getRarity();
        $sResetsAPity = $rarityConfig->isSResetsAPity();

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
                    'item_id' => $row['itemId'],
                    'name' => $row['name'],
                    'item_type' => $row['itemType'],
                    'rarity' => $rarity,
                    'pity' => $itemPity,
                    'pulled_at' => $pulledAtFormatted,
                ];
                $sPity = 0;
                if ($sResetsAPity) {
                    $aPity = 0;
                }
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
