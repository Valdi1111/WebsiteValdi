<?php

namespace App\PerfectworldBundle\Repository;

use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerfectworldPull>
 */
class PerfectworldPullRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerfectworldPull::class);
    }

    /**
     * Chronologically sorted rows of one banner (oldest first), as plain arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function findBannerRows(PerfectworldGameProfile $profile, string $bannerKey): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.id, p.bannerKey, p.poolId, p.pulledAt, p.groupPosition, p.kind, p.rollResult, p.rewardType, p.rewardId, p.name, p.rarity, p.quantity')
            ->where('p.gameProfile = :profile')
            ->andWhere('p.bannerKey = :bannerKey')
            ->setParameter('profile', $profile)
            ->setParameter('bannerKey', $bannerKey)
            ->orderBy('p.pulledAt', 'ASC')
            ->addOrderBy('p.groupPosition', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Existing identities of one banner, keyed by "Y-m-d H:i:s|position".
     *
     * @return array<string, array{id: int, rewardId: string, exporterVersion: string}>
     */
    public function findIdentityMap(PerfectworldGameProfile $profile, string $bannerKey): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id, p.pulledAt, p.groupPosition, p.rewardId, p.exporterVersion')
            ->where('p.gameProfile = :profile')
            ->andWhere('p.bannerKey = :bannerKey')
            ->setParameter('profile', $profile)
            ->setParameter('bannerKey', $bannerKey)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            /** @var \DateTimeImmutable $pulledAt */
            $pulledAt = $row['pulledAt'];
            $map[self::buildIdentityKey($pulledAt->format('Y-m-d H:i:s'), (int) $row['groupPosition'])] = [
                'id' => (int) $row['id'],
                'rewardId' => (string) $row['rewardId'],
                'exporterVersion' => (string) $row['exporterVersion'],
            ];
        }

        return $map;
    }

    public static function buildIdentityKey(string $pulledAt, int $groupPosition): string
    {
        return $pulledAt . '|' . $groupPosition;
    }
}
