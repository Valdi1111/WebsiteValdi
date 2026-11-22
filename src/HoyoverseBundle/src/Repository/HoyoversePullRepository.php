<?php

namespace App\HoyoverseBundle\Repository;

use App\GamesBundle\Model\Pull\PullOrigin;
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
     * Only recorded rows count: a manual S or a filler is not a copy of an imported pull.
     *
     * @param array<string, string> $gachaTypeToGroupKey
     * @return array<string, int>
     */
    public function findExistingLogicalPullCounts(HoyoverseGameProfile $profile, array $gachaTypeToGroupKey): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.gachaType, p.pulledAt, p.name')
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
     * Every row of the profile as plain arrays, oldest first (pulledAt, then pullId): the input of
     * HoyoversePullStatsCalculator::groupRowsByBanner().
     *
     * @return list<array<string, mixed>>
     */
    public function findProfileRows(HoyoverseGameProfile $profile): array
    {
        return $this->findBannerRows($profile, []);
    }

    /**
     * Rows of the given gacha types (every type when empty) as plain arrays, oldest first
     * (pulledAt, then pullId), filler and manual rows included: the input of
     * HoyoversePullStatsCalculator::compute() and of the realign step.
     *
     * @param list<string> $gachaTypes
     *
     * @return list<array<string, mixed>>
     */
    public function findBannerRows(HoyoverseGameProfile $profile, array $gachaTypes): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id, p.pullId, p.gachaType, p.gachaName, p.itemType, p.itemId, p.name, p.rarity, p.pulledAt, p.origin, p.declaredPity')
            ->where('p.gameProfile = :profile')
            ->setParameter('profile', $profile);

        if ($gachaTypes !== []) {
            $qb->andWhere('p.gachaType IN (:gachaTypes)')
                ->setParameter('gachaTypes', $gachaTypes);
        }

        return $qb->orderBy('p.pulledAt', 'ASC')
            ->addOrderBy('p.pullId', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Distinct recorded S items of a game, across every profile (suggestions of the manual S form).
     * Manual rows are left out: their names are free text typed by other users.
     *
     * @return list<array{itemId: ?string, name: string, itemType: string, rarity: int}>
     */
    public function findDistinctRecordedSItems(int $gameId, int $sRarity): array
    {
        /** @var list<array{itemId: ?string, name: string, itemType: string, rarity: int}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.itemId, p.name, p.itemType, p.rarity')
            ->innerJoin('p.gameProfile', 'g')
            ->where('g.gameId = :gameId')
            ->andWhere('p.rarity >= :sRarity')
            ->andWhere('p.origin = :recorded')
            ->setParameter('gameId', $gameId)
            ->setParameter('sRarity', $sRarity)
            ->setParameter('recorded', PullOrigin::Recorded)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * Distinct item types of the recorded pulls of a game, across every profile.
     *
     * @return list<string>
     */
    public function findDistinctRecordedItemTypes(int $gameId): array
    {
        /** @var list<string> $types */
        $types = $this->createQueryBuilder('p')
            ->select('DISTINCT p.itemType')
            ->innerJoin('p.gameProfile', 'g')
            ->where('g.gameId = :gameId')
            ->andWhere('p.origin = :recorded')
            ->setParameter('gameId', $gameId)
            ->setParameter('recorded', PullOrigin::Recorded)
            ->orderBy('p.itemType', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $types;
    }
}
