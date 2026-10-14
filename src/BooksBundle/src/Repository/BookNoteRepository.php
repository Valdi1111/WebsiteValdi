<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookNote;
use App\CoreBundle\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookNote>
 *
 * @method BookNote|null find($id, $lockMode = null, $lockVersion = null)
 * @method BookNote|null findOneBy(array $criteria, array $orderBy = null)
 * @method BookNote[]    findAll()
 * @method BookNote[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BookNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookNote::class);
    }

    /**
     * @return BookNote[]
     */
    public function findByBookAndUser(Book $book, User $user): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.book = :book')
            ->andWhere('n.user = :user')
            ->setParameter('book', $book)
            ->setParameter('user', $user)
            ->orderBy('n.sectionIndex', 'ASC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
