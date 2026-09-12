<?php

namespace App\HoyoverseBundle\Repository;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HoyoverseGameProfile>
 */
class HoyoverseGameProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HoyoverseGameProfile::class);
    }

    /**
     * @return HoyoverseGameProfile[]
     */
    public function findEligibleProfiles(?string $featureFlagField, ?int $gameId = null, ?string $timezone = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.account', 'a')
            ->addSelect('a')
            ->where('p.active = :profileActive')
            ->setParameter('profileActive', true);

        if ($featureFlagField !== null) {
            $qb->andWhere(sprintf('p.%s = :featureEnabled', $featureFlagField))
                ->setParameter('featureEnabled', true);
        }

        if ($gameId !== null) {
            $qb->andWhere('p.gameId = :gameId')
                ->setParameter('gameId', $gameId);
        }

        if ($timezone !== null) {
            $qb->andWhere('p.parsedTimezone = :timezone')
                ->setParameter('timezone', $timezone);
        }

        return $qb->getQuery()->getResult();
    }
}
