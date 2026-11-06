<?php

namespace App\GryphlineBundle\Repository;

use App\GryphlineBundle\Entity\GryphlineAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GryphlineAccount>
 */
class GryphlineAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GryphlineAccount::class);
    }
}
