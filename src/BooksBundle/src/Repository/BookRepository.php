<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookCache;
use App\BooksBundle\Entity\BookMetadata;
use App\BooksBundle\Entity\BookProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
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
     * @param ?int $limit
     * @param ?int $offset
     * @param array $orders
     * @param ?int $shelfId
     * @return Book[]
     */
    private function _books(?int $limit, ?int $offset, array $orders, ?int $shelfId = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->addSelect('bm', 'bc', 'bp')
            ->innerJoin(BookMetadata::class, 'bm', Join::WITH, "b.id = bm.book_id")
            ->innerJoin(BookCache::class, 'bc', Join::WITH, "b.id = bc.book_id")
            ->innerJoin(BookProgress::class, 'bp', Join::WITH, "b.id = bp.book_id");
        if ($shelfId === -1) {
            $qb->andWhere("b.shelf_id IS NULL");
        } else if ($shelfId) {
            $qb->andWhere("b.shelf_id = :shelfId")->setParameter("shelfId", $shelfId);
        }
        foreach ($orders as $order) {
            $qb->addOrderBy($order['sort'], $order['order']);
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }
        if ($offset) {
            $qb->setFirstResult($offset);
        }
        $books = [];
        foreach ($qb->getQuery()->getResult() as $item) {
            if ($item instanceof Book) {
                $books[] = $item;
            }
        }
        return $books;
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return Book[]
     */
    public function getAll(int $limit, int $offset): array
    {
        return $this->_books($limit, $offset, [
            ['sort' => "bp.last_read", 'order' => "DESC"],
            ['sort' => "b.id", 'order' => "DESC"]
        ]);
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return Book[]
     */
    public function getNotInShelves(int $limit, int $offset): array
    {
        return $this->_books($limit, $offset, [['sort' => "b.url", 'order' => "ASC"]], -1);
    }

    /**
     * @param int $shelfId
     * @return Book[]
     */
    public function getShelfBooks(int $shelfId): array
    {
        return $this->_books(null, null, [['sort' => "b.url", 'order' => "ASC"]], $shelfId);
    }

    /**
     * @return string[]
     */
    public function getRegisteredPaths(): array
    {
        $res = $this->createQueryBuilder('b')
            ->select('b.url')
            ->getQuery()
            ->getArrayResult();
        return array_column($res, 'url');
    }

    /**
     * @param string $path
     * @return Book[]
     */
    public function getWithPath(string $path): array
    {
        return $this->createQueryBuilder('b')
            ->where("b.url LIKE :path")
            ->setParameter("path", "/$path/%")
            ->getQuery()
            ->getResult();
    }

}
