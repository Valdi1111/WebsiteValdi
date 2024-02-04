<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\EpisodeDownload;
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
class AwDownloadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EpisodeDownload::class);
    }

}
