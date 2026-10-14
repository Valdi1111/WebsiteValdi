<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookProgress;
use App\CoreBundle\Entity\User;
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

    public function findByBookAndUser(Book $book, User $user): ?BookProgress
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.book = :book')
            ->andWhere('n.user = :user')
            ->setParameter('book', $book)
            ->setParameter('user', $user)
            ->orderBy('n.sectionIndex', 'ASC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
