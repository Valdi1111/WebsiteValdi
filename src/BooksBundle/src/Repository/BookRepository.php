<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\Book;
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
     * @param User $user
     * @param ?int $limit
     * @param ?int $offset
     * @param ?int $shelfId
     * @return QueryBuilder
     */
    private function books(User $user, ?int $limit, ?int $offset, ?int $shelfId = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b AS book')
            ->leftJoin('b.progresses', 'bp')
            ->andWhere('bp.user = :userId OR bp.user IS NULL')
            ->setParameter('userId', $user->getId());
        if ($shelfId === -1) {
            $qb->andWhere("b.shelf_id IS NULL");
        } else if ($shelfId) {
            $qb->andWhere("b.shelf_id = :shelfId")
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
     * @param User $user
     * @param int $limit
     * @param int $offset
     * @return Book[]
     */
    public function getAll(User $user, int $limit, int $offset): array
    {
        $results = $this->books($user, $limit, $offset)
            ->addSelect('COALESCE(bp.last_read, b.created) AS orderColumn')
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
     * @param User $user
     * @param int $limit
     * @param int $offset
     * @return Book[]
     */
    public function getNotInShelves(User $user, int $limit, int $offset): array
    {
        $results = $this->books($user, $limit, $offset, -1)
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
