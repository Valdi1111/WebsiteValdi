<?php

namespace App\HoyoverseBundle\Repository;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HoyoverseDiaryEntry>
 */
class HoyoverseDiaryEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HoyoverseDiaryEntry::class);
    }
    /**
     * Returns distinct periods recorded for a specific game profile.
     *
     * @return list<string>
     */
    public function findDistinctPeriods(HoyoverseGameProfile $profile): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.period')
            ->where('e.gameProfile = :profile')
            ->setParameter('profile', $profile)
            ->orderBy('e.period', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();

        return $rows;
    }

    /**
     * Calculates total amount acquired in a specific period and currency.
     */
    public function getTotalForPeriod(HoyoverseGameProfile $profile, string $period, GameDiaryCurrency $currency): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COALESCE(SUM(e.amount), 0)')
            ->where('e.gameProfile = :profile')
            ->andWhere('e.period = :period')
            ->andWhere('e.currency = :currency')
            ->setParameter('profile', $profile)
            ->setParameter('period', $period)
            ->setParameter('currency', $currency->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Aggregates total currency earnings by actionName.
     *
     * @return list<array{actionName: string, total: int}>
     */
    public function getActionBreakdown(HoyoverseGameProfile $profile, string $period, GameDiaryCurrency $currency): array
    {
        return $this->createQueryBuilder('e')
            ->select('COALESCE(NULLIF(e.actionName, \'\'), \'Other\') as actionName, SUM(e.amount) as total')
            ->where('e.gameProfile = :profile')
            ->andWhere('e.period = :period')
            ->andWhere('e.currency = :currency')
            ->setParameter('profile', $profile)
            ->setParameter('period', $period)
            ->setParameter('currency', $currency->value)
            ->groupBy('actionName')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Returns raw entries for daily aggregation.
     *
     * @return list<array{recordedAt: \DateTimeInterface, amount: int}>
     */
    public function getEntriesForDailyTrend(HoyoverseGameProfile $profile, string $period, GameDiaryCurrency $currency): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.recordedAt, e.amount')
            ->where('e.gameProfile = :profile')
            ->andWhere('e.period = :period')
            ->andWhere('e.currency = :currency')
            ->setParameter('profile', $profile)
            ->setParameter('period', $period)
            ->setParameter('currency', $currency->value)
            ->orderBy('e.recordedAt', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Paginated raw log entries with optional action and date filters, including sum.
     *
     * @return array{items: list<HoyoverseDiaryEntry>, total: int, filteredTotalAmount: int}
     */
    public function findPaginated(
        HoyoverseGameProfile $profile,
        string $period,
        GameDiaryCurrency $currency,
        ?string $actionFilter,
        ?string $dateFilter,
        int $page = 1,
        int $limit = 20
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->where('e.gameProfile = :profile')
            ->andWhere('e.period = :period')
            ->andWhere('e.currency = :currency')
            ->setParameter('profile', $profile)
            ->setParameter('period', $period)
            ->setParameter('currency', $currency->value);

        if ($actionFilter !== null && trim($actionFilter) !== '') {
            $qb->andWhere('LOWER(e.actionName) LIKE :filter')
                ->setParameter('filter', '%' . mb_strtolower(trim($actionFilter)) . '%');
        }

        if ($dateFilter !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
            $qb->andWhere('e.recordedAt >= :dateStart AND e.recordedAt <= :dateEnd')
                ->setParameter('dateStart', new \DateTimeImmutable($dateFilter . ' 00:00:00'))
                ->setParameter('dateEnd', new \DateTimeImmutable($dateFilter . ' 23:59:59'));
        }

        $aggregatesQb = clone $qb;
        $aggregates = $aggregatesQb->select('COUNT(e.id) as totalCount, COALESCE(SUM(e.amount), 0) as totalAmount')
            ->getQuery()
            ->getSingleResult();

        $items = $qb->select('e')
            ->orderBy('e.recordedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => (int) $aggregates['totalCount'],
            'filteredTotalAmount' => (int) $aggregates['totalAmount'],
        ];
    }

}
