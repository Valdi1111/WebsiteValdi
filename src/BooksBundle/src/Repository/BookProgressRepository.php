<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\BookProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookProgress>
 *
 * @method BookProgress|null find($id, $lockMode = null, $lockVersion = null)
 * @method BookProgress|null findOneBy(array $criteria, array $orderBy = null)
 * @method BookProgress[]    findAll()
 * @method BookProgress[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BookProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookProgress::class);
    }

}
