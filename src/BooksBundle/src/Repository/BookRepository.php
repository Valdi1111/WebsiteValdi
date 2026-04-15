<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\Library;
use App\CoreBundle\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Book>
 *
 * @method Book|null find($id, $lockMode = null, $lockVersion = null)
 * @method Book|null findOneBy(array $criteria, array $orderBy = null)
 * @method Book[]    findAll()
 * @method Book[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BookRepository extends ServiceEntityRepository
{
    const int SHELF_FILTER_NO_FILTER = 0;
    const int SHELF_FILTER_NOT_IN_SHELVES = -1;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * @param Library $library
     * @param User $user
     * @param null|int $limit
     * @param null|int $offset
     * @param int $shelfId
     * @return QueryBuilder
     */
    private function books(Library $library, User $user, ?int $limit = null, ?int $offset = null, int $shelfId = self::SHELF_FILTER_NO_FILTER): QueryBuilder
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b AS book')
            ->leftJoin('b.bookProgresses', 'bp')
            ->andWhere('b.library = :library')
            ->andWhere('bp.user = :user OR bp.user IS NULL')
            ->setParameter('library', $library)
            ->setParameter('user', $user);
        if ($shelfId === self::SHELF_FILTER_NOT_IN_SHELVES) {
            $qb->andWhere("b.shelf IS NULL");
        } else if ($shelfId != self::SHELF_FILTER_NO_FILTER) {
            $qb->andWhere("b.shelf = :shelfId")
                ->setParameter("shelfId", $shelfId);
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }
        if ($offset) {
            $qb->setFirstResult($offset);
        }
        return $qb;
    }

    /**
     * @param Library $library
     * @param User $user
     * @param int $limit
     * @param int $offset
     * @return array{books: Book[], books_count: int}
     */
    public function getAll(Library $library, User $user, int $limit, int $offset): array
    {
        $results = $this->books($library, $user, $limit, $offset)
            ->addSelect('COALESCE(bp.lastRead, b.created) AS orderColumn')
            ->addOrderBy('orderColumn', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
        return [
            "books" => array_column($results, 'book'),
            "books_count" => $this->books($library, $user)
                ->select('COUNT(b)')
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }

    /**
     * @param Library $library
     * @param User $user
     * @param int $limit
     * @param int $offset
     * @return array{books: Book[], books_count: int}
     */
    public function getNotInShelves(Library $library, User $user, int $limit, int $offset): array
    {
        $results = $this->books($library, $user, $limit, $offset, self::SHELF_FILTER_NOT_IN_SHELVES)
            ->addOrderBy('b.url', 'ASC')
            ->getQuery()
            ->getResult();
        return [
            "books" => array_column($results, 'book'),
            "books_count" => $this->books($library, $user, shelfId: self::SHELF_FILTER_NOT_IN_SHELVES)
                ->select('COUNT(b)')
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }

    /**
     * @param Library $library
     * @return string[]
     */
    public function getRegisteredPaths(Library $library): array
    {
        return $this->createQueryBuilder('b')
            ->select('b.url')
            ->andWhere('b.library = :library')
            ->setParameter('library', $library)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * @param Library $library
     * @param string $path
     * @return Book[]
     */
    public function getWithPath(Library $library, string $path): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.library = :library')
            ->andWhere("b.url LIKE :path")
            ->setParameter('library', $library)
            ->setParameter("path", "$path/%")
            ->getQuery()
            ->getResult();
    }

}
