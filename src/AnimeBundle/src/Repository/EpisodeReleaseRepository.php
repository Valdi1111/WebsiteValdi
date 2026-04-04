<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\EpisodeRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EpisodeRelease>
 *
 * @method EpisodeRelease|null find($id, $lockMode = null, $lockVersion = null)
 * @method EpisodeRelease|null findOneBy(array $criteria, array $orderBy = null)
 * @method EpisodeRelease[]    findAll()
 * @method EpisodeRelease[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EpisodeReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EpisodeRelease::class);
    }

}