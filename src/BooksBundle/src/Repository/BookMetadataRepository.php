<?php

namespace App\BooksBundle\Repository;

use App\BooksBundle\Entity\BookMetadata;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookMetadata>
 *
 * @method BookMetadata|null find($id, $lockMode = null, $lockVersion = null)
 * @method BookMetadata|null findOneBy(array $criteria, array $orderBy = null)
 * @method BookMetadata[]    findAll()
 * @method BookMetadata[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BookMetadataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookMetadata::class);
    }

}
