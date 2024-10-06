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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * @param Library $library
     * @param User $user
     * @param ?int $limit
     * @param ?int $offset
     * @param ?int $shelfId
     * @return QueryBuilder
     */
    private function books(Library $library, User $user, ?int $limit, ?int $offset, ?int $shelfId = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b AS book')
            ->leftJoin('b.bookProgresses', 'bp')
            ->leftJoin('b.shelf', 's')
            ->andWhere('b.library = :libraryId')
            ->andWhere('bp.user = :userId OR bp.user IS NULL')
            ->setParameter('libraryId', $library->getId())
            ->setParameter('userId', $user->getId());
        if ($shelfId === -1) {
            $qb->andWhere("s.id IS NULL");
        } else if ($shelfId) {
            $qb->andWhere("s.id = :shelfId")
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
     * @return Book[]
     */
    public function getAll(Library $library, User $user, int $limit, int $offset): array
    {
        $results = $this->books($library, $user, $limit, $offset)
            ->addSelect('COALESCE(bp.lastRead, b.created) AS orderColumn')
            ->addOrderBy('orderColumn', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
        $books = [];
        foreach ($results as $result) {
            $books[] = $result['book'];
        }
        return $books;
    }

    /**
     * @param Library $library
     * @param User $user
     * @param int $limit
     * @param int $offset
     * @return Book[]
     */
    public function getNotInShelves(Library $library, User $user, int $limit, int $offset): array
    {
        $results = $this->books($library, $user, $limit, $offset, -1)
            ->addOrderBy('b.url', 'ASC')
            ->getQuery()
            ->getResult();
        $books = [];
        foreach ($results as $result) {
            $books[] = $result['book'];
        }
        return $books;
    }

    /**
     * @param Library $library
     * @return string[]
     */
    public function getRegisteredPaths(Library $library): array
    {
        $res = $this->createQueryBuilder('b')
            ->select('b.url')
            ->andWhere('b.library = :libraryId')
            ->setParameter('libraryId', $library->getId())
            ->getQuery()
            ->getArrayResult();
        return array_column($res, 'url');
    }

    /**
     * @param Library $library
     * @param string $path
     * @return Book[]
     */
    public function getWithPath(Library $library, string $path): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.library = :libraryId')
            ->andWhere("b.url LIKE :path")
            ->setParameter('libraryId', $library->getId())
            ->setParameter("path", "/$path/%")
            ->getQuery()
            ->getResult();
    }

}
