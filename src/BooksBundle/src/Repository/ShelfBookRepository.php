<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\ShelfBook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShelfBook>
 *
 * @method ShelfBook|null find($id, $lockMode = null, $lockVersion = null)
 * @method ShelfBook|null findOneBy(array $criteria, array $orderBy = null)
 * @method ShelfBook[]    findAll()
 * @method ShelfBook[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShelfBookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShelfBook::class);
    }

}
