<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\EpisodeDownloadAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EpisodeDownloadAttempt>
 *
 * @method EpisodeDownloadAttempt|null find($id, $lockMode = null, $lockVersion = null)
 * @method EpisodeDownloadAttempt|null findOneBy(array $criteria, array $orderBy = null)
 * @method EpisodeDownloadAttempt[]    findAll()
 * @method EpisodeDownloadAttempt[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EpisodeDownloadAttemptRepository extends ServiceEntityRepository
{

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EpisodeDownloadAttempt::class);
    }

}
