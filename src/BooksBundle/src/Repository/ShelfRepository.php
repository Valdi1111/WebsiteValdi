<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\Shelf;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shelf>
 *
 * @method Shelf|null find($id, $lockMode = null, $lockVersion = null)
 * @method Shelf|null findOneBy(array $criteria, array $orderBy = null)
 * @method Shelf[]    findAll()
 * @method Shelf[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShelfRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shelf::class);
    }

    /**
     * @return Shelf[]
     */
    public function getShelves(): array
    {
        $items = $this->createQueryBuilder('s')->addSelect('COUNT(b)')
            ->leftJoin(Book::class, 'b', Join::WITH, "b.shelf_id = s.id")
            ->groupBy('s')
            ->orderBy('s.name')
            ->getQuery()->getResult();
        $list = [];
        foreach ($items as $item) {
            /** @var Shelf $shelf */
            $shelf = $item[0];
            $shelf->setCount($item[1]);
            $list[] = $shelf;
        }
        return $list;
    }

}
