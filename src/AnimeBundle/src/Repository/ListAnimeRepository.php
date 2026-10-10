<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\ListAnime;
use App\CoreBundle\Model\StandardTable\TableRepositoryInterface;
use App\CoreBundle\Model\StandardTable\TableRepositoryTrait;
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
class ListAnimeRepository extends ServiceEntityRepository implements TableRepositoryInterface
{
    use TableRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListAnime::class);
    }

}
