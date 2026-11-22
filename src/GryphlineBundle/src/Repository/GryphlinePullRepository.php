<?php

namespace App\GryphlineBundle\Repository;

use App\GamesBundle\Model\Pull\PullOrigin;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GryphlinePull>
 */
class GryphlinePullRepository extends ServiceEntityRepository
{
    /** Fields read by GryphlinePullStatsCalculator. */
    private const string STATS_FIELDS = 'p.id, p.seqId, p.poolType, p.poolCategory, p.poolId, p.poolName, p.itemId, p.name, p.rarity, p.pulledAt, p.origin, p.declaredPity';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GryphlinePull::class);
    }

    /**
     * Whether the profile already has a pull with this seq id (the sync stops at the first one).
     * Manual and filler seq ids carry a prefix, so they never match an official seq id.
     */
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
     * All rows of a profile as plain arrays, ordered by pulledAt.
     * GryphlinePullStatsCalculator applies the final order and the grouping by banner.
     *
     * @return list<array<string, mixed>>
     */
    public function findProfileRows(GryphlineGameProfile $profile): array
    {
        return $this->createQueryBuilder('p')
            ->select(self::STATS_FIELDS)
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile)
            ->orderBy('p.pulledAt', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Rows of one banner group as plain arrays, ordered by pulledAt: a row belongs to the group when its pool
     * category or its pool type is one of the group's categories (no categories: every row of the profile).
     * GryphlinePullStatsCalculator applies the final order.
     *
     * @param list<string> $categories Categories of the banner group (GachaConfig::getBannerGroups('categories'))
     * @return list<array<string, mixed>>
     */
    public function findBannerRows(GryphlineGameProfile $profile, array $categories): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select(self::STATS_FIELDS)
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile);

        if ($categories !== []) {
            $qb->andWhere('p.poolCategory IN (:categories) OR p.poolType IN (:categories)')
                ->setParameter('categories', $categories);
        }

        return $qb->orderBy('p.pulledAt', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Returns a hash set of all existing (pool_category, seq_id) keys for the given profile.
     *
     * Manual and filler rows are included: their seq ids ("manual_<uuid>", "filler_<uuid>") never
     * collide with an official or imported one.
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
     * Returns multiset counts of existing recorded pulls grouped by (bannerGroupKey, pulledAt, lower(name)).
     *
     * Manual and filler rows are left out: an imported pull matching a manual S must still be imported
     * (the realign step then merges the manual row into it).
     *
     * @param array<string, string> $poolTypeToGroupKey Map of poolType/category => canonical banner group key
     * @return array<string, int>
     */
    public function findExistingLogicalPullCounts(GryphlineGameProfile $profile, array $poolTypeToGroupKey): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.poolType, p.poolCategory, p.pulledAt, p.name')
            ->where('p.gameProfile = :profile')
            ->andWhere('p.origin = :recorded')
            ->setParameter('profile', $profile)
            ->setParameter('recorded', PullOrigin::Recorded)
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
     * Finds managed recorded GryphlinePull entities whose seq_id starts with a synthetic prefix
     * and match the given pool types, timestamp, and item name (never a manual or filler row).
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
            ->andWhere('p.origin = :recorded')
            ->setParameter('profile', $profile)
            ->setParameter('recorded', PullOrigin::Recorded)
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

    /**
     * Distinct recorded items of a pool category at or above a rarity, across all profiles
     * (suggestions of the manual S form).
     *
     * @return list<array{itemId: string, name: string, rarity: int}>
     */
    public function findDistinctRecordedItems(string $poolCategory, int $minRarity): array
    {
        /** @var list<array{itemId: string, name: string, rarity: int|string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.itemId, MAX(p.name) AS name, MAX(p.rarity) AS rarity')
            ->where('p.poolCategory = :poolCategory')
            ->andWhere('p.rarity >= :minRarity')
            ->andWhere('p.origin = :recorded')
            ->andWhere('p.itemId IS NOT NULL')
            ->setParameter('poolCategory', $poolCategory)
            ->setParameter('minRarity', $minRarity)
            ->setParameter('recorded', PullOrigin::Recorded)
            ->groupBy('p.itemId')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'itemId' => (string) $row['itemId'],
            'name' => (string) $row['name'],
            'rarity' => (int) $row['rarity'],
        ], $rows);
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
}
