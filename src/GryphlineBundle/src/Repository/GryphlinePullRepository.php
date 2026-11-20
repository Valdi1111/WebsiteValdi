<?php

namespace App\GryphlineBundle\Repository;

use App\GamesBundle\Model\Game\RarityConfig;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GryphlinePull>
 */
class GryphlinePullRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GryphlinePull::class);
    }

    public function existsByProfileAndSeqId(GryphlineGameProfile $profile, string $seqId, ?string $poolCategory = null): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('1')
            ->where('p.gameProfile = :profile')
            ->andWhere('p.seqId = :seqId')
            ->setParameter('profile', $profile)
            ->setParameter('seqId', $seqId);

        if ($poolCategory !== null && $poolCategory !== '') {
            $qb->andWhere('p.poolCategory = :poolCategory')
                ->setParameter('poolCategory', $poolCategory);
        }

        return null !== $qb->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param array<string, array{
     *     key: string,
     *     name: string,
     *     categories: array<string>,
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
        GryphlineGameProfile $profile,
        array $bannerGroups,
        RarityConfig $rarityConfig
    ): array {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id, p.seqId, p.poolType, p.poolCategory, p.poolId, p.poolName, p.itemId, p.name, p.rarity, p.pulledAt')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile)
            ->orderBy('p.pulledAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        usort($rows, static fn (array $a, array $b): int => ($a['pulledAt'] <=> $b['pulledAt']) ?: (((int) $a['seqId']) <=> ((int) $b['seqId'])));

        $sRarity = $rarityConfig->getS()->getRarity();
        $aRarity = $rarityConfig->getA()->getRarity();

        $catToGroupKey = [];
        foreach ($bannerGroups as $key => $group) {
            foreach ($group['categories'] as $cat) {
                $catToGroupKey[(string) $cat] = (string) $key;
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
            $cat = (string) ($row['poolType'] ?: ($row['poolCategory'] ?? 'char'));
            $groupKey = $catToGroupKey[$cat] ?? $cat;

            if (!isset($groupedRows[$groupKey])) {
                $groupedRows[$groupKey] = [];
                $bannerGroups[$groupKey] = [
                    'key' => $groupKey,
                    'name' => $row['poolName'] ?: ('Headhunting ' . $groupKey),
                    'categories' => [$cat],
                    's_max_pity' => 80,
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
     * @param array<int> $rarities
     * @param array{
     *     key: string,
     *     name: string,
     *     categories: array<string>,
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
        GryphlineGameProfile $profile,
        array $bannerGroup,
        RarityConfig $rarityConfig,
        array $rarities = [],
        ?string $search = null,
        int $page = 1,
        int $limit = 20
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id, p.seqId, p.poolType, p.poolCategory, p.poolId, p.poolName, p.itemId, p.name, p.rarity, p.pulledAt')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile);

        if (!empty($bannerGroup['categories'])) {
            $qb->andWhere('p.poolCategory IN (:categories) OR p.poolType IN (:categories)')
                ->setParameter('categories', $bannerGroup['categories']);
        }

        $rows = $qb->orderBy('p.pulledAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        usort($rows, static fn (array $a, array $b): int => ($a['pulledAt'] <=> $b['pulledAt']) ?: (((int) $a['seqId']) <=> ((int) $b['seqId'])));

        $computed = $this->computeBannerStats($rows, $bannerGroup, $rarityConfig);

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
     *     categories: array<string>,
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
                    'item_type' => $row['poolCategory'] ?? 'char',
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
            ];
        }

        $recentSPulls = array_reverse($recentSPulls);

        return [
            'summary' => [
                'key' => $bannerGroup['key'],
                'name' => $bannerGroup['name'],
                'categories' => $bannerGroup['categories'],
                'total_pulls' => count($rows),
                'currency_spent' => count($rows) * 500,
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

    /**
     * Returns a hash set of all existing (pool_category, seq_id) keys for the given profile.
     *
     * @return array<string, true>
     */
    public function findExistingSeqKeySet(GryphlineGameProfile $profile): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.poolCategory, p.seqId')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile)
            ->getQuery()
            ->getArrayResult();

        $set = [];
        foreach ($rows as $row) {
            $set[self::buildSeqKey((string) $row['poolCategory'], (string) $row['seqId'])] = true;
        }

        return $set;
    }

    /**
     * Returns multiset counts of existing pulls grouped by (bannerGroupKey, pulledAt, lower(name)).
     *
     * @param array<string, string> $poolTypeToGroupKey Map of poolType/category => canonical banner group key
     * @return array<string, int>
     */
    public function findExistingLogicalPullCounts(GryphlineGameProfile $profile, array $poolTypeToGroupKey): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.poolType, p.poolCategory, p.pulledAt, p.name')
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

            $poolKey = (string) ($row['poolType'] ?: ($row['poolCategory'] ?? ''));
            $groupKey = $poolTypeToGroupKey[$poolKey] ?? $poolKey;
            $key = self::buildLogicalKey($groupKey, $pulledAt, (string) $row['name']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Finds managed GryphlinePull entities whose seq_id starts with a synthetic prefix
     * and match the given pool types, timestamp, and item name.
     *
     * @param list<string> $poolTypes
     * @return list<GryphlinePull>
     */
    public function findSyntheticCandidatesForReconciliation(
        GryphlineGameProfile $profile,
        string $seqIdPrefix,
        array $poolTypes,
        \DateTimeImmutable $pulledAt,
        string $name,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->where('p.gameProfile = :profile')
            ->andWhere('p.seqId LIKE :prefix')
            ->andWhere('p.pulledAt = :pulledAt')
            ->andWhere('LOWER(p.name) = :lowerName')
            ->setParameter('profile', $profile)
            ->setParameter('prefix', $seqIdPrefix . '%')
            ->setParameter('pulledAt', $pulledAt)
            ->setParameter('lowerName', mb_strtolower(trim($name)))
            ->orderBy('p.seqId', 'DESC');

        if ($poolTypes !== []) {
            $qb->andWhere('p.poolType IN (:poolTypes)')
                ->setParameter('poolTypes', $poolTypes);
        }

        /** @var list<GryphlinePull> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public static function buildSeqKey(string $poolCategory, string $seqId): string
    {
        return sprintf('%s|%s', $poolCategory, $seqId);
    }

    public static function buildLogicalKey(string $bannerGroupKey, \DateTimeInterface $pulledAt, string $name): string
    {
        return sprintf(
            '%s|%s|%s',
            $bannerGroupKey,
            $pulledAt->format('Y-m-d H:i:s'),
            mb_strtolower(trim($name))
        );
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
}
