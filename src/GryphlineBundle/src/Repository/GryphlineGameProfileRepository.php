<?php

namespace App\GryphlineBundle\Repository;

use App\GryphlineBundle\Entity\GryphlineGameProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GryphlineGameProfile>
 */
class GryphlineGameProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GryphlineGameProfile::class);
    }

    /**
     * @return GryphlineGameProfile[]
     */
    public function findEligibleProfiles(?string $featureFlagField, ?string $gameBiz = null, ?string $timezone = null): array
    {
        return $this->qbEligibleProfiles($featureFlagField, $gameBiz, $timezone)
            ->addSelect('a')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<array{id: int, gameBiz: string}>
     */
    public function findEligibleProfileIds(?string $featureFlagField, ?string $gameBiz = null, ?string $timezone = null): array
    {
        return $this->qbEligibleProfiles($featureFlagField, $gameBiz, $timezone)
            ->select('p.id', 'p.gameBiz')
            ->getQuery()
            ->getResult();
    }

    protected function qbEligibleProfiles(?string $featureFlagField, ?string $gameBiz = null, ?string $timezone = null): QueryBuilder
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

        if ($gameBiz !== null) {
            $qb->andWhere('p.gameBiz = :gameBiz')
                ->setParameter('gameBiz', $gameBiz);
        }

        if ($timezone !== null) {
            $qb->andWhere('p.parsedTimezone = :timezone')
                ->setParameter('timezone', $timezone);
        }

        return $qb;
    }
}
