<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\ListManga;
use App\CoreBundle\Repository\ITableRepository;
use App\CoreBundle\Repository\TableRepositoryTrait;
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
class ListMangaRepository extends ServiceEntityRepository implements ITableRepository
{
    use TableRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListManga::class);
    }

}
