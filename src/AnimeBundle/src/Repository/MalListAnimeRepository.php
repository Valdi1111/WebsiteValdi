<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\ListAnime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ListAnime>
 *
 * @method ListAnime|null find($id, $lockMode = null, $lockVersion = null)
 * @method ListAnime|null findOneBy(array $criteria, array $orderBy = null)
 * @method ListAnime[]    findAll()
 * @method ListAnime[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MalListAnimeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListAnime::class);
    }

}
