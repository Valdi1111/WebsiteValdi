<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookBookmark;
use App\CoreBundle\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookBookmark>
 *
 * @method BookBookmark|null find($id, $lockMode = null, $lockVersion = null)
 * @method BookBookmark|null findOneBy(array $criteria, array $orderBy = null)
 * @method BookBookmark[]    findAll()
 * @method BookBookmark[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BookBookmarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookBookmark::class);
    }

    /**
     * @return BookBookmark[]
     */
    public function findByBookAndUser(Book $book, User $user): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.book = :book')
            ->andWhere('b.user = :user')
            ->setParameter('book', $book)
            ->setParameter('user', $user)
            ->orderBy('b.fraction', 'ASC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
