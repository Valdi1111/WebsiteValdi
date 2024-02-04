<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\ListManga;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ListManga>
 *
 * @method ListManga|null find($id, $lockMode = null, $lockVersion = null)
 * @method ListManga|null findOneBy(array $criteria, array $orderBy = null)
 * @method ListManga[]    findAll()
 * @method ListManga[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MalListMangaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListManga::class);
    }

}
