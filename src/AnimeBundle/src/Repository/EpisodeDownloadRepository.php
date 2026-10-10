<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\CoreBundle\Model\StandardTable\TableRepositoryInterface;
use App\CoreBundle\Model\StandardTable\TableRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EpisodeDownload>
 *
 * @method EpisodeDownload|null find($id, $lockMode = null, $lockVersion = null)
 * @method EpisodeDownload|null findOneBy(array $criteria, array $orderBy = null)
 * @method EpisodeDownload[]    findAll()
 * @method EpisodeDownload[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EpisodeDownloadRepository extends ServiceEntityRepository implements TableRepositoryInterface
{
    use TableRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EpisodeDownload::class);
    }

    /**
     * @return EpisodeDownload[]
     */
    public function findByTracker(string $tracker, int $trackerId, ?bool $default = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->innerJoin('e.trackers', 't')
            ->andWhere('t.tracker = :tracker')
            ->andWhere('t.trackerId = :trackerId')
            ->setParameter('tracker', $tracker)
            ->setParameter('trackerId', $trackerId);
        if ($default !== null) {
            $qb->andWhere('e.defaultDownload = :default')
                ->setParameter('default', $default);
        }
        return $qb->getQuery()
            ->getResult();
    }

}
