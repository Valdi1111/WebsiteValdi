<?php

namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\SeasonFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonFolder>
 *
 * @method SeasonFolder|null find($id, $lockMode = null, $lockVersion = null)
 * @method SeasonFolder|null findOneBy(array $criteria, array $orderBy = null)
 * @method SeasonFolder[]    findAll()
 * @method SeasonFolder[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SeasonFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonFolder::class);
    }

}
