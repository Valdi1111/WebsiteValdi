<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\EpisodeDownloadTracker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EpisodeDownloadTracker>
 *
 * @method EpisodeDownloadTracker|null find($id, $lockMode = null, $lockVersion = null)
 * @method EpisodeDownloadTracker|null findOneBy(array $criteria, array $orderBy = null)
 * @method EpisodeDownloadTracker[]    findAll()
 * @method EpisodeDownloadTracker[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EpisodeDownloadTrackerRepository extends ServiceEntityRepository
{

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EpisodeDownloadTracker::class);
    }

}
