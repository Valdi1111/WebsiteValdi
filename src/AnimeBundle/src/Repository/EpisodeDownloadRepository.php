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

}
