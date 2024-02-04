<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\BookCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookCache>
 *
 * @method BookCache|null find($id, $lockMode = null, $lockVersion = null)
 * @method BookCache|null findOneBy(array $criteria, array $orderBy = null)
 * @method BookCache[]    findAll()
 * @method BookCache[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BookCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookCache::class);
    }

}
